<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A multi-turn AI conversation.
 *
 * Deliberately unlinked to any project table — consuming apps associate
 * conversations with their own owner/session models via their own pivots.
 *
 * @property int $id
 * @property string|null $title
 * @property string $mode
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $system_prompt
 * @property int|null $connection_id
 * @property string|null $cli_session_id
 * @property string|null $session_provider
 * @property string|null $session_model
 * @property string|null $tools_hash
 * @property array<string, mixed>|null $metadata
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Message> $messages
 * @property-read Connection|null $connection
 */
class Conversation extends Model
{
    protected $table = 'ai_bridge_conversations';

    protected $fillable = [
        'title',
        'mode',
        'provider',
        'model',
        'system_prompt',
        'connection_id',
        'cli_session_id',
        'session_provider',
        'session_model',
        'tools_hash',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('id');
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /**
     * Append a message to this conversation and bump its updated_at.
     *
     * @param  array<string, mixed>  $attributes  Extra columns: blocks, provider, model, usage, incomplete.
     */
    public function appendMessage(string $role, string $content, array $attributes = []): Message
    {
        $message = $this->messages()->create(array_merge($attributes, [
            'role' => $role,
            'content' => $content,
        ]));

        // Bump updated_at so conversation listings sort by recency.
        $this->touch();

        return $message;
    }

    /**
     * Build the chat history for the `messages` streaming option.
     *
     * Returns prior turns as a list of {role, content} entries. Tool calls and
     * tool results ARE included (the AI must retain tool context); thinking
     * blocks are EXCLUDED (chat models do not retain prior reasoning).
     *
     * Call this BEFORE persisting the new user turn — it returns everything
     * currently stored, and the streaming engine appends the live message.
     *
     * @return list<array{role: string, content: string}>
     */
    public function historyFor(): array
    {
        return $this->messages
            ->map(fn (Message $m) => [
                'role' => $m->role,
                'content' => self::renderHistoryContent($m),
            ])
            ->filter(fn (array $entry) => $entry['content'] !== '')
            ->values()
            ->all();
    }

    /**
     * Render a message's history content: text + tool calls/results, no thinking.
     */
    public static function renderHistoryContent(Message $message): string
    {
        $blocks = $message->blocks;

        // No structured blocks — fall back to the flat content.
        if (! is_array($blocks) || $blocks === []) {
            return trim($message->content);
        }

        $parts = [];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? 'text';

            switch ($type) {
                case 'thinking':
                    // Excluded — chat models do not retain prior reasoning.
                    break;

                case 'tool_call':
                    $name = $block['tool_name'] ?? 'tool';
                    $params = json_encode($block['parameters'] ?? new \stdClass());
                    $parts[] = "[tool call: {$name}({$params})]";
                    break;

                case 'tool_result':
                    $result = $block['result'] ?? '';
                    if (! is_string($result)) {
                        $result = json_encode($result);
                    }
                    $parts[] = "[tool result: {$result}]";
                    break;

                case 'text':
                default:
                    $text = $block['text'] ?? '';
                    if (is_string($text) && $text !== '') {
                        $parts[] = $text;
                    }
                    break;
            }
        }

        return trim(implode("\n", $parts));
    }
}
