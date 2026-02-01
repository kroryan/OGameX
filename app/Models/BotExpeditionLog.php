<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotExpeditionLog extends Model
{
    protected $table = 'bot_expedition_log';

    protected $fillable = [
        'bot_id', 'resources_found', 'dark_matter_found',
        'ships_found', 'found_nothing', 'lost_fleet',
        'fleet_sent', 'details',
    ];

    protected $casts = [
        'found_nothing' => 'boolean',
        'lost_fleet' => 'boolean',
        'fleet_sent' => 'array',
        'details' => 'array',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
