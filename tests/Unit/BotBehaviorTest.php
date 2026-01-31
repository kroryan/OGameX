<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use OGame\Factories\BotServiceFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Bot;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Models\UserTech;
use Tests\TestCase;

class BotBehaviorTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithPlanet(array $planetOverrides = []): array
    {
        $user = User::factory()->create();
        $planet = Planet::factory()->create(array_merge([
            'user_id' => $user->id,
            'galaxy' => 1,
            'system' => 100,
            'planet' => 5,
            'planet_type' => 1,
            'metal' => 200000,
            'crystal' => 100000,
            'deuterium' => 50000,
        ], $planetOverrides));

        $user->planet_current = $planet->id;
        $user->save();

        $tech = UserTech::create(['user_id' => $user->id]);
        $tech->computer_technology = 1;
        $tech->save();

        return [$user, $planet];
    }

    private function createBotForUser(User $user, array $overrides = []): Bot
    {
        return Bot::create(array_merge([
            'user_id' => $user->id,
            'name' => 'TestBot',
            'personality' => 'balanced',
            'is_active' => true,
            'priority_target_type' => 'random',
        ], $overrides));
    }

    public function testDefaultActivityWindowUsesCycle(): void
    {
        Cache::flush();
        config()->set('bots.default_activity_cycle_minutes', 60);
        config()->set('bots.default_activity_window_minutes', 10);

        [$user] = $this->createUserWithPlanet();
        $bot = $this->createBotForUser($user);

        cache()->put('bot_activity_offset_' . $bot->id, 0, now()->addHours(24));

        $this->travelTo(now()->startOfDay());
        $this->assertTrue($bot->isActive());

        $this->travelTo(now()->startOfDay()->addMinutes(15));
        $this->assertFalse($bot->isActive());
    }

    public function testAttackRequiresRecentEspionageReport(): void
    {
        [$user, $planet] = $this->createUserWithPlanet();
        [$targetUser, $targetPlanet] = $this->createUserWithPlanet([
            'galaxy' => 1,
            'system' => 101,
            'planet' => 6,
        ]);

        $bot = $this->createBotForUser($user);

        $planetService = app(PlanetServiceFactory::class)->make($planet->id);
        $planetService->addUnit('light_fighter', 50);
        $planetService->addUnit('small_cargo', 10);

        $botService = app(BotServiceFactory::class)->makeFromBotModel($bot);
        $targetService = app(PlanetServiceFactory::class)->make($targetPlanet->id);

        $result = $botService->sendAttackFleet($targetService);

        $this->assertFalse($result);
        $this->assertEquals(0, FleetMission::count());
    }

    public function testPhalanxAbortCancelsAttack(): void
    {
        config()->set('bots.attack_phalanx_scan_chance', 1.0);
        config()->set('bots.attack_phalanx_abort_window_seconds', 600);

        [$user, $planet] = $this->createUserWithPlanet();
        [$targetUser, $targetPlanet] = $this->createUserWithPlanet([
            'galaxy' => 1,
            'system' => 100,
            'planet' => 6,
        ]);

        $bot = $this->createBotForUser($user);

        $moon = Planet::factory()->create([
            'user_id' => $user->id,
            'galaxy' => 1,
            'system' => 100,
            'planet' => 9,
            'planet_type' => 3,
            'sensor_phalanx' => 1,
            'deuterium' => 10000,
        ]);

        $planetService = app(PlanetServiceFactory::class)->make($planet->id);
        $planetService->addUnit('light_fighter', 80);
        $planetService->addUnit('small_cargo', 20);
        $planetService->addUnit('espionage_probe', 5);

        $report = new EspionageReport();
        $report->planet_galaxy = 1;
        $report->planet_system = 100;
        $report->planet_position = 6;
        $report->planet_type = 1;
        $report->planet_user_id = $targetUser->id;
        $report->resources = ['metal' => 250000, 'crystal' => 150000, 'deuterium' => 100000];
        $report->ships = [];
        $report->defense = [];
        $report->player_info = [];
        $report->debris = [];
        $report->buildings = [];
        $report->research = [];
        $report->save();

        $incoming = new FleetMission();
        $incoming->user_id = $targetUser->id;
        $incoming->planet_id_from = $targetPlanet->id;
        $incoming->planet_id_to = $targetPlanet->id;
        $incoming->galaxy_to = 1;
        $incoming->system_to = 100;
        $incoming->position_to = 6;
        $incoming->type_from = 1;
        $incoming->type_to = 1;
        $incoming->mission_type = 1;
        $incoming->time_departure = now()->timestamp;
        $incoming->time_arrival = now()->timestamp + 300;
        $incoming->save();

        $botService = app(BotServiceFactory::class)->makeFromBotModel($bot);
        $targetService = app(PlanetServiceFactory::class)->make($targetPlanet->id);

        $result = $botService->sendAttackFleet($targetService);

        $this->assertFalse($result);
        $this->assertEquals(0, FleetMission::where('user_id', $user->id)->count());
    }

    public function testMerchantTradeUsedWhenNoCargoShips(): void
    {
        config()->set('bots.merchant_trade_min_imbalance', 0.1);
        config()->set('bots.merchant_trade_amount_ratio', 0.05);
        config()->set('bots.merchant_trade_amount_max_ratio', 0.1);

        [$user, $planet] = $this->createUserWithPlanet([
            'metal' => 500000,
            'crystal' => 1000,
            'deuterium' => 1000,
            'metal_store' => 50,
            'crystal_store' => 50,
            'deuterium_store' => 50,
            'metal_max' => 2000000,
            'crystal_max' => 2000000,
            'deuterium_max' => 2000000,
            'small_cargo' => 0,
            'large_cargo' => 0,
        ]);

        $user->dark_matter = 10000;
        $user->save();

        $bot = $this->createBotForUser($user, ['personality' => 'economic']);

        $botService = app(BotServiceFactory::class)->makeFromBotModel($bot);
        $result = $botService->sendResourceTransport();

        $user->refresh();
        $this->assertTrue($result);
        $this->assertLessThan(10000, $user->dark_matter);
    }
}
