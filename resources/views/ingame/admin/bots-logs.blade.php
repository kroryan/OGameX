@extends('ingame.layouts.main')

@section('content')
    @php /** @var \OGame\Services\PlanetService $currentPlanet */ @endphp

    <div class="maincontent">
        <div id="planet" class="shortHeader">
            <h2>Bot Logs: {{ $bot->name }}</h2>
        </div>

        <div id="buttonz">
            <div class="header">
                <h2>Bot Logs: {{ $bot->name }}</h2>
            </div>
            <div class="content">
                <div class="buddylistContent">

                    <!-- Filter -->
                    <div style="margin-bottom: 20px;">
                        <form action="{{ route('admin.bots.logs', $bot->id) }}" method="GET" style="display: inline;">
                            <label style="margin-right: 10px;">Filter by action:</label>
                            <select name="action_type" class="textInput textBeefy" onchange="this.form.submit();">
                                <option value="">All Actions</option>
                                @foreach ($actionTypes as $type)
                                    <option value="{{ $type->value }}" {{ $currentFilter === $type->value ? 'selected' : '' }}>
                                        {{ $type->getLabel() }}
                                    </option>
                                @endforeach
                            </select>
                            <a href="{{ route('admin.bots.index') }}" class="btn_blue" style="margin-left: 20px;">Back to Bots</a>
                        </form>
                    </div>

                    <!-- Logs Table -->
                    @if ($logs->count() > 0)
                        <table class="table" style="width: 100%;">
                            <thead>
                                <tr style="background-color: #222;">
                                    <th style="width: 50px;">ID</th>
                                    <th style="width: 80px;">Time</th>
                                    <th style="width: 100px;">Action</th>
                                    <th>Description</th>
                                    <th style="width: 80px;">Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($logs as $log)
                                    <tr style="border-bottom: 1px solid #333;">
                                        <td>{{ $log->id }}</td>
                                        <td style="font-size: 0.9em;">{{ $log->created_at->format('H:i') }}</td>
                                        <td>
                                            <span class="badge" style="background-color: {{ $log->action_type === 'attack' ? '#c00' : ($log->action_type === 'build' ? '#0c0' : ($log->action_type === 'research' ? '#00c' : '#666')) }}; padding: 2px 8px; border-radius: 3px; font-size: 0.85em;">
                                                {{ ucfirst($log->action_type) }}
                                            </span>
                                        </td>
                                        <td>{{ $log->action_description }}</td>
                                        <td>
                                            @if ($log->result === 'success')
                                                <span style="color: #0f0;">✓</span>
                                            @elseif ($log->result === 'failed')
                                                <span style="color: #f00;">✗</span>
                                            @else
                                                <span style="color: #fc0;">~</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        <!-- Pagination -->
                        @if ($logs->hasPages())
                            <div style="text-align: center; margin-top: 20px;">
                                {{ $logs->appends(['action_type' => $currentFilter])->links() }}
                            </div>
                        @endif
                    @else
                        <p class="textCenter" style="padding: 20px;">No logs found for this bot.</p>
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
