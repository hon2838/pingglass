<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/ui/card/Card.vue';
import CardHeader from '@/Components/ui/card/CardHeader.vue';
import CardTitle from '@/Components/ui/card/CardTitle.vue';
import CardContent from '@/Components/ui/card/CardContent.vue';
import CardFooter from '@/Components/ui/card/CardFooter.vue';
import Button from '@/Components/ui/button/Button.vue';
import Input from '@/Components/ui/input/Input.vue';
import Label from '@/Components/ui/label/Label.vue';
import Badge from '@/Components/ui/badge/Badge.vue';
import Checkbox from '@/Components/ui/checkbox/Checkbox.vue';
import Table from '@/Components/ui/table/Table.vue';
import TableHeader from '@/Components/ui/table/TableHeader.vue';
import TableBody from '@/Components/ui/table/TableBody.vue';
import TableRow from '@/Components/ui/table/TableRow.vue';
import TableHead from '@/Components/ui/table/TableHead.vue';
import TableCell from '@/Components/ui/table/TableCell.vue';
import Separator from '@/Components/ui/separator/Separator.vue';
import StatusDot from '@/Components/StatusDot.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    targets: Object,
    categories: Array,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const categoryId = ref(props.filters?.category_id || '');
const selected = ref([]);
const showImport = ref(false);

const allSelected = computed(() =>
    props.targets.data.length > 0 && selected.value.length === props.targets.data.length
);

function toggleSelectAll() {
    if (allSelected.value) {
        selected.value = [];
    } else {
        selected.value = props.targets.data.map(t => t.id);
    }
}

function toggleSelect(id) {
    const idx = selected.value.indexOf(id);
    if (idx === -1) {
        selected.value.push(id);
    } else {
        selected.value.splice(idx, 1);
    }
}

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

function batchDelete() {
    if (!selected.value.length) return;
    if (!confirm(`Delete ${selected.value.length} targets? This will also delete all their measurements.`)) return;

    router.delete(route('admin.targets.batch-destroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = []; },
    });
}

// Import form
const importForm = useForm({
    file: null,
    category_id: '',
    is_public: true,
    is_enabled: true,
    icmp_enabled: true,
    tcp_enabled: false,
    show_host_publicly: false,
});

function handleFileChange(e) {
    importForm.file = e.target.files[0];
}

function submitImport() {
    importForm.post(route('admin.targets.import'), {
        onSuccess: () => {
            showImport.value = false;
            importForm.reset();
        },
    });
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Targets</h1>
            <div class="flex items-center gap-2">
                <Button variant="outline" @click="showImport = !showImport">
                    {{ showImport ? 'Close Import' : 'Import CSV' }}
                </Button>
                <Link :href="route('admin.targets.create')">
                    <Button>Add Target</Button>
                </Link>
            </div>
        </div>

        <FlashMessages class="mb-4" />

        <!-- Import Section -->
        <Card v-if="showImport" class="mb-6">
            <CardHeader>
                <CardTitle class="text-base">Import Targets from CSV</CardTitle>
            </CardHeader>
            <form @submit.prevent="submitImport">
                <CardContent class="space-y-4">
                    <p class="text-sm text-muted-foreground">
                        CSV format: <code class="text-xs bg-muted px-1 py-0.5 rounded">name,host,tcp_port,slug</code>
                        — one target per line. First line can be a header (it will be skipped if it starts with "name").
                        Port and slug are optional. If slug is omitted, it is auto-generated from the name.
                    </p>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <Label for="import-file">CSV File</Label>
                            <input id="import-file" type="file" accept=".csv,.txt" @change="handleFileChange"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm file:border-0 file:bg-transparent file:text-sm file:font-medium" />
                            <p v-if="importForm.errors.file" class="text-sm text-destructive">{{ importForm.errors.file }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="import-category">Category</Label>
                            <select id="import-category" v-model="importForm.category_id"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                <option value="" disabled>Select category</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                            <p v-if="importForm.errors.category_id" class="text-sm text-destructive">{{ importForm.errors.category_id }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-6">
                        <div class="flex items-center gap-2">
                            <Checkbox id="import-icmp" v-model="importForm.icmp_enabled" />
                            <Label for="import-icmp">ICMP enabled</Label>
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox id="import-tcp" v-model="importForm.tcp_enabled" />
                            <Label for="import-tcp">TCP enabled</Label>
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox id="import-public" v-model="importForm.is_public" />
                            <Label for="import-public">Public</Label>
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox id="import-enabled" v-model="importForm.is_enabled" />
                            <Label for="import-enabled">Enabled</Label>
                        </div>
                    </div>
                </CardContent>
                <CardFooter>
                    <Button type="submit" :disabled="importForm.processing || !importForm.file || !importForm.category_id">
                        {{ importForm.processing ? 'Importing...' : 'Import' }}
                    </Button>
                </CardFooter>
            </form>
        </Card>

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
            <div class="flex-1" />
            <Button
                v-if="selected.length > 0"
                variant="destructive"
                @click="batchDelete"
            >
                Delete Selected ({{ selected.length }})
            </Button>
        </div>

        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-10">
                                <Checkbox :model-value="allSelected" @update:model-value="toggleSelectAll" />
                            </TableHead>
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
                        <TableRow v-for="target in targets.data" :key="target.id" :class="selected.includes(target.id) && 'bg-muted/50'">
                            <TableCell>
                                <Checkbox
                                    :model-value="selected.includes(target.id)"
                                    @update:model-value="toggleSelect(target.id)"
                                />
                            </TableCell>
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
                            <TableCell colspan="9" class="text-center py-8 text-muted-foreground">
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
