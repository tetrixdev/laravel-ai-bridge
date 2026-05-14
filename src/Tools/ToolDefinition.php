<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Tools;

use Closure;
use Tetrix\AiBridge\Contracts\ToolHandler;

/**
 * Immutable value object representing a tool the AI can call.
 *
 * A ToolDefinition holds the tool's name, description, JSON Schema parameters,
 * and the handler (closure or ToolHandler instance) that executes it.
 */
final class ToolDefinition
{
    /**
     * @param  string  $name  Unique tool name (e.g. 'roll_dice').
     * @param  string  $description  Human-readable description for the AI.
     * @param  array<string, mixed>  $parameters  JSON Schema for the tool's input parameters.
     * @param  Closure|ToolHandler  $handler  The callable that executes the tool.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        private readonly Closure|ToolHandler $handler,
    ) {}

    /**
     * Create a ToolDefinition from a ToolHandler class instance.
     */
    public static function fromHandler(ToolHandler $handler): self
    {
        return new self(
            name: $handler->name(),
            description: $handler->description(),
            parameters: $handler->parameters(),
            handler: $handler,
        );
    }

    /**
     * Execute the tool with the given parameters.
     *
     * @param  array<string, mixed>  $params
     * @return mixed
     */
    public function execute(array $params): mixed
    {
        if ($this->handler instanceof ToolHandler) {
            return $this->handler->handle($params);
        }

        return ($this->handler)($params);
    }

    /**
     * Serialize the tool definition to the format expected by Chat Completions APIs.
     *
     * @return array<string, mixed>
     */
    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->parameters,
            ],
        ];
    }

    /**
     * Serialize the tool definition to a plain array (useful for WebSocket transmission).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->parameters,
        ];
    }
}
