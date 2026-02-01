@extends('ingame.layouts.main')

@section('content')
    @php /** @var \OGame\Services\PlanetService $currentPlanet */ @endphp

    <style>
        /* Charts styling */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: linear-gradient(135deg, #2a2a2a 0%, #1a1a1a 100%);
            border: 1px solid #444;
            border-radius: 8px;
            padding: 20px;
        }

        .chart-card h3 {
            margin: 0 0 15px 0;
            font-size: 16px;
            color: #f48406;
            text-align: center;
            font-weight: bold;
        }

        .chart-canvas-container {
            position: relative;
            height: 280px;
        }

        .chart-full-width {
            grid-column: 1 / -1;
        }

        .chart-full-width .chart-canvas-container {
            height: 320px;
        }

        /* Stats cards */
        .stats-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: linear-gradient(135deg, #2a2a2a 0%, #1a1a1a 100%);
            border: 1px solid #444;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }

        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 11px;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .value {
            font-size: 26px;
            font-weight: bold;
            color: #f48406;
        }

        .stat-card.success .value { color: #0f0; }
        .stat-card.danger .value { color: #f00; }
        .stat-card.info .value { color: #00ccff; }
        .stat-card.warning .value { color: #ffaa00; }

        /* Legend styling */
        .chart-legend-custom {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 15px;
            margin-top: 15px;
            font-size: 12px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .legend-color {
            width: 14px;
            height: 14px;
            border-radius: 3px;
        }

        /* Loading state */
        .chart-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 280px;
            color: #888;
            font-size: 14px;
        }

        .chart-loading::after {
            content: '';
            width: 20px;
            height: 20px;
            border: 2px solid #444;
            border-top-color: #f48406;
            border-radius: 50%;
            margin-left: 10px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Table styling */
        .table-scrollable {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #333;
            border-radius: 5px;
            background-color: #1a1a1a;
        }

        .table-scrollable table thead th {
            position: sticky;
            top: 0;
            background-color: #222;
            z-index: 10;
        }
    </style>

    <div class="maincontent">
        <div id="planet" class="shortHeader">
            <h2>Bot Statistics & Progress</h2>
        </div>

        <div id="buttonz">
            <div class="header">
                <h2>Bot Statistics & Progress</h2>
            </div>
            <div class="content">
                <div class="buddylistContent">

                    <!-- Stats Cards -->
                    <div class="stats-cards-container">
                        <div class="stat-card">
                            <h3>Total Bots</h3>
                            <div class="value">{{ $stats['total'] }}</div>
                        </div>
                        <div class="stat-card success">
                            <h3>Active Bots</h3>
                            <div class="value">{{ $stats['active'] }}</div>
                        </div>
                        <div class="stat-card danger">
                            <h3>Inactive Bots</h3>
                            <div class="value">{{ $stats['total'] - $stats['active'] }}</div>
                        </div>
                        <div class="stat-card info">
                            <h3>Total Actions</h3>
                            <div class="value">{{ array_sum($actionBreakdown) }}</div>
                        </div>
                    </div>

                    <!-- Target Type Distribution (Quick View) -->
                    <p class="box_highlight textCenter" style="margin-top: 25px;">Target Type Distribution</p>
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

                    <!-- Charts Section -->
                    <div class="charts-grid">
                        <!-- Bot Scores Comparison -->
                        <div class="chart-card chart-full-width">
                            <h3>📊 Bot Scores Comparison (Top 15)</h3>
                            <div class="chart-canvas-container">
                                <canvas id="botScoresChart"></canvas>
                            </div>
                        </div>

                        <!-- Personality Distribution -->
                        <div class="chart-card">
                            <h3>🎭 Personality Distribution</h3>
                            <div class="chart-canvas-container">
                                <canvas id="personalityChart"></canvas>
                            </div>
                        </div>

                        <!-- Action Breakdown -->
                        <div class="chart-card">
                            <h3>⚡ Action Breakdown (All Time)</h3>
                            <div class="chart-canvas-container">
                                <canvas id="actionBreakdownChart"></canvas>
                            </div>
                        </div>

                        <!-- Action History (Last 7 Days) -->
                        <div class="chart-card chart-full-width">
                            <h3>📈 Action History (Last 7 Days)</h3>
                            <div class="chart-canvas-container">
                                <canvas id="actionHistoryChart"></canvas>
                            </div>
                        </div>

                        <!-- Success Rate -->
                        <div class="chart-card chart-full-width">
                            <h3>✅ Success Rate Over Time (Last 7 Days)</h3>
                            <div class="chart-canvas-container">
                                <canvas id="successRateChart"></canvas>
                            </div>
                        </div>

                        <!-- Scores by Category (Radar) -->
                        <div class="chart-card">
                            <h3>🎯 Average Scores by Category</h3>
                            <div class="chart-canvas-container">
                                <canvas id="scoresRadarChart"></canvas>
                            </div>
                        </div>

                        <!-- Target Type Distribution -->
                        <div class="chart-card">
                            <h3>🎯 Target Type Distribution</h3>
                            <div class="chart-canvas-container">
                                <canvas id="targetTypeChart"></canvas>
                            </div>
                        </div>
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

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <script language="javascript">
        initBBCodes();
        initOverlays();

        // Default chart colors and options
        const defaultChartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        color: '#ccc',
                        font: { size: 11 }
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#999', font: { size: 10 } },
                    grid: { color: '#333' }
                },
                y: {
                    ticks: { color: '#999', font: { size: 10 } },
                    grid: { color: '#333' }
                }
            }
        };

        const personalityColors = {
            aggressive: '#c00',
            defensive: '#00c',
            economic: '#0c0',
            balanced: '#f48406'
        };

        const actionColors = {
            build: '#0c0',
            fleet: '#00ccff',
            attack: '#c00',
            research: '#ff00ff',
            trade: '#ffaa00',
            expedition: '#8800ff'
        };

        // Fetch chart data
        fetch('{{ route('admin.bots.stats-data') }}')
            .then(response => response.json())
            .then(data => {
                createBotScoresChart(data.botScores);
                createPersonalityChart(data.personalityDistribution);
                createActionBreakdownChart(data.actionBreakdown);
                createActionHistoryChart(data.actionHistory);
                createSuccessRateChart(data.successRate);
                createScoresRadarChart(data.botScores);
                createTargetTypeChart(data.targetTypeDistribution);
            })
            .catch(error => console.error('Error loading chart data:', error));

        function createBotScoresChart(botScores) {
            const sorted = [...botScores].sort((a, b) => b.general - a.general).slice(0, 15);
            const ctx = document.getElementById('botScoresChart').getContext('2d');

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: sorted.map(b => b.name),
                    datasets: [
                        {
                            label: 'Economy',
                            data: sorted.map(b => b.economy),
                            backgroundColor: '#0c0',
                            stack: 'Stack 0'
                        },
                        {
                            label: 'Research',
                            data: sorted.map(b => b.research),
                            backgroundColor: '#ff00ff',
                            stack: 'Stack 0'
                        },
                        {
                            label: 'Military',
                            data: sorted.map(b => b.military),
                            backgroundColor: '#c00',
                            stack: 'Stack 0'
                        }
                    ]
                },
                options: {
                    ...defaultChartOptions,
                    plugins: {
                        ...defaultChartOptions.plugins,
                        title: {
                            display: true,
                            text: 'Stacked: Economy + Research + Military = General Score',
                            color: '#666',
                            font: { size: 10 }
                        }
                    }
                }
            });
        }

        function createPersonalityChart(distribution) {
            const ctx = document.getElementById('personalityChart').getContext('2d');

            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Aggressive', 'Defensive', 'Economic', 'Balanced'],
                    datasets: [{
                        data: [distribution.aggressive, distribution.defensive, distribution.economic, distribution.balanced],
                        backgroundColor: [personalityColors.aggressive, personalityColors.defensive, personalityColors.economic, personalityColors.balanced],
                        borderWidth: 2,
                        borderColor: '#1a1a1a'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: '#ccc', font: { size: 11 }, padding: 10 }
                        }
                    }
                }
            });
        }

        function createActionBreakdownChart(breakdown) {
            const ctx = document.getElementById('actionBreakdownChart').getContext('2d');
            const labels = Object.keys(breakdown).map(k => k.charAt(0).toUpperCase() + k.slice(1));
            const data = Object.values(breakdown);
            const colors = labels.map(l => actionColors[l.toLowerCase()] || '#666');

            new Chart(ctx, {
                type: 'polarArea',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors.map(c => c + 'cc'),
                        borderWidth: 2,
                        borderColor: '#1a1a1a'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: '#ccc', font: { size: 10 }, padding: 8 }
                        }
                    },
                    scales: {
                        r: {
                            ticks: { color: '#999', backdropColor: 'transparent' },
                            grid: { color: '#333' }
                        }
                    }
                }
            });
        }

        function createActionHistoryChart(history) {
            const ctx = document.getElementById('actionHistoryChart').getContext('2d');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: history.dates,
                    datasets: Object.entries(history.actions).map(([action, counts]) => ({
                        label: action.charAt(0).toUpperCase() + action.slice(1),
                        data: counts,
                        borderColor: actionColors[action] || '#666',
                        backgroundColor: (actionColors[action] || '#666') + '33',
                        tension: 0.3,
                        fill: true
                    }))
                },
                options: {
                    ...defaultChartOptions,
                    plugins: {
                        ...defaultChartOptions.plugins,
                        legend: {
                            display: true,
                            position: 'top',
                            labels: { color: '#ccc', font: { size: 10 }, usePointStyle: true }
                        }
                    },
                    scales: {
                        ...defaultChartOptions.scales,
                        x: { ...defaultChartOptions.scales.x, ticks: { ...defaultChartOptions.scales.x.ticks, maxRotation: 45 } }
                    }
                }
            });
        }

        function createSuccessRateChart(successRateData) {
            const ctx = document.getElementById('successRateChart').getContext('2d');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: successRateData.map(d => d.date),
                    datasets: [{
                        label: 'Success Rate %',
                        data: successRateData.map(d => d.rate),
                        borderColor: '#0f0',
                        backgroundColor: '#0f033',
                        tension: 0.3,
                        fill: true,
                        pointRadius: 5,
                        pointHoverRadius: 8
                    }]
                },
                options: {
                    ...defaultChartOptions,
                    scales: {
                        ...defaultChartOptions.scales,
                        y: {
                            ...defaultChartOptions.scales.y,
                            min: 0,
                            max: 100,
                            ticks: {
                                ...defaultChartOptions.scales.y.ticks,
                                callback: value => value + '%'
                            }
                        }
                    }
                }
            });
        }

        function createScoresRadarChart(botScores) {
            const ctx = document.getElementById('scoresRadarChart').getContext('2d');

            const averages = {
                economy: botScores.reduce((sum, b) => sum + b.economy, 0) / (botScores.length || 1),
                research: botScores.reduce((sum, b) => sum + b.research, 0) / (botScores.length || 1),
                military: botScores.reduce((sum, b) => sum + b.military, 0) / (botScores.length || 1)
            };

            new Chart(ctx, {
                type: 'radar',
                data: {
                    labels: ['Economy', 'Research', 'Military'],
                    datasets: [{
                        label: 'Average Scores',
                        data: [averages.economy, averages.research, averages.military],
                        backgroundColor: 'rgba(244, 132, 6, 0.3)',
                        borderColor: '#f48406',
                        borderWidth: 2,
                        pointBackgroundColor: '#f48406'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        r: {
                            ticks: { color: '#999', backdropColor: 'transparent' },
                            grid: { color: '#333' },
                            angleLines: { color: '#333' },
                            pointLabels: { color: '#ccc', font: { size: 12 } }
                        }
                    }
                }
            });
        }

        function createTargetTypeChart(targetDistribution) {
            const ctx = document.getElementById('targetTypeChart').getContext('2d');

            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: ['Random', 'Weak', 'Rich', 'Similar'],
                    datasets: [{
                        data: [targetDistribution.random, targetDistribution.weak, targetDistribution.rich, targetDistribution.similar],
                        backgroundColor: ['#666', '#ff6b6b', '#ffd93d', '#6bcb77'],
                        borderWidth: 2,
                        borderColor: '#1a1a1a'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: '#ccc', font: { size: 10 }, padding: 10 }
                        }
                    }
                }
            });
        }
    </script>
@endsection
