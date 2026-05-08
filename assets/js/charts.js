/**
 * Apotek Zam Zam - Chart.js Configurations & Helpers
 */

// Global Chart.js defaults
if (window.Chart) {
    Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#64748B';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.padding = 16;
    Chart.defaults.elements.line.tension = 0.4;
    Chart.defaults.elements.point.radius = 3;
    Chart.defaults.elements.point.hoverRadius = 6;
}

const CHART_COLORS = {
    primary: '#1D9E75',
    primaryLight: 'rgba(29, 158, 117, 0.15)',
    secondary: '#7F77DD',
    secondaryLight: 'rgba(127, 119, 221, 0.15)',
    danger: '#EF4444',
    dangerLight: 'rgba(239, 68, 68, 0.15)',
    warning: '#F59E0B',
    warningLight: 'rgba(245, 158, 11, 0.15)',
    blue: '#3B82F6',
    blueLight: 'rgba(59, 130, 246, 0.15)',
    gray: '#94A3B8',
    grayLight: 'rgba(148, 163, 184, 0.15)',
};

const CHART_TOOLTIP = {
    backgroundColor: 'rgba(26, 26, 46, 0.9)',
    titleFont: { weight: '600' },
    padding: 12,
    borderColor: 'rgba(255,255,255,0.1)',
    borderWidth: 1,
    cornerRadius: 8,
};

/**
 * Create a line chart
 */
function createLineChart(canvasId, labels, datasets, options = {}) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', align: 'end' },
            tooltip: CHART_TOOLTIP
        },
        scales: {
            x: {
                grid: { display: false },
                border: { display: false },
                ticks: { padding: 8 }
            },
            y: {
                grid: { color: 'rgba(0,0,0,0.04)' },
                border: { display: false },
                ticks: { padding: 8 },
                beginAtZero: true
            }
        }
    };

    return new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: deepMerge(defaultOptions, options)
    });
}

/**
 * Create a bar chart
 */
function createBarChart(canvasId, labels, datasets, options = {}) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', align: 'end' },
            tooltip: CHART_TOOLTIP
        },
        scales: {
            x: {
                grid: { display: false },
                border: { display: false },
                ticks: { padding: 8 }
            },
            y: {
                grid: { color: 'rgba(0,0,0,0.04)' },
                border: { display: false },
                beginAtZero: true,
                ticks: { padding: 8 }
            }
        }
    };

    return new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets },
        options: deepMerge(defaultOptions, options)
    });
}

/**
 * Create a doughnut chart
 */
function createDoughnutChart(canvasId, labels, data, colors, options = {}) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: { padding: 16, usePointStyle: true, pointStyleWidth: 10 }
            },
            tooltip: CHART_TOOLTIP
        }
    };

    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: colors,
                borderWidth: 0,
                hoverOffset: 4,
            }]
        },
        options: deepMerge(defaultOptions, options)
    });
}

/**
 * Create a prediction chart with historical + forecast lines
 */
function createPredictionChart(canvasId, historicalData, predictionData) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const allLabels = [...historicalData.labels, ...predictionData.labels];

    // Historical line: pad nulls for future period
    const historicalValues = [
        ...historicalData.values,
        ...new Array(predictionData.values.length).fill(null)
    ];

    // Prediction line: pad nulls for past period, overlap at last historical point
    const predictionValues = [
        ...new Array(historicalData.values.length - 1).fill(null),
        historicalData.values[historicalData.values.length - 1],
        ...predictionData.values
    ];

    const canvasCtx = ctx.getContext('2d');
    
    // Create stunning gradients
    const gradAktual = canvasCtx.createLinearGradient(0, 0, 0, 360);
    gradAktual.addColorStop(0, 'rgba(59, 130, 246, 0.4)'); // Blue 500
    gradAktual.addColorStop(1, 'rgba(59, 130, 246, 0.0)');

    const gradPrediksi = canvasCtx.createLinearGradient(0, 0, 0, 360);
    gradPrediksi.addColorStop(0, 'rgba(217, 70, 239, 0.4)'); // Fuchsia 500
    gradPrediksi.addColorStop(1, 'rgba(217, 70, 239, 0.0)');

    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: allLabels,
            datasets: [
                {
                    label: 'Data Aktual',
                    data: historicalValues,
                    borderColor: '#3b82f6', // Blue 500
                    backgroundColor: gradAktual,
                    fill: true,
                    borderWidth: 3,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                    tension: 0.4
                },
                {
                    label: 'Prediksi LSTM',
                    data: predictionValues,
                    borderColor: '#d946ef', // Fuchsia 500
                    backgroundColor: gradPrediksi,
                    fill: true,
                    borderWidth: 3,
                    borderDash: [5, 5],
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    pointStyle: 'circle',
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', align: 'end' },
                tooltip: {
                    ...CHART_TOOLTIP,
                    callbacks: {
                        label: function(context) {
                            if (context.parsed.y === null) return null;
                            return context.dataset.label + ': ' + Math.round(context.parsed.y) + ' unit';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { padding: 8, maxTicksLimit: 12 }
                },
                y: {
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    border: { display: false },
                    beginAtZero: true,
                    ticks: { padding: 8 }
                }
            }
        }
    });
}

/**
 * Deep merge utility for chart options
 */
function deepMerge(target, source) {
    const output = { ...target };
    for (const key in source) {
        if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
            output[key] = deepMerge(target[key] || {}, source[key]);
        } else {
            output[key] = source[key];
        }
    }
    return output;
}

/**
 * Create a Loss per Epoch chart (training loss + validation loss)
 * Bukti konvergensi model LSTM
 */
function createLossChart(canvasId, lossData, valLossData) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const epochs = lossData.map((_, i) => 'Epoch ' + (i + 1));

    const canvasCtx = ctx.getContext('2d');
    
    // Instead of a hardcoded 360px gradient, use a solid transparent fill to avoid clipping issues
    const bgLoss = 'rgba(16, 185, 129, 0.15)'; 

    const datasets = [
        {
            label: 'Training Loss',
            data: lossData,
            borderColor: '#10b981', // Emerald
            backgroundColor: bgLoss,
            fill: 'origin',
            borderWidth: 3,
            pointRadius: 0,
            pointHoverRadius: 6,
            tension: 0.4,
        }
    ];

    if (valLossData && valLossData.length > 0) {
        const bgValLoss = 'rgba(245, 158, 11, 0.15)'; 

        datasets.push({
            label: 'Validation Loss',
            data: valLossData,
            borderColor: '#f59e0b', // Amber
            backgroundColor: bgValLoss,
            fill: 'origin',
            borderWidth: 3,
            borderDash: [5, 5],
            pointRadius: 0,
            pointHoverRadius: 6,
            tension: 0.4,
        });
    }

    return new Chart(ctx, {
        type: 'line',
        data: { labels: epochs, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', align: 'end' },
                tooltip: {
                    ...CHART_TOOLTIP,
                    callbacks: {
                        label: function(context) {
                            if (context.parsed.y === null) return null;
                            return context.dataset.label + ': ' + context.parsed.y.toFixed(6);
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: true, color: '#e2e8f0' },
                    title: {
                        display: true,
                        text: 'Epoch (Iterasi ke-)',
                        font: { size: 12, weight: '600' },
                        color: '#64748B'
                    },
                    ticks: {
                        padding: 8,
                        maxTicksLimit: 10,
                        autoSkip: true,
                        maxRotation: 45
                    }
                },
                y: {
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    border: { display: false },
                    beginAtZero: true,
                    ticks: {
                        padding: 8,
                        callback: function(value) {
                            return value.toFixed(4);
                        }
                    },
                    title: {
                        display: true,
                        text: 'Loss (MSE)',
                        font: { size: 11, weight: '600' },
                        color: '#94A3B8'
                    }
                }
            }
        }
    });
}

/**
 * Create an Error/Residual chart per week (bar chart)
 * Analisis residual: error per minggu di test set
 */
function createErrorChart(canvasId, labels, errors) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const colors = errors.map(e => e >= 0 ? 'rgba(52, 211, 153, 1)' : 'rgba(248, 113, 113, 1)');
    const bgColors = errors.map(e => e >= 0 ? 'rgba(52, 211, 153, 0.8)' : 'rgba(248, 113, 113, 0.8)');

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Error (Aktual - Prediksi)',
                data: errors,
                backgroundColor: bgColors,
                borderColor: colors,
                borderWidth: 1,
                borderRadius: 2,
                maxBarThickness: 16,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', align: 'end' },
                tooltip: {
                    ...CHART_TOOLTIP,
                    callbacks: {
                        label: function(context) {
                            const val = context.parsed.y;
                            const label = val >= 0 ? 'Under-predict' : 'Over-predict';
                            return `Error: ${val.toFixed(2)} (${label})`;
                        }
                    }
                },
                annotation: undefined
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: true, color: '#e2e8f0' },
                    ticks: { padding: 8, maxRotation: 45, maxTicksLimit: 12 }
                },
                y: {
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    border: { display: true, color: '#e2e8f0' },
                    ticks: { padding: 8 },
                    title: {
                        display: true,
                        text: 'Error (unit)',
                        font: { size: 11, weight: '600' },
                        color: '#94A3B8'
                    }
                }
            }
        }
    });
}
