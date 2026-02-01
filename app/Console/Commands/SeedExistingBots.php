<?php

namespace OGame\Console\Commands;

use Illuminate\Console\Command;
use OGame\Models\Bot;
use OGame\Services\ObjectService;

/**
 * SeedExistingBots - Retroactively seed existing bots with initial buildings,
 * research, units, and resources to jumpstart their progression.
 *
 * Only upgrades buildings/research that are below the seed level.
 */
class SeedExistingBots extends Command
{
    protected $signature = 'ogamex:seed-existing-bots
                            {--dry-run : Show what would be changed without applying}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Seed existing bots with initial buildings, research, units, and resources';

    public function handle(): int
    {
        $seed = config('bots.initial_seed', []);
        if (empty($seed)) {
            $this->error('No initial_seed configuration found in config/bots.php');
            return 1;
        }

        $bots = Bot::where('is_active', true)->get();
        $this->info("Found {$bots->count()} active bots to seed.");

        if ($bots->isEmpty()) {
            return 0;
        }

        $isDryRun = $this->option('dry-run');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - no changes will be applied.');
        }

        if (!$isDryRun && !$this->option('force')) {
            if (!$this->confirm("Seed {$bots->count()} bots with initial buildings/research/units/resources?")) {
                return 0;
            }
        }

        $playerServiceFactory = app(\OGame\Factories\PlayerServiceFactory::class);
        $seededCount = 0;
        $skippedCount = 0;

        foreach ($bots as $bot) {
            try {
                $player = $playerServiceFactory->make($bot->user_id);
                $planets = $player->planets->all();

                if (empty($planets)) {
                    $this->warn("Bot {$bot->name} (#{$bot->id}): no planets, skipping.");
                    $skippedCount++;
                    continue;
                }

                $planet = reset($planets);
                $changes = [];

                // Seed buildings
                $buildings = $seed['buildings'] ?? [];
                foreach ($buildings as $machineName => $targetLevel) {
                    try {
                        $currentLevel = $planet->getObjectLevel($machineName);
                        if ($currentLevel < $targetLevel) {
                            $changes[] = "  {$machineName}: {$currentLevel} -> {$targetLevel}";
                            if (!$isDryRun) {
                                $object = ObjectService::getObjectByMachineName($machineName);
                                $planet->setObjectLevel($object->id, $targetLevel, false);
                            }
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
                if (!$isDryRun && !empty($changes)) {
                    $planet->save();
                }

                // Seed research
                $research = $seed['research'] ?? [];
                foreach ($research as $machineName => $targetLevel) {
                    try {
                        $currentLevel = $player->getResearchLevel($machineName);
                        if ($currentLevel < $targetLevel) {
                            $changes[] = "  {$machineName}: {$currentLevel} -> {$targetLevel}";
                            if (!$isDryRun) {
                                $player->setResearchLevel($machineName, $targetLevel);
                            }
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }

                // Seed units
                $units = $seed['units'] ?? [];
                foreach ($units as $machineName => $targetAmount) {
                    try {
                        $current = $planet->getObjectAmount($machineName);
                        if ($current < $targetAmount) {
                            $toAdd = $targetAmount - $current;
                            $changes[] = "  {$machineName}: +{$toAdd} (had {$current})";
                            if (!$isDryRun) {
                                $planet->addUnit($machineName, $toAdd);
                            }
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }

                // Add bonus resources (only if bot has very low resources)
                $resources = $seed['resources'] ?? [];
                if (!empty($resources)) {
                    try {
                        $currentResources = $planet->getResources();
                        $totalCurrent = $currentResources->metal->get() + $currentResources->crystal->get() + $currentResources->deuterium->get();
                        $totalSeed = ($resources['metal'] ?? 0) + ($resources['crystal'] ?? 0) + ($resources['deuterium'] ?? 0);

                        if ($totalCurrent < $totalSeed * 2) {
                            $changes[] = "  resources: +{$resources['metal']}M +{$resources['crystal']}C +{$resources['deuterium']}D";
                            if (!$isDryRun) {
                                $bonus = new \OGame\Models\Resources(
                                    $resources['metal'] ?? 0,
                                    $resources['crystal'] ?? 0,
                                    $resources['deuterium'] ?? 0,
                                    0
                                );
                                $planet->addResources($bonus);
                            }
                        }
                    } catch (\Exception $e) {
                        // Non-critical
                    }
                }

                if (!empty($changes)) {
                    $this->info("Bot {$bot->name} (#{$bot->id}):");
                    foreach ($changes as $change) {
                        $this->line($change);
                    }
                    $seededCount++;
                } else {
                    $skippedCount++;
                }
            } catch (\Exception $e) {
                $this->error("Bot {$bot->name} (#{$bot->id}): error - {$e->getMessage()}");
                $skippedCount++;
            }
        }

        $this->info("Done: {$seededCount} bots seeded, {$skippedCount} skipped.");
        return 0;
    }
}
