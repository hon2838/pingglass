<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/ui/card/Card.vue';
import CardContent from '@/Components/ui/card/CardContent.vue';
import Button from '@/Components/ui/button/Button.vue';
import Badge from '@/Components/ui/badge/Badge.vue';
import Table from '@/Components/ui/table/Table.vue';
import TableHeader from '@/Components/ui/table/TableHeader.vue';
import TableBody from '@/Components/ui/table/TableBody.vue';
import TableRow from '@/Components/ui/table/TableRow.vue';
import TableHead from '@/Components/ui/table/TableHead.vue';
import TableCell from '@/Components/ui/table/TableCell.vue';
import StatusDot from '@/Components/StatusDot.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { formatDateTime } from '@/lib/utils';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    incidents: Object,
    currentStatus: String,
});

const status = ref(props.currentStatus);

watch(status, (val) => {
    router.get(route('admin.incidents.index'), { status: val }, { preserveState: true });
});

function closeIncident(id) {
    router.post(route('admin.incidents.close', id));
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Incidents</h1>
            <div class="flex gap-2">
                <Button
                    v-for="s in ['open', 'closed', 'all']"
                    :key="s"
                    :variant="status === s ? 'default' : 'outline'"
                    size="sm"
                    @click="status = s"
                >
                    {{ s.charAt(0).toUpperCase() + s.slice(1) }}
                </Button>
            </div>
        </div>

        <FlashMessages class="mb-4" />

        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Target</TableHead>
                            <TableHead>Type</TableHead>
                            <TableHead>Protocol</TableHead>
                            <TableHead>Started</TableHead>
                            <TableHead>Duration</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="incident in incidents.data" :key="incident.id">
                            <TableCell class="font-medium">
                                <Link :href="route('admin.targets.edit', incident.target_id)" class="hover:underline">
                                    {{ incident.target_name }}
                                </Link>
                            </TableCell>
                            <TableCell>
                                <Badge variant="outline" class="text-xs">
                                    {{ incident.type.replace('_', ' ') }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-sm">
                                {{ incident.protocol?.toUpperCase() || 'Both' }}
                            </TableCell>
                            <TableCell class="text-sm">
                                {{ formatDateTime(incident.started_at) }}
                            </TableCell>
                            <TableCell class="text-sm font-mono">
                                {{ incident.duration_human }}
                            </TableCell>
                            <TableCell>
                                <div class="flex items-center gap-1.5">
                                    <StatusDot :status="incident.status === 'open' ? 'down' : 'online'" size="sm" />
                                    <span class="text-sm">{{ incident.status }}</span>
                                </div>
                            </TableCell>
                            <TableCell class="text-right">
                                <Button
                                    v-if="incident.status === 'open'"
                                    variant="outline"
                                    size="sm"
                                    @click="closeIncident(incident.id)"
                                >
                                    Close
                                </Button>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="incidents.data.length === 0">
                            <TableCell colspan="7" class="text-center py-8 text-muted-foreground">
                                No incidents found.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <!-- Pagination -->
        <div v-if="incidents.last_page > 1" class="flex justify-center gap-2 mt-4">
            <Link
                v-for="link in incidents.links"
                :key="link.label"
                :href="link.url"
                :class="[
                    'px-3 py-1 rounded-md text-sm',
                    link.active ? 'bg-primary text-primary-foreground' : 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
                    !link.url && 'opacity-50 pointer-events-none'
                ]"
                v-html="link.label"
            />
        </div>
    </div>
</template>
