<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/ui/card/Card.vue';
import CardContent from '@/Components/ui/card/CardContent.vue';
import Button from '@/Components/ui/button/Button.vue';
import Input from '@/Components/ui/input/Input.vue';
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

defineOptions({ layout: AdminLayout });

const props = defineProps({
    targets: Object,
    categories: Array,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const categoryId = ref(props.filters?.category_id || '');

let searchTimeout = null;
watch(search, (val) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('admin.targets.index'), { search: val, category_id: categoryId.value }, { preserveState: true });
    }, 300);
});

watch(categoryId, (val) => {
    router.get(route('admin.targets.index'), { search: search.value, category_id: val }, { preserveState: true });
});

function toggle(field, id) {
    router.post(route('admin.targets.toggle', id), { field });
}

function destroy(id) {
    if (confirm('Are you sure? This will delete all measurements for this target.')) {
        router.delete(route('admin.targets.destroy', id));
    }
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Targets</h1>
            <Link :href="route('admin.targets.create')">
                <Button>Add Target</Button>
            </Link>
        </div>

        <FlashMessages class="mb-4" />

        <!-- Filters -->
        <div class="flex gap-4 mb-4">
            <div class="flex-1 max-w-sm">
                <Input v-model="search" placeholder="Search targets..." />
            </div>
            <select
                v-model="categoryId"
                class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">All Categories</option>
                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
            </select>
        </div>

        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Target</TableHead>
                            <TableHead>Category</TableHead>
                            <TableHead>Host</TableHead>
                            <TableHead class="text-center">ICMP</TableHead>
                            <TableHead class="text-center">TCP</TableHead>
                            <TableHead class="text-center">Status</TableHead>
                            <TableHead class="text-center">Public</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="target in targets.data" :key="target.id">
                            <TableCell>
                                <div class="font-medium">{{ target.name }}</div>
                                <div class="text-xs text-muted-foreground font-mono">{{ target.slug }}</div>
                            </TableCell>
                            <TableCell class="text-sm">{{ target.category?.name }}</TableCell>
                            <TableCell class="font-mono text-sm">
                                {{ target.show_host_publicly ? target.host : '***' }}
                            </TableCell>
                            <TableCell class="text-center">
                                <Badge :variant="target.icmp_enabled ? 'success' : 'secondary'" class="text-xs">
                                    {{ target.icmp_enabled ? 'On' : 'Off' }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-center">
                                <Badge :variant="target.tcp_enabled ? 'success' : 'secondary'" class="text-xs">
                                    {{ target.tcp_enabled ? target.tcp_port : 'Off' }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-center">
                                <StatusDot :status="target.state?.overall_status || 'unknown'" />
                            </TableCell>
                            <TableCell class="text-center">
                                <button @click="toggle('is_public', target.id)" class="cursor-pointer">
                                    <Badge :variant="target.is_public ? 'success' : 'secondary'" class="text-xs">
                                        {{ target.is_public ? 'Yes' : 'No' }}
                                    </Badge>
                                </button>
                            </TableCell>
                            <TableCell class="text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <Link :href="route('admin.targets.edit', target.id)">
                                        <Button variant="ghost" size="sm">Edit</Button>
                                    </Link>
                                    <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="destroy(target.id)">
                                        Delete
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="targets.data.length === 0">
                            <TableCell colspan="8" class="text-center py-8 text-muted-foreground">
                                No targets found.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <!-- Pagination -->
        <div v-if="targets.last_page > 1" class="flex justify-center gap-2 mt-4">
            <Link
                v-for="link in targets.links"
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
