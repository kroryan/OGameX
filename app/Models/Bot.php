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
 * @property array|null $activity_schedule
 * @property array|null $action_probabilities
 * @property array|null $economy_settings
 * @property array|null $fleet_settings
 * @property array|null $behavior_flags
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
        'activity_schedule',
        'action_probabilities',
        'economy_settings',
        'fleet_settings',
        'behavior_flags',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_action_at' => 'datetime',
        'attack_cooldown_until' => 'datetime',
        'max_fleets_sent' => 'integer',
        'activity_schedule' => 'array',
        'action_probabilities' => 'array',
        'economy_settings' => 'array',
        'fleet_settings' => 'array',
        'behavior_flags' => 'array',
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
     * Check if the bot is currently active (considering schedule).
     */
    public function isActive(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // Check activity schedule
        if ($this->activity_schedule !== null) {
            $now = now();
            $currentHour = (int)$now->format('H');
            $currentDay = strtolower($now->format('l'));

            // Check if bot is active at this time
            $schedule = $this->activity_schedule;
            if (isset($schedule['active_hours'])) {
                $activeHours = $schedule['active_hours'];
                if (!in_array($currentHour, $activeHours)) {
                    return false; // Outside active hours
                }
            }

            if (isset($schedule['inactive_days'])) {
                if (in_array($currentDay, $schedule['inactive_days'])) {
                    return false; // Inactive day
                }
            }
            return true;
        }

        // Default: simulate human activity window if no schedule is configured.
        $cycleMinutes = (int) config('bots.default_activity_cycle_minutes', 240);
        $windowMinutes = (int) config('bots.default_activity_window_minutes', 20);

        if ($cycleMinutes <= 0 || $windowMinutes <= 0 || $windowMinutes >= $cycleMinutes) {
            return true;
        }

        $offsetKey = 'bot_activity_offset_' . $this->id;
        $offset = cache()->get($offsetKey);
        if (!is_int($offset)) {
            $offset = random_int(0, $cycleMinutes - 1);
            cache()->put($offsetKey, $offset, now()->addHours(24));
        }

        $minutes = (int) floor(now()->timestamp / 60);
        $position = ($minutes + $offset) % $cycleMinutes;

        return $position < $windowMinutes;
    }

    /**
     * Check if the bot can attack (not in cooldown).
     */
    public function canAttack(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->attack_cooldown_until === null) {
            return true;
        }

        return $this->attack_cooldown_until->isPast();
    }

    /**
     * Check if bot should skip action based on behavior flags.
     */
    public function shouldSkipAction(string $actionType): bool
    {
        if ($this->behavior_flags === null) {
            return false;
        }

        $flags = $this->behavior_flags;

        // Skip certain actions if disabled
        if (isset($flags['disabled_actions']) && in_array($actionType, $flags['disabled_actions'])) {
            return true;
        }

        // Skip actions if resources are low
        if (isset($flags['min_resources_for_actions'])) {
            $min = $flags['min_resources_for_actions'];
            // Check if bot has enough resources
            // This will be checked in the action itself
        }

        return false;
    }

    /**
     * Get action probabilities for this bot.
     */
    public function getActionProbabilities(): array
    {
        $default = [
            'build' => 30,
            'fleet' => 25,
            'attack' => 20,
            'research' => 25,
        ];

        $personalityWeights = match ($this->getPersonalityEnum()) {
            BotPersonality::AGGRESSIVE => ['build' => 20, 'fleet' => 35, 'attack' => 35, 'research' => 10],
            BotPersonality::DEFENSIVE => ['build' => 40, 'fleet' => 25, 'attack' => 10, 'research' => 25],
            BotPersonality::ECONOMIC => ['build' => 50, 'fleet' => 15, 'attack' => 5, 'research' => 30],
            BotPersonality::BALANCED => ['build' => 30, 'fleet' => 25, 'attack' => 20, 'research' => 25],
        };

        $base = $this->action_probabilities ?? $personalityWeights;
        return array_merge($default, $base);
    }

    /**
     * Get economy settings for this bot.
     */
    public function getEconomySettings(): array
    {
        $default = [
            'save_for_upgrade_percent' => 0.3, // Save 30% of production for upgrades
            'min_resources_for_actions' => 500, // Minimum resources to perform actions
            'max_storage_before_spending' => 0.7, // Only spend if storage is 70% full
            'prioritize_production' => 'balanced', // 'balanced', 'metal', 'crystal', 'deuterium'
        ];

        return array_merge($default, $this->economy_settings ?? []);
    }

    /**
     * Get fleet settings for this bot.
     */
    public function getFleetSettings(): array
    {
        $default = [
            'attack_fleet_percentage' => 0.7, // Percentage of fleet to send in attacks
            'expedition_fleet_percentage' => 0.3, // Percentage of fleet to send on expeditions
            'min_fleet_size_for_attack' => 100, // Minimum fleet points to attack
            'prefer_fast_ships' => false, // Prefer faster ships over powerful ones
            'always_include_recyclers' => true, // Always include recyclers in attacks
            'max_expedition_fleet_cost_percentage' => 0.2, // Max 20% of fleet value for expeditions
        ];

        return array_merge($default, $this->fleet_settings ?? []);
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
     * Get the personality (convenience method).
     */
    public function getPersonality(): BotPersonality
    {
        return $this->getPersonalityEnum();
    }

    /**
     * Get the target type as an enum.
     */
    public function getTargetTypeEnum(): BotTargetType
    {
        return BotTargetType::from($this->priority_target_type);
    }

    /**
     * Get the target type (convenience method).
     */
    public function getTargetType(): BotTargetType
    {
        return $this->getTargetTypeEnum();
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
