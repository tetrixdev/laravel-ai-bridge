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
        ->and($seen[0][1])->toBe("first\n…[incomplete: the stream ended before this result finished]");
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
        // "truncated by the server", not "incomplete": every chunk arrived and
        // THIS side declined to hold them.
        ->and($seen[0][1])->toContain('truncated by the server');
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

test('many ids cannot allocate what one id may not', function () {
    // The per-result ceiling bounds ONE buffer, and the sender picks the
    // tool_call_id every buffer is keyed by — so on its own it bounds nothing.
    // A thousand ids carrying an unfinished chunk each is a thousand buffers,
    // none of them individually near its limit.
    $seen = collectToolResults(function (StreamHandler $h) {
        $piece = str_repeat('x', 1024 * 1024);
        for ($i = 0; $i < 200; $i++) {
            $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
                'tool_call_id' => "t{$i}", 'result' => $piece, 'chunk_index' => 0, 'final' => false,
            ]));
        }
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    // Never more buffers than the cap allows...
    expect(count($seen))->toBeLessThanOrEqual(64);

    // ...and never more bytes across all of them than the aggregate ceiling.
    $total = array_sum(array_map(fn ($r) => strlen($r[1]), $seen));
    expect($total)->toBeLessThanOrEqual(32 * 1024 * 1024 + 64 * 200);
});

test('one large result still assembles under the aggregate ceiling', function () {
    // The aggregate limit must not make the ordinary case worse: a single
    // result up to its own ceiling still arrives whole.
    $seen = collectToolResults(function (StreamHandler $h) {
        $piece = str_repeat('y', 1024 * 1024);
        for ($i = 0; $i < 8; $i++) {
            $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
                'tool_call_id' => 't1', 'result' => $piece, 'chunk_index' => $i, 'final' => $i === 7,
            ]));
        }
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect($seen)->toHaveCount(1)
        ->and(strlen($seen[0][1]))->toBe(8 * 1024 * 1024)
        ->and($seen[0][1])->not->toContain('incomplete');
});

test('flushing a buffer gives its bytes back to the aggregate', function () {
    // Without the release, a long turn of ordinary results walks the running
    // total up to the ceiling and then refuses everything after it.
    $seen = collectToolResults(function (StreamHandler $h) {
        $piece = str_repeat('z', 1024 * 1024);
        for ($call = 0; $call < 40; $call++) {
            for ($i = 0; $i < 2; $i++) {
                $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
                    'tool_call_id' => "c{$call}", 'result' => $piece, 'chunk_index' => $i, 'final' => $i === 1,
                ]));
            }
        }
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    // 80 MB in total, one call at a time, each completed and released before
    // the next begins. Every one arrives whole.
    expect($seen)->toHaveCount(40);
    foreach ($seen as $result) {
        expect(strlen($result[1]))->toBe(2 * 1024 * 1024);
    }
});

/*
|--------------------------------------------------------------------------
| The flush has to happen BEFORE the terminal callbacks, not merely happen
|--------------------------------------------------------------------------
|
| The tests above assert on raw onToolResult callbacks, which fire whatever the
| ordering is. Moving every flush to AFTER its dispatchCallbacks left the whole
| suite green — and it is not cosmetic: the recorder persists from inside one of
| those callbacks, so a result flushed afterwards is assembled correctly and then
| never written down. These assert on the RECORD, which is the thing that has to
| be right.
|
*/

test('a partial result reaches the transcript when the turn ends normally', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Bash', 'tool_call_id' => 't1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'half an answer', 'chunk_index' => 0, 'final' => false,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect($tool)->toHaveKey('result')
        ->and($tool['result'])->toStartWith('half an answer');
});

test('a partial result reaches the transcript when the turn errors', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Bash', 'tool_call_id' => 't1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'half an answer', 'chunk_index' => 0, 'final' => false,
        ]));
        $h->dispatchEvent(wire(MessageTypes::ERROR, ['code' => 'provider_error', 'message' => 'died']));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect($tool)->toHaveKey('result')
        ->and($tool['result'])->toStartWith('half an answer');
});

test('a partial result reaches the transcript when the turn is cancelled', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Bash', 'tool_call_id' => 't1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'half an answer', 'chunk_index' => 0, 'final' => false,
        ]));
        $h->cancel();
        $h->dispatchCancelled('user stopped it');
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect($tool)->toHaveKey('result')
        ->and($tool['result'])->toStartWith('half an answer');
});

test('the marker says which side ran out, because they are different faults', function () {
    // A result the bridge never finished sending and a result this side refused
    // to hold are not the same problem, and the same words for both send
    // whoever debugs it to the wrong machine.
    $stopped = collectToolResults(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'partial', 'chunk_index' => 0, 'final' => false,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $refused = collectToolResults(function (StreamHandler $h) {
        $piece = str_repeat('x', 1024 * 1024);
        for ($i = 0; $i < 20; $i++) {
            $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
                'tool_call_id' => 't1', 'result' => $piece, 'chunk_index' => $i, 'final' => $i === 19,
            ]));
        }
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect($stopped[0][1])->toContain('the stream ended')
        ->and($stopped[0][1])->not->toContain('truncated by the server')
        ->and($refused[0][1])->toContain('truncated by the server')
        ->and($refused[0][1])->not->toContain('the stream ended');
});

test('a complete result is never refused, however many buffers are open', function () {
    // It needs no buffer, so no buffering limit has any business refusing it.
    // Dropped outright once 64 buffers were open: no callback, no result on the
    // block, and a chat drawing that call as still running for ever.
    $seen = collectToolResults(function (StreamHandler $h) {
        for ($i = 0; $i < 64; $i++) {
            $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
                'tool_call_id' => "filler{$i}", 'result' => 'x', 'chunk_index' => 0, 'final' => false,
            ]));
        }
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 'real', 'result' => 'the answer is 42', 'chunk_index' => 0, 'final' => true,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $real = collect($seen)->first(fn ($r) => $r[0] === 'real');

    expect($real)->not->toBeNull()
        ->and($real[1])->toBe('the answer is 42');
});

test('an unchunked result releases a half-assembled buffer for the same call', function () {
    // Left in place the buffer never finalises and its bytes stay charged
    // against the turn's ceiling, starving every later result on the
    // connection. The second call proves the budget came back.
    $seen = collectToolResults(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => str_repeat('x', 1024 * 1024), 'chunk_index' => 0, 'final' => false,
        ]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, ['tool_call_id' => 't1', 'result' => 'superseded']));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    // One result, the whole one — and no partial left over to flush.
    expect($seen)->toBe([['t1', 'superseded', null]]);
});

test("the bridge's own dropped byte count is kept, not discarded", function () {
    $seen = collectToolResults(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => 'head', 'chunk_index' => 0,
            'final' => true, 'truncated_bytes' => 900000000,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toStartWith('head')
        ->and($seen[0][1])->toContain('900000000');
});

test('a turn of many results is bounded in total, not just one at a time', function () {
    // Bounding one result bounds nothing: the number of tool calls in a turn is
    // the model's choice. Seventy calls returning 2 MB each is a 70 MB row,
    // past a default max_allowed_packet — and the write is caught, logged and
    // swallowed, so the whole turn goes, prose included.
    $blocks = recordTurn(function (StreamHandler $h) {
        for ($i = 0; $i < 20; $i++) {
            $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
                'block_index' => $i, 'block_type' => 'tool_call',
                'tool_name' => 'Bash', 'tool_call_id' => "t{$i}",
            ]));
            $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => $i]));
            $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
                'tool_call_id' => "t{$i}", 'result' => str_repeat('x', 900 * 1024),
            ]));
        }
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $total = collect($blocks)->sum(fn ($b) => strlen($b['result'] ?? ''));

    // 18 MB offered, 8 MB kept, and the turn itself survived.
    expect($total)->toBeLessThanOrEqual(8 * 1024 * 1024 + 4096)
        ->and(collect($blocks)->where('type', 'tool_call'))->toHaveCount(20);

    // Spent in arrival order: the first results are whole, a later one is cut.
    expect($blocks[0]['result'])->not->toContain('truncated by the server')
        ->and(collect($blocks)->contains(fn ($b) => str_contains($b['result'] ?? '', 'truncated by the server')))->toBeTrue();
});

test('the turn budget does not cut a character in half either', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        // The first result eats most of the budget on an ODD boundary, so the
        // second is cut at an offset that lands inside a two-byte character.
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Bash', 'tool_call_id' => 't0',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't0', 'result' => str_repeat('a', 7 * 1024 * 1024 + 1),
        ]));

        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 1, 'block_type' => 'tool_call', 'tool_name' => 'Bash', 'tool_call_id' => 't1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 1]));
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 't1', 'result' => str_repeat('é', 2 * 1024 * 1024),
        ]));

        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    foreach ($blocks as $block) {
        expect(mb_check_encoding($block['result'] ?? '', 'UTF-8'))->toBeTrue();
    }
    expect(json_encode($blocks))->not->toBeFalse();
});
