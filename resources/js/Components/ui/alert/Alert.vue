<script setup>
import { cn } from '@/lib/utils';
import { cva } from 'class-variance-authority';

const props = defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'destructive', 'success'].includes(v),
    },
});

const variants = cva(
    'relative w-full rounded-lg border p-4 [&>svg~*]:pl-7 [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg]:text-foreground',
    {
        variants: {
            variant: {
                default: 'bg-background text-foreground',
                destructive: 'border-destructive/50 text-destructive dark:border-destructive [&>svg]:text-destructive',
                success: 'border-status-online/50 text-status-online [&>svg]:text-status-online',
            },
        },
    }
);
</script>

<template>
    <div :role="'alert'" :class="cn(variants({ variant }), $attrs.class)">
        <slot />
    </div>
</template>
