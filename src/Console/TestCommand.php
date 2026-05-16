<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Console;

use Illuminate\Console\Command;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Protocol\StreamEvent;

/**
 * Artisan command to send a test AI request through the bridge.
 *
 * For BYOK mode, sends a real Chat Completions request and shows
 * streaming events in real-time in the console.
 *
 * Requires AI_BRIDGE_ENDPOINT and AI_BRIDGE_API_KEY in .env for BYOK mode.
 *
 * Usage:
 *   php artisan ai-bridge:test
 *   php artisan ai-bridge:test "What is 2+2?"
 *   php artisan ai-bridge:test "Hello!" --mode=bridge --provider=claude
 *   php artisan ai-bridge:test "Hello!" --mode=byok
 */
class TestCommand extends Command
{
    protected $signature = 'ai-bridge:test
        {message=Hello from AI Bridge!}
        {--mode=byok}
        {--provider= : Provider to use in bridge mode (claude, codex, gemini)}
        {--user-id=1 : User ID to simulate for bridge mode}';

    protected $description = 'Send a test AI request through the bridge';

    public function handle(AiBridgeManager $manager): int
    {
        $message = $this->argument('message');
        $mode = $this->option('mode');

        $this->info("Sending test request in {$mode} mode...");
        $this->line("Message: {$message}");
        $this->newLine();

        if ($mode === 'bridge') {
            $this->warn('Bridge mode requires an active bridge connection.');
            $this->warn('Make sure a bridge client is connected via WebSocket.');
            $this->newLine();
        }

        $failed = false;

        // Validate explicitly so an unrecognised --mode argument produces a clean
        // user-facing error instead of an uncaught ValueError.
        $resolvedMode = ProviderMode::tryFrom($mode);
        if ($resolvedMode === null) {
            $validModes = implode(', ', array_map(fn (ProviderMode $m) => "'{$m->value}'", ProviderMode::cases()));
            $this->error("Unknown mode '{$mode}'. Valid modes: {$validModes}.");

            return self::FAILURE;
        }

        $options = [
            'mode' => $resolvedMode,
            'system_prompt' => 'You are a helpful assistant. Keep responses brief.',
        ];

        // Bridge mode requires a user_id (artisan commands have no auth context).
        if ($resolvedMode === ProviderMode::Bridge) {
            $options['user_id'] = $this->option('user-id') ?? '1';
        }

        // Allow provider selection for bridge mode
        if ($provider = $this->option('provider')) {
            $options['provider'] = $provider;
        }

        try {
            $stream = $manager->stream('test-conversation', $message, $options);

            $stream->onBlockStart(function (StreamEvent $event) {
                $blockType = $event->data['block_type'] ?? 'unknown';
                $blockIndex = $event->data['block_index'] ?? 0;
                $this->line("<fg=cyan>[block_start]</> type={$blockType} index={$blockIndex}");
            });

            $stream->onBlockDelta(function (StreamEvent $event) {
                $content = $event->data['content'] ?? '';
                $this->output->write($content);
            });

            $stream->onBlockStop(function (StreamEvent $event) {
                $this->newLine();
                $blockType = $event->data['block_type'] ?? 'unknown';
                $blockIndex = $event->data['block_index'] ?? 0;
                $this->line("<fg=cyan>[block_stop]</> type={$blockType} index={$blockIndex}");
            });

            $stream->onToolCall(function (string $toolName, array $params, string $callId) {
                $this->line("<fg=yellow>[tool_call]</> {$toolName}({$callId}): ".json_encode($params));
            });

            $stream->onDone(function (?array $usage) {
                $this->newLine();
                $this->info('Stream completed successfully.');
                if ($usage) {
                    $input = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 'n/a';
                    $output = $usage['output_tokens'] ?? $usage['completion_tokens'] ?? 'n/a';
                    $this->line("Usage: {$input} input tokens, {$output} output tokens");
                }
            });

            $stream->onError(function (string $code, string $errorMessage) use (&$failed) {
                $this->newLine();
                $this->error("Stream error [{$code}]: {$errorMessage}");
                $failed = true;
            });

            // Register a cancelled callback so mid-stream cancellations are
            // reported as failures rather than silently exiting with code 0.
            $stream->onCancelled(function (string $reason) use (&$failed) {
                $this->newLine();
                $this->error("Stream cancelled: {$reason}");
                $failed = true;
            });

            $stream->start();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->newLine();

            // Show mode-specific configuration hints.
            if ($mode === 'bridge') {
                $this->line('For bridge mode, ensure these are set in your .env:');
                $this->line('  AI_BRIDGE_MODE=bridge');
                $this->line('  AI_BRIDGE_TOKEN_SECRET=<32+ char secret>');
                $this->line('  AI_BRIDGE_SERVER_PORT=8085');
                $this->line('And make sure the bridge server is running: php artisan ai-bridge:serve');
            } else {
                $this->line('For BYOK/managed mode, ensure these are set in your .env:');
                $this->line('  AI_BRIDGE_ENDPOINT=https://api.openai.com');
                $this->line('  AI_BRIDGE_API_KEY=sk-...');
                $this->line('  AI_BRIDGE_MODEL=gpt-4o');
            }

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Unexpected error: '.$e->getMessage());

            return self::FAILURE;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
