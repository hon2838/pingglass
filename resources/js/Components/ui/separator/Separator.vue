<script setup>
import { cn } from '@/lib/utils';

const props = defineProps({
    orientation: {
        type: String,
        default: 'horizontal',
    },
    decorative: Boolean,
});
</script>

<template>
    <div
        :role="decorative ? 'none' : 'separator'"
        :aria-orientation="orientation"
        :class="cn(
            'shrink-0 bg-border',
            orientation === 'horizontal' ? 'h-[1px] w-full' : 'h-full w-[1px]',
            $attrs.class
        )"
    />
</template>
