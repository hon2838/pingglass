<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/ui/card/Card.vue';
import CardHeader from '@/Components/ui/card/CardHeader.vue';
import CardTitle from '@/Components/ui/card/CardTitle.vue';
import CardContent from '@/Components/ui/card/CardContent.vue';
import Badge from '@/Components/ui/badge/Badge.vue';
import StatusDot from '@/Components/StatusDot.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    checks: Object,
});

const statusVariant = {
    ok: 'success',
    warning: 'warning',
    error: 'destructive',
    unknown: 'secondary',
};

const statusLabel = {
    ok: 'Healthy',
    warning: 'Warning',
    error: 'Error',
    unknown: 'Unknown',
};

const checkLabels = {
    database: { name: 'Database', icon: 'db' },
    redis: { name: 'Redis', icon: 'redis' },
    queue: { name: 'Queue', icon: 'queue' },
    failed_jobs: { name: 'Failed Jobs', icon: 'alert' },
    fping: { name: 'fping', icon: 'tool' },
    scheduler: { name: 'Scheduler', icon: 'clock' },
    last_completed_cycle: { name: 'Last Completed Cycle', icon: 'cycle' },
    probe_config: { name: 'Probe Configuration', icon: 'settings' },
};
</script>

<template>
    <div>
        <h1 class="text-2xl font-bold mb-6">System Health</h1>

        <div class="grid gap-4 md:grid-cols-2">
            <Card v-for="(check, key) in checks" :key="key">
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">
                        {{ checkLabels[key]?.name || key }}
                    </CardTitle>
                    <Badge :variant="statusVariant[check.status] || 'secondary'" class="text-xs">
                        {{ statusLabel[check.status] || check.status }}
                    </Badge>
                </CardHeader>
                <CardContent>
                    <div class="flex items-start gap-2">
                        <StatusDot
                            :status="check.status === 'ok' ? 'online' : check.status === 'warning' ? 'degraded' : check.status === 'error' ? 'down' : 'unknown'"
                            class="mt-1.5"
                        />
                        <p class="text-sm text-muted-foreground">{{ check.message }}</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
