<script setup>
import { cn } from '@/lib/utils';
</script>

<template>
    <div :class="cn('text-sm [&_p]:leading-relaxed', $attrs.class)">
        <slot />
    </div>
</template>
