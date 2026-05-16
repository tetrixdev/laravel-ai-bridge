<?php

declare(strict_types=1);

use Tetrix\AiBridge\Tools\ToolParameter;

/*
|--------------------------------------------------------------------------
| ToolParameter Unit Tests
|--------------------------------------------------------------------------
|
| These tests verify construction validation and JSON Schema generation for
| the structured tool authoring path. No Laravel app container needed.
|
*/

test('constructs a valid parameter', function () {
    $param = new ToolParameter('sides', 'integer', 'Number of sides');

    expect($param->name)->toBe('sides');
    expect($param->type)->toBe('integer');
    expect($param->description)->toBe('Number of sides');
    expect($param->required)->toBeTrue();
    expect($param->enum)->toBeNull();
});

test('accepts every valid JSON Schema type', function (string $type) {
    $param = new ToolParameter('value', $type, 'A value');

    expect($param->type)->toBe($type);
})->with(['string', 'integer', 'number', 'boolean', 'array', 'object']);

test('rejects an invalid type', function () {
    new ToolParameter('value', 'float', 'A value');
})->throws(InvalidArgumentException::class, "Invalid tool parameter type 'float'");

test('rejects an empty name', function () {
    new ToolParameter('', 'string', 'A value');
})->throws(InvalidArgumentException::class, 'name must not be empty');

test('rejects a whitespace-only name', function () {
    new ToolParameter('   ', 'string', 'A value');
})->throws(InvalidArgumentException::class, 'name must not be empty');

test('rejects an empty description', function () {
    new ToolParameter('value', 'string', '');
})->throws(InvalidArgumentException::class, "parameter 'value' must have a non-empty description");

test('rejects a whitespace-only description', function () {
    new ToolParameter('value', 'string', '   ');
})->throws(InvalidArgumentException::class, "parameter 'value' must have a non-empty description");

test('toJsonSchema() returns [] for an empty list', function () {
    expect(ToolParameter::toJsonSchema([]))->toBe([]);
});

test('toJsonSchema() builds properties and required split', function () {
    $schema = ToolParameter::toJsonSchema([
        new ToolParameter('sides', 'integer', 'Number of sides'),
        new ToolParameter('count', 'integer', 'Number of dice', required: false),
    ]);

    expect($schema)->toBe([
        'type' => 'object',
        'properties' => [
            'sides' => ['type' => 'integer', 'description' => 'Number of sides'],
            'count' => ['type' => 'integer', 'description' => 'Number of dice'],
        ],
        'required' => ['sides'],
    ]);
});

test('toJsonSchema() omits required key when no parameters are required', function () {
    $schema = ToolParameter::toJsonSchema([
        new ToolParameter('count', 'integer', 'Number of dice', required: false),
    ]);

    expect($schema)->toBe([
        'type' => 'object',
        'properties' => [
            'count' => ['type' => 'integer', 'description' => 'Number of dice'],
        ],
    ]);
});

test('toJsonSchema() includes enum when provided', function () {
    $schema = ToolParameter::toJsonSchema([
        new ToolParameter('realm', 'string', 'Which realm', enum: ['mortal', 'fae']),
    ]);

    expect($schema['properties']['realm'])->toBe([
        'type' => 'string',
        'description' => 'Which realm',
        'enum' => ['mortal', 'fae'],
    ]);
});
