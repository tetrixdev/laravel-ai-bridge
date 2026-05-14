<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Contracts;

/**
 * Interface for classes that handle tool execution.
 *
 * Implement this interface to define a tool the AI can call. Tools
 * registered via the ToolRegistry can use either a closure or a class
 * implementing this interface.
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
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Execute the tool with the given parameters.
     *
     * @param  array<string, mixed>  $params  The parameters passed by the AI.
     * @return mixed  The result to send back to the AI.
     */
    public function handle(array $params): mixed;
}
