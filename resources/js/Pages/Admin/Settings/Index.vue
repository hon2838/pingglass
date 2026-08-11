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
import Separator from '@/Components/ui/separator/Separator.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { useForm } from '@inertiajs/vue3';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: Object,
});

const form = useForm({
    probe_interval: props.settings.probe_interval,
    icmp_samples: props.settings.icmp_samples,
    tcp_samples: props.settings.tcp_samples,
    icmp_timeout: props.settings.icmp_timeout,
    tcp_timeout: props.settings.tcp_timeout,
    loss_threshold_percent: props.settings.loss_threshold_percent,
    latency_threshold_ms: props.settings.latency_threshold_ms,
    raw_retention_days: props.settings.raw_retention_days,
    rollup5m_retention_days: props.settings.rollup5m_retention_days,
    down_confirmation_cycles: props.settings.down_confirmation_cycles,
    recovery_confirmation_cycles: props.settings.recovery_confirmation_cycles,
});

function submit() {
    form.post(route('admin.settings.update'));
}
</script>

<template>
    <div class="max-w-2xl">
        <h1 class="text-2xl font-bold mb-6">Monitoring Settings</h1>

        <FlashMessages class="mb-4" />

        <form @submit.prevent="submit">
            <Card class="mb-6">
                <CardHeader>
                    <CardTitle class="text-base">Probe Configuration</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="space-y-2">
                        <Label for="probe_interval">Default Probe Interval</Label>
                        <select id="probe_interval" v-model="form.probe_interval" class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                            <option :value="60">1 minute</option>
                            <option :value="120">2 minutes</option>
                            <option :value="300">5 minutes</option>
                            <option :value="600">10 minutes</option>
                            <option :value="900">15 minutes</option>
                            <option :value="1800">30 minutes</option>
                            <option :value="3600">1 hour</option>
                        </select>
                        <p class="text-xs text-muted-foreground">Targets may override this default. The scheduler still runs once per minute and dispatches only targets that are due.</p>
                    </div>

                    <Separator />

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <Label for="icmp_samples">ICMP Samples</Label>
                            <Input id="icmp_samples" v-model="form.icmp_samples" type="number" min="1" max="100" />
                        </div>
                        <div class="space-y-2">
                            <Label for="tcp_samples">TCP Samples</Label>
                            <Input id="tcp_samples" v-model="form.tcp_samples" type="number" min="1" max="100" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <Label for="icmp_timeout">ICMP Timeout (ms)</Label>
                            <Input id="icmp_timeout" v-model="form.icmp_timeout" type="number" min="100" max="30000" />
                        </div>
                        <div class="space-y-2">
                            <Label for="tcp_timeout">TCP Timeout (ms)</Label>
                            <Input id="tcp_timeout" v-model="form.tcp_timeout" type="number" min="100" max="30000" />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card class="mb-6">
                <CardHeader>
                    <CardTitle class="text-base">Data Retention</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="space-y-2">
                        <Label for="raw_retention_days">Raw Measurement Retention (days)</Label>
                        <Input id="raw_retention_days" v-model="form.raw_retention_days" type="number" min="1" max="365" />
                        <p class="text-xs text-muted-foreground">Individual per-minute measurements are deleted after this period.</p>
                    </div>
                    <div class="space-y-2">
                        <Label for="rollup5m_retention_days">5-Minute Rollup Retention (days)</Label>
                        <Input id="rollup5m_retention_days" v-model="form.rollup5m_retention_days" type="number" min="1" max="3650" />
                        <p class="text-xs text-muted-foreground">5-minute aggregated data is deleted after this period. Hourly rollups are kept indefinitely.</p>
                    </div>
                </CardContent>
            </Card>

            <Card class="mb-6">
                <CardHeader>
                    <CardTitle class="text-base">Status Thresholds</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <Label for="loss_threshold_percent">Default Loss Threshold (%)</Label>
                            <Input id="loss_threshold_percent" v-model="form.loss_threshold_percent" type="number" min="0" max="100" step="0.1" />
                            <p class="text-xs text-muted-foreground">At or above this loss, a reachable target is degraded.</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="latency_threshold_ms">Default Median Latency Threshold (ms)</Label>
                            <Input id="latency_threshold_ms" v-model="form.latency_threshold_ms" type="number" min="0" max="10000" step="1" />
                            <p class="text-xs text-muted-foreground">Above this median latency, a target is degraded.</p>
                        </div>
                    </div>
                    <Separator />
                    <div class="space-y-2">
                        <Label for="down_confirmation_cycles">Down Confirmation (cycles)</Label>
                        <Input id="down_confirmation_cycles" v-model="form.down_confirmation_cycles" type="number" min="1" max="10" />
                        <p class="text-xs text-muted-foreground">Number of consecutive failed cycles before marking a target as DOWN.</p>
                    </div>
                    <div class="space-y-2">
                        <Label for="recovery_confirmation_cycles">Recovery Confirmation (cycles)</Label>
                        <Input id="recovery_confirmation_cycles" v-model="form.recovery_confirmation_cycles" type="number" min="1" max="10" />
                        <p class="text-xs text-muted-foreground">Number of consecutive successful cycles before closing an incident.</p>
                    </div>
                </CardContent>
            </Card>

            <div class="flex justify-end">
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : 'Save Settings' }}
                </Button>
            </div>
        </form>
    </div>
</template>
