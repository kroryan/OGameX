<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OGame\Enums\BotActionType;

/**
 * @property int $id
 * @property int $bot_id
 * @property string $action_type
 * @property string $action_description
 * @property array|null $resources_spended
 * @property string $result
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Bot $bot
 */
class BotLog extends Model
{
    protected $fillable = [
        'bot_id',
        'action_type',
        'action_description',
        'resources_spended',
        'result',
    ];

    protected $casts = [
        'resources_spended' => 'array',
    ];

    /**
     * Get the bot that owns this log.
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * Get the action type as an enum.
     */
    public function getActionTypeEnum(): BotActionType
    {
        return BotActionType::from($this->action_type);
    }
}
