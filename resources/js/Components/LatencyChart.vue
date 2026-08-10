<script setup>
import { onMounted, ref, watch, onUnmounted } from 'vue';
import * as echarts from 'echarts';

const props = defineProps({
    series: { type: Object, default: () => ({}) },
    range: { type: String, default: '24h' },
    height: { type: String, default: '350px' },
});

const chartRef = ref(null);
let chart = null;

function initChart() {
    if (!chartRef.value) return;
    chart = echarts.init(chartRef.value, null, { renderer: 'canvas' });
    updateChart();
}

function updateChart() {
    if (!chart) return;

    const seriesData = [];
    const legendData = [];

    const colors = {
        icmp: '#3b82f6',
        tcp: '#f59e0b',
    };

    for (const [protocol, data] of Object.entries(props.series)) {
        if (!data || data.length === 0) continue;
        const color = colors[protocol] || '#6366f1';
        const pUpper = protocol.toUpperCase();

        legendData.push(`${pUpper} Median`);

        // P10-P90 band (outer, lighter)
        // Lower bound: P10 (invisible, stacked base)
        seriesData.push({
            name: `${pUpper} P10-P90`,
            type: 'line',
            stack: `${protocol}_p90_band`,
            smooth: true,
            showSymbol: false,
            lineStyle: { opacity: 0 },
            areaStyle: { opacity: 0 },
            data: data.map(d => [d.time, d.p10 ?? 0]),
            color: 'transparent',
            tooltip: { show: false },
        });

        // Delta: P90 - P10 (visible fill)
        seriesData.push({
            name: `${pUpper} P10-P90`,
            type: 'line',
            stack: `${protocol}_p90_band`,
            smooth: true,
            showSymbol: false,
            lineStyle: { opacity: 0 },
            areaStyle: {
                color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: color + '18' },
                    { offset: 1, color: color + '18' },
                ]),
                origin: 'auto',
            },
            data: data.map(d => {
                const p10 = d.p10 ?? 0;
                const p90 = d.p90 ?? 0;
                return [d.time, Math.max(0, p90 - p10)];
            }),
            color: color + '20',
            tooltip: { show: false },
        });

        // P25-P75 band (inner, medium)
        seriesData.push({
            name: `${pUpper} P25-P75`,
            type: 'line',
            stack: `${protocol}_p75_band`,
            smooth: true,
            showSymbol: false,
            lineStyle: { opacity: 0 },
            areaStyle: { opacity: 0 },
            data: data.map(d => [d.time, d.p25 ?? 0]),
            color: 'transparent',
            tooltip: { show: false },
        });

        seriesData.push({
            name: `${pUpper} P25-P75`,
            type: 'line',
            stack: `${protocol}_p75_band`,
            smooth: true,
            showSymbol: false,
            lineStyle: { opacity: 0 },
            areaStyle: {
                color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: color + '30' },
                    { offset: 1, color: color + '30' },
                ]),
                origin: 'auto',
            },
            data: data.map(d => {
                const p25 = d.p25 ?? 0;
                const p75 = d.p75 ?? 0;
                return [d.time, Math.max(0, p75 - p25)];
            }),
            color: color + '40',
            tooltip: { show: false },
        });

        // Median line (on top)
        seriesData.push({
            name: `${pUpper} Median`,
            type: 'line',
            smooth: true,
            showSymbol: false,
            lineStyle: { width: 2, color },
            areaStyle: { opacity: 0 },
            data: data.map(d => [d.time, d.median]),
            color,
        });
    }

    const isDark = document.documentElement.classList.contains('dark');

    chart.setOption({
        backgroundColor: 'transparent',
        tooltip: {
            trigger: 'axis',
            backgroundColor: isDark ? '#1e293b' : '#fff',
            borderColor: isDark ? '#334155' : '#e2e8f0',
            textStyle: { color: isDark ? '#e2e8f0' : '#1e293b' },
            formatter: (params) => {
                if (!params.length) return '';
                const time = new Date(params[0].data[0]).toLocaleString();
                let html = `<div style="font-size:12px"><strong>${time}</strong><br/>`;

                // Group by protocol and show stats
                const protocols = {};
                params.forEach(p => {
                    if (p.seriesName.includes('P10') || p.seriesName.includes('P25') || p.seriesName.includes('P75')) return;
                    // This is the median line - find the original data point
                    const idx = p.dataIndex;
                    const raw = Object.entries(props.series);
                    for (const [proto, data] of raw) {
                        if (!data || !data[idx]) continue;
                        const d = data[idx];
                        if (p.seriesName.includes(proto.toUpperCase()) && p.seriesName.includes('Median')) {
                            html += `<div style="margin:4px 0">`;
                            html += `<span style="color:${colors[proto]}">\u25CF</span> <strong>${proto.toUpperCase()}</strong><br/>`;
                            html += `&nbsp;&nbsp;Median: ${d.median?.toFixed(1) ?? '-'} ms<br/>`;
                            html += `&nbsp;&nbsp;P10-P90: ${d.p10?.toFixed(1) ?? '-'} - ${d.p90?.toFixed(1) ?? '-'} ms<br/>`;
                            html += `&nbsp;&nbsp;P25-P75: ${d.p25?.toFixed(1) ?? '-'} - ${d.p75?.toFixed(1) ?? '-'} ms<br/>`;
                            html += `&nbsp;&nbsp;Loss: ${d.loss?.toFixed(1) ?? '0'}%`;
                            html += `</div>`;
                        }
                    }
                });

                return html + '</div>';
            },
        },
        legend: {
            data: legendData,
            bottom: 0,
            textStyle: { color: isDark ? '#94a3b8' : '#64748b' },
        },
        grid: { top: 20, right: 20, bottom: 40, left: 60 },
        xAxis: {
            type: 'time',
            axisLine: { lineStyle: { color: isDark ? '#334155' : '#e2e8f0' } },
            axisLabel: { color: isDark ? '#94a3b8' : '#64748b' },
        },
        yAxis: {
            type: 'value',
            name: 'ms',
            min: 0,
            axisLine: { show: false },
            splitLine: { lineStyle: { color: isDark ? '#1e293b' : '#f1f5f9' } },
            axisLabel: { color: isDark ? '#94a3b8' : '#64748b' },
        },
        series: seriesData,
    }, true);
}

watch(() => props.series, updateChart, { deep: true });

onMounted(() => {
    initChart();
    window.addEventListener('resize', () => chart?.resize());
});

onUnmounted(() => {
    chart?.dispose();
    chart = null;
});
</script>

<template>
    <div ref="chartRef" :style="{ height }" />
</template>
