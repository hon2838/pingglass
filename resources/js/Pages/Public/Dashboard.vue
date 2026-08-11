<script setup>
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/ui/card/Card.vue';
import CardContent from '@/Components/ui/card/CardContent.vue';
import CardHeader from '@/Components/ui/card/CardHeader.vue';
import CardTitle from '@/Components/ui/card/CardTitle.vue';
import AverageLatencyChart from '@/Components/AverageLatencyChart.vue';
import StatusDot from '@/Components/StatusDot.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { computed } from 'vue';
import { formatTime } from '@/lib/utils';

defineOptions({ layout: PublicLayout });

const props = defineProps({
    categories: Array,
    overallStatus: String,
    lastUpdated: String,
    globalLatencyMetrics: Object,
});

const overallLabel = computed(() => ({
    operational: 'All Systems Operational',
    degraded: 'Some Systems Degraded',
    down: 'System Outage',
}[props.overallStatus] || 'Status Unknown'));

function statusForTarget(target) {
    return target.state?.overall_status || 'unknown';
}

function timeAgo(dateStr) {
    if (!dateStr) return 'Never';
    const diff = Math.floor((new Date() - new Date(dateStr)) / 1000);
    if (diff < 60) return `${diff}s ago`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}
</script>

<template>
    <div class="container py-8">
        <!-- Overall Status Banner -->
        <div class="mb-8 text-center">
            <div class="inline-flex items-center gap-3 rounded-lg border bg-card px-6 py-4 shadow-sm">
                <StatusDot :status="overallStatus === 'operational' ? 'online' : overallStatus" size="lg" />
                <div class="text-left">
                    <h1 class="text-xl font-semibold">{{ overallLabel }}</h1>
                    <p class="text-sm text-muted-foreground">
                        Last updated {{ formatTime(lastUpdated) }}
                    </p>
                </div>
            </div>
        </div>

        <Card class="mb-8">
            <CardHeader>
                <CardTitle class="text-base">All Targets — Average Latency (24 hours)</CardTitle>
            </CardHeader>
            <CardContent>
                <AverageLatencyChart :series="globalLatencyMetrics" />
            </CardContent>
        </Card>

        <div class="grid gap-3 mb-8 sm:grid-cols-2 lg:grid-cols-4 text-sm">
            <div class="rounded-lg border bg-card p-3">
                <div class="flex items-center gap-2 font-medium"><StatusDot status="online" /> Online</div>
                <p class="mt-1 text-xs text-muted-foreground">All enabled probes are reachable and below their thresholds.</p>
            </div>
            <div class="rounded-lg border bg-card p-3">
                <div class="flex items-center gap-2 font-medium"><StatusDot status="degraded" /> Degraded</div>
                <p class="mt-1 text-xs text-muted-foreground">High loss/latency, mixed probe results, or a failure awaiting confirmation.</p>
            </div>
            <div class="rounded-lg border bg-card p-3">
                <div class="flex items-center gap-2 font-medium"><StatusDot status="down" /> Down</div>
                <p class="mt-1 text-xs text-muted-foreground">Every enabled probe failed for the configured confirmation cycles.</p>
            </div>
            <div class="rounded-lg border bg-card p-3">
                <div class="flex items-center gap-2 font-medium"><StatusDot status="unknown" /> Unknown</div>
                <p class="mt-1 text-xs text-muted-foreground">No trustworthy result is available or monitoring data is stale.</p>
            </div>
        </div>

        <!-- Categories -->
        <div v-for="category in categories" :key="category.id" class="mb-8">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold">{{ category.name }}</h2>
                <Link
                    :href="route('monitor.category', category.slug)"
                    class="text-sm text-muted-foreground hover:text-foreground transition-colors"
                >
                    View all {{ category.total_targets }} &rarr;
                </Link>
            </div>

            <Card class="mb-4">
                <CardContent class="p-4">
                    <AverageLatencyChart :series="category.latency_metrics" height="180px" />
                </CardContent>
            </Card>

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="target in category.targets"
                    :key="target.id"
                    :href="route('target.show', target.slug)"
                    class="group"
                >
                    <Card class="transition-all hover:shadow-md hover:border-primary/20">
                        <CardContent class="p-4">
                            <div class="flex items-start justify-between mb-3">
                                <div>
                                    <h3 class="font-medium group-hover:text-primary transition-colors">
                                        {{ target.name }}
                                    </h3>
                                    <p
                                        v-if="target.show_host_publicly && target.host"
                                        class="text-xs text-muted-foreground font-mono mt-0.5"
                                    >
                                        {{ target.host }}
                                    </p>
                                </div>
                                <StatusBadge :status="statusForTarget(target)" />
                            </div>

                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div v-if="target.icmp_enabled">
                                    <div class="text-xs text-muted-foreground mb-0.5">ICMP</div>
                                    <div class="flex items-center gap-1.5">
                                        <StatusDot :status="target.state?.icmp_status || 'unknown'" size="sm" />
                                        <span v-if="target.state?.icmp_latency_ms">
                                            {{ target.state.icmp_latency_ms.toFixed(1) }} ms
                                        </span>
                                        <span v-else class="text-muted-foreground">-</span>
                                    </div>
                                    <div v-if="target.state?.icmp_loss_percent !== null && target.state?.icmp_loss_percent !== undefined" class="text-xs text-muted-foreground">
                                        Loss: {{ target.state.icmp_loss_percent.toFixed(1) }}%
                                    </div>
                                </div>
                                <div v-if="target.tcp_enabled">
                                    <div class="text-xs text-muted-foreground mb-0.5">TCP :{{ target.tcp_port }}</div>
                                    <div class="flex items-center gap-1.5">
                                        <StatusDot :status="target.state?.tcp_status || 'unknown'" size="sm" />
                                        <span v-if="target.state?.tcp_latency_ms">
                                            {{ target.state.tcp_latency_ms.toFixed(1) }} ms
                                        </span>
                                        <span v-else class="text-muted-foreground">-</span>
                                    </div>
                                    <div v-if="target.state?.tcp_loss_percent !== null && target.state?.tcp_loss_percent !== undefined" class="text-xs text-muted-foreground">
                                        Loss: {{ target.state.tcp_loss_percent.toFixed(1) }}%
                                    </div>
                                </div>
                            </div>

                            <div v-if="target.state?.last_measured_at" class="mt-3 text-xs text-muted-foreground">
                                Last checked {{ timeAgo(target.state.last_measured_at) }}
                            </div>
                        </CardContent>
                    </Card>
                </Link>
            </div>
        </div>

        <div v-if="categories.length === 0" class="text-center py-16 text-muted-foreground">
            <p class="text-lg">No monitoring targets configured yet.</p>
            <p class="text-sm mt-1">Check back later or contact the administrator.</p>
        </div>
    </div>
</template>
