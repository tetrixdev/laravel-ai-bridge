<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single turn in a conversation.
 *
 * `content` is the flat text of the turn (assistant text blocks concatenated;
 * thinking and tool calls are NOT in `content`). `blocks` is the full ordered
 * list of typed blocks for faithful UI replay:
 *   [{type:'text', text:'...'},
 *    {type:'thinking', text:'...'},
 *    {type:'tool_call', tool_name:'...', parameters:{...}, tool_call_id:'...'},
 *    {type:'tool_result', tool_call_id:'...', result:'...'}]
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string $content
 * @property array<int, array<string, mixed>>|null $blocks
 * @property string|null $provider
 * @property string|null $model
 * @property array<string, mixed>|null $usage
 * @property bool $incomplete
 */
class Message extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    protected $table = 'ai_bridge_messages';

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'blocks',
        'provider',
        'model',
        'usage',
        'incomplete',
    ];

    protected $casts = [
        'blocks' => 'array',
        'usage' => 'array',
        'incomplete' => 'boolean',
    ];

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
