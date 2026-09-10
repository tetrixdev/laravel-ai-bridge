<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\ConversationRecorder;
use Tetrix\AiBridge\Streaming\StreamHandler;

/*
|--------------------------------------------------------------------------
| One corpus, two readers — the PHP half
|--------------------------------------------------------------------------
|
| tests/Conformance/tool-call-scenarios.json is replayed here through
| ConversationRecorder, and in tests/Browser/conformance.spec.js through the
| chat component. They are independent implementations of the same protocol
| rules, and every parity bug in this area came from asserting by hand that
| they agree — which they repeatedly did not.
|
| Add a scenario to the JSON when a divergence is found; both suites pick it up
| with no further wiring.
|
*/

uses(RefreshDatabase::class);

function conformanceScenarios(): array
{
    $path = __DIR__.'/../Conformance/tool-call-scenarios.json';
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return collect($data['scenarios'])->mapWithKeys(fn ($s) => [$s['name'] => [$s]])->all();
}

/** Only the fields both implementations model. */
function comparableToolCalls(array $blocks): array
{
    return collect($blocks)
        ->where('type', 'tool_call')
        ->map(function (array $block): array {
            $shape = [
                'tool_name' => $block['tool_name'] ?? null,
                'tool_call_id' => $block['tool_call_id'] ?? null,
                'parameters' => $block['parameters'] ?? [],
            ];
            if (isset($block['parameters_raw'])) {
                $shape['parameters_raw'] = $block['parameters_raw'];
            }

            return $shape;
        })
        ->values()
        ->all();
}

it('records the same tool calls the chat component draws', function (array $scenario) {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);

    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $handler = new StreamHandler($provider);
    $handler->setMode(ProviderMode::Byok);
    $handler->setConversationId((string) $conversation->id);
    ConversationRecorder::attach($handler, $conversation);

    foreach ($scenario['events'] as $event) {
        if ($event['event'] === MessageTypes::TOOL_CALL) {
            // The WebSocket tool_call frame does not arrive as a stream event.
            $handler->dispatchToolCall(
                $event['data']['tool_name'],
                $event['data']['parameters'] ?? [],
                $event['data']['tool_call_id'] ?? '',
            );

            continue;
        }

        $handler->dispatchEvent(StreamEvent::fromArray([
            'type' => MessageTypes::STREAM,
            'request_id' => 'req-conf',
            'event' => $event['event'],
            'data' => $event['data'],
        ]));
    }

    $handler->dispatchEvent(StreamEvent::fromArray([
        'type' => MessageTypes::STREAM, 'request_id' => 'req-conf',
        'event' => MessageTypes::DONE, 'data' => ['usage' => null],
    ]));

    $blocks = $conversation->messages()->where('role', 'assistant')->latest('id')->first()?->blocks ?? [];

    $expected = collect($scenario['expected'])->map(fn ($e) => [
        'tool_name' => $e['tool_name'] ?? null,
        'tool_call_id' => $e['tool_call_id'] ?? null,
        'parameters' => $e['parameters'] ?? [],
    ] + (isset($e['parameters_raw']) ? ['parameters_raw' => $e['parameters_raw']] : []))->all();

    expect(comparableToolCalls($blocks))->toEqual($expected);
})->with(conformanceScenarios());
