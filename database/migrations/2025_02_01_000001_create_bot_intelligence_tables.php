<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates tables for bot intelligence, strategic planning, and memory systems.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Persistent intelligence database for espionage data
        Schema::create('bot_intel', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bot_id');
            $table->unsignedInteger('target_user_id');
            $table->unsignedInteger('target_planet_id')->nullable();
            $table->unsignedTinyInteger('galaxy');
            $table->unsignedSmallInteger('system');
            $table->unsignedTinyInteger('planet');
            $table->bigInteger('resources_metal')->default(0);
            $table->bigInteger('resources_crystal')->default(0);
            $table->bigInteger('resources_deuterium')->default(0);
            $table->integer('fleet_power')->default(0);
            $table->integer('defense_power')->default(0);
            $table->json('ships')->nullable();
            $table->json('defenses')->nullable();
            $table->json('buildings')->nullable();
            $table->json('research')->nullable();
            $table->integer('threat_level')->default(0); // 0-100
            $table->integer('profitability_score')->default(0);
            $table->boolean('is_inactive')->default(false);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_espionage_at')->nullable();
            $table->timestamps();

            $table->foreign('bot_id')->references('id')->on('bots')->onDelete('cascade');
            $table->index(['bot_id', 'target_user_id']);
            $table->index(['bot_id', 'galaxy', 'system']);
            $table->index(['bot_id', 'profitability_score']);
        });

        // Player activity patterns tracking
        Schema::create('bot_activity_patterns', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bot_id');
            $table->unsignedInteger('target_user_id');
            $table->json('hourly_activity')->nullable(); // 24-element array of activity scores
            $table->json('daily_activity')->nullable();  // 7-element array of activity scores
            $table->float('avg_online_hours')->default(0);
            $table->integer('observation_count')->default(0);
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamps();

            $table->foreign('bot_id')->references('id')->on('bots')->onDelete('cascade');
            $table->unique(['bot_id', 'target_user_id']);
        });

        // Long-term strategic plans
        Schema::create('bot_strategic_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bot_id');
            $table->string('plan_type', 50); // tech_chain, build_order, fleet_goal, colony_plan, moon_plan
            $table->string('goal_description');
            $table->json('steps'); // ordered list of steps to achieve the goal
            $table->integer('current_step')->default(0);
            $table->string('status', 20)->default('active'); // active, completed, abandoned
            $table->integer('priority')->default(50); // 0-100
            $table->timestamp('target_completion_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('bot_id')->references('id')->on('bots')->onDelete('cascade');
            $table->index(['bot_id', 'status', 'priority']);
        });

        // Planet specialization tracking
        Schema::create('bot_planet_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bot_id');
            $table->unsignedInteger('planet_id');
            $table->string('specialization', 30); // economy, fleet, defense, research, deuterium, balanced
            $table->json('target_levels')->nullable(); // desired building levels
            $table->json('current_progress')->nullable();
            $table->integer('priority')->default(50);
            $table->timestamps();

            $table->foreign('bot_id')->references('id')->on('bots')->onDelete('cascade');
            $table->unique(['bot_id', 'planet_id']);
        });

        // Battle history and learning
        Schema::create('bot_battle_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bot_id');
            $table->unsignedInteger('target_user_id');
            $table->unsignedInteger('target_planet_id')->nullable();
            $table->string('battle_type', 20); // attack, defense, ninja
            $table->string('result', 20); // win, loss, draw
            $table->bigInteger('loot_gained')->default(0);
            $table->bigInteger('fleet_lost_value')->default(0);
            $table->integer('attack_power')->default(0);
            $table->integer('defense_power')->default(0);
            $table->json('fleet_sent')->nullable();
            $table->json('fleet_lost')->nullable();
            $table->json('enemy_fleet')->nullable();
            $table->json('enemy_defenses')->nullable();
            $table->timestamps();

            $table->foreign('bot_id')->references('id')->on('bots')->onDelete('cascade');
            $table->index(['bot_id', 'target_user_id']);
            $table->index(['bot_id', 'result']);
            $table->index('created_at');
        });

        // Threat map entries
        Schema::create('bot_threat_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bot_id');
            $table->unsignedInteger('threat_user_id');
            $table->integer('threat_score')->default(0); // -100 (ally) to 100 (enemy)
            $table->integer('times_attacked_us')->default(0);
            $table->integer('times_we_attacked')->default(0);
            $table->integer('times_we_won')->default(0);
            $table->integer('times_we_lost')->default(0);
            $table->boolean('is_nap')->default(false); // non-aggression pact
            $table->boolean('is_ally')->default(false);
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->foreign('bot_id')->references('id')->on('bots')->onDelete('cascade');
            $table->unique(['bot_id', 'threat_user_id']);
        });

        // Expedition results tracking
        Schema::create('bot_expedition_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bot_id');
            $table->bigInteger('resources_found')->default(0);
            $table->integer('dark_matter_found')->default(0);
            $table->integer('ships_found')->default(0);
            $table->boolean('found_nothing')->default(false);
            $table->boolean('lost_fleet')->default(false);
            $table->json('fleet_sent')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->foreign('bot_id')->references('id')->on('bots')->onDelete('cascade');
            $table->index(['bot_id', 'created_at']);
        });

        // Add new columns to bots table for enhanced state
        Schema::table('bots', function (Blueprint $table) {
            $table->string('state', 30)->default('exploring')->after('behavior_flags');
            // State machine: exploring, building, raiding, defending, saving, colonizing
            $table->json('traits')->nullable()->after('state');
            // Secondary traits: vengeful, opportunistic, cautious, impatient
            $table->integer('risk_tolerance')->default(50)->after('traits');
            // 0-100 risk tolerance
            $table->json('preferred_targets')->nullable()->after('risk_tolerance');
            // Target preferences
            $table->integer('espionage_counter')->default(0)->after('preferred_targets');
            // Counter for times we've been espionaged recently
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_expedition_log');
        Schema::dropIfExists('bot_threat_map');
        Schema::dropIfExists('bot_battle_history');
        Schema::dropIfExists('bot_planet_plans');
        Schema::dropIfExists('bot_strategic_plans');
        Schema::dropIfExists('bot_activity_patterns');
        Schema::dropIfExists('bot_intel');

        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn(['state', 'traits', 'risk_tolerance', 'preferred_targets', 'espionage_counter']);
        });
    }
};
