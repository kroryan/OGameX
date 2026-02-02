<?php

namespace OGame\Console\Commands\Scheduler;

use Illuminate\Console\Command;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Services\BotService;

/**
 * ProcessBotEmergencies - Fast 1-minute emergency threat response for bots.
 */
class ProcessBotEmergencies extends Command
{
    protected $signature = 'ogamex:scheduler:process-bot-emergencies';
    protected $description = 'Process imminent bot threats and trigger immediate fleet saves';

    public function handle(): int
    {
        $botFactory = app(\OGame\Factories\BotServiceFactory::class);

        // Query 1: all active bot planet IDs + bot IDs
        $botPlanets = Planet::join('bots', 'planets.user_id', '=', 'bots.user_id')
            ->where('bots.is_active', true)
            ->select('planets.id as planet_id', 'bots.id as bot_id')
            ->get();

        if ($botPlanets->isEmpty()) {
            return 0;
        }

        $planetIds = $botPlanets->pluck('planet_id')->toArray();
        $planetToBot = $botPlanets->pluck('bot_id', 'planet_id');

        $now = now()->timestamp;
        $cutoff = $now + 300; // 5 minutes

        // Query 2: incoming hostile missions arriving soon
        $incoming = FleetMission::whereIn('planet_id_to', $planetIds)
            ->where('canceled', 0)
            ->where('processed', 0)
            ->whereIn('mission_type', [1, 9, 10])
            ->whereBetween('time_arrival', [$now, $cutoff])
            ->get(['planet_id_to']);

        if ($incoming->isEmpty()) {
            return 0;
        }

        $threatenedBots = [];
        foreach ($incoming as $mission) {
            $botId = $planetToBot[$mission->planet_id_to] ?? null;
            if ($botId) {
                $threatenedBots[$botId] = true;
            }
        }

        if (empty($threatenedBots)) {
            return 0;
        }

        foreach (array_keys($threatenedBots) as $botId) {
            $cooldownKey = "bot:{$botId}:emergency_cooldown";
            if (cache()->has($cooldownKey)) {
                continue;
            }

            try {
                /** @var BotService $botService */
                $botService = $botFactory->makeFromBotId($botId);
            } catch (\Exception) {
                continue;
            }
            if (!$botService) {
                continue;
            }

            if ($botService->performFleetSave()) {
                cache()->put($cooldownKey, true, now()->addMinutes(3));
            }
        }

        return 0;
    }
}
