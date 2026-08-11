<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import * as echarts from 'echarts';

const props = defineProps({
    series: { type: Object, default: () => ({ icmp: [], tcp: [] }) },
    height: { type: String, default: '220px' },
});

const chartRef = ref(null);
let chart = null;
let resizeObserver = null;

function updateChart() {
    if (!chart) return;

    const colors = { icmp: '#3b82f6', tcp: '#f59e0b' };
    const chartSeries = Object.entries(props.series)
        .filter(([, values]) => values?.some(point => point.avg !== null))
        .map(([protocol, values]) => ({
            name: `${protocol.toUpperCase()} average`,
            type: 'line',
            smooth: true,
            showSymbol: false,
            connectNulls: false,
            lineStyle: { width: 2, color: colors[protocol] },
            areaStyle: { color: colors[protocol] + '12' },
            data: values.map(point => [point.time, point.avg, point.loss, point.targets]),
        }));
    const isDark = document.documentElement.classList.contains('dark');

    chart.setOption({
        backgroundColor: 'transparent',
        animation: false,
        tooltip: {
            trigger: 'axis',
            backgroundColor: isDark ? '#1e293b' : '#fff',
            borderColor: isDark ? '#334155' : '#e2e8f0',
            textStyle: { color: isDark ? '#e2e8f0' : '#1e293b' },
            formatter: params => {
                if (!params.length) return '';
                let output = `<strong>${new Date(params[0].data[0]).toLocaleString()}</strong>`;
                for (const item of params) {
                    const average = item.data[1] === null ? '-' : `${Number(item.data[1]).toFixed(1)} ms`;
                    const loss = item.data[2] === null ? '-' : `${Number(item.data[2]).toFixed(1)}%`;
                    output += `<br/><span style="color:${item.color}">●</span> ${item.seriesName}: ${average}, loss ${loss}, ${item.data[3]} targets`;
                }
                return output;
            },
        },
        legend: {
            bottom: 0,
            textStyle: { color: isDark ? '#94a3b8' : '#64748b' },
        },
        grid: { top: 15, right: 20, bottom: 42, left: 55 },
        xAxis: {
            type: 'time',
            axisLabel: { color: isDark ? '#94a3b8' : '#64748b' },
            axisLine: { lineStyle: { color: isDark ? '#334155' : '#e2e8f0' } },
        },
        yAxis: {
            type: 'value',
            name: 'ms',
            min: 0,
            axisLabel: { color: isDark ? '#94a3b8' : '#64748b' },
            splitLine: { lineStyle: { color: isDark ? '#1e293b' : '#f1f5f9' } },
        },
        graphic: chartSeries.length ? [] : [{
            type: 'text',
            left: 'center',
            top: 'middle',
            style: { text: 'Collecting aggregate latency data…', fill: isDark ? '#94a3b8' : '#64748b' },
        }],
        series: chartSeries,
    }, true);
}

watch(() => props.series, updateChart, { deep: true });

onMounted(() => {
    chart = echarts.init(chartRef.value, null, { renderer: 'canvas' });
    updateChart();
    resizeObserver = new ResizeObserver(() => chart?.resize());
    resizeObserver.observe(chartRef.value);
});

onUnmounted(() => {
    resizeObserver?.disconnect();
    chart?.dispose();
});
</script>

<template>
    <div ref="chartRef" class="w-full" :style="{ height }" />
</template>
