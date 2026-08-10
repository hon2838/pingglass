<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/ui/card/Card.vue';
import CardHeader from '@/Components/ui/card/CardHeader.vue';
import CardTitle from '@/Components/ui/card/CardTitle.vue';
import CardContent from '@/Components/ui/card/CardContent.vue';
import Button from '@/Components/ui/button/Button.vue';
import Badge from '@/Components/ui/badge/Badge.vue';
import Table from '@/Components/ui/table/Table.vue';
import TableHeader from '@/Components/ui/table/TableHeader.vue';
import TableBody from '@/Components/ui/table/TableBody.vue';
import TableRow from '@/Components/ui/table/TableRow.vue';
import TableHead from '@/Components/ui/table/TableHead.vue';
import TableCell from '@/Components/ui/table/TableCell.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { Link, router } from '@inertiajs/vue3';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    categories: Array,
});

function toggle(field, id) {
    router.post(route('admin.categories.toggle', id), { field });
}

function destroy(id) {
    if (confirm('Are you sure you want to delete this category?')) {
        router.delete(route('admin.categories.destroy', id));
    }
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Categories</h1>
            <Link :href="route('admin.categories.create')">
                <Button>Add Category</Button>
            </Link>
        </div>

        <FlashMessages class="mb-4" />

        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Slug</TableHead>
                            <TableHead class="text-center">Targets</TableHead>
                            <TableHead class="text-center">Public</TableHead>
                            <TableHead class="text-center">Enabled</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="cat in categories" :key="cat.id">
                            <TableCell class="font-medium">{{ cat.name }}</TableCell>
                            <TableCell class="font-mono text-sm text-muted-foreground">{{ cat.slug }}</TableCell>
                            <TableCell class="text-center">
                                <Badge variant="secondary">{{ cat.targets_count }}</Badge>
                            </TableCell>
                            <TableCell class="text-center">
                                <button @click="toggle('is_public', cat.id)" class="cursor-pointer">
                                    <Badge :variant="cat.is_public ? 'success' : 'secondary'">
                                        {{ cat.is_public ? 'Yes' : 'No' }}
                                    </Badge>
                                </button>
                            </TableCell>
                            <TableCell class="text-center">
                                <button @click="toggle('is_enabled', cat.id)" class="cursor-pointer">
                                    <Badge :variant="cat.is_enabled ? 'success' : 'secondary'">
                                        {{ cat.is_enabled ? 'Yes' : 'No' }}
                                    </Badge>
                                </button>
                            </TableCell>
                            <TableCell class="text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <Link :href="route('admin.categories.edit', cat.id)">
                                        <Button variant="ghost" size="sm">Edit</Button>
                                    </Link>
                                    <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="destroy(cat.id)">
                                        Delete
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="categories.length === 0">
                            <TableCell colspan="6" class="text-center py-8 text-muted-foreground">
                                No categories yet. Create one to get started.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
