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

/**
 * Every block, every field.
 *
 * This compared four fields of `tool_call` blocks only, which made it blind to
 * the branch's own headline feature — where a tool RESULT ends up — and to the
 * very bug it was written for, on the code path it did not cover. A comparison
 * narrower than the thing it is comparing is decoration.
 *
 * Keys are sorted so the two implementations are not held to an insertion order
 * neither promises.
 */
function comparableBlocks(array $blocks): array
{
    return collect($blocks)
        ->map(function (array $block): array {
            // Presentation-only state the component keeps and the record does
            // not; neither side promises it and no consumer reads it.
            unset($block['_open']);
            ksort($block);

            return $block;
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

    // The terminal is part of the scenario. Appending `done` unconditionally —
    // which this used to do — made it impossible to express a turn that ends in
    // an error or a cancellation, which is where several divergences lived.
    $terminals = [MessageTypes::DONE, MessageTypes::ERROR, 'cancelled'];
    $endsItself = collect($scenario['events'])->contains(fn ($e) => in_array($e['event'], $terminals, true));
    if (! $endsItself) {
        $handler->dispatchEvent(StreamEvent::fromArray([
            'type' => MessageTypes::STREAM, 'request_id' => 'req-conf',
            'event' => MessageTypes::DONE, 'data' => ['usage' => null],
        ]));
    }

    $blocks = $conversation->messages()->where('role', 'assistant')->latest('id')->first()?->blocks ?? [];

    $expected = collect($scenario['expected'])->map(function (array $block): array {
        ksort($block);

        return $block;
    })->all();

    expect(comparableBlocks($blocks))->toEqual($expected);
})->with(conformanceScenarios());
