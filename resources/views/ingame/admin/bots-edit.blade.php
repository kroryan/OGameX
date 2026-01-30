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

                        <p class="box_highlight textCenter">Bot Configuration</p>
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
