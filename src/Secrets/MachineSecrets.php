<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Secrets;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Connections\ConnectionStatus;
use Tetrix\AiBridge\Events\MachineSecretChanged;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Upstreams\UpstreamClient;

/**
 * The secret store on a person's machine, managed through its bridge: list, put, describe,
 * remove and set an end (PROTOCOL.md, "Secrets on the machine"). Reached as `AiBridge::secrets()`.
 *
 * Off unless the machine's operator started the bridge with `--machine-secrets`: only then does
 * its hello carry `machine_secrets`, and a bridge without it is never sent a `secret_*` frame
 * (every call answers `machine_secrets_unsupported`). The bridge keeps the secrets in its own
 * store on the machine (`~/.ai-bridge/secrets`).
 *
 * A value goes in and never comes out: nothing here returns, logs or stores one. It travels as
 * JSON from this process to the serve process (POST /api/secrets) and from there in the
 * `secret_put` frame, so keep `AI_BRIDGE_RELAY_URL` local or https. The request goes through
 * Laravel's HTTP client, so a recorder of outgoing requests sees it: with Telescope, call
 * `Telescope::hideRequestParameters(['value'])`.
 *
 * Every method answers an array `{ok: bool, ...}` and never throws for a refusal. On failure
 * `code` is the machine's (`store_unavailable`, `not_found`, `invalid_name`, `invalid_request`,
 * `invalid_end`, `end_required`, `ended`, `exists`, `timeout`, `failed`) or one decided on the way
 * (`machine_secrets_unsupported`, `machine_secrets_outdated`, `ends_unsupported`,
 * `not_connected`, `unreachable`, `send_failed`). For a put, describe, remove or set_end whose
 * outcome cannot be known (`timeout`, `unreachable`, a bridge that dropped
 * after it was sent) the answer also carries `result_unknown: true`: list the secrets again
 * before retrying.
 *
 * Every put, describe, remove and set_end, whatever its outcome, fires MachineSecretChanged and writes
 * one info log line (operation, connection, name, outcome; never a value or a description), so
 * a host can keep an audit trail.
 *
 * Works from any process except the serve process itself, like AiBridge::upstreams(). Takes a
 * Connection the caller already fetched through its own scope; authorising that is the
 * caller's job.
 */
class MachineSecrets
{
    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly ConnectionStatus $status,
    ) {}

    /**
     * Whether this connection's secrets can be managed now: its bridge is connected and its
     * hello carried `machine_secrets` at revision 2 or newer (see ConnectionStatus::for()).
     * False while the machine is off or the serve process cannot be reached, even when the last
     * hello had it: a bridge may come back without `--machine-secrets`. A call made then is
     * answered at once with the reason (`not_connected`, `unreachable`,
     * `machine_secrets_unsupported`, `machine_secrets_outdated`). Also false during a short drop
     * of a `reliable_delivery` bridge; a call made then is held, and sent after the reconnect
     * only if the bridge that comes back passes the same check.
     */
    public function supported(Connection $connection): bool
    {
        $status = $this->status->for($connection);

        return $status['connected'] === true
            && $status['machine_secrets'] === true
            && SecretFrames::refusalFor(['machine_secrets' => ['revision' => $status['machine_secrets_revision']]]) === null;
    }

    /**
     * Whether secrets on this connection's machine can be given an end now: supported(), and the
     * bridge announced `machine_secrets` at revision 3 or newer. A put with an end or a
     * setEnd() to an older bridge is answered `ends_unsupported` with nothing sent.
     */
    public function supportsEnds(Connection $connection): bool
    {
        $status = $this->status->for($connection);
        $info = ['machine_secrets' => ['revision' => $status['machine_secrets_revision']]];

        return $status['connected'] === true
            && $status['machine_secrets'] === true
            && SecretFrames::refusalFor($info) === null
            && SecretFrames::endsRefusalFor($info, ['type' => MessageTypes::SECRET_SET_END]) === null;
    }

    /**
     * The machine's secrets: names, descriptions, when each was last updated and its end, never
     * a value; plus `ended`, the ends the machine handled lately (`{name, kind, ended_at}`, the
     * last 30 days). An expired secret is still listed, with `expired_at`; a deleted one is not.
     *
     * @return array{ok: bool, secrets?: list<array{name: string, description: string|null, updated_at: string|null, ends_at: string|null, end_kind: string|null, expired_at: string|null}>, ended?: list<array{name: string, kind: string, ended_at: string|null}>, code?: string, error?: string}
     */
    public function list(Connection $connection): array
    {
        $answer = $this->call($connection, ['op' => 'list']);
        if (($answer['ok'] ?? false) === true) {
            $answer['secrets'] ??= [];
            $answer['ended'] ??= [];
        }

        return $answer;
    }

    /**
     * Add a secret. An existing name is refused with `exists` unless `$replace` is true; a
     * bridge older than revision 2 is sent nothing (`machine_secrets_outdated`). On
     * success `replaced` says whether an existing secret was overwritten.
     *
     * With `$endsAt` the secret ends then (`$endKind` `expire`, the default, or `delete`); it must
     * be in the future and at most 10 years ahead (`invalid_end`), and a bridge below revision 3
     * is sent nothing (`ends_unsupported`). An end with sub-seconds is rounded up to the next
     * whole second, so it is never earlier than asked.
     *
     * A replace must state its end: `$endsAt` (the secret ends then) or `$noEnd: true` (it has
     * none, deliberately). A replace with neither is refused `end_required` before anything is
     * sent, so a forgotten argument never makes a short-lived secret permanent; one with both is
     * refused `invalid_end`. The end given is the end the secret has afterwards: the old one is
     * not kept, and an expired secret is renewed. A first put without `$endsAt` has no end.
     *
     * @return array{ok: bool, replaced?: bool, code?: string, error?: string, result_unknown?: true}
     */
    public function put(Connection $connection, string $name, #[\SensitiveParameter] string $value, ?string $description = null, bool $replace = false, ?\DateTimeInterface $endsAt = null, ?string $endKind = null, bool $noEnd = false): array
    {
        $body = array_filter([
            'op' => 'put',
            'name' => $name,
            'description' => $description,
            'value' => $value,
            'replace' => $replace ?: null,
            'ends_at' => self::endOf($endsAt),
            'end_kind' => $endsAt === null ? $endKind : ($endKind ?? 'expire'),
        ], static fn ($v) => $v !== null);

        if ($noEnd && $endsAt !== null) {
            $answer = ['ok' => false, 'code' => 'invalid_end', 'error' => 'Give an end or noEnd: true, not both.'];
            $this->audit($connection, $body, $answer, microtime(true));

            return $answer;
        }
        // An explicit "no end" travels as `ends_at: null`; a replace without either choice is
        // left without the key, and SecretRequest refuses it `end_required`.
        if ($noEnd) {
            $body['ends_at'] = null;
        }

        $answer = $this->call($connection, $body);

        // Whatever the machine or anything on the way said, it does not repeat the value.
        if (is_string($answer['error'] ?? null) && $value !== '') {
            $answer['error'] = str_replace($value, '[value]', $answer['error']);
        }

        return $answer;
    }

    /**
     * Change a secret's description.
     *
     * @return array{ok: bool, code?: string, error?: string, result_unknown?: true}
     */
    public function describe(Connection $connection, string $name, string $description): array
    {
        return $this->call($connection, ['op' => 'describe', 'name' => $name, 'description' => $description]);
    }

    /**
     * Remove a secret.
     *
     * @return array{ok: bool, code?: string, error?: string, result_unknown?: true}
     */
    public function remove(Connection $connection, string $name): array
    {
        return $this->call($connection, ['op' => 'remove', 'name' => $name]);
    }

    /**
     * Set a secret's end (`$endKind` `expire`, the default, or `delete`), or remove it with a
     * null `$endsAt` (a `$endKind` with it is refused `invalid_end`, as on a put). Leaves the
     * value and the description alone. Refused `not_found` when the
     * secret is absent, `ended` when it already expired (renew it with a put and `replace`),
     * `invalid_end` for an end in the past or more than 10 years ahead, and `ends_unsupported`
     * with nothing sent for a bridge below revision 3.
     *
     * An end with sub-seconds is rounded up to the next whole second.
     *
     * @return array{ok: bool, code?: string, error?: string, result_unknown?: true}
     */
    public function setEnd(Connection $connection, string $name, ?\DateTimeInterface $endsAt, ?string $endKind = null): array
    {
        $body = ['op' => 'set_end', 'name' => $name, 'ends_at' => self::endOf($endsAt)];
        $endKind = $endsAt === null ? $endKind : ($endKind ?? 'expire');
        if ($endKind !== null) {
            $body['end_kind'] = $endKind;
        }

        return $this->call($connection, $body);
    }

    /**
     * An end as it travels: UTC, second precision, `Z` suffix. Sub-seconds are rounded up to the
     * next whole second: cutting them off could turn `+1 second` into now, which is refused
     * `invalid_end`, and an end is never earlier than the caller asked.
     */
    private static function endOf(?\DateTimeInterface $endsAt): ?string
    {
        if ($endsAt === null) {
            return null;
        }
        $utc = \DateTimeImmutable::createFromInterface($endsAt)->setTimezone(new \DateTimeZone('UTC'));
        if ((int) $utc->format('u') !== 0) {
            $utc = $utc->setTimestamp($utc->getTimestamp() + 1);
        }

        return $utc->format('Y-m-d\\TH:i:s\\Z');
    }

    /**
     * Check the request, POST it to the serve process, and shape the answer.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(Connection $connection, #[\SensitiveParameter] array $body): array
    {
        $started = microtime(true);
        $answer = $this->answer($connection, $body);

        if ($body['op'] !== 'list') {
            $this->audit($connection, $body, $answer, $started);
        }

        return $answer;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function answer(Connection $connection, #[\SensitiveParameter] array $body): array
    {
        $frame = SecretRequest::frameOf($body);
        if (! isset($frame['type'])) {
            return ['ok' => false] + $frame;
        }

        if (! $connection->isBridge() || ($connection->connection_key ?? '') === '') {
            return ['ok' => false, 'code' => 'invalid_request', 'error' => 'Only a CLI bridge connection has a machine.'];
        }

        if (($refusal = $this->endsRefusal($connection, $frame)) !== null) {
            return $refusal;
        }

        $answer = $this->relay($connection->connection_key, $body, (int) config('ai-bridge.machine_secrets.timeout', 20) + 3);

        // A write that was sent and not answered may still have happened: the machine's command
        // was stopped (`timeout` from the machine), nobody answered in time (`timeout` from the
        // serve process), the bridge dropped after it was sent (marked by the serve process), or
        // the serve process's own answer never came back (`unreachable`).
        if ($body['op'] !== 'list' && ($answer['ok'] ?? false) !== true) {
            $code = $answer['code'] ?? null;
            if ($code === 'unreachable') {
                $answer['error'] = 'The bridge server could not be reached or did not answer, so the result is unknown: list the secrets again before retrying.';
            }
            if (in_array($code, ['timeout', 'unreachable'], true)) {
                $answer['result_unknown'] = true;
            }
        }

        return $answer;
    }

    /**
     * `ends_unsupported` before anything is sent, for an end to a connected bridge that announced
     * revision 2 but not 3. Any other state goes on to the serve process, which decides with
     * the live hello and answers the same code (409), or the reason the bridge cannot be used.
     *
     * @param  array<string, mixed>  $frame
     * @return array{ok: false, code: string, error: string}|null
     */
    private function endsRefusal(Connection $connection, #[\SensitiveParameter] array $frame): ?array
    {
        if (! SecretFrames::needsEnds($frame)) {
            return null;
        }

        $status = $this->status->for($connection);
        $info = ['machine_secrets' => ['revision' => $status['machine_secrets_revision']]];
        if ($status['connected'] !== true || $status['machine_secrets'] !== true || SecretFrames::refusalFor($info) !== null) {
            return null;
        }

        $refusal = SecretFrames::endsRefusalFor($info, $frame);
        if ($refusal === null) {
            return null;
        }
        unset($refusal['_status']);

        return $refusal;
    }

    /**
     * The audit trail of one change: MachineSecretChanged and an info log line, never the value.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $answer
     */
    private function audit(Connection $connection, #[\SensitiveParameter] array $body, array $answer, float $started): void
    {
        $ok = ($answer['ok'] ?? false) === true;
        $outcome = $ok ? 'ok' : (is_string($answer['code'] ?? null) ? $answer['code'] : 'failed');
        $name = is_string($body['name'] ?? null) ? mb_substr($body['name'], 0, 128) : '';
        $unknown = ($answer['result_unknown'] ?? null) === true;
        $hasEnd = in_array($body['op'], ['put', 'set_end'], true);
        $endsAt = is_string($body['ends_at'] ?? null) ? $body['ends_at'] : null;
        $endKind = $endsAt !== null && is_string($body['end_kind'] ?? null) ? $body['end_kind'] : null;
        $replaced = $ok && ($answer['replaced'] ?? null) === true;
        $userId = self::authUserId();
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        // Neither the log line nor a listener may cost the caller its answer: the change has
        // already happened (or may have).
        try {
            Log::info('AI Bridge: machine secret '.$body['op'], [
                'operation' => (string) $body['op'],
                'connection_id' => $connection->id,
                'user_id' => $userId,
                'name' => $name,
                'outcome' => $outcome,
                'result_unknown' => $unknown,
                'replaced' => $replaced,
                'duration_ms' => $durationMs,
            ] + ($hasEnd ? ['ends_at' => $endsAt, 'end_kind' => $endKind] : []));
        } catch (\Throwable) {
            // A failing log handler is not the caller's problem.
        }

        try {
            MachineSecretChanged::dispatch(
                $userId,
                (int) $connection->id,
                (string) $body['op'],
                $name,
                $outcome,
                $unknown,
                $replaced,
                $durationMs,
                $endsAt,
                $endKind,
            );
        } catch (\Throwable $e) {
            try {
                Log::warning('AI Bridge: a MachineSecretChanged listener failed', ['error' => $e::class]);
            } catch (\Throwable) {
                // The logger failing too still costs the caller nothing.
            }
        }
    }

    private static function authUserId(): int|string|null
    {
        try {
            return auth()->id();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function relay(string $connectionKey, #[\SensitiveParameter] array $body, int $timeout): array
    {
        try {
            $relayToken = $this->tokenManager->generate($connectionKey, ['scope' => TokenManager::INTERNAL_RELAY_SCOPE], 60);

            /** @var Response $response */
            $response = Http::withToken($relayToken)
                ->timeout($timeout)
                ->acceptJson()
                ->post(UpstreamClient::internalApiBase().'/api/secrets', $body);
        } catch (\Throwable $e) {
            // The operation and the exception's class only: a message could quote the request.
            Log::info('AI Bridge: could not reach the serve process for a machine secret request', [
                'op' => $body['op'],
                'error' => $e::class,
            ]);

            return ['ok' => false, 'code' => 'unreachable', 'error' => 'The bridge server could not be reached.'];
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($response->successful() && is_bool($json['ok'] ?? null)) {
            return $json;
        }

        return [
            'ok' => false,
            'code' => is_string($json['code'] ?? null) ? $json['code'] : 'unreachable',
            'error' => is_string($json['error'] ?? null) ? $json['error'] : 'The bridge server answered '.$response->status().'.',
        ] + (($json['result_unknown'] ?? null) === true ? ['result_unknown' => true] : []);
    }
}
