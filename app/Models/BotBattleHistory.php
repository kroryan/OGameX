<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotBattleHistory extends Model
{
    protected $table = 'bot_battle_history';

    protected $fillable = [
        'bot_id', 'target_user_id', 'target_planet_id',
        'battle_type', 'result',
        'loot_gained', 'fleet_lost_value',
        'attack_power', 'defense_power',
        'fleet_sent', 'fleet_lost', 'enemy_fleet', 'enemy_defenses',
    ];

    protected $casts = [
        'fleet_sent' => 'array',
        'fleet_lost' => 'array',
        'enemy_fleet' => 'array',
        'enemy_defenses' => 'array',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function getNetProfit(): int
    {
        return $this->loot_gained - $this->fleet_lost_value;
    }
}
