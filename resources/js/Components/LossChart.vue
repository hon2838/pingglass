<script setup>
import { onMounted, ref, watch, onUnmounted } from 'vue';
import * as echarts from 'echarts';
import { formatChartTime, formatDateTime } from '@/lib/utils';

const props = defineProps({
    series: { type: Object, default: () => ({}) },
    height: { type: String, default: '200px' },
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
    const colors = { icmp: '#ef4444', tcp: '#f59e0b' };

    for (const [protocol, data] of Object.entries(props.series)) {
        if (!data || data.length === 0) continue;
        seriesData.push({
            name: protocol.toUpperCase(),
            type: 'bar',
            data: data.map(d => [d.time, d.loss]),
            itemStyle: { color: colors[protocol] || '#ef4444', opacity: 0.8 },
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
                let html = `<div style="font-size:12px"><strong>${formatDateTime(params[0].data[0])}</strong><br/>`;
                params.forEach(p => {
                    html += `<span style="color:${p.color}">\u25CF</span> ${p.seriesName}: ${p.data[1].toFixed(1)}%<br/>`;
                });
                return html + '</div>';
            },
        },
        grid: { top: 10, right: 20, bottom: 30, left: 50 },
        xAxis: {
            type: 'time',
            axisLine: { lineStyle: { color: isDark ? '#334155' : '#e2e8f0' } },
            axisLabel: {
                color: isDark ? '#94a3b8' : '#64748b',
                formatter: value => formatChartTime(value),
            },
        },
        yAxis: {
            type: 'value',
            name: '%',
            max: 100,
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
