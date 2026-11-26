<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Secrets;

use Tetrix\AiBridge\Protocol\MessageTypes;

/**
 * The rules a request about the machine's secrets must meet, and the `secret_*` frame it
 * becomes (PROTOCOL.md, "Secrets on the machine").
 *
 * Checked twice with the same code: in the web process, so a bad request never leaves it, and
 * in the serve process, so a relay token alone cannot get a malformed frame to a machine. The
 * bridge checks names again before its store is touched.
 *
 * A refusal's text never contains the value.
 */
final class SecretRequest
{
    /**
     * A secret's name: a letter or digit, then letters, digits, `.`, `_` or `-`, 64 at most.
     * `\z`, not `$`: `$` also matches before a trailing newline.
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/';

    /** The longest description, in characters (code points), as the bridge counts them. */
    public const MAX_DESCRIPTION = 1000;

    /** The largest value, in bytes. */
    public const MAX_VALUE_BYTES = 65536;

    public const OPERATIONS = ['list', 'put', 'describe', 'remove', 'set_end'];

    /** What happens at a secret's end: `expire` keeps the entry without its value, `delete` removes both. */
    public const END_KINDS = ['expire', 'delete'];

    /** The only form an `ends_at` travels in: ISO 8601 UTC, second precision, `Z` suffix. */
    public const END_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/';

    /** How far ahead an end may be set, in years. */
    public const MAX_END_YEARS = 10;

    /** Why `$name` is not a secret's name, or null when it is. */
    public static function nameRefusal(mixed $name): ?string
    {
        // index.json is the bridge's own index of descriptions, never a secret.
        if (! is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1 || str_contains($name, '..') || strtolower($name) === 'index.json') {
            return 'A secret name is 1 to 64 characters: letters, digits, ".", "_" or "-", starting with a letter or digit, never ".." (and not "index.json").';
        }

        return null;
    }

    /** Why `$description` cannot be sent, or null when it can. */
    public static function descriptionRefusal(mixed $description): ?string
    {
        if (! is_string($description) || ! mb_check_encoding($description, 'UTF-8')) {
            return 'A description must be UTF-8 text.';
        }
        if (mb_strlen($description, 'UTF-8') > self::MAX_DESCRIPTION) {
            return 'A description is at most '.self::MAX_DESCRIPTION.' characters.';
        }
        // As the bridge: no control characters but tab and newline, since `aib secret list`
        // prints descriptions to a terminal.
        if (preg_match('/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}]/u', $description) === 1) {
            return 'A description must not contain control characters (tab and newline are fine).';
        }
        return null;
    }

    /**
     * Why `$endsAt` cannot be set as a secret's end, or null when it can: the wire form of
     * END_PATTERN, a real moment, after now and at most MAX_END_YEARS ahead. The bridge checks
     * the same against its own clock, which decides.
     */
    public static function endRefusal(mixed $endsAt, ?\DateTimeImmutable $now = null): ?string
    {
        if (! is_string($endsAt) || preg_match(self::END_PATTERN, $endsAt) !== 1) {
            return 'ends_at must be an ISO 8601 UTC time with a Z suffix, like 2026-12-01T15:00:00Z.';
        }
        $end = self::momentOf($endsAt);
        if ($end === null) {
            return 'ends_at is not a real moment.';
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($end <= $now) {
            return 'A secret\'s end must be in the future.';
        }
        if ($end > $now->modify('+'.self::MAX_END_YEARS.' years')) {
            return 'A secret\'s end is at most '.self::MAX_END_YEARS.' years ahead.';
        }

        return null;
    }

    /**
     * `$value` as a moment when it is in the wire form of END_PATTERN and a real date and time
     * (2026-02-30 is not), or null. Says nothing about whether it is in the future.
     */
    public static function momentOf(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || preg_match(self::END_PATTERN, $value) !== 1) {
            return null;
        }
        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new \DateTimeZone('UTC'));
        // createFromFormat rolls 2026-02-30 over to March; a round trip catches it.
        if ($moment === false || $moment->format('Y-m-d\\TH:i:s\\Z') !== $value) {
            return null;
        }

        return $moment;
    }

    /** Why `$endKind` is not an end kind, or null when it is. */
    public static function endKindRefusal(mixed $endKind): ?string
    {
        if (! is_string($endKind) || ! in_array($endKind, self::END_KINDS, true)) {
            return 'end_kind must be one of '.implode(', ', self::END_KINDS).'.';
        }

        return null;
    }

    /** Why `$value` cannot be sent, or null when it can. Never quotes the value. */
    public static function valueRefusal(#[\SensitiveParameter] mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return 'A secret value must be a non-empty string.';
        }
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            return 'A secret value is at most '.self::MAX_VALUE_BYTES.' bytes.';
        }
        if (! mb_check_encoding($value, 'UTF-8')) {
            return 'A secret value must be UTF-8 text.';
        }

        return null;
    }

    /**
     * The `secret_*` frame (without its `id`) for a request body
     * `{op: list|put|describe|remove|set_end, name?, description?, value?, replace?, ends_at?,
     * end_kind?}`; or, when the request breaks a rule, `{code: invalid_name|invalid_request|
     * invalid_end|end_required, error}` (no `type` key). A put with `replace: true` must carry the
     * `ends_at` key (a time, or null for no end), else `end_required`.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function frameOf(#[\SensitiveParameter] array $body): array
    {
        $refused = static fn (string $error, string $code = 'invalid_request'): array => ['code' => $code, 'error' => $error];

        $op = $body['op'] ?? null;
        if (! is_string($op) || ! in_array($op, self::OPERATIONS, true)) {
            return $refused('op must be one of '.implode(', ', self::OPERATIONS).'.');
        }

        if ($op === 'list') {
            return ['type' => MessageTypes::SECRET_LIST];
        }

        $name = $body['name'] ?? null;
        if (($refusal = self::nameRefusal($name)) !== null) {
            return $refused($refusal, 'invalid_name');
        }

        if ($op === 'remove') {
            return ['type' => MessageTypes::SECRET_REMOVE, 'name' => $name];
        }

        if ($op === 'set_end') {
            if (! array_key_exists('ends_at', $body)) {
                return $refused('set_end needs ends_at: a time, or null to remove the end.');
            }
            if (($body['ends_at'] === null) !== (($body['end_kind'] ?? null) === null)) {
                return $refused('ends_at and end_kind go together: give both or neither.', 'invalid_end');
            }
            if ($body['ends_at'] === null) {
                return ['type' => MessageTypes::SECRET_SET_END, 'name' => $name, 'ends_at' => null];
            }
            if (($refusal = self::endRefusal($body['ends_at']) ?? self::endKindRefusal($body['end_kind'])) !== null) {
                return $refused($refusal, 'invalid_end');
            }

            return ['type' => MessageTypes::SECRET_SET_END, 'name' => $name, 'ends_at' => $body['ends_at'], 'end_kind' => $body['end_kind']];
        }

        $description = $body['description'] ?? null;

        if ($op === 'describe') {
            if (($refusal = self::descriptionRefusal($description)) !== null) {
                return $refused($refusal);
            }

            return ['type' => MessageTypes::SECRET_DESCRIBE, 'name' => $name, 'description' => $description];
        }

        // put
        if ($description !== null && ($refusal = self::descriptionRefusal($description)) !== null) {
            return $refused($refusal);
        }
        if (($refusal = self::valueRefusal($body['value'] ?? null)) !== null) {
            return $refused($refusal);
        }
        $replace = $body['replace'] ?? false;
        if (! is_bool($replace)) {
            return $refused('replace must be true or false.');
        }

        // A replace states its end: a time, or null for deliberately none. Without the key the
        // caller may have forgotten it, and a short-lived secret would silently become permanent.
        if ($replace && ! array_key_exists('ends_at', $body)) {
            return $refused('A replace must state the secret\'s end: ends_at as a time (with end_kind), or null for no end.', 'end_required');
        }
        $endsAt = $body['ends_at'] ?? null;
        $endKind = $body['end_kind'] ?? null;
        if (($endsAt === null) !== ($endKind === null)) {
            return $refused('ends_at and end_kind go together: give both or neither.', 'invalid_end');
        }
        if ($endsAt !== null && ($refusal = self::endRefusal($endsAt) ?? self::endKindRefusal($endKind)) !== null) {
            return $refused($refusal, 'invalid_end');
        }

        $frame = ['type' => MessageTypes::SECRET_PUT, 'name' => $name];
        if ($description !== null && $description !== '') {
            $frame['description'] = $description;
        }
        $frame['value'] = $body['value'];
        if ($replace) {
            $frame['replace'] = true;
        }
        // On a replace `ends_at` always travels, null included; on a first put only an end.
        if ($endsAt !== null) {
            $frame['ends_at'] = $endsAt;
            $frame['end_kind'] = $endKind;
        } elseif ($replace) {
            $frame['ends_at'] = null;
        }

        return $frame;
    }
}
