<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bots', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement()->primary();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('name');
            $table->enum('personality', ['aggressive', 'defensive', 'economic', 'balanced'])->default('balanced');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_action_at')->nullable();
            $table->timestamp('attack_cooldown_until')->nullable();
            $table->enum('priority_target_type', ['random', 'weak', 'rich', 'similar'])->default('random');
            $table->unsignedInteger('max_fleets_sent')->default(3);
            $table->timestamps();

            $table->index('is_active');
            $table->index('last_action_at');
            $table->index('attack_cooldown_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
