<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotPlanetPlan extends Model
{
    protected $table = 'bot_planet_plans';

    protected $fillable = [
        'bot_id', 'planet_id', 'specialization',
        'target_levels', 'current_progress', 'priority',
    ];

    protected $casts = [
        'target_levels' => 'array',
        'current_progress' => 'array',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
