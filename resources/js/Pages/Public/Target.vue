<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/ui/card/Card.vue';
import CardHeader from '@/Components/ui/card/CardHeader.vue';
import CardTitle from '@/Components/ui/card/CardTitle.vue';
import CardContent from '@/Components/ui/card/CardContent.vue';
import Badge from '@/Components/ui/badge/Badge.vue';
import Button from '@/Components/ui/button/Button.vue';
import Separator from '@/Components/ui/separator/Separator.vue';
import StatusDot from '@/Components/StatusDot.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import LatencyChart from '@/Components/LatencyChart.vue';
import LossChart from '@/Components/LossChart.vue';
import { formatDateTime } from '@/lib/utils';

defineOptions({ layout: PublicLayout });

const props = defineProps({
    target: Object,
    incidents: Array,
});

const range = ref('24h');
const loading = ref(false);
const metrics = ref({});
const showIcmp = ref(true);
const showTcp = ref(true);

const ranges = ['1h', '6h', '24h', '7d', '30d', '6m', '1y'];

// Fetch all data, then filter on the client side for visibility
const allMetrics = ref({});

async function fetchMetrics() {
    loading.value = true;
    try {
        const { data } = await axios.get(route('api.public.targets.metrics', props.target.slug), {
            params: { range: range.value.toLowerCase(), protocol: 'all' },
        });
        allMetrics.value = data.series || {};
        applyVisibility();
    } catch (e) {
        console.error('Failed to fetch metrics:', e);
    } finally {
        loading.value = false;
    }
}

function applyVisibility() {
    const filtered = {};
    if (showIcmp.value && allMetrics.value.icmp) {
        filtered.icmp = allMetrics.value.icmp;
    }
    if (showTcp.value && allMetrics.value.tcp) {
        filtered.tcp = allMetrics.value.tcp;
    }
    metrics.value = filtered;
}

function downloadCsv() {
    const protocols = [];
    if (showIcmp.value) protocols.push('icmp');
    if (showTcp.value) protocols.push('tcp');
    if (protocols.length === 0) return;
    const proto = protocols.length === 2 ? 'all' : protocols[0];
    const url = route('api.public.targets.csv', props.target.slug)
        + `?range=${range.value.toLowerCase()}&protocol=${proto}`;
    window.open(url, '_blank');
}

const canDownloadCsv = computed(() => showIcmp.value || showTcp.value);

onMounted(fetchMetrics);

watch([showIcmp, showTcp], applyVisibility);

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
    <div class="container py-8 max-w-5xl">
        <!-- Breadcrumb -->
        <nav class="flex items-center gap-2 text-sm text-muted-foreground mb-6">
            <Link :href="route('home')" class="hover:text-foreground">Dashboard</Link>
            <span>/</span>
            <Link :href="route('monitor.category', target.category_slug)" class="hover:text-foreground">{{ target.category_name }}</Link>
            <span>/</span>
            <span class="text-foreground">{{ target.name }}</span>
        </nav>

        <!-- Header -->
        <div class="flex items-start justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold">{{ target.name }}</h1>
                <p class="text-muted-foreground mt-1">
                    <span v-if="target.show_host_publicly && target.host" class="font-mono text-sm">{{ target.host }}</span>
                    <span v-else-if="target.host">Host hidden</span>
                </p>
            </div>
            <StatusBadge :status="target.state?.overall_status || 'unknown'" />
        </div>

        <!-- Stats Cards -->
        <div class="grid gap-4 md:grid-cols-2 mb-8">
            <Card v-if="target.icmp_enabled">
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground flex items-center gap-2">
                        <StatusDot :status="target.state?.icmp_status || 'unknown'" />
                        ICMP
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div>
                            <div class="text-2xl font-bold font-mono">
                                {{ target.state?.icmp_latency_ms?.toFixed(1) || '-' }}
                            </div>
                            <div class="text-xs text-muted-foreground">Median ms</div>
                        </div>
                        <div>
                            <div class="text-2xl font-bold font-mono">
                                {{ target.state?.icmp_loss_percent?.toFixed(1) || '0.0' }}%
                            </div>
                            <div class="text-xs text-muted-foreground">Packet Loss</div>
                        </div>
                        <div>
                            <div class="text-2xl font-bold font-mono text-xs">
                                {{ target.state?.last_measured_at ? timeAgo(target.state.last_measured_at) : '-' }}
                            </div>
                            <div class="text-xs text-muted-foreground">Last Check</div>
                        </div>
                    </div>
                    <div v-if="target.icmp_stats" class="grid grid-cols-5 gap-2 text-center mt-3 pt-3 border-t text-xs">
                        <div>
                            <div class="font-mono font-medium">{{ target.icmp_stats.avg_ms?.toFixed(1) || '-' }}</div>
                            <div class="text-muted-foreground">Avg</div>
                        </div>
                        <div>
                            <div class="font-mono font-medium">{{ target.icmp_stats.min_ms?.toFixed(1) || '-' }}</div>
                            <div class="text-muted-foreground">Min</div>
                        </div>
                        <div>
                            <div class="font-mono font-medium">{{ target.icmp_stats.max_ms?.toFixed(1) || '-' }}</div>
                            <div class="text-muted-foreground">Max</div>
                        </div>
                        <div>
                            <div class="font-mono font-medium">{{ target.icmp_stats.stddev_ms?.toFixed(1) || '-' }}</div>
                            <div class="text-muted-foreground">Variation</div>
                        </div>
                        <div>
                            <div class="font-mono font-medium">{{ target.icmp_stats.p95_ms?.toFixed(1) || '-' }}</div>
                            <div class="text-muted-foreground">P95</div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="target.tcp_enabled">
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground flex items-center gap-2">
                        <StatusDot :status="target.state?.tcp_status || 'unknown'" />
                        TCP :{{ target.tcp_port }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div>
                            <div class="text-2xl font-bold font-mono">
                                {{ target.state?.tcp_latency_ms?.toFixed(1) || '-' }}
                            </div>
                            <div class="text-xs text-muted-foreground">Median ms</div>
                        </div>
                        <div>
                            <div class="text-2xl font-bold font-mono">
                                {{ target.state?.tcp_loss_percent?.toFixed(1) || '0.0' }}%
                            </div>
                            <div class="text-xs text-muted-foreground">Packet Loss</div>
                        </div>
                        <div>
                            <div class="text-2xl font-bold font-mono text-xs">
                                {{ target.state?.last_measured_at ? timeAgo(target.state.last_measured_at) : '-' }}
                            </div>
                            <div class="text-xs text-muted-foreground">Last Check</div>
                        </div>
                    </div>
                    <div v-if="target.tcp_stats" class="grid grid-cols-5 gap-2 text-center mt-3 pt-3 border-t text-xs">
                        <div>
                            <div class="font-mono font-medium">{{ target.tcp_stats.avg_ms?.toFixed(1) || '-' }}</div>
                            <div class="text-muted-foreground">Avg</div>
                        </div>
                        <div>
                            <div class="font-mono font-medium">{{ target.tcp_stats.min_ms?.toFixed(1) || '-' }}</div>
                            <div class="text-muted-foreground">Min</div>
                        </div>
                        <div>
                            <div class="font-mono font-medium">{{ target.tcp_stats.max_ms?.toFixed(1) || '-' }}</div>
                            <div class="text-muted-foreground">Max</div>
                        </div>
                        <div>
                            <div class="font-mono font-medium">{{ target.tcp_stats.stddev_ms?.toFixed(1) || '-' }}</div>
                            <div class="text-muted-foreground">Variation</div>
                        </div>
                        <div>
                            <div class="font-mono font-medium">{{ target.tcp_stats.p95_ms?.toFixed(1) || '-' }}</div>
                            <div class="text-muted-foreground">P95</div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Latency Chart -->
        <Card class="mb-8">
            <CardHeader>
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <CardTitle>Latency</CardTitle>
                    <div class="flex items-center gap-2 flex-wrap">
                        <div class="flex gap-1">
                            <Button
                                v-for="r in ranges"
                                :key="r"
                                :variant="range === r ? 'default' : 'outline'"
                                size="sm"
                                @click="range = r; fetchMetrics()"
                            >
                                {{ r }}
                            </Button>
                        </div>
                        <div v-if="target.icmp_enabled && target.tcp_enabled" class="flex items-center gap-2 ml-2 pl-2 border-l">
                            <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                <input type="checkbox" v-model="showIcmp" class="rounded border-input" />
                                <span class="text-blue-500">ICMP</span>
                            </label>
                            <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                <input type="checkbox" v-model="showTcp" class="rounded border-input" />
                                <span class="text-amber-500">TCP</span>
                            </label>
                        </div>
                        <Button variant="outline" size="sm" @click="downloadCsv" :disabled="!canDownloadCsv">
                            CSV
                        </Button>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div v-if="loading" class="flex items-center justify-center h-[350px] text-muted-foreground">
                    Loading...
                </div>
                <LatencyChart v-else :series="metrics" :range="range" />
            </CardContent>
        </Card>

        <!-- Packet Loss Chart -->
        <Card class="mb-8">
            <CardHeader>
                <CardTitle>Packet Loss</CardTitle>
            </CardHeader>
            <CardContent>
                <div v-if="loading" class="flex items-center justify-center h-[200px] text-muted-foreground">
                    Loading...
                </div>
                <LossChart v-else :series="metrics" />
            </CardContent>
        </Card>

        <!-- Incidents -->
        <Card>
            <CardHeader>
                <CardTitle>Recent Incidents</CardTitle>
            </CardHeader>
            <CardContent>
                <div v-if="incidents.length === 0" class="text-center py-8 text-muted-foreground">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-8 w-8 mx-auto mb-2 opacity-50">
                        <path d="m9 12 2 2 4-4"/>
                        <circle cx="12" cy="12" r="10"/>
                    </svg>
                    <p>No recent incidents</p>
                </div>
                <div v-else class="space-y-4">
                    <div
                        v-for="incident in incidents"
                        :key="incident.id"
                        class="flex items-start gap-4 p-3 rounded-lg border"
                    >
                        <StatusDot :status="incident.status === 'open' ? 'down' : 'online'" size="sm" class="mt-1" />
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <Badge :variant="incident.status === 'open' ? 'destructive' : 'secondary'" class="text-xs">
                                    {{ incident.type.replace('_', ' ') }}
                                </Badge>
                                <span v-if="incident.protocol" class="text-xs text-muted-foreground">
                                    {{ incident.protocol.toUpperCase() }}
                                </span>
                                <Badge v-if="incident.status === 'open'" variant="destructive" class="text-xs">Active</Badge>
                            </div>
                            <div class="text-sm text-muted-foreground mt-1">
                                {{ formatDateTime(incident.started_at) }}
                                <template v-if="incident.ended_at">
                                    &rarr; {{ formatDateTime(incident.ended_at) }}
                                </template>
                                <span class="ml-2 text-xs">({{ incident.duration_human }})</span>
                            </div>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
