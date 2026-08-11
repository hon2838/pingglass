<script setup>
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/ui/card/Card.vue';
import CardContent from '@/Components/ui/card/CardContent.vue';
import Input from '@/Components/ui/input/Input.vue';
import StatusDot from '@/Components/StatusDot.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AverageLatencyChart from '@/Components/AverageLatencyChart.vue';
import { ref, watch } from 'vue';

defineOptions({ layout: PublicLayout });

const props = defineProps({
    category: Object,
    targets: Object,
    filters: Object,
    latencyMetrics: Object,
});

const search = ref(props.filters?.search || '');

let searchTimeout = null;
watch(search, (val) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('monitor.category', props.category.slug), { search: val }, {
            preserveState: true,
            preserveScroll: true,
        });
    }, 300);
});

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
        <div class="mb-6">
            <nav class="flex items-center gap-2 text-sm text-muted-foreground mb-2">
                <Link :href="route('home')" class="hover:text-foreground">Dashboard</Link>
                <span>/</span>
                <span class="text-foreground">{{ category.name }}</span>
            </nav>
            <h1 class="text-2xl font-bold">{{ category.name }}</h1>
            <p v-if="category.description" class="text-muted-foreground mt-1">{{ category.description }}</p>
            <p class="text-sm text-muted-foreground mt-1">{{ targets.total }} targets</p>
        </div>

        <Card class="mb-6">
            <CardContent class="p-4">
                <div class="font-medium mb-2">All Targets — Average Latency (24 hours)</div>
                <AverageLatencyChart :series="latencyMetrics" />
            </CardContent>
        </Card>

        <!-- Search -->
        <div class="mb-6 max-w-md">
            <Input
                v-model="search"
                placeholder="Search targets by name or host..."
            />
        </div>

        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="target in targets.data"
                :key="target.id"
                :href="route('target.show', target.slug)"
                class="group"
            >
                <Card class="transition-all hover:shadow-md hover:border-primary/20">
                    <CardContent class="p-4">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <h3 class="font-medium group-hover:text-primary transition-colors">{{ target.name }}</h3>
                                <p v-if="target.show_host_publicly && target.host" class="text-xs text-muted-foreground font-mono mt-0.5">{{ target.host }}</p>
                            </div>
                            <StatusBadge :status="statusForTarget(target)" />
                        </div>

                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div v-if="target.icmp_enabled">
                                <div class="text-xs text-muted-foreground mb-0.5">ICMP</div>
                                <div class="flex items-center gap-1.5">
                                    <StatusDot :status="target.state?.icmp_status || 'unknown'" size="sm" />
                                    <span v-if="target.state?.icmp_latency_ms">{{ target.state.icmp_latency_ms.toFixed(1) }} ms</span>
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
                                    <span v-if="target.state?.tcp_latency_ms">{{ target.state.tcp_latency_ms.toFixed(1) }} ms</span>
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

        <!-- Pagination -->
        <div v-if="targets.last_page > 1" class="flex justify-center gap-2 mt-8">
            <Link
                v-for="(link, idx) in targets.links"
                :key="idx"
                :href="link.url"
                :class="[
                    'px-3 py-1.5 rounded-md text-sm transition-colors',
                    link.active ? 'bg-primary text-primary-foreground' : 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
                    !link.url && 'opacity-50 pointer-events-none'
                ]"
                v-html="link.label"
            />
        </div>

        <div v-if="targets.data.length === 0" class="text-center py-16 text-muted-foreground">
            <p v-if="search">No targets matching "{{ search }}".</p>
            <p v-else>No targets in this category.</p>
        </div>
    </div>
</template>
