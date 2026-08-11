<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ref, onMounted, computed } from 'vue';
import PoweredByFooter from '@/Components/PoweredByFooter.vue';

const page = usePage();
const isDark = ref(false);
const sidebarOpen = ref(false);

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

const navItems = [
    { label: 'Dashboard', route: 'admin.dashboard', icon: 'dashboard' },
    { label: 'Categories', route: 'admin.categories.index', icon: 'folder' },
    { label: 'Targets', route: 'admin.targets.index', icon: 'target' },
    { label: 'Incidents', route: 'admin.incidents.index', icon: 'alert' },
    { label: 'Settings', route: 'admin.settings.index', icon: 'settings' },
    { label: 'Administrators', route: 'admin.users.index', icon: 'users' },
    { label: 'System Health', route: 'admin.health.index', icon: 'health' },
];

function isActive(item) {
    return route().current(item.route + '*') || route().current(item.route);
}

const userName = computed(() => page.props.auth?.user?.name || 'Admin');
</script>

<template>
    <div class="min-h-screen bg-background">
        <!-- Mobile sidebar overlay -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-50 bg-background/80 backdrop-blur-sm lg:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 w-64 border-r bg-sidebar-background transform transition-transform duration-200 lg:translate-x-0',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full'
            ]"
        >
            <div class="flex h-14 items-center border-b px-6">
                <Link :href="route('home')" class="flex items-center gap-2 font-bold text-lg text-sidebar-foreground">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 text-sidebar-primary">
                        <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
                        <path d="m9 12 2 2 4-4"/>
                    </svg>
                    PingGlass
                </Link>
            </div>

            <nav class="flex flex-col gap-1 p-4">
                <Link
                    v-for="item in navItems"
                    :key="item.route"
                    :href="route(item.route)"
                    :class="[
                        'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                        isActive(item)
                            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                            : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
                    ]"
                    @click="sidebarOpen = false"
                >
                    <!-- Dashboard icon -->
                    <svg v-if="item.icon === 'dashboard'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                        <rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>
                    </svg>
                    <!-- Folder icon -->
                    <svg v-if="item.icon === 'folder'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                        <path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>
                    </svg>
                    <!-- Target icon -->
                    <svg v-if="item.icon === 'target'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                        <circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>
                    </svg>
                    <!-- Alert icon -->
                    <svg v-if="item.icon === 'alert'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                        <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4m0 4h.01"/>
                    </svg>
                    <!-- Settings icon -->
                    <svg v-if="item.icon === 'settings'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                        <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                    <!-- Users icon -->
                    <svg v-if="item.icon === 'users'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87m-2-12a4 4 0 0 1 0 7.75"/>
                    </svg>
                    <!-- Health icon -->
                    <svg v-if="item.icon === 'health'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                        <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
                    </svg>
                    {{ item.label }}
                </Link>
            </nav>

            <div class="absolute bottom-0 left-0 right-0 border-t p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-sidebar-foreground">{{ userName }}</span>
                    <div class="flex items-center gap-1">
                        <button @click="toggleDark" class="rounded-md p-1.5 hover:bg-sidebar-accent transition-colors text-sidebar-foreground">
                            <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                                <circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
                            </svg>
                            <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                                <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
                            </svg>
                        </button>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="rounded-md p-1.5 hover:bg-sidebar-accent transition-colors text-sidebar-foreground"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>
                            </svg>
                        </Link>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main content area -->
        <div class="lg:pl-64 min-h-screen flex flex-col">
            <!-- Top bar -->
            <header class="sticky top-0 z-40 flex h-14 items-center gap-4 border-b bg-background/95 backdrop-blur px-6">
                <button
                    @click="sidebarOpen = true"
                    class="lg:hidden inline-flex items-center justify-center rounded-md w-9 h-9 hover:bg-accent"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                        <line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>
                    </svg>
                </button>
                <div class="flex-1" />
                <Link
                    :href="route('home')"
                    class="text-sm text-muted-foreground hover:text-foreground transition-colors"
                >
                    View Public Site
                </Link>
            </header>

            <!-- Page content -->
            <main class="p-6 flex-1">
                <slot />
            </main>

            <PoweredByFooter />
        </div>
    </div>
</template>
