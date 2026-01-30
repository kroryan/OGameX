@extends('ingame.layouts.main')

@section('content')
    @php /** @var \OGame\Services\PlanetService $currentPlanet */ @endphp

    <div class="maincontent">
        <div id="planet" class="shortHeader">
            <h2>Create New Playerbot</h2>
        </div>

        <div id="buttonz">
            <div class="header">
                <h2>Create New Playerbot</h2>
            </div>
            <div class="content">
                <div class="buddylistContent">
                    <form action="{{ route('admin.bots.store') }}" method="POST">
                        @csrf

                        <p class="box_highlight textCenter">Bot Configuration</p>
                        <div class="group bborder" style="display: block;">
                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Player Account:</label>
                                <div class="thefield">
                                    <select name="user_id" class="textInput w100 textBeefy" required>
                                        <option value="">Select a player account...</option>
                                        @foreach ($availableUsers as $user)
                                            <option value="{{ $user->id }}">{{ $user->username }}</option>
                                        @endforeach
                                    </select>
                                    <div style="font-size: 0.9em; color: #999; margin-top: 5px;">
                                        The bot will control this player account. Make sure the account has been created and has a starting planet.
                                    </div>
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Bot Name:</label>
                                <div class="thefield">
                                    <input type="text" name="name" class="textInput w100 textBeefy" placeholder="e.g., Bot Alpha" required>
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Personality:</label>
                                <div class="thefield">
                                    <select name="personality" class="textInput w100 textBeefy" required>
                                        @foreach ($personalities as $personality)
                                            <option value="{{ $personality->value }}">{{ $personality->getLabel() }}</option>
                                        @endforeach
                                    </select>
                                    <div style="font-size: 0.9em; color: #999; margin-top: 5px;">
                                        <strong>Aggressive:</strong> Focuses on fleet and attacks (35% each)<br>
                                        <strong>Defensive:</strong> Focuses on buildings and defense (40% buildings)<br>
                                        <strong>Economic:</strong> Focuses on economy and research (50% buildings)<br>
                                        <strong>Balanced:</strong> Even distribution across all actions
                                    </div>
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Target Preference:</label>
                                <div class="thefield">
                                    <select name="priority_target_type" class="textInput w100 textBeefy" required>
                                        @foreach ($targetTypes as $type)
                                            <option value="{{ $type->value }}">{{ $type->getLabel() }}</option>
                                        @endforeach
                                    </select>
                                    <div style="font-size: 0.9em; color: #999; margin-top: 5px;">
                                        What type of targets the bot will prioritize for attacks.
                                    </div>
                                </div>
                            </div>

                            <div class="fieldwrapper">
                                <label class="styled textBeefy">Max Fleets Sent:</label>
                                <div class="thefield">
                                    <input type="number" name="max_fleets_sent" class="textInput w50 textCenter textBeefy" value="3" min="1" max="10">
                                    <div style="font-size: 0.9em; color: #999; margin-top: 5px;">
                                        Maximum number of attack fleets the bot can have active at once.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="text-align: center; margin-top: 20px;">
                            <a href="{{ route('admin.bots.index') }}" class="btn_blue" style="margin-right: 10px;">Cancel</a>
                            <button type="submit" class="btn_blue">Create Bot</button>
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
