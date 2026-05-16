<?php

declare(strict_types=1);

use Tetrix\AiBridge\Tools\AbstractTool;
use Tetrix\AiBridge\Tools\ToolDefinition;
use Tetrix\AiBridge\Tools\ToolParameter;

/*
|--------------------------------------------------------------------------
| ToolDefinition Unit Tests
|--------------------------------------------------------------------------
|
| These tests verify the construction-time validation that guarantees a tool
| can never be registered with a malformed name, an empty description, or
| parameter properties that lack a description.
|
*/

test('constructs a valid tool definition', function () {
    $tool = new ToolDefinition(
        'roll_dice',
        'Roll one or more dice',
        ['type' => 'object', 'properties' => ['sides' => ['type' => 'integer', 'description' => 'Sides']]],
        fn ($p) => null,
    );

    expect($tool->name)->toBe('roll_dice');
});

test('accepts an empty parameters array for a no-parameter tool', function () {
    $tool = new ToolDefinition('ping', 'Ping the server', [], fn ($p) => null);

    expect($tool->parameters)->toBe([]);
});

test('rejects an empty name', function () {
    new ToolDefinition('', 'Some description', [], fn ($p) => null);
})->throws(InvalidArgumentException::class, 'Tool name must not be empty');

test('rejects an invalid name', function (string $name) {
    new ToolDefinition($name, 'Some description', [], fn ($p) => null);
})->with([
    '1leading_digit',
    'has spaces',
    'has.dot',
    'emoji😀',
    str_repeat('a', 65),
])->throws(InvalidArgumentException::class, 'Invalid tool name');

test('rejects an empty description', function () {
    new ToolDefinition('valid_name', '   ', [], fn ($p) => null);
})->throws(InvalidArgumentException::class, "Tool 'valid_name' must have a non-empty description");

test('rejects parameters that are not a JSON Schema object', function () {
    new ToolDefinition('my_tool', 'Desc', ['type' => 'string'], fn ($p) => null);
})->throws(InvalidArgumentException::class, '"type" => "object"');

test('accepts a parameters object without a properties key', function () {
    $tool = new ToolDefinition('my_tool', 'Desc', ['type' => 'object'], fn ($p) => null);

    expect($tool->parameters)->toBe(['type' => 'object']);
});

test('rejects a non-array properties key', function () {
    new ToolDefinition('my_tool', 'Desc', ['type' => 'object', 'properties' => 'nope'], fn ($p) => null);
})->throws(InvalidArgumentException::class, '"properties" must be an array');

test('rejects a property that is not an array', function () {
    new ToolDefinition(
        'my_tool',
        'Desc',
        ['type' => 'object', 'properties' => ['city' => 'string']],
        fn ($p) => null,
    );
})->throws(InvalidArgumentException::class, "parameter 'city' must be a JSON Schema array");

test('rejects a property without a description', function () {
    new ToolDefinition(
        'my_tool',
        'Desc',
        ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
        fn ($p) => null,
    );
})->throws(InvalidArgumentException::class, "parameter 'city' must have a non-empty description");

test('rejects a property with an empty description', function () {
    new ToolDefinition(
        'my_tool',
        'Desc',
        ['type' => 'object', 'properties' => ['city' => ['type' => 'string', 'description' => '   ']]],
        fn ($p) => null,
    );
})->throws(InvalidArgumentException::class, "parameter 'city' must have a non-empty description");

test('accepts valid parameters with described properties', function () {
    $tool = new ToolDefinition(
        'my_tool',
        'Desc',
        [
            'type' => 'object',
            'properties' => [
                'city' => ['type' => 'string', 'description' => 'The city name'],
            ],
            'required' => ['city'],
        ],
        fn ($p) => null,
    );

    expect($tool->parameters['properties']['city']['description'])->toBe('The city name');
});

test('fromHandler() round-trips an AbstractTool subclass', function () {
    $handler = new class extends AbstractTool {
        public function name(): string
        {
            return 'lookup_character';
        }

        public function description(): string
        {
            return 'Look up a character';
        }

        protected function defineParameters(): array
        {
            return [
                new ToolParameter('name', 'string', 'The character name'),
                new ToolParameter('realm', 'string', 'Which realm', required: false, enum: ['mortal', 'fae']),
            ];
        }

        public function handle(array $params): mixed
        {
            return ['found' => $params['name']];
        }
    };

    $tool = ToolDefinition::fromHandler($handler);

    expect($tool->name)->toBe('lookup_character');
    expect($tool->description)->toBe('Look up a character');
    expect($tool->parameters)->toBe([
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'The character name'],
            'realm' => ['type' => 'string', 'description' => 'Which realm', 'enum' => ['mortal', 'fae']],
        ],
        'required' => ['name'],
    ]);
    expect($tool->execute(['name' => 'Merlin']))->toBe(['found' => 'Merlin']);
});

test('fromHandler() rejects an AbstractTool with a description-less raw handler', function () {
    $handler = new class extends AbstractTool {
        public function name(): string
        {
            return 'bad_tool';
        }

        public function description(): string
        {
            return '';
        }

        protected function defineParameters(): array
        {
            return [];
        }

        public function handle(array $params): mixed
        {
            return null;
        }
    };

    ToolDefinition::fromHandler($handler);
})->throws(InvalidArgumentException::class, 'must have a non-empty description');
