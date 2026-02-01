<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotActivityPattern extends Model
{
    protected $table = 'bot_activity_patterns';

    protected $fillable = [
        'bot_id', 'target_user_id',
        'hourly_activity', 'daily_activity',
        'avg_online_hours', 'observation_count',
        'last_observed_at',
    ];

    protected $casts = [
        'hourly_activity' => 'array',
        'daily_activity' => 'array',
        'last_observed_at' => 'datetime',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * Get the best hours to attack (when target is likely offline).
     */
    public function getBestAttackHours(): array
    {
        $hourly = $this->hourly_activity ?? array_fill(0, 24, 0);
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[$h] = $hourly[$h] ?? 0;
        }
        asort($hours);
        return array_slice(array_keys($hours), 0, 6, true);
    }

    /**
     * Check if target is likely online now.
     */
    public function isLikelyOnlineNow(): bool
    {
        $hourly = $this->hourly_activity ?? array_fill(0, 24, 0);
        $currentHour = (int) now()->format('H');
        $score = $hourly[$currentHour] ?? 0;
        $avg = count($hourly) > 0 ? array_sum($hourly) / count($hourly) : 0;
        return $score > $avg * 1.2;
    }

    /**
     * Record an observation of activity.
     */
    public function recordActivity(bool $isActive): void
    {
        $hour = (int) now()->format('H');
        $day = (int) now()->format('w'); // 0=Sunday

        $hourly = $this->hourly_activity ?? array_fill(0, 24, 0);
        $daily = $this->daily_activity ?? array_fill(0, 7, 0);

        if ($isActive) {
            $hourly[$hour] = ($hourly[$hour] ?? 0) + 1;
            $daily[$day] = ($daily[$day] ?? 0) + 1;
        }

        $this->hourly_activity = $hourly;
        $this->daily_activity = $daily;
        $this->observation_count++;
        $this->last_observed_at = now();
        $this->save();
    }
}
