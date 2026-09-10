<?php

declare(strict_types=1);

/**
 * A tool result too large for one frame arrives in chunks and is reassembled
 * here, at the first thing to touch the wire.
 *
 * Everything downstream — ConversationRecorder, BufferingSink, the browser
 * component — keeps seeing ONE complete result. That is the whole design: the
 * chunking lives on the wire and nowhere else, so the three independent readers
 * of a tool result cannot disagree about it.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Streaming\StreamHandler;

uses(RefreshDatabase::class);

/**
 * Drive a handler with these events and return every tool_result it dispatched.
 *
 * @return list<array{0: string, 1: mixed, 2: bool|null}>
 */
function collectToolResults(callable $drive): array
{
    $handler = recordingHandler();
    $seen = [];
    $handler->onToolResult(function (string $id, mixed $result, ?bool $isError) use (&$seen) {
        $seen[] = [$id, $result, $isError];
    });

    $drive($handler);

    return $seen;
}

test('a result that fits in one frame is passed straight through', function () {
    // No chunk fields: the shape every result had before chunking existed.
    $seen = collectToolResults(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'all of it',
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect($seen)->toBe([['t1', 'all of it', null]]);
});

test('chunks are joined back into one result, dispatched once', function () {
    $seen = collectToolResults(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'one ', 'chunk_index' => 0, 'final' => false,
        ]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'two ', 'chunk_index' => 1, 'final' => false,
        ]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'three', 'chunk_index' => 2, 'final' => true,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect($seen)->toBe([['t1', 'one two three', null]]);
});

test('nothing is dispatched until the final chunk arrives', function () {
    // A downstream consumer must never see half a result and treat it as whole.
    $seen = collectToolResults(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'half', 'chunk_index' => 0, 'final' => false,
        ]));
    });

    expect($seen)->toBe([]);
});

test('two calls chunking at the same time do not mix', function () {
    // Parallel tool calls interleave on one connection. Keyed by call id, not
    // by arrival order, or one tool gets the other tool's output.
    $seen = collectToolResults(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 'a', 'result' => 'A1', 'chunk_index' => 0, 'final' => false,
        ]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 'b', 'result' => 'B1', 'chunk_index' => 0, 'final' => false,
        ]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 'a', 'result' => 'A2', 'chunk_index' => 1, 'final' => true,
        ]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 'b', 'result' => 'B2', 'chunk_index' => 1, 'final' => true,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect($seen)->toBe([['a', 'A1A2', null], ['b', 'B1B2', null]]);
});

test('the verdict survives, whichever chunk carried it', function () {
    $seen = collectToolResults(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'boom ', 'chunk_index' => 0, 'final' => false, 'is_error' => true,
        ]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'again', 'chunk_index' => 1, 'final' => true, 'is_error' => true,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect($seen)->toBe([['t1', 'boom again', true]]);
});

test('a result whose final chunk never comes is kept, and marked', function () {
    // Dropping it silently is the exact failure this whole change exists to
    // stop. A partial result that says it is partial beats nothing at all.
    $seen = collectToolResults(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'the first half', 'chunk_index' => 0, 'final' => false,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect($seen)->toHaveCount(1)
        ->and($seen[0][0])->toBe('t1')
        ->and($seen[0][1])->toStartWith('the first half')
        ->and($seen[0][1])->toContain('incomplete');
});

test('a partial result survives the turn ending in an error', function () {
    $seen = collectToolResults(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'got this far', 'chunk_index' => 0, 'final' => false, 'is_error' => false,
        ]));
        $h->dispatchEvent(wire(MessageTypes::ERROR, ['code' => 'provider_error', 'message' => 'died']));
    });

    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toStartWith('got this far')
        ->and($seen[0][2])->toBe(false);
});

test('a gap in the numbering is reported, not papered over', function () {
    // Reordering or back-filling would guess at content. Saying so does not.
    $seen = collectToolResults(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'first', 'chunk_index' => 0, 'final' => false,
        ]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'fourth', 'chunk_index' => 3, 'final' => true,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toBe("first\n…[incomplete: the bridge stopped sending this result before it finished]");
});

test('a stream cannot make this process allocate without limit', function () {
    // The bridge stops at the same ceiling. A server must not depend on a
    // client to bound its memory.
    $seen = collectToolResults(function (StreamHandler $h) {
        $piece = str_repeat('x', 1024 * 1024);
        for ($i = 0; $i < 20; $i++) {
            $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
                'tool_call_id' => 't1', 'result' => $piece, 'chunk_index' => $i, 'final' => $i === 19,
            ]));
        }
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect($seen)->toHaveCount(1)
        ->and(strlen($seen[0][1]))->toBeLessThanOrEqual(16 * 1024 * 1024 + 200)
        ->and($seen[0][1])->toContain('incomplete');
});

test('a chunked result reaches the transcript as one block', function () {
    // The end-to-end point of reassembling at the wire: the recorder never
    // learns that chunking exists.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Read', 'tool_call_id' => 't1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"file_path":"/a"}']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'line one\n', 'chunk_index' => 0, 'final' => false,
        ]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'line two', 'chunk_index' => 1, 'final' => true,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect($tool)->not->toBeNull()
        ->and($tool['result'])->toBe('line one\nline two')
        ->and(collect($blocks)->where('type', 'tool_result'))->toHaveCount(0);
});

test('a partial result survives the turn being cancelled', function () {
    // The third terminal, and the one that nearly got away: `cancel()` sets a
    // flag that makes dispatchToolResult refuse, so a flush written the obvious
    // way is dropped by the very guard meant to protect it. The recorder keeps
    // partial TEXT on this same terminal, so a tool result vanishing here would
    // be the odd one out.
    $seen = collectToolResults(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'got this far', 'chunk_index' => 0, 'final' => false, 'is_error' => true,
        ]));
        $h->cancel();
        $h->dispatchCancelled('user stopped it');
    });

    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toStartWith('got this far')
        ->and($seen[0][1])->toContain('incomplete')
        ->and($seen[0][2])->toBe(true);
});

test('a cancelled stream stops accumulating chunks', function () {
    // Otherwise a cancelled turn can still be made to allocate, one 1 MB frame
    // at a time, by a sender that simply keeps going.
    // Deliberately NOT final. A final chunk would be stopped by the guard on
    // dispatchToolResult even if it had been buffered, so the test would pass
    // whether or not the buffering was prevented — proving nothing. A partial
    // one is only ever dispatched by the terminal flush, which bypasses that
    // guard, so it appears if and only if it was retained.
    $seen = collectToolResults(function (StreamHandler $h) {
        $h->cancel();
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'after the cancel', 'chunk_index' => 0, 'final' => false,
        ]));
        $h->dispatchCancelled('user stopped it');
    });

    expect($seen)->toBe([]);
});

test('an enormous result is bounded before it reaches the database', function () {
    // The write is wrapped in a catch that logs and swallows, so an oversized
    // row does not fail loudly — it loses the whole assistant turn, prose and
    // all. Chunking made that reachable by removing the bridge's own 256 KB
    // bound, so the record needs one of its own.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Bash', 'tool_call_id' => 't1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"command":"cat big"}']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));

        $piece = str_repeat('x', 512 * 1024);
        for ($i = 0; $i < 6; $i++) {
            $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
                'tool_call_id' => 't1', 'result' => $piece, 'chunk_index' => $i, 'final' => $i === 5,
            ]));
        }
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect($tool)->not->toBeNull()
        ->and(strlen($tool['result']))->toBeLessThan(1048576 + 200)
        ->and($tool['result'])->toContain('truncated by the server')
        ->and($tool['result_truncated_bytes'])->toBe(6 * 512 * 1024);

    // The turn itself survived, which is the whole point.
    expect(collect($blocks)->firstWhere('type', 'tool_call')['tool_name'])->toBe('Bash');
});

test('bounding the result does not cut a character in half', function () {
    // substr at a byte offset leaves half a UTF-8 character, the blocks cast
    // then fails, and the entire turn is lost — the same destruction this
    // bound exists to prevent, arrived at from the other direction. The
    // multi-byte characters are placed so the 1 MB mark lands INSIDE one.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Bash', 'tool_call_id' => 't1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));

        // 'é' is two bytes. An odd-length ASCII prefix puts every following
        // character boundary off the 1 MB mark by one byte.
        $body = str_repeat('a', 1048575).str_repeat('é', 200_000);
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => $body, 'chunk_index' => 0, 'final' => true,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect($tool)->not->toBeNull()
        ->and(mb_check_encoding($tool['result'], 'UTF-8'))->toBeTrue()
        ->and(json_encode($tool['result']))->not->toBeFalse();
});
