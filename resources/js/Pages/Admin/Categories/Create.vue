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
import Textarea from '@/Components/ui/textarea/Textarea.vue';
import Checkbox from '@/Components/ui/checkbox/Checkbox.vue';
import { useForm, Link } from '@inertiajs/vue3';

defineOptions({ layout: AdminLayout });

const form = useForm({
    name: '',
    slug: '',
    description: '',
    is_public: true,
    is_enabled: true,
    sort_order: 0,
});

function autoSlug() {
    form.slug = form.name
        .toLowerCase()
        .replace(/[^a-z0-9\u4e00-\u9fff]+/g, '-')
        .replace(/^-|-$/g, '');
}

function submit() {
    form.post(route('admin.categories.store'));
}
</script>

<template>
    <div class="max-w-2xl">
        <div class="flex items-center gap-3 mb-6">
            <Link :href="route('admin.categories.index')" class="text-muted-foreground hover:text-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                    <path d="m15 18-6-6 6-6"/>
                </svg>
            </Link>
            <h1 class="text-2xl font-bold">Create Category</h1>
        </div>

        <form @submit.prevent="submit">
            <Card>
                <CardContent class="p-6 space-y-6">
                    <div class="space-y-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" @blur="autoSlug" placeholder="e.g. 上海" />
                        <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="slug">Slug</Label>
                        <Input id="slug" v-model="form.slug" placeholder="e.g. shanghai" />
                        <p v-if="form.errors.slug" class="text-sm text-destructive">{{ form.errors.slug }}</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="description">Description</Label>
                        <Textarea id="description" v-model="form.description" placeholder="Optional description" />
                    </div>

                    <div class="space-y-2">
                        <Label for="sort_order">Sort Order</Label>
                        <Input id="sort_order" v-model="form.sort_order" type="number" min="0" />
                    </div>

                    <div class="flex items-center gap-6">
                        <div class="flex items-center gap-2">
                            <Checkbox id="is_public" v-model="form.is_public" />
                            <Label for="is_public">Public</Label>
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox id="is_enabled" v-model="form.is_enabled" />
                            <Label for="is_enabled">Enabled</Label>
                        </div>
                    </div>
                </CardContent>
                <CardFooter class="flex justify-between">
                    <Link :href="route('admin.categories.index')">
                        <Button variant="outline" type="button">Cancel</Button>
                    </Link>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Creating...' : 'Create Category' }}
                    </Button>
                </CardFooter>
            </Card>
        </form>
    </div>
</template>
