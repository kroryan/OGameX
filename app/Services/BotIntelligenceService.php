<?php

namespace OGame\Services;

use OGame\Models\BotIntel;
use OGame\Models\BotActivityPattern;
use OGame\Models\BotThreatMap;
use OGame\Models\EspionageReport;
use OGame\Models\Planet;
use OGame\Enums\BotActionType;

/**
 * BotIntelligenceService - Persistent intelligence database and espionage management.
 *
 * Handles: #7 persistent intel, #8 activity patterns, #9 proactive espionage,
 * #10 counter-espionage, #11 chain espionage, #12 threat map, #13 fleet tracking.
 */
class BotIntelligenceService
{
    /**
     * Record espionage report data into persistent intel database.
     */
    public function recordEspionageIntel(int $botId, EspionageReport $report, ?int $targetUserId = null): BotIntel
    {
        $data = [
            'resources_metal' => $report->resources['metal'] ?? 0,
            'resources_crystal' => $report->resources['crystal'] ?? 0,
            'resources_deuterium' => $report->resources['deuterium'] ?? 0,
            'ships' => $report->ships ?? [],
            'defenses' => $report->defense ?? [],
            'buildings' => $report->buildings ?? [],
            'research' => $report->research ?? [],
            'fleet_power' => $this->calculatePowerFromReport($report, 'ships'),
            'defense_power' => $this->calculatePowerFromReport($report, 'defense'),
            'last_espionage_at' => now(),
        ];

        if ($targetUserId) {
            $data['target_user_id'] = $targetUserId;
        }

        $intel = BotIntel::updateOrCreate(
            [
                'bot_id' => $botId,
                'galaxy' => $report->planet_galaxy,
                'system' => $report->planet_system,
                'planet' => $report->planet_position,
            ],
            $data
        );

        // Enhanced profitability scoring: loot potential / risk ratio
        $totalResources = $intel->getTotalResources();
        $totalDefense = $intel->fleet_power + $intel->defense_power;
        // Lootable amount is ~50% of stored resources in OGame
        $lootable = (int) ($totalResources * 0.5);
        // Defense cost to overcome (weighted lower since partial losses)
        $defenseCost = (int) ($totalDefense * 50);
        // Profitability = expected loot - expected cost to overcome defenses
        $intel->profitability_score = max(0, $lootable - $defenseCost);
        $intel->save();

        return $intel;
    }

    /**
     * Update activity pattern for a target player.
     */
    public function updateActivityPattern(int $botId, int $targetUserId, bool $isActive): void
    {
        $pattern = BotActivityPattern::firstOrCreate(
            ['bot_id' => $botId, 'target_user_id' => $targetUserId],
            ['hourly_activity' => array_fill(0, 24, 0), 'daily_activity' => array_fill(0, 7, 0)]
        );
        $pattern->recordActivity($isActive);
    }

    /**
     * Check if now is a good time to attack a target (based on activity patterns).
     */
    public function isGoodTimeToAttack(int $botId, int $targetUserId): bool
    {
        $pattern = BotActivityPattern::where('bot_id', $botId)
            ->where('target_user_id', $targetUserId)
            ->first();

        if (!$pattern || $pattern->observation_count < 5) {
            return true; // Not enough data, assume it's fine
        }

        return !$pattern->isLikelyOnlineNow();
    }

    /**
     * Get the best attack hours for a target.
     */
    public function getBestAttackHours(int $botId, int $targetUserId): array
    {
        $pattern = BotActivityPattern::where('bot_id', $botId)
            ->where('target_user_id', $targetUserId)
            ->first();

        if (!$pattern) {
            return range(0, 23);
        }

        return $pattern->getBestAttackHours();
    }

    /**
     * Get most profitable known targets for a bot.
     */
    public function getProfitableTargets(int $botId, int $limit = 10, array $avoidUserIds = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = BotIntel::where('bot_id', $botId)
            ->where('profitability_score', '>', 0)
            ->where('last_espionage_at', '>', now()->subHours(24))
            ->orderByDesc('profitability_score');

        if (!empty($avoidUserIds)) {
            $query->whereNotIn('target_user_id', $avoidUserIds);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get intel for a specific target.
     */
    public function getTargetIntel(int $botId, int $targetUserId): ?BotIntel
    {
        return BotIntel::where('bot_id', $botId)
            ->where('target_user_id', $targetUserId)
            ->orderByDesc('last_espionage_at')
            ->first();
    }

    /**
     * Find nearby planets that need espionage (proactive scanning).
     */
    public function findPlanetsNeedingEspionage(int $botId, int $galaxy, int $system, int $range = 5): array
    {
        $minSystem = max(1, $system - $range);
        $maxSystem = $system + $range;

        // Get coordinates we've already scouted recently (use galaxy/system/planet since target_planet_id may not be set)
        $knownCoords = BotIntel::where('bot_id', $botId)
            ->where('galaxy', $galaxy)
            ->whereBetween('system', [$minSystem, $maxSystem])
            ->where('last_espionage_at', '>', now()->subHours(12))
            ->get()
            ->map(fn($i) => "{$i->galaxy}:{$i->system}:{$i->planet}")
            ->toArray();

        $planets = Planet::where('galaxy', $galaxy)
            ->whereBetween('system', [$minSystem, $maxSystem])
            ->where('destroyed', 0)
            ->whereNotNull('user_id')
            ->where('user_id', '>', 0)
            ->inRandomOrder()
            ->limit(15)
            ->get();

        // Filter out already-scouted coordinates
        return $planets->filter(function ($planet) use ($knownCoords) {
            $key = "{$planet->galaxy}:{$planet->system}:{$planet->planet}";
            return !in_array($key, $knownCoords);
        })->take(5)->values()->toArray();
    }

    /**
     * Update threat map after an interaction.
     */
    public function recordThreatInteraction(int $botId, int $targetUserId, string $type, bool $won = false): void
    {
        $entry = BotThreatMap::firstOrCreate(
            ['bot_id' => $botId, 'threat_user_id' => $targetUserId],
            ['threat_score' => 0]
        );

        if ($type === 'attacked_us') {
            $entry->recordAttackOnUs();
        } elseif ($type === 'our_attack') {
            $entry->recordOurAttack($won);
        }
    }

    /**
     * Get the threat map for a bot.
     */
    public function getThreatMap(int $botId): \Illuminate\Database\Eloquent\Collection
    {
        return BotThreatMap::where('bot_id', $botId)
            ->orderByDesc('threat_score')
            ->get();
    }

    /**
     * Check if a user is considered dangerous.
     */
    public function isUserDangerous(int $botId, int $targetUserId): bool
    {
        $entry = BotThreatMap::where('bot_id', $botId)
            ->where('threat_user_id', $targetUserId)
            ->first();

        return $entry && $entry->isDangerous();
    }

    /**
     * Check if a user has a NAP with the bot.
     */
    public function hasNAP(int $botId, int $targetUserId): bool
    {
        $entry = BotThreatMap::where('bot_id', $botId)
            ->where('threat_user_id', $targetUserId)
            ->first();

        return $entry && ($entry->is_nap || $entry->is_ally);
    }

    /**
     * Mark alliance members as allies in the threat map.
     */
    public function syncAllianceAllies(int $botId, int $allianceId): void
    {
        $allyUserIds = \OGame\Models\User::where('alliance_id', $allianceId)->pluck('id')->toArray();

        foreach ($allyUserIds as $userId) {
            BotThreatMap::updateOrCreate(
                ['bot_id' => $botId, 'threat_user_id' => $userId],
                ['is_ally' => true, 'threat_score' => -50]
            );
        }
    }

    /**
     * Detect incoming espionage attempts (counter-espionage).
     */
    public function detectEspionage(BotService $botService): bool
    {
        $bot = $botService->getBot();
        $planetIds = $botService->getPlayer()->planets->allIds();

        $recentEspionage = \OGame\Models\FleetMission::whereIn('planet_id_to', $planetIds)
            ->where('mission_type', 6) // Espionage
            ->where('canceled', 0)
            ->where('time_arrival', '>', now()->subMinutes(30)->timestamp)
            ->count();

        if ($recentEspionage > 0) {
            $bot->espionage_counter += $recentEspionage;
            $bot->save();
            return true;
        }

        return false;
    }

    /**
     * Get nearby inactive players (good farm targets).
     */
    public function getInactiveTargets(int $botId, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return BotIntel::where('bot_id', $botId)
            ->where('is_inactive', true)
            ->where('last_espionage_at', '>', now()->subHours(48))
            ->orderByDesc('profitability_score')
            ->limit($limit)
            ->get();
    }

    private function calculatePowerFromReport(EspionageReport $report, string $type): int
    {
        $items = $type === 'ships' ? ($report->ships ?? []) : ($report->defense ?? []);
        $total = 0;
        $pointValues = [
            'light_fighter' => 3, 'heavy_fighter' => 6, 'cruiser' => 10,
            'battle_ship' => 30, 'battlecruiser' => 40, 'bomber' => 35,
            'destroyer' => 60, 'deathstar' => 200, 'small_cargo' => 5,
            'large_cargo' => 10, 'recycler' => 8, 'espionage_probe' => 1,
            'rocket_launcher' => 2, 'light_laser' => 2, 'heavy_laser' => 4,
            'gauss_cannon' => 10, 'ion_cannon' => 8, 'plasma_turret' => 30,
            'small_shield_dome' => 5, 'large_shield_dome' => 20,
        ];

        foreach ($items as $name => $amount) {
            $total += ($pointValues[$name] ?? 1) * (int) $amount;
        }
        return $total;
    }
}
