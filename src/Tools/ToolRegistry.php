<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Tools;

use Closure;
use InvalidArgumentException;
use Tetrix\AiBridge\Contracts\ToolHandler;

/**
 * Singleton registry of tools available to the AI.
 *
 * Consuming applications register tools at boot time (e.g. in a service provider),
 * and the streaming layer reads them when constructing AI requests.
 *
 * Usage:
 *   $registry->register('roll_dice', 'Roll dice', $schema, fn ($p) => ['result' => 42]);
 *   $registry->registerHandler(new RollDiceHandler());
 */
class ToolRegistry
{
    /** @var array<string, ToolDefinition> */
    private array $tools = [];

    /**
     * Register a tool using individual arguments.
     *
     * @param  string  $name  Unique tool name.
     * @param  string  $description  Description for the AI.
     * @param  array<string, mixed>  $parameters  JSON Schema for parameters.
     * @param  Closure  $handler  The function that executes the tool.
     * @return $this
     *
     * @throws InvalidArgumentException If a tool with the same name is already registered.
     */
    public function register(string $name, string $description, array $parameters, Closure $handler): static
    {
        $this->ensureUnique($name);

        $this->tools[$name] = new ToolDefinition($name, $description, $parameters, $handler);

        return $this;
    }

    /**
     * Register a tool from a ToolHandler class instance.
     *
     * @param  ToolHandler  $handler
     * @return $this
     *
     * @throws InvalidArgumentException If a tool with the same name is already registered.
     */
    public function registerHandler(ToolHandler $handler): static
    {
        $this->ensureUnique($handler->name());

        $this->tools[$handler->name()] = ToolDefinition::fromHandler($handler);

        return $this;
    }

    /**
     * Check if a tool is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Get a registered tool by name.
     *
     * @throws InvalidArgumentException If the tool is not registered.
     */
    public function get(string $name): ToolDefinition
    {
        if (! $this->has($name)) {
            throw new InvalidArgumentException("Tool '{$name}' is not registered.");
        }

        return $this->tools[$name];
    }

    /**
     * Execute a tool by name with the given parameters.
     *
     * @param  string  $name  The tool name.
     * @param  array<string, mixed>  $params  The parameters from the AI.
     * @return mixed  The tool result.
     *
     * @throws InvalidArgumentException If the tool is not registered.
     */
    public function execute(string $name, array $params): mixed
    {
        return $this->get($name)->execute($params);
    }

    /**
     * Get all registered tools.
     *
     * @return array<string, ToolDefinition>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Get all tools as function schemas (for Chat Completions API).
     *
     * @return array<int, array<string, mixed>>
     */
    public function toFunctionSchemas(): array
    {
        return array_values(
            array_map(fn (ToolDefinition $tool) => $tool->toFunctionSchema(), $this->tools)
        );
    }

    /**
     * Get all tools as plain arrays (for WebSocket transmission to bridge).
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_values(
            array_map(fn (ToolDefinition $tool) => $tool->toArray(), $this->tools)
        );
    }

    /**
     * Remove a tool from the registry.
     */
    public function remove(string $name): static
    {
        unset($this->tools[$name]);

        return $this;
    }

    /**
     * Remove all tools from the registry.
     */
    public function flush(): static
    {
        $this->tools = [];

        return $this;
    }

    /**
     * Get the number of registered tools.
     */
    public function count(): int
    {
        return count($this->tools);
    }

    /**
     * Ensure no tool with the given name is already registered.
     *
     * @throws InvalidArgumentException
     */
    private function ensureUnique(string $name): void
    {
        if ($this->has($name)) {
            throw new InvalidArgumentException(
                "A tool with the name '{$name}' is already registered. Use remove() first to replace it."
            );
        }
    }
}
