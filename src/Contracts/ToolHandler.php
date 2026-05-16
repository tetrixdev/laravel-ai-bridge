<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Contracts;

/**
 * Interface for classes that handle tool execution.
 *
 * Implement this interface to define a tool the AI can call. Tools
 * registered via the ToolRegistry can use either a closure or a class
 * implementing this interface.
 *
 * The recommended base class is {@see \Tetrix\AiBridge\Tools\AbstractTool}: it
 * implements parameters() for you from a list of
 * {@see \Tetrix\AiBridge\Tools\ToolParameter} objects and guarantees every
 * parameter is fully described.
 *
 * When implementing parameters() directly, every property returned MUST include
 * a non-empty "description" string. ToolDefinition rejects parameter schemas
 * with description-less properties at registration time.
 */
interface ToolHandler
{
    /**
     * Get the tool's unique name.
     */
    public function name(): string;

    /**
     * Get a human-readable description of the tool.
     */
    public function description(): string;

    /**
     * Get the JSON Schema describing the tool's parameters.
     *
     * Every property in the returned schema MUST include a non-empty
     * "description" string. An empty array is allowed for a tool that takes no
     * parameters.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Execute the tool with the given parameters.
     *
     * IMPORTANT: Parameters are NOT automatically validated against the JSON Schema
     * defined in parameters(). Implementations MUST self-validate input (type check,
     * required fields, bounds) before processing. The AI may pass malformed or
     * unexpected values.
     *
     * The return value MUST be JSON-serializable (scalars, arrays, objects implementing
     * JsonSerializable). Non-serializable return values will result in a tool_error
     * being sent to the AI.
     *
     * @param  array<string, mixed>  $params  The parameters passed by the AI.
     * @return mixed  The result to send back to the AI. Must be JSON-serializable.
     */
    public function handle(array $params): mixed;
}
