<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import PoweredByFooter from '@/Components/PoweredByFooter.vue';

const page = usePage();
const isDark = ref(false);

function toggleDark() {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('dark', isDark.value);
    localStorage.setItem('pingglass-theme', isDark.value ? 'dark' : 'light');
}

onMounted(() => {
    const saved = localStorage.getItem('pingglass-theme');
    isDark.value = saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', isDark.value);
});
</script>

<template>
    <div class="min-h-screen bg-background flex flex-col">
        <!-- Header -->
        <header class="sticky top-0 z-50 w-full border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
            <div class="container flex h-14 items-center justify-between">
                <div class="flex items-center gap-6">
                    <Link href="/" class="flex items-center gap-2 font-bold text-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 text-primary">
                            <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
                            <path d="m9 12 2 2 4-4"/>
                        </svg>
                        PingGlass
                    </Link>
                    <nav class="flex items-center gap-4 text-sm">
                        <Link
                            :href="route('home')"
                            :class="route().current('home') ? 'text-foreground font-medium' : 'text-muted-foreground hover:text-foreground transition-colors'"
                        >
                            Dashboard
                        </Link>
                    </nav>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        @click="toggleDark"
                        class="inline-flex items-center justify-center rounded-md w-9 h-9 hover:bg-accent hover:text-accent-foreground transition-colors"
                    >
                        <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                            <circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
                        </svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                            <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
                        </svg>
                    </button>
                    <Link
                        v-if="page.props.auth?.user"
                        :href="route('admin.dashboard')"
                        class="inline-flex items-center justify-center rounded-md text-sm font-medium h-9 px-4 bg-secondary text-secondary-foreground hover:bg-secondary/80 transition-colors"
                    >
                        Admin
                    </Link>
                    <Link
                        v-else
                        :href="route('login')"
                        class="inline-flex items-center justify-center rounded-md text-sm font-medium h-9 px-4 hover:bg-accent hover:text-accent-foreground transition-colors text-muted-foreground"
                    >
                        Login
                    </Link>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1">
            <slot />
        </main>

        <PoweredByFooter />
    </div>
</template>
