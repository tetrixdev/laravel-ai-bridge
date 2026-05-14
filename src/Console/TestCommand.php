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
 *   php artisan ai-bridge:test "Hello!" --mode=byok
 */
class TestCommand extends Command
{
    protected $signature = 'ai-bridge:test {message=Hello from AI Bridge!} {--mode=byok}';

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

        try {
            $stream = $manager->stream('test-conversation', $message, [
                'mode' => $mode,
                'system_prompt' => 'You are a helpful assistant. Keep responses brief.',
            ]);

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

            $stream->onError(function (string $code, string $errorMessage) {
                $this->newLine();
                $this->error("Stream error [{$code}]: {$errorMessage}");
            });

            $stream->start();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->newLine();

            if ($mode === 'byok' || $mode === 'managed') {
                $this->line('For BYOK/managed mode, ensure these are set in your .env:');
                $this->line('  AI_BRIDGE_ENDPOINT=https://api.openai.com');
                $this->line('  AI_BRIDGE_API_KEY=sk-...');
                $this->line('  AI_BRIDGE_MODEL=gpt-4o');
            }

            return 1;
        } catch (\Exception $e) {
            $this->error('Unexpected error: '.$e->getMessage());

            return 1;
        }

        return 0;
    }
}
