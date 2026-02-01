<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerProgressSnapshot extends Model
{
    protected $table = 'player_progress_snapshots';

    protected $fillable = [
        'user_id',
        'is_bot',
        'general',
        'economy',
        'research',
        'military',
        'wars',
        'sampled_at',
    ];

    protected $casts = [
        'is_bot' => 'boolean',
        'general' => 'integer',
        'economy' => 'integer',
        'research' => 'integer',
        'military' => 'integer',
        'wars' => 'integer',
        'sampled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
