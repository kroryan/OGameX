@extends('ingame.layouts.main')

@section('content')
    @php /** @var \OGame\Services\PlanetService $currentPlanet */ @endphp

    <style>
        .progress-page {
            padding-bottom: 20px;
        }

        .progress-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .range-controls {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .range-controls select {
            background: #1f1f1f;
            border: 1px solid #444;
            color: #ddd;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 12px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: 18px;
            margin-top: 20px;
        }

        .chart-card {
            background: linear-gradient(135deg, #1f1f1f 0%, #141414 100%);
            border: 1px solid #333;
            border-radius: 8px;
            padding: 16px;
        }

        .chart-card h3 {
            margin: 0 0 12px 0;
            font-size: 15px;
            color: #f48406;
            text-align: left;
            font-weight: bold;
        }

        .chart-canvas-container {
            position: relative;
            height: 320px;
        }

        .chart-full-width {
            grid-column: 1 / -1;
        }

        .chart-legend-note {
            margin-top: 10px;
            font-size: 11px;
            color: #999;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .legend-swatch {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .legend-dot {
            width: 14px;
            height: 3px;
            border-radius: 2px;
            display: inline-block;
        }

        .legend-dot.bot {
            background: #ff7a18;
            border: 1px dashed rgba(255, 122, 24, 0.6);
        }

        .legend-dot.player {
            background: #1fb6ff;
        }

        .chart-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 320px;
            color: #888;
            font-size: 13px;
        }

        .chart-loading::after {
            content: '';
            width: 18px;
            height: 18px;
            border: 2px solid #444;
            border-top-color: #f48406;
            border-radius: 50%;
            margin-left: 10px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .chart-disclaimer {
            font-size: 11px;
            color: #777;
            margin-top: 6px;
        }
    </style>

    <div class="maincontent progress-page">
        <div id="planet" class="shortHeader">
            <h2>Bots & Players Progress</h2>
        </div>

        <div id="buttonz">
            <div class="header">
                <h2>Bots & Players Progress</h2>
            </div>
            <div class="content">
                <div class="buddylistContent">
                    <div class="progress-header">
                        <div>
                            <p class="box_highlight textCenter" style="margin: 0;">Updated every 30 minutes</p>
                        </div>
                        <div class="range-controls">
                            <label for="progressRange" class="styled textBeefy" style="margin: 0;">Range</label>
                            <select id="progressRange">
                                <option value="24h">Last 24h</option>
                                <option value="7d" selected>Last 7 days</option>
                                <option value="30d">Last 30 days</option>
                            </select>
                            <button type="button" class="btn_blue" id="refreshCharts">Refresh</button>
                        </div>
                    </div>

                    <div class="chart-legend-note">
                        <span class="legend-swatch"><span class="legend-dot bot"></span>Bots (dashed)</span>
                        <span class="legend-swatch"><span class="legend-dot player"></span>Players (solid)</span>
                        <span>Hover a line to see the player or bot name.</span>
                    </div>

                    <div class="charts-grid">
                        <div class="chart-card chart-full-width">
                            <h3>Population (General Score)</h3>
                            <div class="chart-canvas-container">
                                <canvas id="progressGeneralChart"></canvas>
                            </div>
                        </div>

                        <div class="chart-card chart-full-width">
                            <h3>Construction (Economy Score)</h3>
                            <div class="chart-canvas-container">
                                <canvas id="progressEconomyChart"></canvas>
                            </div>
                        </div>

                        <div class="chart-card chart-full-width">
                            <h3>Technology (Research Score)</h3>
                            <div class="chart-canvas-container">
                                <canvas id="progressResearchChart"></canvas>
                            </div>
                        </div>

                        <div class="chart-card chart-full-width">
                            <h3>Fleet (Military Score)</h3>
                            <div class="chart-canvas-container">
                                <canvas id="progressMilitaryChart"></canvas>
                            </div>
                        </div>

                        <div class="chart-card chart-full-width">
                            <h3>Wars (Total Battle Count)</h3>
                            <div class="chart-canvas-container">
                                <canvas id="progressWarsChart"></canvas>
                            </div>
                            <div class="chart-disclaimer">
                                For players, wars count uses total battle reports where the player was the defender.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="footer"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <script language="javascript">
        initBBCodes();
        initOverlays();

        const charts = {};
        const colorCache = new Map();

        const chartMeta = [
            { id: 'progressGeneralChart', metric: 'general' },
            { id: 'progressEconomyChart', metric: 'economy' },
            { id: 'progressResearchChart', metric: 'research' },
            { id: 'progressMilitaryChart', metric: 'military' },
            { id: 'progressWarsChart', metric: 'wars' }
        ];

        const baseChartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            normalized: true,
            interaction: { mode: 'nearest', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y ?? 0}`
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#999', maxRotation: 45, font: { size: 10 } },
                    grid: { color: '#333' }
                },
                y: {
                    ticks: { color: '#999', font: { size: 10 } },
                    grid: { color: '#333' }
                }
            },
            elements: {
                point: { radius: 0 }
            }
        };

        function hashString(value) {
            let hash = 0;
            for (let i = 0; i < value.length; i++) {
                hash = ((hash << 5) - hash) + value.charCodeAt(i);
                hash |= 0;
            }
            return Math.abs(hash);
        }

        function colorFor(label, isBot) {
            const key = `${label}-${isBot ? 'bot' : 'player'}`;
            if (colorCache.has(key)) {
                return colorCache.get(key);
            }
            const hash = hashString(label);
            const hue = hash % 360;
            const saturation = isBot ? 80 : 60;
            const lightness = isBot ? 55 : 45;
            const color = {
                stroke: `hsl(${hue}, ${saturation}%, ${lightness}%)`,
                fill: `hsla(${hue}, ${saturation}%, ${lightness}%, 0.12)`
            };
            colorCache.set(key, color);
            return color;
        }

        function buildDatasets(rawDatasets) {
            return rawDatasets.map((dataset) => {
                const color = colorFor(dataset.label, dataset.is_bot);
                return {
                    label: dataset.label,
                    data: dataset.data,
                    borderColor: color.stroke,
                    backgroundColor: color.fill,
                    borderWidth: dataset.is_bot ? 1.6 : 1.1,
                    borderDash: dataset.is_bot ? [4, 2] : [],
                    tension: 0.15,
                    spanGaps: true
                };
            });
        }

        function fetchChart(metric, canvasId) {
            const range = document.getElementById('progressRange').value;
            return fetch(`{{ route('bots.progress.data') }}?metric=${metric}&range=${range}`)
                .then((response) => response.json())
                .then((data) => {
                    const ctx = document.getElementById(canvasId).getContext('2d');
                    const datasets = buildDatasets(data.datasets);

                    if (charts[canvasId]) {
                        charts[canvasId].data.labels = data.labels;
                        charts[canvasId].data.datasets = datasets;
                        charts[canvasId].update();
                        return;
                    }

                    charts[canvasId] = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.labels,
                            datasets: datasets
                        },
                        options: baseChartOptions
                    });
                })
                .catch((error) => console.error('Progress chart error:', error));
        }

        function refreshAllCharts() {
            chartMeta.forEach((meta) => fetchChart(meta.metric, meta.id));
        }

        document.getElementById('refreshCharts').addEventListener('click', refreshAllCharts);
        document.getElementById('progressRange').addEventListener('change', refreshAllCharts);

        refreshAllCharts();
    </script>
@endsection
