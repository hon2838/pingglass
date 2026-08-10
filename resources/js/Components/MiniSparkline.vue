<script setup>
import { onMounted, ref, watch, onUnmounted } from 'vue';
import * as echarts from 'echarts';

const props = defineProps({
    data: { type: Array, default: () => [] },
    color: { type: String, default: '#3b82f6' },
    height: { type: String, default: '40px' },
    width: { type: String, default: '120px' },
});

const chartRef = ref(null);
let chart = null;

function initChart() {
    if (!chartRef.value || !props.data.length) return;
    chart = echarts.init(chartRef.value, null, { renderer: 'canvas' });

    chart.setOption({
        backgroundColor: 'transparent',
        grid: { top: 2, right: 0, bottom: 2, left: 0 },
        xAxis: { type: 'category', show: false, data: props.data.map((_, i) => i) },
        yAxis: { type: 'value', show: false },
        series: [{
            type: 'line',
            data: props.data,
            smooth: true,
            showSymbol: false,
            lineStyle: { width: 1.5, color: props.color },
            areaStyle: { color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                { offset: 0, color: props.color + '40' },
                { offset: 1, color: props.color + '05' },
            ])},
        }],
    });
}

watch(() => props.data, () => {
    if (chart) {
        chart.setOption({ series: [{ data: props.data }] });
    }
}, { deep: true });

onMounted(initChart);
onUnmounted(() => { chart?.dispose(); chart = null; });
</script>

<template>
    <div ref="chartRef" :style="{ height, width }" />
</template>
