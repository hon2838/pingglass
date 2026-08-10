<script setup>
import Badge from '@/Components/ui/badge/Badge.vue';
import StatusDot from '@/Components/StatusDot.vue';

defineProps({
    status: { type: String, default: 'unknown' },
});

const variantMap = {
    online: 'success',
    operational: 'success',
    degraded: 'warning',
    down: 'destructive',
    unknown: 'secondary',
};

const labelMap = {
    online: 'Online',
    operational: 'Operational',
    degraded: 'Degraded',
    down: 'Down',
    unknown: 'Unknown',
};
</script>

<template>
    <Badge :variant="variantMap[status] || 'secondary'" class="gap-1.5">
        <StatusDot :status="status" size="sm" />
        {{ labelMap[status] || 'Unknown' }}
    </Badge>
</template>
