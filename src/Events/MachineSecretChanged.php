<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A change to the secret store on a person's machine was asked for through AiBridge::secrets():
 * a put, describe, remove or set_end, whatever its outcome. Listing is not a change and fires nothing.
 *
 * For a host to keep an audit trail of what was done to a machine's secrets and by whom. The
 * value is never carried, nor a description. `$outcome` is `ok`, or the answer's code (the
 * machine's: `exists`, `not_found`, `store_unavailable`, `invalid_name`, `invalid_request`,
 * `invalid_end`, `end_required`, `ended`, `timeout`, `failed`; or one decided on the way:
 * `machine_secrets_unsupported`, `machine_secrets_outdated`, `ends_unsupported`, `not_connected`,
 * `unreachable`, `send_failed`). With
 * `$resultUnknown` the change may or may not have happened. `$replaced` is true for a put that
 * overwrote an existing secret. A request refused before anything was sent (`invalid_*` from the
 * checks here) fires too, so a trail also shows what was attempted. `$endsAt` and `$endKind` are
 * the end asked for on a put or set_end (ISO 8601 UTC, `expire` or `delete`); both are null for
 * a describe or remove, and for a put or set_end that leaves the secret without an end.
 *
 * Fired in the process that called AiBridge::secrets(), after the answer; `$userId` is the
 * signed-in user there, or null (a queued job, a console command).
 */
class MachineSecretChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int|string|null $userId,
        public readonly int $connectionId,
        /** `put`, `describe`, `remove` or `set_end`. */
        public readonly string $operation,
        public readonly string $name,
        public readonly string $outcome,
        public readonly bool $resultUnknown,
        public readonly bool $replaced,
        public readonly int $durationMs,
        public readonly ?string $endsAt = null,
        public readonly ?string $endKind = null,
    ) {}
}
