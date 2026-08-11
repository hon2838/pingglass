<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/ui/card/Card.vue';
import CardHeader from '@/Components/ui/card/CardHeader.vue';
import CardTitle from '@/Components/ui/card/CardTitle.vue';
import CardContent from '@/Components/ui/card/CardContent.vue';
import Badge from '@/Components/ui/badge/Badge.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { Link } from '@inertiajs/vue3';
import { formatDateTime, formatTime } from '@/lib/utils';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    stats: Object,
    lastCycle: Object,
    recentIncidents: Array,
    queueSize: Number,
});
</script>

<template>
    <div>
        <h1 class="text-2xl font-bold mb-6">Dashboard</h1>

        <!-- Stats Grid -->
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4 mb-8">
            <Card>
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">Total Targets</CardTitle>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4 text-muted-foreground">
                        <circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>
                    </svg>
                </CardHeader>
                <CardContent>
                    <div class="text-3xl font-bold">{{ stats.targets }}</div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">Online</CardTitle>
                    <StatusDot status="online" size="lg" />
                </CardHeader>
                <CardContent>
                    <div class="text-3xl font-bold text-status-online">{{ stats.online }}</div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">Degraded</CardTitle>
                    <StatusDot status="degraded" size="lg" />
                </CardHeader>
                <CardContent>
                    <div class="text-3xl font-bold text-status-degraded">{{ stats.degraded }}</div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">Down</CardTitle>
                    <StatusDot status="down" size="lg" />
                </CardHeader>
                <CardContent>
                    <div class="text-3xl font-bold text-status-down">{{ stats.down }}</div>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 md:grid-cols-2 mb-8">
            <!-- Probe Health -->
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Probe Health</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm">Last Cycle</span>
                        <span v-if="lastCycle" class="text-sm font-mono">
                            {{ formatTime(lastCycle.started_at) }}
                        </span>
                        <span v-else class="text-sm text-muted-foreground">Never</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm">Status</span>
                        <Badge :variant="lastCycle?.status === 'completed' ? 'success' : 'secondary'">
                            {{ lastCycle?.status || 'N/A' }}
                        </Badge>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm">Duration</span>
                        <span class="text-sm font-mono">{{ lastCycle?.duration || '-' }}s</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm">Queue Size</span>
                        <Badge :variant="queueSize > 10 ? 'warning' : 'secondary'">
                            {{ queueSize }}
                        </Badge>
                    </div>
                </CardContent>
            </Card>

            <!-- Quick Links -->
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Quick Actions</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2">
                    <Link
                        :href="route('admin.categories.create')"
                        class="flex items-center gap-2 rounded-md border p-3 text-sm hover:bg-accent transition-colors"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                            <path d="M5 12h14m-7-7v14"/>
                        </svg>
                        Add Category
                    </Link>
                    <Link
                        :href="route('admin.targets.create')"
                        class="flex items-center gap-2 rounded-md border p-3 text-sm hover:bg-accent transition-colors"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                            <path d="M5 12h14m-7-7v14"/>
                        </svg>
                        Add Target
                    </Link>
                    <Link
                        :href="route('admin.settings.index')"
                        class="flex items-center gap-2 rounded-md border p-3 text-sm hover:bg-accent transition-colors"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                            <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                        Monitoring Settings
                    </Link>
                </CardContent>
            </Card>
        </div>

        <!-- Recent Incidents -->
        <Card>
            <CardHeader>
                <div class="flex items-center justify-between">
                    <CardTitle class="text-base">Recent Incidents</CardTitle>
                    <Link :href="route('admin.incidents.index')" class="text-sm text-muted-foreground hover:text-foreground">
                        View all &rarr;
                    </Link>
                </div>
            </CardHeader>
            <CardContent>
                <div v-if="recentIncidents.length === 0" class="text-center py-8 text-muted-foreground text-sm">
                    No open incidents
                </div>
                <div v-else class="space-y-3">
                    <div
                        v-for="incident in recentIncidents"
                        :key="incident.id"
                        class="flex items-center gap-3 p-3 rounded-lg border"
                    >
                        <StatusDot status="down" />
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-sm">{{ incident.target_name }}</span>
                                <Badge variant="destructive" class="text-xs">{{ incident.type.replace('_', ' ') }}</Badge>
                            </div>
                            <div class="text-xs text-muted-foreground mt-0.5">
                                {{ formatDateTime(incident.started_at) }} ({{ incident.duration_human }})
                            </div>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
