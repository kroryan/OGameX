<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OGame\Enums\BotPersonality;
use OGame\Enums\BotTargetType;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $personality
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_action_at
 * @property \Illuminate\Support\Carbon|null $attack_cooldown_until
 * @property string $priority_target_type
 * @property int $max_fleets_sent
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BotLog> $logs
 */
class Bot extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'personality',
        'is_active',
        'last_action_at',
        'attack_cooldown_until',
        'priority_target_type',
        'max_fleets_sent',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_action_at' => 'datetime',
        'attack_cooldown_until' => 'datetime',
        'max_fleets_sent' => 'integer',
    ];

    /**
     * Get the user associated with this bot.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the logs for this bot.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(BotLog::class)->latest();
    }

    /**
     * Check if the bot is currently active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if the bot can attack (not in cooldown).
     */
    public function canAttack(): bool
    {
        if ($this->attack_cooldown_until === null) {
            return true;
        }

        return $this->attack_cooldown_until->isPast();
    }

    /**
     * Set attack cooldown for the specified number of hours.
     */
    public function setAttackCooldown(int $hours): void
    {
        $this->attack_cooldown_until = now()->addHours($hours);
        $this->save();
    }

    /**
     * Get the personality as an enum.
     */
    public function getPersonalityEnum(): BotPersonality
    {
        return BotPersonality::from($this->personality);
    }

    /**
     * Get the target type as an enum.
     */
    public function getTargetTypeEnum(): BotTargetType
    {
        return BotTargetType::from($this->priority_target_type);
    }

    /**
     * Update the last action timestamp.
     */
    public function updateLastAction(): void
    {
        $this->last_action_at = now();
        $this->save();
    }
}
