@extends('ingame.layouts.main')

@section('content')
    @php /** @var \OGame\Services\PlanetService $currentPlanet */ @endphp

    @php
        // Extract current settings for the form
        $activitySchedule = $bot->activity_schedule ?? [];
        $activeHours = $activitySchedule['active_hours'] ?? [];
        $inactiveDays = $activitySchedule['inactive_days'] ?? [];

        $actionProbs = $bot->action_probabilities ?? [];
        $buildProb = $actionProbs['build'] ?? null;
        $fleetProb = $actionProbs['fleet'] ?? null;
        $attackProb = $actionProbs['attack'] ?? null;
        $researchProb = $actionProbs['research'] ?? null;

        $economySettings = $bot->economy_settings ?? [];
        $savePercent = $economySettings['save_for_upgrade_percent'] ?? null;
        $minResources = $economySettings['min_resources_for_actions'] ?? null;
        $maxStoragePercent = $economySettings['max_storage_before_spending'] ?? null;
        $prioritizeProduction = $economySettings['prioritize_production'] ?? 'balanced';

        $fleetSettings = $bot->fleet_settings ?? [];
        $attackFleetPercent = $fleetSettings['attack_fleet_percentage'] ?? null;
        $expeditionFleetPercent = $fleetSettings['expedition_fleet_percentage'] ?? null;
        $minFleetSize = $fleetSettings['min_fleet_size_for_attack'] ?? null;
        $preferFastShips = $fleetSettings['prefer_fast_ships'] ?? null;
        $alwaysIncludeRecyclers = $fleetSettings['always_include_recyclers'] ?? null;

        $behaviorFlags = $bot->behavior_flags ?? [];
        $disabledActions = $behaviorFlags['disabled_actions'] ?? [];
        $avoidStronger = $behaviorFlags['avoid_stronger_players'] ?? null;
        $maxPlanets = $behaviorFlags['max_planets_to_colonize'] ?? null;
    @endphp

    @if (session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    <style>
        .bot-config-section {
            background: linear-gradient(to bottom, #1a1a1a 0%, #0d0d0d 100%);
            border: 1px solid #444;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .bot-config-section h3 {
            color: #ff9900;
            border-bottom: 1px solid #444;
            padding-bottom: 8px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .config-row {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .config-label {
            width: 200px;
            color: #aaa;
            font-size: 12px;
        }
        .config-field {
            flex: 1;
            min-width: 200px;
        }
        .hours-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 3px;
            margin-top: 8px;
        }
        .hour-checkbox {
            display: none;
        }
        .hour-label {
            display: inline-block;
            width: 100%;
            height: 28px;
            line-height: 28px;
            text-align: center;
            background: #222;
            border: 1px solid #444;
            cursor: pointer;
            font-size: 11px;
            color: #888;
            border-radius: 3px;
        }
        .hour-checkbox:checked + .hour-label {
            background: #2a5a2a;
            border-color: #4a8a4a;
            color: #8f8;
        }
        .day-checkbox {
            display: none;
        }
        .day-label {
            display: inline-block;
            padding: 8px 15px;
            background: #222;
            border: 1px solid #444;
            cursor: pointer;
            font-size: 12px;
            color: #888;
            border-radius: 3px;
            margin-right: 5px;
            margin-bottom: 5px;
        }
        .day-checkbox:checked + .day-label {
            background: #5a2a2a;
            border-color: #8a4a4a;
            color: #f88;
        }
        .action-checkbox {
            display: none;
        }
        .action-label {
            display: inline-block;
            padding: 8px 20px;
            background: #222;
            border: 1px solid #444;
            cursor: pointer;
            font-size: 12px;
            color: #888;
            border-radius: 3px;
            margin-right: 5px;
        }
        .action-checkbox:checked + .action-label {
            background: #2a2a5a;
            border-color: #4a4a8a;
            color: #88f;
        }
        .slider-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .slider {
            flex: 1;
            height: 8px;
            border-radius: 4px;
            background: #333;
            outline: none;
            -webkit-appearance: none;
        }
        .slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #ff9900;
            cursor: pointer;
        }
        .slider::-moz-range-thumb {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #ff9900;
            cursor: pointer;
            border: none;
        }
        .slider-value {
            min-width: 50px;
            text-align: center;
            color: #ff9900;
            font-weight: bold;
        }
        .radio-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .radio-option {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .radio-option input[type="radio"] {
            margin: 0;
        }
        .radio-option label {
            color: #aaa;
            font-size: 12px;
            cursor: pointer;
        }
        .info-box {
            background: #1a2a1a;
            border-left: 3px solid #4a8a4a;
            padding: 10px 15px;
            margin: 10px 0;
            font-size: 11px;
            color: #8a9a8a;
        }
        .prob-sum-warning {
            color: #f88;
            font-weight: bold;
        }
        .prob-sum-ok {
            color: #8f8;
            font-weight: bold;
        }
        input[type="number"], select {
            background: #1a1a1a;
            border: 1px solid #444;
            color: #ddd;
            padding: 5px 10px;
            border-radius: 3px;
        }
        input[type="number"]:focus, select:focus {
            outline: none;
            border-color: #666;
        }
    </style>

    <div class="maincontent">
        <div id="planet" class="shortHeader">
            <h2>Edit Playerbot: {{ $bot->name }}</h2>
        </div>

        <div id="buttonz">
            <div class="header">
                <h2>Edit Playerbot: {{ $bot->name }}</h2>
            </div>
            <div class="content">
                <div class="buddylistContent">
                    <form action="{{ route('admin.bots.update', $bot->id) }}" method="POST" id="botConfigForm">
                        @csrf
                        @method('PUT')

                        <!-- Basic Configuration -->
                        <div class="bot-config-section">
                            <h3>📋 Basic Configuration</h3>

                            <div class="config-row">
                                <div class="config-label">Bot Name:</div>
                                <div class="config-field">
                                    <input type="text" name="name" class="textInput w100 textBeefy" value="{{ $bot->name }}" required>
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Player Account:</div>
                                <div class="config-field">
                                    <input type="text" class="textInput w100 textBeefy" value="{{ $bot->user->username ?? 'N/A' }}" disabled>
                                    <div style="font-size: 0.8em; color: #666; margin-top: 3px;">
                                        The player account cannot be changed
                                    </div>
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Personality:</div>
                                <div class="config-field">
                                    <select name="personality" class="textInput w100 textBeefy" required>
                                        @foreach ($personalities as $personality)
                                            <option value="{{ $personality->value }}" {{ $bot->personality === $personality->value ? 'selected' : '' }}>
                                                {{ $personality->getLabel() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Target Preference:</div>
                                <div class="config-field">
                                    <select name="priority_target_type" class="textInput w100 textBeefy" required>
                                        @foreach ($targetTypes as $type)
                                            <option value="{{ $type->value }}" {{ $bot->priority_target_type === $type->value ? 'selected' : '' }}>
                                                {{ $type->getLabel() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Max Fleets Sent:</div>
                                <div class="config-field">
                                    <input type="number" name="max_fleets_sent" class="textInput w50 textCenter textBeefy" value="{{ $bot->max_fleets_sent }}" min="1" max="10">
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Status:</div>
                                <div class="config-field">
                                    @if ($bot->is_active)
                                        <span style="color: #0f0;">● Active</span>
                                    @else
                                        <span style="color: #f00;">● Inactive</span>
                                    @endif
                                    <div style="margin-top: 5px;">
                                        <a href="{{ route('admin.bots.toggle', $bot->id) }}" class="btn_blue" style="font-size: 0.9em;">
                                            Toggle Status
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Activity Schedule -->
                        <div class="bot-config-section">
                            <h3>⏰ Activity Schedule</h3>
                            <div class="info-box">
                                Define when the bot should be active. Leave empty to be active 24/7.
                            </div>

                            <div class="config-row">
                                <div class="config-label">Active Hours:</div>
                                <div class="config-field">
                                    <div class="hours-grid">
                                        @for ($hour = 0; $hour < 24; $hour++)
                                            $checked = in_array($hour, $activeHours) ? 'checked' : '';
                                            <input type="checkbox" class="hour-checkbox" name="active_hours[]" value="{{ $hour }}" id="hour_{{ $hour }}" {{ $checked }}>
                                            <label for="hour_{{ $hour }}" class="hour-label">{{ $hour }}</label>
                                        @endfor
                                    </div>
                                    <div style="font-size: 0.8em; color: #666; margin-top: 5px;">
                                        Select the hours when the bot should be active (0-23)
                                    </div>
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Inactive Days:</div>
                                <div class="config-field">
                                    @foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day)
                                        $checked = in_array($day, $inactiveDays) ? 'checked' : '';
                                        <input type="checkbox" class="day-checkbox" name="inactive_days[]" value="{{ $day }}" id="day_{{ $day }}" {{ $checked }}>
                                        <label for="day_{{ $day }}" class="day-label">{{ ucfirst($day) }}</label>
                                    @endforeach
                                    <div style="font-size: 0.8em; color: #666; margin-top: 5px;">
                                        Select days when the bot should NOT be active
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Probabilities -->
                        <div class="bot-config-section">
                            <h3>🎯 Action Probabilities</h3>
                            <div class="info-box">
                                Override the default action weights for this bot. Leave at 0 to use personality defaults.
                                <br>Total: <span id="probSum">0</span>% <span id="probStatus"></span>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Build:</div>
                                <div class="config-field slider-container">
                                    <input type="range" class="slider" id="buildProb" min="0" max="100" value="{{ $buildProb ?? 0 }}" oninput="updateSlider('build')">
                                    <span class="slider-value" id="buildValue">{{ $buildProb ?? 0 }}%</span>
                                    <input type="hidden" name="prob_build" id="prob_build" value="{{ $buildProb ?? '' }}">
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Fleet:</div>
                                <div class="config-field slider-container">
                                    <input type="range" class="slider" id="fleetProb" min="0" max="100" value="{{ $fleetProb ?? 0 }}" oninput="updateSlider('fleet')">
                                    <span class="slider-value" id="fleetValue">{{ $fleetProb ?? 0 }}%</span>
                                    <input type="hidden" name="prob_fleet" id="prob_fleet" value="{{ $fleetProb ?? '' }}">
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Attack:</div>
                                <div class="config-field slider-container">
                                    <input type="range" class="slider" id="attackProb" min="0" max="100" value="{{ $attackProb ?? 0 }}" oninput="updateSlider('attack')">
                                    <span class="slider-value" id="attackValue">{{ $attackProb ?? 0 }}%</span>
                                    <input type="hidden" name="prob_attack" id="prob_attack" value="{{ $attackProb ?? '' }}">
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Research:</div>
                                <div class="config-field slider-container">
                                    <input type="range" class="slider" id="researchProb" min="0" max="100" value="{{ $researchProb ?? 0 }}" oninput="updateSlider('research')">
                                    <span class="slider-value" id="researchValue">{{ $researchProb ?? 0 }}%</span>
                                    <input type="hidden" name="prob_research" id="prob_research" value="{{ $researchProb ?? '' }}">
                                </div>
                            </div>
                        </div>

                        <!-- Economy Settings -->
                        <div class="bot-config-section">
                            <h3>💰 Economy Settings</h3>

                            <div class="config-row">
                                <div class="config-label">Save for Upgrades:</div>
                                <div class="config-field slider-container">
                                    <input type="range" class="slider" id="savePercent" min="0" max="100" value="{{ ($savePercent ?? 30) * 100 }}" oninput="updateSlider('save')">
                                    <span class="slider-value" id="saveValue">{{ $savePercent ?? 30 }}%</span>
                                    <input type="hidden" name="economy_save_percent" id="economy_save_percent" value="{{ $savePercent ?? '' }}">
                                    <span style="color: #888; font-size: 11px;">% of production to save</span>
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Min Resources for Actions:</div>
                                <div class="config-field">
                                    <input type="number" name="economy_min_resources" value="{{ $minResources ?? '' }}" placeholder="Default: 10000" style="width: 150px;">
                                    <span style="color: #888; font-size: 11px; margin-left: 10px;">Metal + Crystal minimum</span>
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Max Storage Before Spending:</div>
                                <div class="config-field slider-container">
                                    <input type="range" class="slider" id="maxStoragePercent" min="50" max="100" value="{{ ($maxStoragePercent ?? 0.9) * 100 }}" oninput="updateSlider('storage')">
                                    <span class="slider-value" id="storageValue">{{ ($maxStoragePercent ?? 0.9) * 100 }}%</span>
                                    <input type="hidden" name="economy_max_storage" id="economy_max_storage" value="{{ $maxStoragePercent ?? '' }}">
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Prioritize Production:</div>
                                <div class="config-field radio-group">
                                    <div class="radio-option">
                                        <input type="radio" name="economy_prioritize" value="" {{ !$prioritizeProduction || $prioritizeProduction === 'balanced' ? 'checked' : '' }} id="prod_balanced">
                                        <label for="prod_balanced">Balanced</label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" name="economy_prioritize" value="metal" {{ $prioritizeProduction === 'metal' ? 'checked' : '' }} id="prod_metal">
                                        <label for="prod_metal">Metal</label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" name="economy_prioritize" value="crystal" {{ $prioritizeProduction === 'crystal' ? 'checked' : '' }} id="prod_crystal">
                                        <label for="prod_crystal">Crystal</label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" name="economy_prioritize" value="deuterium" {{ $prioritizeProduction === 'deuterium' ? 'checked' : '' }} id="prod_deuterium">
                                        <label for="prod_deuterium">Deuterium</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Fleet Settings -->
                        <div class="bot-config-section">
                            <h3>🚀 Fleet Settings</h3>

                            <div class="config-row">
                                <div class="config-label">Attack Fleet Percentage:</div>
                                <div class="config-field slider-container">
                                    <input type="range" class="slider" id="attackFleetPercent" min="10" max="100" value="{{ ($attackFleetPercent ?? 0.7) * 100 }}" oninput="updateSlider('attackFleet')">
                                    <span class="slider-value" id="attackFleetValue">{{ ($attackFleetPercent ?? 0.7) * 100 }}%</span>
                                    <input type="hidden" name="fleet_attack_percent" id="fleet_attack_percent" value="{{ $attackFleetPercent ?? '' }}">
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Expedition Fleet Percentage:</div>
                                <div class="config-field slider-container">
                                    <input type="range" class="slider" id="expeditionFleetPercent" min="5" max="50" value="{{ ($expeditionFleetPercent ?? 0.3) * 100 }}" oninput="updateSlider('expeditionFleet')">
                                    <span class="slider-value" id="expeditionFleetValue">{{ ($expeditionFleetPercent ?? 0.3) * 100 }}%</span>
                                    <input type="hidden" name="fleet_expedition_percent" id="fleet_expedition_percent" value="{{ $expeditionFleetPercent ?? '' }}">
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Min Fleet Size for Attack:</div>
                                <div class="config-field">
                                    <input type="number" name="fleet_min_size" value="{{ $minFleetSize ?? '' }}" placeholder="Default: 100" style="width: 150px;">
                                    <span style="color: #888; font-size: 11px; margin-left: 10px;">Minimum fleet points</span>
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Prefer Fast Ships:</div>
                                <div class="config-field">
                                    <input type="checkbox" name="fleet_prefer_fast" value="1" {{ $preferFastShips ? 'checked' : '' }} id="preferFast">
                                    <label for="preferFast" style="color: #aaa; font-size: 12px; cursor: pointer;">Prioritize speed over power</label>
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Always Include Recyclers:</div>
                                <div class="config-field">
                                    <input type="checkbox" name="fleet_recyclers" value="1" {{ $alwaysIncludeRecyclers ?? true ? 'checked' : '' }} id="includeRecyclers">
                                    <label for="includeRecyclers" style="color: #aaa; font-size: 12px; cursor: pointer;">Include recyclers in attack fleets</label>
                                </div>
                            </div>
                        </div>

                        <!-- Behavior Flags -->
                        <div class="bot-config-section">
                            <h3>⚙️ Behavior Flags</h3>

                            <div class="config-row">
                                <div class="config-label">Disabled Actions:</div>
                                <div class="config-field">
                                    @foreach (['trade', 'expedition'] as $action)
                                        $checked = in_array($action, $disabledActions) ? 'checked' : '';
                                        <input type="checkbox" class="action-checkbox" name="disabled_actions[]" value="{{ $action }}" id="disable_{{ $action }}" {{ $checked }}>
                                        <label for="disable_{{ $action }}" class="action-label">{{ ucfirst($action) }}</label>
                                    @endforeach
                                    <div style="font-size: 0.8em; color: #666; margin-top: 5px;">
                                        Select actions that the bot should NEVER perform
                                    </div>
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Avoid Stronger Players:</div>
                                <div class="config-field">
                                    <input type="checkbox" name="avoid_stronger" value="1" {{ $avoidStronger ? 'checked' : '' }} id="avoidStronger">
                                    <label for="avoidStronger" style="color: #aaa; font-size: 12px; cursor: pointer;">Don't attack players with more fleet power</label>
                                </div>
                            </div>

                            <div class="config-row">
                                <div class="config-label">Max Planets to Colonize:</div>
                                <div class="config-field">
                                    <input type="number" name="max_planets" value="{{ $maxPlanets ?? '' }}" placeholder="No limit" min="1" max="15" style="width: 150px;">
                                    <span style="color: #888; font-size: 11px; margin-left: 10px;">Leave empty for no limit</span>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div style="text-align: center; margin-top: 20px; padding: 15px; background: #0d0d0d; border-radius: 5px;">
                            <a href="{{ route('admin.bots.index') }}" class="btn_blue" style="margin-right: 10px;">← Back to Bots</a>
                            <a href="{{ route('admin.bots.logs', $bot->id) }}" class="btn_blue" style="margin-right: 10px;">View Logs</a>
                            <button type="submit" class="btn_blue">💾 Save Configuration</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="footer"></div>
        </div>
    </div>

    <script>
        // Update slider display values
        function updateSlider(type) {
            switch(type) {
                case 'build':
                    document.getElementById('buildValue').textContent = document.getElementById('buildProb').value + '%';
                    document.getElementById('prob_build').value = document.getElementById('buildProb').value;
                    break;
                case 'fleet':
                    document.getElementById('fleetValue').textContent = document.getElementById('fleetProb').value + '%';
                    document.getElementById('prob_fleet').value = document.getElementById('fleetProb').value;
                    break;
                case 'attack':
                    document.getElementById('attackValue').textContent = document.getElementById('attackProb').value + '%';
                    document.getElementById('prob_attack').value = document.getElementById('attackProb').value;
                    break;
                case 'research':
                    document.getElementById('researchValue').textContent = document.getElementById('researchProb').value + '%';
                    document.getElementById('prob_research').value = document.getElementById('researchProb').value;
                    break;
                case 'save':
                    document.getElementById('saveValue').textContent = document.getElementById('savePercent').value + '%';
                    document.getElementById('economy_save_percent').value = document.getElementById('savePercent').value / 100;
                    break;
                case 'storage':
                    document.getElementById('storageValue').textContent = document.getElementById('maxStoragePercent').value + '%';
                    document.getElementById('economy_max_storage').value = document.getElementById('maxStoragePercent').value / 100;
                    break;
                case 'attackFleet':
                    document.getElementById('attackFleetValue').textContent = document.getElementById('attackFleetPercent').value + '%';
                    document.getElementById('fleet_attack_percent').value = document.getElementById('attackFleetPercent').value / 100;
                    break;
                case 'expeditionFleet':
                    document.getElementById('expeditionFleetValue').textContent = document.getElementById('expeditionFleetPercent').value + '%';
                    document.getElementById('fleet_expedition_percent').value = document.getElementById('expeditionFleetPercent').value / 100;
                    break;
            }
            updateProbSum();
        }

        // Update probability sum and status
        function updateProbSum() {
            const build = parseInt(document.getElementById('buildProb').value) || 0;
            const fleet = parseInt(document.getElementById('fleetProb').value) || 0;
            const attack = parseInt(document.getElementById('attackProb').value) || 0;
            const research = parseInt(document.getElementById('researchProb').value) || 0;
            const total = build + fleet + attack + research;

            document.getElementById('probSum').textContent = total;
            const statusEl = document.getElementById('probStatus');

            if (total === 0) {
                statusEl.textContent = '(Using personality defaults)';
                statusEl.className = 'prob-sum-ok';
            } else if (total === 100) {
                statusEl.textContent = '✓ Perfect!';
                statusEl.className = 'prob-sum-ok';
            } else if (total > 100) {
                statusEl.textContent = '⚠️ Over 100%!';
                statusEl.className = 'prob-sum-warning';
            } else {
                statusEl.textContent = '⚠️ Under 100%';
                statusEl.className = 'prob-sum-warning';
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateProbSum();

            // Initialize sliders
            updateSlider('build');
            updateSlider('fleet');
            updateSlider('attack');
            updateSlider('research');
            updateSlider('save');
            updateSlider('storage');
            updateSlider('attackFleet');
            updateSlider('expeditionFleet');
        });
    </script>

    @include('ingame.components.footer')
@endsection
