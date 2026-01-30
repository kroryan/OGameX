@extends('ingame.layouts.main')

@section('content')
    @php /** @var \OGame\Services\PlanetService $currentPlanet */ @endphp

    <div class="maincontent">
        <div id="planet" class="shortHeader">
            <h2>Bot Statistics</h2>
        </div>

        <div id="buttonz">
            <div class="header">
                <h2>Bot Statistics</h2>
            </div>
            <div class="content">
                <div class="buddylistContent">

                    <!-- General Statistics -->
                    <p class="box_highlight textCenter">General Statistics</p>
                    <div class="group bborder" style="margin-bottom: 20px;">
                        <table class="table" style="width: 100%; max-width: 600px; margin: 0 auto;">
                            <tr>
                                <td style="width: 70%;">Total Bots:</td>
                                <td style="text-align: right;"><strong>{{ $stats['total'] }}</strong></td>
                            </tr>
                            <tr>
                                <td>Active Bots:</td>
                                <td style="text-align: right; color: #0f0;"><strong>{{ $stats['active'] }}</strong></td>
                            </tr>
                            <tr>
                                <td>Total Actions Executed:</td>
                                <td style="text-align: right;"><strong>{{ array_sum($actionBreakdown) }}</strong></td>
                            </tr>
                        </table>
                    </div>

                    <!-- Personality Distribution -->
                    <p class="box_highlight textCenter">Personality Distribution</p>
                    <div class="group bborder" style="margin-bottom: 20px;">
                        <table class="table" style="width: 100%; max-width: 600px; margin: 0 auto;">
                            @foreach ($stats['by_personality'] as $personality => $count)
                                <tr>
                                    <td style="width: 70%;">{{ ucfirst($personality) }}:</td>
                                    <td style="text-align: right;">
                                        <strong>{{ $count }}</strong>
                                        @if ($stats['total'] > 0)
                                            ({{ round($count / $stats['total'] * 100, 1) }}%)
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </div>

                    <!-- Target Type Distribution -->
                    <p class="box_highlight textCenter">Target Type Distribution</p>
                    <div class="group bborder" style="margin-bottom: 20px;">
                        <table class="table" style="width: 100%; max-width: 600px; margin: 0 auto;">
                            @foreach ($stats['by_target_type'] as $targetType => $count)
                                <tr>
                                    <td style="width: 70%;">{{ ucfirst($targetType) }}:</td>
                                    <td style="text-align: right;">
                                        <strong>{{ $count }}</strong>
                                        @if ($stats['total'] > 0)
                                            ({{ round($count / $stats['total'] * 100, 1) }}%)
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </div>

                    <!-- Action Breakdown -->
                    <p class="box_highlight textCenter">Action Breakdown (All Time)</p>
                    <div class="group bborder" style="margin-bottom: 20px;">
                        <table class="table" style="width: 100%; max-width: 600px; margin: 0 auto;">
                            @foreach ($actionBreakdown as $action => $count)
                                <tr>
                                    <td style="width: 70%;">{{ ucfirst($action) }}:</td>
                                    <td style="text-align: right;"><strong>{{ $count }}</strong></td>
                                </tr>
                            @endforeach
                        </table>
                    </div>

                    <!-- Recent Activity -->
                    <p class="box_highlight textCenter">Recent Activity (Last 100)</p>
                    <div class="group bborder" style="margin-bottom: 20px;">
                        @if ($recentLogs->count() > 0)
                            <table class="table" style="width: 100%;">
                                <thead>
                                    <tr style="background-color: #222;">
                                        <th style="width: 50px;">ID</th>
                                        <th style="width: 80px;">Time</th>
                                        <th style="width: 150px;">Bot</th>
                                        <th style="width: 80px;">Action</th>
                                        <th>Description</th>
                                        <th style="width: 60px;">Result</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($recentLogs as $log)
                                        <tr style="border-bottom: 1px solid #333;">
                                            <td>{{ $log->id }}</td>
                                            <td style="font-size: 0.9em;">{{ $log->created_at->diffForHumans() }}</td>
                                            <td>{{ $log->bot->name }}</td>
                                            <td>
                                                <span class="badge" style="background-color: {{ $log->action_type === 'attack' ? '#c00' : ($log->action_type === 'build' ? '#0c0' : '#666') }}; padding: 2px 6px; border-radius: 3px; font-size: 0.8em;">
                                                    {{ ucfirst($log->action_type) }}
                                                </span>
                                            </td>
                                            <td style="font-size: 0.9em;">{{ $log->action_description }}</td>
                                            <td>
                                                @if ($log->result === 'success')
                                                    <span style="color: #0f0;">✓</span>
                                                @else
                                                    <span style="color: #f00;">✗</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <p class="textCenter" style="padding: 20px;">No activity recorded yet.</p>
                        @endif
                    </div>

                    <!-- Back Button -->
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="{{ route('admin.bots.index') }}" class="btn_blue">Back to Bots</a>
                    </div>

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
