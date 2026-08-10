<script setup>
import { cn } from '@/lib/utils';
</script>

<template>
    <thead :class="cn('[&_tr]:border-b', $attrs.class)">
        <slot />
    </thead>
</template>
