<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bot Scheduler Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the playerbot system.
    |
    */

    // Enable/disable the bot scheduler
    'scheduler_enabled' => env('BOTS_SCHEDULER_ENABLED', true),

    // How often (in minutes) the bot scheduler should run
    'scheduler_interval_minutes' => env('BOTS_SCHEDULER_INTERVAL', 5),

    // Number of bots to process per scheduler run (prevents long locks)
    'scheduler_batch_size' => env('BOTS_SCHEDULER_BATCH_SIZE', 200),

    // Default cooldown (in hours) after a bot attacks before it can attack again
    'default_attack_cooldown_hours' => 2,

    // Maximum number of fleets a bot can have active at once
    'max_fleets_per_bot' => 3,

    // Chance (0-1) for a bot to go on expedition instead of building fleet
    // 0.15 = 15% chance
    'expedition_chance' => 0.15,

    // Allow bots to target other bots (true = bots can attack bots)
    'allow_target_bots' => true,

    // Allow bots to join/create alliances
    'allow_alliances' => true,
    'alliance_apply_chance' => 0.05, // 5% per bot tick to apply/join
    'alliance_create_chance' => 0.02, // 2% per bot tick to create if none available
    'alliance_max_created' => 50, // Cap total bot-created alliances
    'alliance_max_members' => 30, // Soft cap per alliance for bot logic
    'alliance_action_cooldown_minutes' => 360,
    'alliance_auto_accept' => true,

    // Maximum level a bot will build buildings/research to
    'max_building_level' => 30,
    'max_research_level' => 10,

    // Default activity cycle when no schedule is defined
    // Bots are active for 20 minutes every 4 hours by default.
    'default_activity_cycle_minutes' => 240,
    'default_activity_window_minutes' => 20,

    // Strategic thresholds and tuning
    'espionage_report_max_age_minutes' => 20,
    'target_intel_max_age_minutes' => 30,
    'attack_min_loot_ratio_capacity' => 0.2,
    'attack_expected_loss_cost_multiplier' => 1000,
    'attack_expected_loss_min_profit_ratio' => 0.3,
    'attack_min_profit_consumption_multiplier' => 1.0,
    'attack_phalanx_scan_enabled' => true,
    'attack_phalanx_scan_chance' => 0.35,
    'attack_phalanx_abort_window_seconds' => 300,
    'avoid_stronger_player_ratio' => 1.2,

    // Merchant trade tuning
    'merchant_trade_min_imbalance' => 0.35,
    'merchant_trade_amount_min' => 5000,
    'merchant_trade_amount_ratio' => 0.2,
    'merchant_trade_amount_max_ratio' => 0.5,

    // Maximum percentage of units to send in an attack (bot keeps rest for defense)
    'attack_fleet_percentage' => [
        'aggressive' => 0.9,
        'defensive' => 0.5,
        'economic' => 0.3,
        'balanced' => 0.7,
    ],

    // Action weights by personality (build, fleet, attack, research)
    'personality_weights' => [
        'aggressive' => [20, 35, 35, 10],
        'defensive' => [40, 25, 10, 25],
        'economic' => [50, 15, 5, 30],
        'balanced' => [30, 25, 20, 25],
    ],
];
