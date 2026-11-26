<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Secrets;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/**
 * The serve process's handling of `secret_result`, the bridge's one answer to a `secret_*`
 * frame. It goes to whoever waits for that id (BridgeWebSocketServer::apiSecrets()).
 *
 * Runs inside the ReactPHP event loop, where an uncaught TypeError drops every connected
 * bridge, so every field is type-checked and reduced to what PROTOCOL.md documents.
 */
final class SecretFrames
{
    /** The codes a failed `secret_result` may carry; anything else becomes `failed`. */
    public const CODES = ['machine_secrets_unsupported', 'store_unavailable', 'not_found', 'invalid_name', 'invalid_request', 'invalid_end', 'end_required', 'ended', 'exists', 'timeout', 'failed'];

    /** The most rows one list answer is read for. */
    private const MAX_ROWS = 1000;

    /** The lowest `machine_secrets.revision` sent any `secret_*` frame (revision 1 overwrote on a plain put). */
    public const MIN_REVISION = 2;

    /** The lowest `machine_secrets.revision` sent a `secret_put` with an end or any `secret_set_end`. */
    public const ENDS_REVISION = 3;

    public function __construct(
        private readonly BridgeConnectionManager $connections,
    ) {}

    /**
     * Why a bridge that said this at hello must not be sent a `secret_*` frame, or null when it
     * may: 409 `machine_secrets_unsupported` without `machine_secrets` (its operator did not opt
     * in), 409 `machine_secrets_outdated` below revision 2 or without a revision. Checked before
     * sending, and again for a frame held through a short drop against the bridge that came
     * back, which may be an older one.
     *
     * @param  array<string, mixed>  $bridgeInfo  What the bridge said at hello (BridgeConnectionManager).
     * @return array{ok: false, code: string, error: string, _status: int}|null
     */
    public static function refusalFor(array $bridgeInfo): ?array
    {
        $field = $bridgeInfo['machine_secrets'] ?? null;
        if (! is_array($field)) {
            return [
                'ok' => false,
                'code' => 'machine_secrets_unsupported',
                'error' => 'This bridge does not announce machine_secrets; it needs updating, or its operator has not started it with --machine-secrets.',
                '_status' => 409,
            ];
        }

        $revision = $field['revision'] ?? null;
        if (! is_int($revision) || $revision < self::MIN_REVISION) {
            return [
                'ok' => false,
                'code' => 'machine_secrets_outdated',
                'error' => 'The bridge on this machine manages secrets in an older way; update it (ai-bridge 0.46.0 or newer).',
                '_status' => 409,
            ];
        }

        return null;
    }

    /**
     * Why a bridge that said this at hello must not be sent this frame for its end, or null when
     * it may: 409 `ends_unsupported` for a `secret_put` carrying an end (a time in `ends_at`) or any
     * `secret_set_end` to a bridge below revision 3, which would drop the end and keep the
     * secret forever. Checked after refusalFor(), and again for a held frame.
     *
     * @param  array<string, mixed>  $bridgeInfo  What the bridge said at hello (BridgeConnectionManager).
     * @param  array<string, mixed>  $frame  The `secret_*` frame (SecretRequest::frameOf()).
     * @return array{ok: false, code: string, error: string, _status: int}|null
     */
    public static function endsRefusalFor(array $bridgeInfo, array $frame): ?array
    {
        if (! self::needsEnds($frame)) {
            return null;
        }

        $revision = $bridgeInfo['machine_secrets']['revision'] ?? null;
        if (is_int($revision) && $revision >= self::ENDS_REVISION) {
            return null;
        }

        return [
            'ok' => false,
            'code' => 'ends_unsupported',
            'error' => 'The bridge on this machine cannot give a secret an end; update it to a version that supports secret ends.',
            '_status' => 409,
        ];
    }

    /**
     * Whether this `secret_*` frame needs revision 3: a `secret_set_end`, or a `secret_put` with an
     * end. A replace's `ends_at: null` (deliberately no end) needs nothing: a bridge below
     * revision 3 keeps no end anyway.
     *
     * @param  array<string, mixed>  $frame
     */
    public static function needsEnds(array $frame): bool
    {
        $type = $frame['type'] ?? null;

        return $type === MessageTypes::SECRET_SET_END
            || ($type === MessageTypes::SECRET_PUT && ($frame['ends_at'] ?? null) !== null);
    }

    /** The key a `secret_*` exchange waits under: per bridge, so ids cannot cross users. */
    public static function exchangeKey(string $userId, string $id): string
    {
        return 'secret:'.$userId.':'.$id;
    }

    /** @param  array<string, mixed>  $message */
    public function result(string $connectionId, array $message): ?array
    {
        $userId = $this->connections->getUserIdByConnectionId($connectionId);
        $id = $message['id'] ?? null;

        if ($userId === null) {
            Log::warning('AI Bridge: secret_result from unauthenticated connection', ['connection_id' => $connectionId]);

            return null;
        }
        if (! is_string($id) || $id === '' || strlen($id) > 128) {
            return null;
        }

        $this->connections->resolvePendingExchange(self::exchangeKey($userId, $id), $userId, self::answerOf($message));

        return null;
    }

    /**
     * A `secret_result` reduced to `{ok: true, secrets?, ended?, replaced?}` or
     * `{ok: false, code, error}`.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public static function answerOf(array $message): array
    {
        if (($message['ok'] ?? null) === true) {
            $answer = ['ok' => true];
            if (array_key_exists('secrets', $message)) {
                $answer['secrets'] = self::rowsOf($message['secrets']);
            }
            if (array_key_exists('ended', $message)) {
                $answer['ended'] = self::endedOf($message['ended']);
            }
            if (is_bool($message['replaced'] ?? null)) {
                $answer['replaced'] = $message['replaced'];
            }

            return $answer;
        }

        $code = $message['code'] ?? null;
        $code = is_string($code) && in_array($code, self::CODES, true) ? $code : 'failed';
        $error = $message['error'] ?? null;

        return [
            'ok' => false,
            'code' => $code,
            'error' => match (true) {
                // Stopped before it finished: a put, describe, remove or set_end may have happened.
                $code === 'timeout' => 'The machine\'s secret store did not finish in time, so the result is unknown: list the secrets again before retrying.',
                is_string($error) && $error !== '' && mb_check_encoding($error, 'UTF-8') => mb_substr($error, 0, 500),
                default => 'The machine could not do this.',
            },
        ];
    }

    /**
     * The rows of a list answer: `{name, description, updated_at, ends_at, end_kind,
     * expired_at}`, a row whose name breaks the naming rule dropped. An end comes as a pair:
     * when either half is missing or malformed, both are null.
     *
     * @return list<array{name: string, description: string|null, updated_at: string|null, ends_at: string|null, end_kind: string|null, expired_at: string|null}>
     */
    public static function rowsOf(mixed $value): array
    {
        $rows = [];

        foreach (self::listOf($value) as $row) {
            if (! is_array($row) || SecretRequest::nameRefusal($row['name'] ?? null) !== null) {
                continue;
            }
            $description = $row['description'] ?? null;
            $endsAt = self::endTimeOf($row['ends_at'] ?? null);
            $endKind = self::kindOf($row['end_kind'] ?? null);
            $hasEnd = $endsAt !== null && $endKind !== null;

            $rows[] = [
                'name' => $row['name'],
                'description' => is_string($description) && mb_check_encoding($description, 'UTF-8')
                    ? mb_substr($description, 0, SecretRequest::MAX_DESCRIPTION) : null,
                'updated_at' => self::timeOf($row['updated_at'] ?? null),
                'ends_at' => $hasEnd ? $endsAt : null,
                'end_kind' => $hasEnd ? $endKind : null,
                'expired_at' => self::endTimeOf($row['expired_at'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * The `ended` records of a list answer: `{name, kind, ended_at}`, a record with a bad name or
     * kind dropped.
     *
     * @return list<array{name: string, kind: string, ended_at: string|null}>
     */
    public static function endedOf(mixed $value): array
    {
        $records = [];

        foreach (self::listOf($value) as $record) {
            if (! is_array($record) || SecretRequest::nameRefusal($record['name'] ?? null) !== null) {
                continue;
            }
            $kind = self::kindOf($record['kind'] ?? null);
            if ($kind === null) {
                continue;
            }

            $records[] = [
                'name' => $record['name'],
                'kind' => $kind,
                'ended_at' => self::endTimeOf($record['ended_at'] ?? null),
            ];
        }

        return $records;
    }

    /** @return list<mixed> */
    private static function listOf(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? array_slice($value, 0, self::MAX_ROWS) : [];
    }

    /** `updated_at`: the bridge's own timestamp (ISO 8601, any precision), kept when short. */
    private static function timeOf(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 64 ? $value : null;
    }

    /**
     * `ends_at`, `expired_at`, `ended_at`: an end's moment in the one wire form
     * (SecretRequest::END_PATTERN, a real date); anything else becomes null.
     */
    private static function endTimeOf(mixed $value): ?string
    {
        return is_string($value) && SecretRequest::momentOf($value) !== null ? $value : null;
    }

    private static function kindOf(mixed $value): ?string
    {
        return is_string($value) && in_array($value, SecretRequest::END_KINDS, true) ? $value : null;
    }
}
