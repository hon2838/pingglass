<script setup>
import { cn } from '@/lib/utils';
import { cva } from 'class-variance-authority';

const props = defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'secondary', 'destructive', 'outline', 'success', 'warning'].includes(v),
    },
});

const variants = cva(
    'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-primary text-primary-foreground hover:bg-primary/80',
                secondary: 'border-transparent bg-secondary text-secondary-foreground hover:bg-secondary/80',
                destructive: 'border-transparent bg-destructive text-destructive-foreground hover:bg-destructive/80',
                outline: 'text-foreground',
                success: 'border-transparent bg-status-online/15 text-status-online',
                warning: 'border-transparent bg-status-degraded/15 text-status-degraded',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    }
);
</script>

<template>
    <div :class="cn(variants({ variant }), $attrs.class)">
        <slot />
    </div>
</template>
