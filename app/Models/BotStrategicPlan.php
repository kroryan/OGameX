<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotStrategicPlan extends Model
{
    protected $fillable = [
        'bot_id', 'plan_type', 'goal_description', 'steps',
        'current_step', 'status', 'priority',
        'target_completion_at', 'completed_at',
    ];

    protected $casts = [
        'steps' => 'array',
        'target_completion_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function getCurrentStep(): ?array
    {
        $steps = $this->steps ?? [];
        return $steps[$this->current_step] ?? null;
    }

    public function advanceStep(): bool
    {
        $steps = $this->steps ?? [];
        if ($this->current_step + 1 >= count($steps)) {
            $this->status = 'completed';
            $this->completed_at = now();
            $this->save();
            return false;
        }

        $this->current_step++;
        $this->save();
        return true;
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function abandon(): void
    {
        $this->status = 'abandoned';
        $this->save();
    }
}
