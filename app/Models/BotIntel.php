<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotIntel extends Model
{
    protected $table = 'bot_intel';

    protected $fillable = [
        'bot_id', 'target_user_id', 'target_planet_id',
        'galaxy', 'system', 'planet',
        'resources_metal', 'resources_crystal', 'resources_deuterium',
        'fleet_power', 'defense_power',
        'ships', 'defenses', 'buildings', 'research',
        'threat_level', 'profitability_score',
        'is_inactive', 'last_activity_at', 'last_espionage_at',
    ];

    protected $casts = [
        'ships' => 'array',
        'defenses' => 'array',
        'buildings' => 'array',
        'research' => 'array',
        'is_inactive' => 'boolean',
        'last_activity_at' => 'datetime',
        'last_espionage_at' => 'datetime',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function getTotalResources(): int
    {
        return $this->resources_metal + $this->resources_crystal + $this->resources_deuterium;
    }
}
