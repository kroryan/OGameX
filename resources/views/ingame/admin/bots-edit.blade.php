@extends('ingame.layouts.main')

@section('content')
    @php /** @var \OGame\Services\PlanetService $currentPlanet */ @endphp

    @if (session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

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
                    <form action="{{ route('admin.bots.update', $bot->id) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <p class="box_highlight textCenter">Basic Configuration</p>
                        <div class="group bborder" style="display: block;">
                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Bot Name:</label>
                                <div class="thefield">
                                    <input type="text" name="name" class="textInput w100 textBeefy" value="{{ $bot->name }}" required>
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Player Account:</label>
                                <div class="thefield">
                                    <input type="text" class="textInput w100 textBeefy" value="{{ $bot->user->username ?? 'N/A' }}" disabled>
                                    <div style="font-size: 0.9em; color: #999; margin-top: 5px;">
                                        The player account cannot be changed.
                                    </div>
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Personality:</label>
                                <div class="thefield">
                                    <select name="personality" class="textInput w100 textBeefy" required>
                                        @foreach ($personalities as $personality)
                                            <option value="{{ $personality->value }}" {{ $bot->personality === $personality->value ? 'selected' : '' }}>
                                                {{ $personality->getLabel() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Target Preference:</label>
                                <div class="thefield">
                                    <select name="priority_target_type" class="textInput w100 textBeefy" required>
                                        @foreach ($targetTypes as $type)
                                            <option value="{{ $type->value }}" {{ $bot->priority_target_type === $type->value ? 'selected' : '' }}>
                                                {{ $type->getLabel() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Max Fleets Sent:</label>
                                <div class="thefield">
                                    <input type="number" name="max_fleets_sent" class="textInput w50 textCenter textBeefy" value="{{ $bot->max_fleets_sent }}" min="1" max="10">
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Status:</label>
                                <div class="thefield">
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

                        <p class="box_highlight textCenter" style="margin-top: 15px;">Advanced Configuration (JSON)</p>
                        <div class="group bborder" style="display: block;">
                            <div style="font-size: 0.9em; color: #999; margin-bottom: 10px;">
                                Leave blank to use default values based on personality.
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Activity Schedule:</label>
                                <div class="thefield">
                                    <textarea name="activity_schedule" class="textInput w100 textBeefy" rows="3" placeholder='{"active_hours": [0,1,2,...,23], "inactive_days": ["saturday", "sunday"]}'>{{ $bot->activity_schedule ? json_encode($bot->activity_schedule, JSON_PRETTY_PRINT) : '' }}</textarea>
                                    <div style="font-size: 0.8em; color: #999;">Define when the bot is active (hours 0-23, days lowercase)</div>
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Action Probabilities:</label>
                                <div class="thefield">
                                    <textarea name="action_probabilities" class="textInput w100 textBeefy" rows="2" placeholder='{"build": 30, "fleet": 25, "attack": 20, "research": 25}'>{{ $bot->action_probabilities ? json_encode($bot->action_probabilities, JSON_PRETTY_PRINT) : '' }}</textarea>
                                    <div style="font-size: 0.8em; color: #999;">Override default action weights (must sum to 100)</div>
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Economy Settings:</label>
                                <div class="thefield">
                                    <textarea name="economy_settings" class="textInput w100 textBeefy" rows="3" placeholder='{"save_for_upgrade_percent": 0.3, "min_resources_for_actions": 10000}'>{{ $bot->economy_settings ? json_encode($bot->economy_settings, JSON_PRETTY_PRINT) : '' }}</textarea>
                                    <div style="font-size: 0.8em; color: #999;">Resource management settings</div>
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Fleet Settings:</label>
                                <div class="thefield">
                                    <textarea name="fleet_settings" class="textInput w100 textBeefy" rows="3" placeholder='{"attack_fleet_percentage": 0.7, "min_fleet_size_for_attack": 100}'>{{ $bot->fleet_settings ? json_encode($bot->fleet_settings, JSON_PRETTY_PRINT) : '' }}</textarea>
                                    <div style="font-size: 0.8em; color: #999;">Fleet composition and attack behavior</div>
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Behavior Flags:</label>
                                <div class="thefield">
                                    <textarea name="behavior_flags" class="textInput w100 textBeefy" rows="2" placeholder='{"disabled_actions": ["trade"], "avoid_stronger_players": true}'>{{ $bot->behavior_flags ? json_encode($bot->behavior_flags, JSON_PRETTY_PRINT) : '' }}</textarea>
                                    <div style="font-size: 0.8em; color: #999;">Enable/disable specific behaviors</div>
                                </div>
                            </div>
                        </div>

                        <div style="text-align: center; margin-top: 20px;">
                            <a href="{{ route('admin.bots.index') }}" class="btn_blue" style="margin-right: 10px;">Cancel</a>
                            <a href="{{ route('admin.bots.logs', $bot->id) }}" class="btn_blue" style="margin-right: 10px;">View Logs</a>
                            <button type="submit" class="btn_blue">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="footer"></div>
        </div>
    </div>

    <script language="javascript">
        initBBCodes();
        initOverlays();
    </script>
@endsection
