<?php

declare(strict_types=1);

use Tetrix\AiBridge\Contracts\ToolHandler;
use Tetrix\AiBridge\Tools\ToolRegistry;

/*
|--------------------------------------------------------------------------
| ToolRegistry Unit Tests
|--------------------------------------------------------------------------
|
| These tests verify tool registration, lookup, execution, and serialization.
| No Laravel app container needed — ToolRegistry is a plain PHP class.
|
*/

beforeEach(function () {
    $this->registry = new ToolRegistry();
});

test('register() registers a tool with closure handler', function () {
    $this->registry->register(
        'roll_dice',
        'Roll a dice',
        ['type' => 'object', 'properties' => ['sides' => ['type' => 'integer']]],
        fn (array $params) => ['result' => random_int(1, $params['sides'] ?? 6)]
    );

    expect($this->registry->has('roll_dice'))->toBeTrue();
    expect($this->registry->count())->toBe(1);
});

test('registerHandler() registers a ToolHandler instance', function () {
    $handler = new class implements ToolHandler {
        public function name(): string { return 'get_weather'; }
        public function description(): string { return 'Get weather info'; }
        public function parameters(): array { return ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]]; }
        public function handle(array $params): mixed { return ['temp' => 72, 'city' => $params['city']]; }
    };

    $this->registry->registerHandler($handler);

    expect($this->registry->has('get_weather'))->toBeTrue();
});

test('has() returns false for unregistered tool', function () {
    expect($this->registry->has('nonexistent'))->toBeFalse();
});

test('get() returns the registered ToolDefinition', function () {
    $this->registry->register(
        'my_tool',
        'My tool description',
        ['type' => 'object'],
        fn ($p) => 'result'
    );

    $tool = $this->registry->get('my_tool');

    expect($tool->name)->toBe('my_tool');
    expect($tool->description)->toBe('My tool description');
    expect($tool->parameters)->toBe(['type' => 'object']);
});

test('get() throws for unregistered tool', function () {
    $this->registry->get('nonexistent');
})->throws(InvalidArgumentException::class, "Tool 'nonexistent' is not registered.");

test('execute() calls the closure handler with correct params', function () {
    $receivedParams = null;

    $this->registry->register(
        'capture_tool',
        'Captures params',
        ['type' => 'object'],
        function (array $params) use (&$receivedParams) {
            $receivedParams = $params;
            return 'ok';
        }
    );

    $this->registry->execute('capture_tool', ['key' => 'value']);

    expect($receivedParams)->toBe(['key' => 'value']);
});

test('execute() returns handler result', function () {
    $this->registry->register(
        'sum_tool',
        'Sums a and b',
        ['type' => 'object'],
        fn (array $p) => ['sum' => ($p['a'] ?? 0) + ($p['b'] ?? 0)]
    );

    $result = $this->registry->execute('sum_tool', ['a' => 3, 'b' => 5]);

    expect($result)->toBe(['sum' => 8]);
});

test('execute() works with ToolHandler instance', function () {
    $handler = new class implements ToolHandler {
        public function name(): string { return 'multiply'; }
        public function description(): string { return 'Multiply two numbers'; }
        public function parameters(): array { return ['type' => 'object']; }
        public function handle(array $params): mixed {
            return ['product' => ($params['a'] ?? 0) * ($params['b'] ?? 0)];
        }
    };

    $this->registry->registerHandler($handler);

    $result = $this->registry->execute('multiply', ['a' => 4, 'b' => 7]);

    expect($result)->toBe(['product' => 28]);
});

test('execute() throws for unregistered tool', function () {
    $this->registry->execute('nonexistent', []);
})->throws(InvalidArgumentException::class);

test('toFunctionSchemas() returns OpenAI-compatible format', function () {
    $this->registry->register(
        'search',
        'Search the web',
        ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
        fn ($p) => []
    );

    $schemas = $this->registry->toFunctionSchemas();

    expect($schemas)->toBeArray()->toHaveCount(1);
    expect($schemas[0])->toBe([
        'type' => 'function',
        'function' => [
            'name' => 'search',
            'description' => 'Search the web',
            'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
        ],
    ]);
});

test('toArray() returns WebSocket format', function () {
    $this->registry->register(
        'search',
        'Search the web',
        ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
        fn ($p) => []
    );

    $array = $this->registry->toArray();

    expect($array)->toBeArray()->toHaveCount(1);
    expect($array[0])->toBe([
        'name' => 'search',
        'description' => 'Search the web',
        'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
    ]);
});

test('flush() removes all tools', function () {
    $this->registry->register('tool_a', 'A', [], fn ($p) => null);
    $this->registry->register('tool_b', 'B', [], fn ($p) => null);

    expect($this->registry->count())->toBe(2);

    $this->registry->flush();

    expect($this->registry->count())->toBe(0);
    expect($this->registry->has('tool_a'))->toBeFalse();
    expect($this->registry->has('tool_b'))->toBeFalse();
});

test('remove() removes a specific tool', function () {
    $this->registry->register('tool_a', 'A', [], fn ($p) => null);
    $this->registry->register('tool_b', 'B', [], fn ($p) => null);

    $this->registry->remove('tool_a');

    expect($this->registry->has('tool_a'))->toBeFalse();
    expect($this->registry->has('tool_b'))->toBeTrue();
    expect($this->registry->count())->toBe(1);
});

test('remove() is safe for non-existent tool', function () {
    $this->registry->remove('nonexistent');

    expect($this->registry->count())->toBe(0);
});

test('register() rejects duplicate tool name', function () {
    $this->registry->register('dup', 'First', [], fn ($p) => null);
    $this->registry->register('dup', 'Second', [], fn ($p) => null);
})->throws(InvalidArgumentException::class, "A tool with the name 'dup' is already registered.");

test('register() returns self for fluent chaining', function () {
    $result = $this->registry->register('tool', 'Desc', [], fn ($p) => null);

    expect($result)->toBe($this->registry);
});

test('all() returns all registered tools', function () {
    $this->registry->register('a', 'Tool A', [], fn ($p) => null);
    $this->registry->register('b', 'Tool B', [], fn ($p) => null);

    $all = $this->registry->all();

    expect($all)->toHaveCount(2);
    expect(array_keys($all))->toBe(['a', 'b']);
});

test('toFunctionSchemas() returns empty array when no tools registered', function () {
    expect($this->registry->toFunctionSchemas())->toBe([]);
});

test('toFunctionSchemas() returns multiple schemas in order', function () {
    $this->registry->register('alpha', 'Alpha', ['type' => 'object'], fn ($p) => null);
    $this->registry->register('beta', 'Beta', ['type' => 'object'], fn ($p) => null);

    $schemas = $this->registry->toFunctionSchemas();

    expect($schemas)->toHaveCount(2);
    expect($schemas[0]['function']['name'])->toBe('alpha');
    expect($schemas[1]['function']['name'])->toBe('beta');
});
