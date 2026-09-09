<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\ConversationRecorder;
use Tetrix\AiBridge\Streaming\StreamHandler;

uses(RefreshDatabase::class);

function probeHandler(): StreamHandler
{
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $h = new StreamHandler($provider);
    $h->setMode(ProviderMode::Byok);
    $h->setConversationId('probe');

    return $h;
}

function probeEvent(string $event, array $data): StreamEvent
{
    return StreamEvent::fromArray([
        'type' => 'stream', 'request_id' => 'req-probe', 'event' => $event, 'data' => $data,
    ]);
}

// ---- PROBE A: done meta through the real WebSocket MessageHandler path ----
test('PROBE A: done meta via MessageHandler', function () {
    Event::fake();
    $manager = new \Tetrix\AiBridge\WebSocket\BridgeConnectionManager();
    $mh = new \Tetrix\AiBridge\WebSocket\MessageHandler(
        connectionManager: $manager,
        tokenManager: app(\Tetrix\AiBridge\Auth\TokenManager::class),
        toolRegistry: new \Tetrix\AiBridge\Tools\ToolRegistry(),
    );

    $handler = probeHandler();
    $seenMeta = null;
    $handler->onDone(function (?array $u, array $m = []) use (&$seenMeta) {
        $seenMeta = $m;
    });

    $manager->addConnection('user-1', 'conn-1');
    $manager->registerPendingRequest('req-1', $handler, 'user-1');

    $mh->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::DONE,
        'data' => [
            'usage' => ['input_tokens' => 6],
            'model' => 'claude-sonnet-5',
            'cost_usd' => 0.03,
            'permission_denials' => [['tool' => 'Bash']],
        ],
    ]));

    dump(['PROBE A meta seen by callback' => $seenMeta, 'lastDoneMeta' => $handler->lastDoneMeta()]);
    expect(true)->toBeTrue();
});

function probe_internal_sink(?array $usage): void
{
    $GLOBALS['probe_b'] = $usage;
}

// ---- PROBE B: arity intolerance for non-userland closures ----
test('PROBE B: Closure::fromCallable on an internal function', function () {
    $handler = probeHandler();
    $handler->onDone(Closure::fromCallable('probe_internal_sink'));
    $handler->dispatchDone(['input_tokens' => 1], ['model' => 'x']);
    dump(['PROBE B userland-1arg-called' => $GLOBALS['probe_b'] ?? 'NOT CALLED']);

    $h2 = probeHandler();
    $err = null;
    try {
        $c = Closure::fromCallable('strtoupper');
        $c('a', 'b');
    } catch (\Throwable $e) {
        $err = get_class($e).': '.$e->getMessage();
    }
    dump(['PROBE B internal-fn extra arg' => $err ?? 'tolerated']);

    // A first-class callable to a method with a fixed signature
    $obj = new class
    {
        public array $seen = [];

        public function onlyUsage(?array $u): void
        {
            $this->seen[] = $u;
        }
    };
    $h2->onDone($obj->onlyUsage(...));
    $h2->dispatchDone(['a' => 1], ['model' => 'y']);
    dump(['PROBE B first-class-callable' => $obj->seen]);

    expect(true)->toBeTrue();
});

// ---- PROBE C: decodeArguments edge cases through the recorder ----
test('PROBE C: decodeArguments shapes', function () {
    $cases = [
        'scalar-number' => '123',
        'scalar-string' => '"hello"',
        'literal-null' => 'null',
        'quoted-null' => '"null"',
        'list' => '[1,2,3]',
        'raw-collision' => '{"_raw":"i am a real argument"}',
        'truncated' => '{"command":"echo on',
        'empty' => '',
        'bool' => 'true',
    ];

    $out = [];
    foreach ($cases as $label => $text) {
        $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
        $handler = probeHandler();
        $handler->setConversationId((string) $conversation->id);
        ConversationRecorder::attach($handler, $conversation);

        $handler->dispatchEvent(probeEvent('block_start', [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Bash', 'tool_call_id' => 'tc1',
        ]));
        if ($text !== '') {
            $handler->dispatchEvent(probeEvent('block_delta', [
                'block_index' => 0, 'block_type' => 'tool_call', 'content' => $text,
            ]));
        }
        $handler->dispatchEvent(probeEvent('block_stop', ['block_index' => 0, 'block_type' => 'tool_call']));
        $handler->dispatchEvent(probeEvent('done', []));

        $blocks = $conversation->messages()->where('role', 'assistant')->latest('id')->first()?->blocks ?? [];
        $out[$label] = collect($blocks)->where('type', 'tool_call')->first();
    }
    dump(['PROBE C' => $out]);
    expect(true)->toBeTrue();
});

// ---- PROBE D: a server-declared LOCAL tool (execute: local) has no WS frame ----
test('PROBE D: mcp__bridge__ local tool is dropped', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    $handler = probeHandler();
    $handler->setConversationId((string) $conversation->id);
    ConversationRecorder::attach($handler, $conversation);

    // Per PROTOCOL.md "Local tools": execute:"local" NEVER emits a tool_call frame.
    $handler->dispatchEvent(probeEvent('block_start', [
        'block_index' => 0, 'block_type' => 'tool_call',
        'tool_name' => 'mcp__bridge__fetch_mail', 'tool_call_id' => 'toolu_local_1',
    ]));
    $handler->dispatchEvent(probeEvent('block_delta', [
        'block_index' => 0, 'block_type' => 'tool_call', 'content' => '{"since":"2026-01-01"}',
    ]));
    $handler->dispatchEvent(probeEvent('block_stop', ['block_index' => 0, 'block_type' => 'tool_call']));
    $handler->dispatchEvent(probeEvent('tool_result', [
        'tool_call_id' => 'toolu_local_1', 'result' => '12 messages', 'is_error' => false,
    ]));
    $handler->dispatchEvent(probeEvent('done', []));

    $blocks = $conversation->messages()->where('role', 'assistant')->latest('id')->first()?->blocks ?? [];
    dump(['PROBE D blocks' => $blocks]);
    expect(true)->toBeTrue();
});

// ---- PROBE E: StreamEvent::toolResult drops a null result ----
test('PROBE E: null result key survival', function () {
    $e = StreamEvent::toolResult('r', 'tc1', null, true);
    dump(['PROBE E data' => $e->data]);

    $e2 = StreamEvent::toolResult('r', 'tc1', false, null);
    dump(['PROBE E false-result' => $e2->data]);

    $e3 = StreamEvent::blockStart('r', \Tetrix\AiBridge\Enums\BlockType::ToolCall, 0, null, null);
    dump(['PROBE E blockStart' => $e3->data]);

    $e4 = StreamEvent::done('r', ['a' => 1], ['usage' => 'HIJACK', 'model' => 'm']);
    dump(['PROBE E done-meta-collision' => $e4->data]);
    expect(true)->toBeTrue();
});

// ---- PROBE F: text block delta text preserved when tool block interleaves ----
test('PROBE F: interleaved / duplicate ids and orphan results', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    $handler = probeHandler();
    $handler->setConversationId((string) $conversation->id);
    ConversationRecorder::attach($handler, $conversation);

    // tool_call block that never gets a block_stop (turn ends mid-arguments)
    $handler->dispatchEvent(probeEvent('block_start', [
        'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Bash', 'tool_call_id' => 'tc_open',
    ]));
    $handler->dispatchEvent(probeEvent('block_delta', [
        'block_index' => 0, 'block_type' => 'tool_call', 'content' => '{"command":"rm -rf',
    ]));
    $handler->dispatchEvent(probeEvent('done', []));

    $blocks = $conversation->messages()->where('role', 'assistant')->latest('id')->first()?->blocks ?? [];
    dump(['PROBE F unterminated tool block' => $blocks]);
    expect(true)->toBeTrue();
});
