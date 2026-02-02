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
    'scheduler_interval_minutes' => env('BOTS_SCHEDULER_INTERVAL', 10),

    // Number of bots to process per scheduler run (prevents long locks)
    'scheduler_batch_size' => env('BOTS_SCHEDULER_BATCH_SIZE', 200),

    // Default cooldown (in minutes) after a bot attacks before it can attack again
    'default_attack_cooldown_minutes' => 30,

    // Maximum number of fleets a bot can have active at once
    'max_fleets_per_bot' => 3,

    // Chance (0-1) for a bot to go on expedition instead of building fleet
    // 0.15 = 15% chance
    'expedition_chance' => 0.20,

    // Allow bots to target other bots (true = bots can attack bots)
    'allow_target_bots' => true,

    // Allow bots to join/create alliances
    'allow_alliances' => true,
    'alliance_apply_chance' => 0.15, // 15% per bot tick to apply/join
    'alliance_create_chance' => 0.08, // 8% per bot tick to create if none available
    'alliance_max_created' => 10, // Cap total bot-created alliances
    'alliance_max_members' => 30, // Soft cap per alliance for bot logic
    'alliance_action_cooldown_minutes' => 120,
    'alliance_auto_accept' => true,
    'alliance_invite_human_chance' => 0.10, // 10% chance per tick for bots to invite nearby humans
    'alliance_diplomat_bonus_chance' => 0.20, // Extra invite chance for DIPLOMAT personality

    // Maximum level a bot will build buildings/research to
    'max_building_level' => 30,
    'max_research_level' => 18,

    // Default activity cycle when no schedule is defined
    // Bots are active continuously by default (window >= cycle disables cycling).
    'default_activity_cycle_minutes' => 60,
    'default_activity_window_minutes' => 60,

    // Strategic thresholds and tuning
    'espionage_report_max_age_minutes' => 20,
    'target_intel_max_age_minutes' => 30,
    'attack_min_loot_ratio_capacity' => 0.10,
    'attack_expected_loss_cost_multiplier' => 1000,
    'attack_expected_loss_min_profit_ratio' => 0.15,
    'attack_min_profit_consumption_multiplier' => 0.5,
    'attack_phalanx_scan_enabled' => true,
    'attack_phalanx_scan_chance' => 0.35,
    'attack_phalanx_abort_window_seconds' => 300,
    'avoid_stronger_player_ratio' => 1.8,
    'battle_sim_iterations' => 3,
    'geopolitical_system_range' => 50,

    // Merchant trade tuning
    'merchant_trade_min_imbalance' => 0.35,
    'merchant_trade_amount_min' => 5000,
    'merchant_trade_amount_ratio' => 0.2,
    'merchant_trade_amount_max_ratio' => 0.5,

    // Bot log retention (days). Set to 0 to disable pruning.
    'bot_logs_retention_days' => env('BOTS_LOGS_RETENTION_DAYS', 14),

    // Metrics-driven tuning
    'bot_metrics_window_days' => 7,
    'bot_metrics_min_samples' => 20,

    // Dynamic fleet threshold for "significant fleet" by game phase
    'significant_fleet_threshold' => [
        'early' => 500,
        'mid' => 15000,
        'late' => 50000,
    ],

    // Build defenses on all planets, not just richest
    'defense_all_planets' => true,

    // Expedition holding time range (hours)
    'expedition_holding_hours_min' => 1,
    'expedition_holding_hours_max' => 4,

    // Record battle results for learning
    'record_battle_history' => true,

    // Enable proactive phalanx scanning before attacks
    'proactive_phalanx_enabled' => true,

    // Moon infrastructure priorities
    'moon_building_enabled' => true,

    // Prefer nearby targets to save deuterium
    'prefer_nearby_targets' => true,
    'nearby_target_system_range' => 50,

    // Auto-recycle debris after own attacks
    'auto_recycle_after_attack' => true,

    // System 2: DM Halving - minimum DM balance to consider halving
    'min_dm_for_halving' => 5000,

    // System 7: Debris harvesting range (systems)
    'debris_harvest_range' => 20,

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

    // Maximum actions a bot can take per tick
    'max_actions_per_tick' => 3,

    // Initial building/research seed for new bots (jumpstart progression)
    'initial_seed' => [
        'buildings' => [
            'metal_mine' => 4,
            'crystal_mine' => 3,
            'deuterium_synthesizer' => 1,
            'solar_plant' => 4,
            'robot_factory' => 2,
            'shipyard' => 1,
            'research_lab' => 1,
            'metal_store' => 2,
            'crystal_store' => 2,
            'deuterium_store' => 1,
        ],
        'research' => [
            'energy_technology' => 1,
            'espionage_technology' => 2,
            'computer_technology' => 1,
            'combustion_drive' => 1,
        ],
        'units' => [
            'espionage_probe' => 5,
            'small_cargo' => 3,
            'light_fighter' => 10,
            'rocket_launcher' => 5,
        ],
        'resources' => [
            'metal' => 5000,
            'crystal' => 3000,
            'deuterium' => 1000,
        ],
    ],

    // Minimum fleet points to consider an attack (lower = more aggressive early)
    'min_fleet_size_for_attack' => 50,

    // Colonization tuning
    'colonization_aggressive_min_fleet' => 5000, // Min fleet points for aggressive/raider to colonize (was 80000)
    'colonization_turtle_min_defense' => 500, // Min defense points for turtle to colonize (was 5000)
    'colonization_cross_galaxy' => true, // Allow cross-galaxy colonization when same galaxy full
    'colonization_max_attempts' => 60, // Max attempts to find empty position
    'colonization_cargo_metal_ratio' => 0.50, // Cargo split for new colonies
    'colonization_cargo_crystal_ratio' => 0.30,
    'colonization_cargo_deut_ratio' => 0.20,

    // ROI optimization
    'roi_score_cap' => 80, // Max ROI bonus for building priority (was 40)
    'storage_forecast_hours_threshold' => 4, // Build storage when this many hours from full
    'energy_deficit_emergency_bonus' => 100, // Priority bonus for energy buildings when deficit (was 45)
    'storage_aggressive_spend_threshold' => 0.80, // At this storage %, drop reserves near 0
    'production_imbalance_threshold' => 0.40, // Deprioritize overproducing mine if imbalance > this
];
