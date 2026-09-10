<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Streaming\StreamHandler;

/*
|--------------------------------------------------------------------------
| A real turn, off a real bridge, through this package
|--------------------------------------------------------------------------
|
| Every other test in this suite feeds the recorder frames that a human wrote.
| This one replays the exact bytes a real `@tetrixdev/ai-bridge` put on the wire
| for a real Claude Code turn — captured by the bridge's own end-to-end harness
| against the installed CLI — and asserts this package makes sense of them.
|
| It is the only test that exercises the seam both packages meet at. Everything
| else tests one half against an idea of the other.
|
*/

uses(RefreshDatabase::class);

test('a captured turn from the real bridge records completely', function () {
    $frames = json_decode(
        (string) file_get_contents(__DIR__.'/fixtures/real-turn-0.8.1.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect($frames)->not->toBeEmpty();

    $rateLimits = [];
    $blocks = recordTurn(function (StreamHandler $h) use ($frames, &$rateLimits) {
        $h->onRateLimit(function (string $provider, array $info) use (&$rateLimits) {
            $rateLimits[] = [$provider, $info];
        });

        foreach ($frames as $frame) {
            $h->dispatchEvent(wire($frame['event'], $frame['data'] ?? []));
        }
    });

    $tools = collect($blocks)->where('type', 'tool_call')->values();

    // Two shell commands ran on the operator's machine. Before this release the
    // record showed neither of them.
    expect($tools)->toHaveCount(2)
        ->and($tools[0]['tool_name'])->toBe('Bash')
        ->and($tools[1]['tool_name'])->toBe('Bash');

    // Each has the id its result is keyed by, and the result itself.
    foreach ($tools as $tool) {
        expect($tool['tool_call_id'])->toStartWith('toolu_')
            ->and($tool)->toHaveKey('result');
    }

    $outputs = $tools->pluck('result')->implode(' ');
    expect($outputs)->toContain('alpha')->and($outputs)->toContain('beta');

    // Nothing orphaned: every result found the call that produced it.
    expect(collect($blocks)->where('type', 'tool_result'))->toHaveCount(0);

    // The assistant's prose survived alongside the tool calls.
    expect(collect($blocks)->where('type', 'text')->pluck('text')->implode(''))->not->toBe('');

    // A rate_limit notice really does arrive on an ordinary turn — it is not a
    // hypothetical — and it must not be drawn into the answer or end the turn.
    expect($rateLimits)->not->toBeEmpty()
        ->and($rateLimits[0][0])->toBe('claude');
});

test('a captured turn reports what it cost, cache included', function () {
    $frames = json_decode(
        (string) file_get_contents(__DIR__.'/fixtures/real-turn-0.8.1.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $handler = recordingHandler();
    foreach ($frames as $frame) {
        $handler->dispatchEvent(wire($frame['event'], $frame['data'] ?? []));
    }

    $usage = $handler->lastDoneUsage();
    $meta = $handler->lastDoneMeta();

    // The cache counters dominate this turn by two orders of magnitude — which
    // is exactly why forwarding only input/output understates it.
    expect($usage['cache_read_input_tokens'])->toBeGreaterThan($usage['input_tokens'])
        ->and($usage['cache_creation_input_tokens'])->toBeGreaterThan(0)
        ->and($meta['model'])->toBeString()
        // The real bridge DOES attach `cli_session_id` to `done` — this fixture
        // proves it rather than supposing it — and neither place this package
        // hands the metadata out passes it on.
        ->and($meta)->not->toHaveKey('cli_session_id');

    $raw = collect($frames)->firstWhere('event', MessageTypes::DONE)['data'];
    expect($raw)->toHaveKey('cli_session_id')
        ->and(\Tetrix\AiBridge\Streaming\BufferingSink::publicDoneMeta($raw))
        ->not->toHaveKey('cli_session_id');
});
