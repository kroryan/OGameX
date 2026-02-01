<?php

namespace OGame\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use OGame\Enums\BotPersonality;
use OGame\Enums\BotTargetType;
use OGame\Models\Bot;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;

class BulkCreateBots implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var array<string, mixed>
     */
    private array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $count = (int) ($this->payload['count'] ?? 0);
        if ($count < 1) {
            return;
        }

        $defaultPassword = (string) ($this->payload['password'] ?? '');
        if ($defaultPassword === '') {
            $defaultPassword = 'botpassword123';
        }

        $creator = app(CreatesNewUsers::class);
        $batchToken = (string) ($this->payload['batch_token'] ?? Str::lower(Str::random(8)));
        $prefix = trim((string) ($this->payload['bot_name_prefix'] ?? 'Bot'));
        if ($prefix === '') {
            $prefix = 'Bot';
        }
        $nameOffset = Bot::where('name', 'like', $prefix . ' %')->count();

        for ($i = 1; $i <= $count; $i++) {
            $email = $this->generateUniqueBotEmail(
                (string) ($this->payload['email_prefix'] ?? 'bot'),
                (string) ($this->payload['email_domain'] ?? 'bots.local'),
                $batchToken,
                $i
            );

            try {
                DB::transaction(function () use ($creator, $email, $i, $defaultPassword, $prefix, $nameOffset) {
                    $user = $creator->create([
                        'email' => $email,
                        'password' => $defaultPassword,
                    ]);

                    $personality = ($this->payload['personality'] ?? 'random') === 'random'
                        ? BotPersonality::cases()[array_rand(BotPersonality::cases())]->value
                        : (string) $this->payload['personality'];

                    $targetType = ($this->payload['priority_target_type'] ?? 'random_choice') === 'random_choice'
                        ? BotTargetType::cases()[array_rand(BotTargetType::cases())]->value
                        : (string) $this->payload['priority_target_type'];

                    $botName = sprintf('%s %03d', $prefix, $nameOffset + $i);

                    Bot::create([
                        'user_id' => $user->id,
                        'name' => $botName,
                        'personality' => $personality,
                        'priority_target_type' => $targetType,
                        'max_fleets_sent' => (int) ($this->payload['max_fleets_sent'] ?? 3),
                        'is_active' => (bool) ($this->payload['is_active'] ?? true),
                    ]);

                    // Seed initial buildings, research, units, and resources
                    $this->seedBotPlanet($user);
                });
            } catch (\Exception $e) {
                // Skip failed entries and continue.
                continue;
            }
        }
    }

    /**
     * Seed the bot's starting planet with initial buildings, research, units, and resources.
     */
    private function seedBotPlanet(User $user): void
    {
        $seed = config('bots.initial_seed', []);
        if (empty($seed)) {
            return;
        }

        try {
            $playerServiceFactory = app(\OGame\Factories\PlayerServiceFactory::class);
            $player = $playerServiceFactory->make($user->id);
            $planets = $player->planets->all();

            if (empty($planets)) {
                return;
            }

            $planet = reset($planets);

            // Set initial building levels
            $buildings = $seed['buildings'] ?? [];
            foreach ($buildings as $machineName => $level) {
                try {
                    $object = ObjectService::getObjectByMachineName($machineName);
                    $currentLevel = $planet->getObjectLevel($machineName);
                    if ($currentLevel < $level) {
                        $planet->setObjectLevel($object->id, $level, false);
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
            $planet->save();

            // Set initial research levels
            $research = $seed['research'] ?? [];
            foreach ($research as $machineName => $level) {
                try {
                    $currentLevel = $player->getResearchLevel($machineName);
                    if ($currentLevel < $level) {
                        $player->setResearchLevel($machineName, $level);
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            // Add initial units
            $units = $seed['units'] ?? [];
            foreach ($units as $machineName => $amount) {
                try {
                    $current = $planet->getObjectAmount($machineName);
                    if ($current < $amount) {
                        $planet->addUnit($machineName, $amount - $current, false);
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
            $planet->save();

            // Add bonus starting resources
            $resources = $seed['resources'] ?? [];
            if (!empty($resources)) {
                try {
                    $bonus = new \OGame\Models\Resources(
                        $resources['metal'] ?? 0,
                        $resources['crystal'] ?? 0,
                        $resources['deuterium'] ?? 0,
                        0
                    );
                    $planet->addResources($bonus);
                } catch (\Exception $e) {
                    // Non-critical
                }
            }
        } catch (\Exception $e) {
            logger()->warning("BulkCreateBots: Failed to seed bot planet for user {$user->id}: {$e->getMessage()}");
        }
    }

    private function generateUniqueBotEmail(string $prefix, string $domain, string $batchToken, int $index): string
    {
        $prefix = $this->sanitizeEmailLocalPart($prefix);
        if ($prefix === '') {
            $prefix = 'bot';
        }

        $attempts = 0;
        do {
            $suffix = sprintf('%s%03d', $batchToken, $index);
            if ($attempts > 0) {
                $suffix .= Str::lower(Str::random(3));
            }

            $local = substr($prefix . $suffix, 0, 64);
            $email = $local . '@' . $domain;
            $attempts++;
        } while (User::where('email', $email)->exists() && $attempts < 5);

        if (User::where('email', $email)->exists()) {
            $local = substr($prefix . $batchToken . $index . Str::lower(Str::random(6)), 0, 64);
            $email = $local . '@' . $domain;
        }

        return $email;
    }

    private function sanitizeEmailLocalPart(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_replace('/[^a-z0-9._+-]+/', '', $value) ?? '';
    }
}
