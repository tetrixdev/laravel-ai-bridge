<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Tools;

use InvalidArgumentException;

/**
 * Immutable value object representing a single tool parameter.
 *
 * Every parameter MUST have a non-empty description so the AI knows what the
 * value is for. Use {@see ToolParameter::toJsonSchema()} to convert a list of
 * parameters into the JSON Schema object expected by the Chat Completions API.
 */
final readonly class ToolParameter
{
    /**
     * The JSON Schema scalar/structural types a parameter may declare.
     *
     * @var list<string>
     */
    private const VALID_TYPES = ['string', 'integer', 'number', 'boolean', 'array', 'object'];

    /**
     * @param  string  $name  The parameter name (non-empty).
     * @param  string  $type  One of: string, integer, number, boolean, array, object.
     * @param  string  $description  Human-readable explanation for the AI (non-empty).
     * @param  bool  $required  Whether the parameter is required.
     * @param  array<int, mixed>|null  $enum  Optional list of allowed values.
     *
     * @throws InvalidArgumentException If the name, type, or description is invalid.
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $description,
        public bool $required = true,
        public ?array $enum = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Tool parameter name must not be empty.');
        }

        if (! in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                "Invalid tool parameter type '%s' for parameter '%s'. Must be one of: %s.",
                $type,
                $name,
                implode(', ', self::VALID_TYPES),
            ));
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException(sprintf(
                "Tool parameter '%s' must have a non-empty description.",
                $name,
            ));
        }
    }

    /**
     * Convert a list of ToolParameter instances into a JSON Schema object.
     *
     * @param  array<int, ToolParameter>  $parameters
     * @return array<string, mixed>  The JSON Schema object, or [] if the list is empty.
     */
    public static function toJsonSchema(array $parameters): array
    {
        if ($parameters === []) {
            return [];
        }

        $properties = [];
        $required = [];

        foreach ($parameters as $parameter) {
            $schema = [
                'type' => $parameter->type,
                'description' => $parameter->description,
            ];

            if ($parameter->enum !== null) {
                $schema['enum'] = $parameter->enum;
            }

            $properties[$parameter->name] = $schema;

            if ($parameter->required) {
                $required[] = $parameter->name;
            }
        }

        $jsonSchema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $jsonSchema['required'] = $required;
        }

        return $jsonSchema;
    }
}
