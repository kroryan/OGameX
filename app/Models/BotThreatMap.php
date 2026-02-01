<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotThreatMap extends Model
{
    protected $table = 'bot_threat_map';

    protected $fillable = [
        'bot_id', 'threat_user_id', 'threat_score',
        'times_attacked_us', 'times_we_attacked',
        'times_we_won', 'times_we_lost',
        'is_nap', 'is_ally', 'last_interaction_at',
    ];

    protected $casts = [
        'is_nap' => 'boolean',
        'is_ally' => 'boolean',
        'last_interaction_at' => 'datetime',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function isDangerous(): bool
    {
        return $this->threat_score >= 50;
    }

    public function isSafeTarget(): bool
    {
        return $this->threat_score <= 20 && !$this->is_nap && !$this->is_ally;
    }

    public function recordAttackOnUs(): void
    {
        $this->times_attacked_us++;
        $this->threat_score = min(100, $this->threat_score + 15);
        $this->last_interaction_at = now();
        $this->save();
    }

    public function recordOurAttack(bool $won): void
    {
        $this->times_we_attacked++;
        if ($won) {
            $this->times_we_won++;
            $this->threat_score = max(-100, $this->threat_score - 5);
        } else {
            $this->times_we_lost++;
            $this->threat_score = min(100, $this->threat_score + 10);
        }
        $this->last_interaction_at = now();
        $this->save();
    }
}
