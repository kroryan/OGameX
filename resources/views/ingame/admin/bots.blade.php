@extends('ingame.layouts.main')

@section('content')
    @php /** @var \OGame\Services\PlanetService $currentPlanet */ @endphp

    @if (session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-error">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error">
            <ul style="margin: 0; padding-left: 20px;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="maincontent">
        <div id="planet" class="shortHeader">
            <h2>Playerbots Management</h2>
        </div>

        <div id="buttonz">
            <div class="header">
                <h2>Playerbots Management</h2>
            </div>
            <div class="content">
                <div class="buddylistContent">

                    <!-- Statistics -->
                    <div class="group bborder" style="margin-bottom: 20px;">
                        <p class="box_highlight textCenter">Statistics</p>
                        <table class="table" style="width: 100%; margin: 0 auto; max-width: 600px;">
                            <tr>
                                <td style="width: 50%;">Total Bots:</td>
                                <td style="text-align: right;"><strong>{{ $stats['total'] }}</strong></td>
                            </tr>
                            <tr>
                                <td>Active Bots:</td>
                                <td style="text-align: right; color: #0f0;"><strong>{{ $stats['active'] }}</strong></td>
                            </tr>
                            <tr>
                                <td>Inactive Bots:</td>
                                <td style="text-align: right; color: #f00;"><strong>{{ $stats['inactive'] }}</strong></td>
                            </tr>
                            <tr>
                                <td>Total Actions:</td>
                                <td style="text-align: right;"><strong>{{ $stats['total_actions'] }}</strong></td>
                            </tr>
                        </table>
                    </div>

                    <!-- Create Bot Button -->
                    <div style="text-align: center; margin-bottom: 20px;">
                        <a href="{{ route('admin.bots.create') }}" class="btn_blue">Create New Bot</a>
                        <a href="{{ route('admin.bots.stats') }}" class="btn_blue">View Statistics</a>
                        <a href="{{ route('admin.bots.logs-all') }}" class="btn_blue">View All Logs</a>
                    </div>

                    <!-- Bulk Create Bots -->
                    <div class="group bborder" style="margin-bottom: 20px;">
                        <p class="box_highlight textCenter">Bulk Create Bot Accounts (max 300)</p>
                        <form action="{{ route('admin.bots.bulk-store') }}" method="POST">
                            @csrf
                            <table class="table" style="width: 100%;">
                                <tr>
                                    <td style="width: 30%;">Count</td>
                                    <td><input type="number" name="count" class="textInput w50 textCenter textBeefy" value="10" min="1" max="300"></td>
                                </tr>
                                <tr>
                                    <td>Password</td>
                                    <td>
                                        <input type="text" name="password" class="textInput w100 textBeefy" placeholder="Default: botpassword123">
                                        <div style="font-size: 0.8em; color: #666; margin-top: 4px;">
                                            Leave empty to use the default password.
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Email Prefix</td>
                                    <td><input type="text" name="email_prefix" class="textInput w100 textBeefy" value="bot"></td>
                                </tr>
                                <tr>
                                    <td>Email Domain</td>
                                    <td><input type="text" name="email_domain" class="textInput w100 textBeefy" value="bots.local"></td>
                                </tr>
                                <tr>
                                    <td>Bot Name Prefix</td>
                                    <td><input type="text" name="bot_name_prefix" class="textInput w100 textBeefy" value="Bot"></td>
                                </tr>
                                <tr>
                                    <td>Personality</td>
                                    <td>
                                        <select name="personality" class="textInput w100 textBeefy">
                                            <option value="random">Random (per bot)</option>
                                            @foreach ($personalities as $personality)
                                                <option value="{{ $personality->value }}">{{ $personality->getLabel() }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Target Type</td>
                                    <td>
                                        <select name="priority_target_type" class="textInput w100 textBeefy">
                                            <option value="random_choice">Random (per bot)</option>
                                            @foreach ($targetTypes as $type)
                                                <option value="{{ $type->value }}">{{ $type->getLabel() }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Max Fleets Sent</td>
                                    <td><input type="number" name="max_fleets_sent" class="textInput w50 textCenter textBeefy" value="3" min="1" max="10"></td>
                                </tr>
                                <tr>
                                    <td>Activate Bots</td>
                                    <td><input type="checkbox" name="is_active" value="1" checked></td>
                                </tr>
                            </table>
                            <div style="text-align: center; margin-top: 10px;">
                                <button type="submit" class="btn_blue" onclick="return confirm('Create these bot accounts?');">Create Bots in Bulk</button>
                            </div>
                        </form>
                    </div>

                    <!-- Bots List -->
                    @if ($bots->count() > 0)
                        <table class="table" style="width: 100%;">
                            <thead>
                                <tr style="background-color: #222;">
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Player</th>
                                    <th>Personality</th>
                                    <th>Target Type</th>
                                    <th>Status</th>
                                    <th>Active Now</th>
                                    <th>Last Action</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($bots as $bot)
                                    <tr style="border-bottom: 1px solid #333;">
                                        <td>{{ $bot->id }}</td>
                                        <td><strong>{{ $bot->name }}</strong></td>
                                        <td>{{ $bot->user->username ?? 'N/A' }}</td>
                                        <td>
                                            <span class="badge" style="background-color: {{ $bot->personality === 'aggressive' ? '#c00' : ($bot->personality === 'defensive' ? '#00c' : ($bot->personality === 'economic' ? '#0c0' : '#666')) }}; padding: 2px 8px; border-radius: 3px;">
                                                {{ ucfirst($bot->personality) }}
                                            </span>
                                        </td>
                                        <td>{{ ucfirst($bot->priority_target_type) }}</td>
                                        <td>
                                            @if ($bot->is_active)
                                                <span style="color: #0f0;">● Active</span>
                                            @else
                                                <span style="color: #f00;">● Inactive</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($bot->isActive())
                                                <span style="color: #0f0;">Yes</span>
                                            @else
                                                <span style="color: #999;">No</span>
                                            @endif
                                        </td>
                                        <td style="font-size: 0.9em;">
                                            @if ($bot->last_action_at)
                                                {{ $bot->last_action_at->diffForHumans() }}
                                            @else
                                                <em>Never</em>
                                            @endif
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <form action="{{ route('admin.bots.force-action', $bot->id) }}" method="POST" style="display: inline;">
                                                @csrf
                                                <input type="hidden" name="action" value="build">
                                                <button type="submit" class="btn_blue" style="font-size: 0.8em; padding: 3px 8px;" title="Force Build">🔨</button>
                                            </form>
                                            <form action="{{ route('admin.bots.force-action', $bot->id) }}" method="POST" style="display: inline;">
                                                @csrf
                                                <input type="hidden" name="action" value="fleet">
                                                <button type="submit" class="btn_blue" style="font-size: 0.8em; padding: 3px 8px;" title="Force Fleet">🚀</button>
                                            </form>
                                            <a href="{{ route('admin.bots.logs', $bot->id) }}" class="btn_blue" style="font-size: 0.8em; padding: 3px 8px; text-decoration: none; display: inline-block;" title="View Logs">📋</a>
                                            <a href="{{ route('admin.bots.edit', $bot->id) }}" class="btn_blue" style="font-size: 0.8em; padding: 3px 8px; text-decoration: none; display: inline-block;" title="Edit">✏️</a>
                                            <form action="{{ route('admin.bots.toggle', $bot->id) }}" method="POST" style="display: inline;" onsubmit="return confirm('Toggle bot status?');">
                                                @csrf
                                                <button type="submit" class="btn_blue" style="font-size: 0.8em; padding: 3px 8px;" title="Toggle Status">🔄</button>
                                            </form>
                                            <form action="{{ route('admin.bots.delete', $bot->id) }}" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this bot?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn_blue" style="font-size: 0.8em; padding: 3px 8px; background-color: #c00;" title="Delete">🗑️</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="textCenter" style="padding: 20px;">No bots found. <a href="{{ route('admin.bots.create') }}">Create your first bot</a>.</p>
                    @endif

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
