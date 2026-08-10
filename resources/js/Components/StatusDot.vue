<script setup>
import { cn } from '@/lib/utils';
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: 'unknown' },
    size: { type: String, default: 'default' },
});

const colorClass = computed(() => ({
    online: 'bg-status-online',
    operational: 'bg-status-online',
    degraded: 'bg-status-degraded',
    down: 'bg-status-down',
    unknown: 'bg-status-unknown',
}[props.status] || 'bg-status-unknown'));

const sizeClass = computed(() => ({
    sm: 'h-2 w-2',
    default: 'h-2.5 w-2.5',
    lg: 'h-3 w-3',
}[props.size] || 'h-2.5 w-2.5'));

const shouldPulse = computed(() => ['down', 'degraded'].includes(props.status));
</script>

<template>
    <span
        :class="cn(
            'inline-block rounded-full',
            colorClass,
            sizeClass,
            shouldPulse && 'animate-pulse-dot'
        )"
    />
</template>
