<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Alert from '@/Components/ui/alert/Alert.vue';
import AlertDescription from '@/Components/ui/alert/AlertDescription.vue';

const page = usePage();

const success = computed(() => page.props.flash?.success);
const error = computed(() => page.props.flash?.error);
</script>

<template>
    <div class="space-y-2">
        <Alert v-if="success" variant="success">
            <AlertDescription>{{ success }}</AlertDescription>
        </Alert>
        <Alert v-if="error" variant="destructive">
            <AlertDescription>{{ error }}</AlertDescription>
        </Alert>
    </div>
</template>
