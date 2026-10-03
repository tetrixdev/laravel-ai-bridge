<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Support;

use Illuminate\Support\Facades\Log;

/**
 * The bridge version this server wants every connected bridge to run, as sent
 * in `welcome.desired_bridge_version`.
 *
 * Read from `ai-bridge.bridge.desired_version`. A bridge running as a managed
 * service moves to exactly this version once it is idle — upgrade or
 * downgrade — so the value is checked hard before it goes anywhere: a strict
 * semver string, nothing that npm would have to interpret (no `v`, no build
 * metadata, no range, tag or URL), and never below {@see self::FLOOR}.
 *
 * Anything else is logged once and treated as no opinion: the field is left
 * out of the welcome entirely, which is exactly what a server that never set
 * it sends. A typo in an env file must not become an instruction.
 */
final class DesiredBridgeVersion
{
    /**
     * The oldest bridge that can update itself.
     *
     * Pinning a machine to anything older strands it there for good: that
     * version never reads the field again, so the server could not move it
     * back. A prerelease of this version sorts below it, per semver, and is
     * refused for the same reason.
     */
    public const FLOOR = '0.24.0';

    /**
     * Strict semver: MAJOR.MINOR.PATCH with an optional prerelease and no
     * build metadata. A numeric prerelease identifier has no leading zero.
     * `D` so `$` is the end of the string: without it PCRE also matches before
     * a final newline, and "0.24.0\n" would go out as a version. The same
     * rule as the bridge's own check (src/selfupdate/version.ts).
     */
    private const PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-(0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(\.(0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?$/D';

    /**
     * Rejected values already logged by this process, so a bad value is
     * reported once rather than on every handshake.
     *
     * @var array<string, true>
     */
    private static array $reported = [];

    /**
     * The configured desired version, or null when there is none or it was
     * refused.
     */
    public static function resolve(): ?string
    {
        $value = config('ai-bridge.bridge.desired_version');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            self::reportOnce($value, 'is not a plain semver version such as "'.self::FLOOR.'" (no leading "v", no build metadata, no range, tag or URL)');

            return null;
        }

        if (self::compare($value, self::FLOOR) < 0) {
            self::reportOnce($value, 'is below '.self::FLOOR.', the first bridge version that can update itself — a machine pinned there could never be moved again');

            return null;
        }

        return $value;
    }

    /**
     * Compare two strict semver strings by semver precedence.
     *
     * Both must already match {@see self::PATTERN}. Returns <0, 0 or >0.
     */
    public static function compare(string $a, string $b): int
    {
        [$coreA, $preA] = array_pad(explode('-', $a, 2), 2, null);
        [$coreB, $preB] = array_pad(explode('-', $b, 2), 2, null);

        $partsA = explode('.', $coreA);
        $partsB = explode('.', $coreB);

        for ($i = 0; $i < 3; $i++) {
            $cmp = self::compareNumeric($partsA[$i], $partsB[$i]);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        // A version without a prerelease outranks the same version with one.
        if ($preA === null || $preB === null) {
            return ($preA === null ? 1 : 0) - ($preB === null ? 1 : 0);
        }

        $idsA = explode('.', $preA);
        $idsB = explode('.', $preB);

        foreach ($idsA as $i => $idA) {
            if (! isset($idsB[$i])) {
                return 1; // A larger set of identifiers ranks higher when all before are equal.
            }

            $idB = $idsB[$i];
            $numA = ctype_digit($idA);
            $numB = ctype_digit($idB);

            // Numeric identifiers compare numerically and always rank below
            // alphanumeric ones, which compare in ASCII order.
            $cmp = match (true) {
                $numA && $numB => self::compareNumeric($idA, $idB),
                $numA => -1,
                $numB => 1,
                default => strcmp($idA, $idB),
            };

            if ($cmp !== 0) {
                return $cmp < 0 ? -1 : 1;
            }
        }

        return count($idsA) < count($idsB) ? -1 : 0;
    }

    /**
     * Forget which rejected values were logged. For tests.
     *
     * @internal
     */
    public static function flushState(): void
    {
        self::$reported = [];
    }

    /**
     * Compare two digit strings numerically without casting, so a component
     * past PHP_INT_MAX cannot overflow into a wrong answer.
     */
    private static function compareNumeric(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');

        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }

    private static function reportOnce(mixed $value, string $problem): void
    {
        $key = get_debug_type($value).':'.(is_scalar($value) ? (string) $value : '');

        if (isset(self::$reported[$key])) {
            return;
        }

        self::$reported[$key] = true;

        Log::error('AI Bridge: ignoring ai-bridge.bridge.desired_version (AI_BRIDGE_DESIRED_VERSION) — '
            .'the value '.$problem.'. No desired_bridge_version is sent, so bridges keep the version they run.', [
                'desired_version' => is_scalar($value) ? $value : get_debug_type($value),
                'floor' => self::FLOOR,
            ]);
    }
}
