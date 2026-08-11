<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/ui/card/Card.vue';
import CardHeader from '@/Components/ui/card/CardHeader.vue';
import CardTitle from '@/Components/ui/card/CardTitle.vue';
import CardContent from '@/Components/ui/card/CardContent.vue';
import Button from '@/Components/ui/button/Button.vue';
import Input from '@/Components/ui/input/Input.vue';
import Label from '@/Components/ui/label/Label.vue';
import Textarea from '@/Components/ui/textarea/Textarea.vue';
import Checkbox from '@/Components/ui/checkbox/Checkbox.vue';
import Separator from '@/Components/ui/separator/Separator.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { useForm, Link } from '@inertiajs/vue3';
import axios from 'axios';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    target: Object,
    categories: Array,
});

const form = useForm({
    name: props.target.name,
    slug: props.target.slug,
    category_id: props.target.category_id,
    host: props.target.host,
    description: props.target.description || '',
    show_host_publicly: props.target.show_host_publicly,
    is_public: props.target.is_public,
    is_enabled: props.target.is_enabled,
    icmp_enabled: props.target.icmp_enabled,
    tcp_enabled: props.target.tcp_enabled,
    tcp_port: props.target.tcp_port,
    loss_threshold_percent: props.target.loss_threshold_percent,
    latency_threshold_ms: props.target.latency_threshold_ms,
    probe_interval_seconds: props.target.probe_interval_seconds,
    sort_order: props.target.sort_order,
});

const testResult = ref(null);
const testing = ref(false);

async function testConnection() {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await axios.post(route('admin.targets.test'), {
            host: form.host,
            icmp_enabled: form.icmp_enabled,
            tcp_enabled: form.tcp_enabled,
            tcp_port: form.tcp_port,
        });
        testResult.value = data;
    } catch (e) {
        testResult.value = { error: e.response?.data?.message || e.response?.data?.error || 'Test failed' };
    } finally {
        testing.value = false;
    }
}

function submit() {
    form.put(route('admin.targets.update', props.target.id));
}
</script>

<template>
    <div class="max-w-2xl">
        <div class="flex items-center gap-3 mb-6">
            <Link :href="route('admin.targets.index')" class="text-muted-foreground hover:text-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                    <path d="m15 18-6-6 6-6"/>
                </svg>
            </Link>
            <h1 class="text-2xl font-bold">Edit Target</h1>
        </div>

        <form @submit.prevent="submit">
            <Card class="mb-6">
                <CardHeader><CardTitle class="text-base">General</CardTitle></CardHeader>
                <CardContent class="space-y-4">
                    <div class="space-y-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" />
                        <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label for="slug">Slug</Label>
                        <Input id="slug" v-model="form.slug" />
                        <p v-if="form.errors.slug" class="text-sm text-destructive">{{ form.errors.slug }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label for="category_id">Category</Label>
                        <select id="category_id" v-model="form.category_id" class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                            <option value="" disabled>Select category</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label for="host">Host / IP</Label>
                        <Input id="host" v-model="form.host" />
                    </div>
                    <div class="space-y-2">
                        <Label for="description">Description</Label>
                        <Textarea id="description" v-model="form.description" />
                    </div>
                </CardContent>
            </Card>

            <Card class="mb-6">
                <CardHeader><CardTitle class="text-base">Visibility</CardTitle></CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex items-center gap-2">
                        <Checkbox id="is_public" v-model="form.is_public" />
                        <Label for="is_public">Public target</Label>
                    </div>
                    <div class="flex items-center gap-2">
                        <Checkbox id="show_host_publicly" v-model="form.show_host_publicly" />
                        <Label for="show_host_publicly">Show IP / Host publicly</Label>
                    </div>
                </CardContent>
            </Card>

            <Card class="mb-6">
                <CardHeader><CardTitle class="text-base">Monitoring</CardTitle></CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex items-center gap-2">
                        <Checkbox id="icmp_enabled" v-model="form.icmp_enabled" />
                        <Label for="icmp_enabled">ICMP Monitoring</Label>
                    </div>
                    <div class="flex items-center gap-2">
                        <Checkbox id="tcp_enabled" v-model="form.tcp_enabled" />
                        <Label for="tcp_enabled">TCP Monitoring</Label>
                    </div>
                    <div v-if="form.tcp_enabled" class="space-y-2">
                        <Label for="tcp_port">TCP Port</Label>
                        <Input id="tcp_port" v-model="form.tcp_port" type="number" min="1" max="65535" />
                    </div>

                    <div class="space-y-2">
                        <Label for="probe_interval_seconds">Probe Interval</Label>
                        <select id="probe_interval_seconds" v-model="form.probe_interval_seconds" class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                            <option :value="null">Use global default</option>
                            <option :value="60">1 minute</option>
                            <option :value="120">2 minutes</option>
                            <option :value="300">5 minutes</option>
                            <option :value="600">10 minutes</option>
                            <option :value="900">15 minutes</option>
                            <option :value="1800">30 minutes</option>
                            <option :value="3600">1 hour</option>
                        </select>
                        <p v-if="form.errors.probe_interval_seconds" class="text-sm text-destructive">{{ form.errors.probe_interval_seconds }}</p>
                    </div>

                    <Separator />

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <Label for="loss_threshold">Loss Threshold (%)</Label>
                            <Input id="loss_threshold" v-model="form.loss_threshold_percent" type="number" min="0" max="100" step="0.1" placeholder="Global default" />
                            <p class="text-xs text-muted-foreground">Blank uses global default</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="latency_threshold">Latency Threshold (ms)</Label>
                            <Input id="latency_threshold" v-model="form.latency_threshold_ms" type="number" min="0" max="10000" step="1" placeholder="Global default" />
                            <p class="text-xs text-muted-foreground">Blank uses global default</p>
                        </div>
                    </div>

                    <Separator />

                    <div>
                        <Button type="button" variant="outline" @click="testConnection" :disabled="testing">
                            {{ testing ? 'Testing...' : 'Test Connection' }}
                        </Button>
                        <div v-if="testResult" class="mt-3 space-y-2">
                            <div v-if="testResult.icmp" class="flex items-center gap-3 p-3 rounded-lg border text-sm">
                                <StatusDot :status="testResult.icmp.status === 'reachable' ? 'online' : 'down'" />
                                <span class="font-medium w-12">ICMP</span>
                                <span v-if="testResult.icmp.status === 'reachable'">
                                    Median: {{ testResult.icmp.median_ms?.toFixed(1) }}ms
                                </span>
                                <span v-else class="text-destructive">Unreachable</span>
                            </div>
                            <div v-if="testResult.tcp" class="flex items-center gap-3 p-3 rounded-lg border text-sm">
                                <StatusDot :status="testResult.tcp.status === 'reachable' ? 'online' : 'down'" />
                                <span class="font-medium w-12">TCP</span>
                                <span v-if="testResult.tcp.status === 'reachable'">
                                    Latency: {{ testResult.tcp.median_ms?.toFixed(1) }}ms
                                </span>
                                <span v-else class="text-destructive">Unreachable</span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div class="flex items-center gap-2 mb-4">
                <Checkbox id="is_enabled" v-model="form.is_enabled" />
                <Label for="is_enabled">Monitoring enabled</Label>
            </div>
            <div class="space-y-2 mb-6">
                <Label for="sort_order">Sort Order</Label>
                <Input id="sort_order" v-model="form.sort_order" type="number" min="0" class="w-32" />
            </div>

            <div class="flex justify-between">
                <Link :href="route('admin.targets.index')">
                    <Button variant="outline" type="button">Cancel</Button>
                </Link>
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : 'Save Changes' }}
                </Button>
            </div>
        </form>
    </div>
</template>
