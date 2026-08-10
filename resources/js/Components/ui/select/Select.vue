<script setup>
import { cn } from '@/lib/utils';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, default: () => [] },
    placeholder: String,
    disabled: Boolean,
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <select
        :value="modelValue"
        :disabled="disabled"
        @change="emit('update:modelValue', $event.target.value)"
        :class="cn(
            'flex h-10 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 [&>span]:line-clamp-1 appearance-none',
            $attrs.class
        )"
    >
        <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
        <option
            v-for="opt in options"
            :key="typeof opt === 'object' ? opt.value : opt"
            :value="typeof opt === 'object' ? opt.value : opt"
        >
            {{ typeof opt === 'object' ? opt.label : opt }}
        </option>
    </select>
</template>
