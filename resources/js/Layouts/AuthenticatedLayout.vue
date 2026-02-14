<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';

interface SubItem {
    name: string;
    href: string;
    routeMatch: string;
}

interface NavItem {
    name: string;
    href: string | null;
    icon: string;
    routeMatch: string;
    enabled: boolean;
    badge?: string;
    children?: SubItem[];
}

const page = usePage();
const sidebarOpen = ref(true);
const mobileMenuOpen = ref(false);

const user = computed(() => page.props.auth?.user);
const currentBrand = computed(() => page.props.currentBrand);
const brands = computed(() => page.props.brands || []);

const navigation: NavItem[] = [
    { name: 'Dashboard', href: 'dashboard', icon: 'home', routeMatch: 'dashboard', enabled: true },
    {
        name: 'Social', href: 'social.posts.index', icon: 'share', routeMatch: 'social.*', enabled: true,
        children: [
            { name: 'Posts', href: 'social.posts.index', routeMatch: 'social.posts.*' },
            { name: 'Calendário', href: 'social.calendar.index', routeMatch: 'social.calendar.*' },
            { name: 'Content Engine', href: 'social.content-engine.index', routeMatch: 'social.content-engine.*' },
            { name: 'Autopilot', href: 'social.autopilot.index', routeMatch: 'social.autopilot.*' },
            { name: 'Contas', href: 'social.accounts.index', routeMatch: 'social.accounts.*' },
        ],
    },
    {
        name: 'Email Marketing', href: 'email.dashboard', icon: 'mail', routeMatch: 'email.*', enabled: true,
        children: [
            { name: 'Dashboard', href: 'email.dashboard', routeMatch: 'email.dashboard' },
            { name: 'Campanhas', href: 'email.campaigns.index', routeMatch: 'email.campaigns.*' },
            { name: 'Templates', href: 'email.templates.index', routeMatch: 'email.templates.*' },
            { name: 'Listas', href: 'email.lists.index', routeMatch: 'email.lists.*' },
            { name: 'Sugestões IA', href: 'email.ai-suggestions.index', routeMatch: 'email.ai-suggestions.*' },
            { name: 'Provedores', href: 'email.providers.index', routeMatch: 'email.providers.*' },
        ],
    },
    {
        name: 'SMS Marketing', href: 'sms.dashboard', icon: 'smartphone', routeMatch: 'sms.*', enabled: true,
        children: [
            { name: 'Dashboard', href: 'sms.dashboard', routeMatch: 'sms.dashboard' },
            { name: 'Campanhas', href: 'sms.campaigns.index', routeMatch: 'sms.campaigns.*' },
            { name: 'Templates', href: 'sms.templates.index', routeMatch: 'sms.templates.*' },
            { name: 'Provedores', href: 'email.providers.index', routeMatch: 'email.providers.*' },
        ],
    },
    { name: 'Chat IA', href: 'chat.index', icon: 'message-circle', routeMatch: 'chat.*', enabled: true },
    {
        name: 'Blog', href: 'blog.index', icon: 'file-text', routeMatch: 'blog.*', enabled: true,
        children: [
            { name: 'Artigos', href: 'blog.index', routeMatch: 'blog.index' },
            { name: 'Calendário', href: 'blog.calendar', routeMatch: 'blog.calendar*' },
            { name: 'Novo Artigo', href: 'blog.create', routeMatch: 'blog.create' },
            { name: 'Categorias', href: 'blog.categories', routeMatch: 'blog.categories*' },
        ],
    },
    {
        name: 'Links', href: 'links.index', icon: 'link', routeMatch: 'links.*', enabled: true,
        children: [
            { name: 'Páginas', href: 'links.index', routeMatch: 'links.index' },
        ],
    },
    {
        name: 'Analytics', href: 'analytics.index', icon: 'bar-chart-2', routeMatch: 'analytics.*', enabled: true,
        children: [
            { name: 'Visão Geral', href: 'analytics.index', routeMatch: 'analytics.index' },
            { name: 'Website', href: 'analytics.website', routeMatch: 'analytics.website' },
            { name: 'Ads', href: 'analytics.ads', routeMatch: 'analytics.ads' },
            { name: 'SEO', href: 'analytics.seo', routeMatch: 'analytics.seo' },
            { name: 'Conexões', href: 'analytics.connections', routeMatch: 'analytics.connections' },
        ],
    },
    { name: 'Métricas', href: 'metrics.index', icon: 'trending-up', routeMatch: 'metrics.*', enabled: true },
    { name: 'Marcas', href: 'brands.index', icon: 'briefcase', routeMatch: 'brands.*', enabled: true },
    { name: 'Logs', href: 'logs.index', icon: 'terminal', routeMatch: 'logs.*', enabled: true },
    { name: 'Configurações', href: 'settings.index', icon: 'settings', routeMatch: 'settings.*', enabled: true },
];

// Determinar qual menu deve estar expandido com base na rota atual
function getActiveMenus(): string[] {
    const expanded: string[] = [];
    for (const item of navigation) {
        if (item.children && item.enabled) {
            try {
                if (route().current(item.routeMatch)) {
                    expanded.push(item.name);
                }
            } catch {}
        }
    }
    return expanded;
}
const expandedMenus = ref<string[]>(getActiveMenus());

// Reagir a navegação SPA (Inertia) — expandir menu correto ao trocar de pagina
const currentUrl = computed(() => page.url);
watch(currentUrl, () => {
    const active = getActiveMenus();
    // Adicionar menus ativos sem fechar os que o usuario abriu manualmente
    for (const name of active) {
        if (!expandedMenus.value.includes(name)) {
            expandedMenus.value.push(name);
        }
    }
    // Fechar menus que nao estao ativos E que o usuario nao esta navegando
    expandedMenus.value = expandedMenus.value.filter(name => {
        // Manter se esta ativo na rota atual
        if (active.includes(name)) return true;
        // Remover se nao esta ativo (usuario navegou para outra secao)
        return false;
    });
});

function isRouteActive(routeMatch: string): boolean {
    try {
        return route().current(routeMatch) ?? false;
    } catch {
        return false;
    }
}

function toggleSubmenu(name: string) {
    const idx = expandedMenus.value.indexOf(name);
    if (idx >= 0) expandedMenus.value.splice(idx, 1);
    else expandedMenus.value.push(name);
}

function isMenuExpanded(name: string): boolean {
    return expandedMenus.value.includes(name);
}

function switchBrand(brandId: number) {
    router.post(route('brands.switch', brandId), {}, {
        preserveState: false,
    });
}

function toggleSidebar() {
    sidebarOpen.value = !sidebarOpen.value;
}
</script>

<template>
    <div class="min-h-screen bg-gray-950">
        <!-- Mobile menu overlay -->
        <div
            v-if="mobileMenuOpen"
            class="fixed inset-0 z-40 bg-black/50 lg:hidden"
            @click="mobileMenuOpen = false"
        />

        <!-- Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 flex flex-col bg-gray-900 border-r border-gray-800 transition-all duration-300',
                sidebarOpen ? 'w-64' : 'w-20',
                mobileMenuOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            ]"
        >
            <!-- Logo -->
            <div class="flex h-16 items-center justify-between px-4 border-b border-gray-800">
                <Link :href="route('dashboard')" class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-white font-bold text-lg shrink-0">
                        M
                    </div>
                    <span v-if="sidebarOpen" class="text-lg font-bold text-white tracking-tight">
                        MKT Privus
                    </span>
                </Link>
                <button
                    @click="toggleSidebar"
                    class="hidden lg:flex items-center justify-center h-8 w-8 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path v-if="sidebarOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                    </svg>
                </button>
            </div>

            <!-- Brand Switcher -->
            <div v-if="sidebarOpen && currentBrand" class="px-3 py-3 border-b border-gray-800">
                <Dropdown align="left" width="56">
                    <template #trigger>
                        <button class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left hover:bg-gray-800 transition group">
                            <div
                                class="flex h-8 w-8 items-center justify-center rounded-lg text-xs font-bold text-white shrink-0"
                                :style="{ backgroundColor: currentBrand.primary_color || '#6366F1' }"
                            >
                                {{ currentBrand.name?.charAt(0)?.toUpperCase() }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-200 truncate">{{ currentBrand.name }}</p>
                                <p class="text-xs text-gray-500 truncate">{{ currentBrand.segment || 'Marca ativa' }}</p>
                            </div>
                            <svg class="w-4 h-4 text-gray-500 group-hover:text-gray-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4" />
                            </svg>
                        </button>
                    </template>
                    <template #content>
                        <div class="px-3 py-2 text-xs font-semibold text-gray-400 uppercase tracking-wider">
                            Trocar marca
                        </div>
                        <button
                            v-for="brand in brands"
                            :key="brand.id"
                            @click="switchBrand(brand.id)"
                            class="flex w-full items-center gap-3 px-3 py-2 text-sm text-gray-300 hover:bg-gray-700 transition"
                            :class="{ 'bg-gray-700/50': brand.id === currentBrand?.id }"
                        >
                            <div
                                class="flex h-6 w-6 items-center justify-center rounded text-xs font-bold text-white"
                                :style="{ backgroundColor: brand.primary_color || '#6366F1' }"
                            >
                                {{ brand.name?.charAt(0)?.toUpperCase() }}
                            </div>
                            <span class="truncate">{{ brand.name }}</span>
                            <svg v-if="brand.id === currentBrand?.id" class="w-4 h-4 text-indigo-400 ml-auto shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </template>
                </Dropdown>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
                <template v-for="item in navigation" :key="item.name">
                    <!-- Item SEM submenu -->
                    <component
                        v-if="!item.children"
                        :is="item.enabled ? Link : 'div'"
                        :href="item.enabled && item.href ? route(item.href) : undefined"
                        :class="[
                            'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all',
                            item.enabled && isRouteActive(item.routeMatch)
                                ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/30'
                                : item.enabled
                                    ? 'text-gray-400 hover:text-white hover:bg-gray-800 cursor-pointer'
                                    : 'text-gray-600 cursor-not-allowed',
                            !sidebarOpen && 'justify-center',
                        ]"
                        :title="!sidebarOpen ? item.name : undefined"
                    >
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <template v-if="item.icon === 'home'">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><polyline points="9 22 9 12 15 12 15 22" />
                            </template>
                            <template v-else-if="item.icon === 'message-circle'">
                                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                            </template>
                            <template v-else-if="item.icon === 'file-text'">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" /><polyline points="10 9 9 9 8 9" />
                            </template>
                            <template v-else-if="item.icon === 'link'">
                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" /><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                            </template>
                            <template v-else-if="item.icon === 'bar-chart-2'">
                                <line x1="18" y1="20" x2="18" y2="10" /><line x1="12" y1="20" x2="12" y2="4" /><line x1="6" y1="20" x2="6" y2="14" />
                            </template>
                            <template v-else-if="item.icon === 'trending-up'">
                                <polyline points="23 6 13.5 15.5 8.5 10.5 1 18" /><polyline points="17 6 23 6 23 12" />
                            </template>
                            <template v-else-if="item.icon === 'briefcase'">
                                <rect x="2" y="7" width="20" height="14" rx="2" ry="2" /><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" />
                            </template>
                            <template v-else-if="item.icon === 'terminal'">
                                <polyline points="4 17 10 11 4 5" /><line x1="12" y1="19" x2="20" y2="19" />
                            </template>
                            <template v-else-if="item.icon === 'smartphone'">
                                <rect x="5" y="2" width="14" height="20" rx="2" ry="2" /><line x1="12" y1="18" x2="12.01" y2="18" />
                            </template>
                            <template v-else-if="item.icon === 'settings'">
                                <circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
                            </template>
                        </svg>
                        <span v-if="sidebarOpen" class="flex-1">{{ item.name }}</span>
                        <span v-if="sidebarOpen && item.badge" class="text-[10px] px-1.5 py-0.5 rounded bg-gray-800 text-gray-500 font-normal">
                            {{ item.badge }}
                        </span>
                    </component>

                    <!-- Item COM submenu -->
                    <div v-else>
                        <!-- Parent button -->
                        <button
                            @click="toggleSubmenu(item.name)"
                            :class="[
                                'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all',
                                isRouteActive(item.routeMatch)
                                    ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/30'
                                    : 'text-gray-400 hover:text-white hover:bg-gray-800',
                                !sidebarOpen && 'justify-center',
                            ]"
                            :title="!sidebarOpen ? item.name : undefined"
                        >
                            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <template v-if="item.icon === 'share'">
                                    <circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><line x1="8.59" y1="13.51" x2="15.42" y2="17.49" /><line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
                                </template>
                                <template v-else-if="item.icon === 'bar-chart-2'">
                                    <line x1="18" y1="20" x2="18" y2="10" /><line x1="12" y1="20" x2="12" y2="4" /><line x1="6" y1="20" x2="6" y2="14" />
                                </template>
                                <template v-else-if="item.icon === 'mail'">
                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" /><polyline points="22,6 12,13 2,6" />
                                </template>
                                <template v-else-if="item.icon === 'smartphone'">
                                    <rect x="5" y="2" width="14" height="20" rx="2" ry="2" /><line x1="12" y1="18" x2="12.01" y2="18" />
                                </template>
                            </svg>
                            <span v-if="sidebarOpen" class="flex-1 text-left">{{ item.name }}</span>
                            <svg
                                v-if="sidebarOpen"
                                :class="['w-4 h-4 shrink-0 transition-transform duration-200', isMenuExpanded(item.name) ? 'rotate-180' : '']"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                            >
                                <polyline points="6 9 12 15 18 9" />
                            </svg>
                        </button>

                        <!-- Submenu items -->
                        <div
                            v-if="sidebarOpen && isMenuExpanded(item.name)"
                            class="mt-1 ml-4 pl-4 border-l border-gray-800 space-y-0.5"
                        >
                            <Link
                                v-for="child in item.children"
                                :key="child.name"
                                :href="route(child.href)"
                                :class="[
                                    'flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] font-medium transition-all',
                                    isRouteActive(child.routeMatch)
                                        ? 'text-indigo-400 bg-indigo-600/10'
                                        : 'text-gray-500 hover:text-gray-200 hover:bg-gray-800/50',
                                ]"
                            >
                                <span
                                    :class="[
                                        'w-1.5 h-1.5 rounded-full shrink-0',
                                        isRouteActive(child.routeMatch) ? 'bg-indigo-400' : 'bg-gray-700',
                                    ]"
                                />
                                {{ child.name }}
                            </Link>
                        </div>

                        <!-- Collapsed tooltip-style submenu -->
                        <div
                            v-if="!sidebarOpen"
                            class="hidden group-hover:block"
                        />
                    </div>
                </template>
            </nav>

            <!-- User info bottom -->
            <div class="border-t border-gray-800 p-3">
                <Dropdown align="left" width="48">
                    <template #trigger>
                        <button :class="['flex w-full items-center gap-3 rounded-xl px-3 py-2.5 hover:bg-gray-800 transition', !sidebarOpen && 'justify-center']">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-white text-sm font-medium shrink-0">
                                {{ user?.name?.charAt(0)?.toUpperCase() }}
                            </div>
                            <div v-if="sidebarOpen" class="flex-1 min-w-0 text-left">
                                <p class="text-sm font-medium text-gray-200 truncate">{{ user?.name }}</p>
                                <p class="text-xs text-gray-500 truncate">{{ user?.email }}</p>
                            </div>
                        </button>
                    </template>
                    <template #content>
                        <DropdownLink :href="route('profile.edit')">
                            Meu Perfil
                        </DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button">
                            Sair
                        </DropdownLink>
                    </template>
                </Dropdown>
            </div>
        </aside>

        <!-- Main content -->
        <div :class="['transition-all duration-300', sidebarOpen ? 'lg:pl-64' : 'lg:pl-20']">
            <!-- Top header -->
            <header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b border-gray-800 bg-gray-900/80 backdrop-blur-xl px-4 lg:px-8">
                <!-- Mobile menu button -->
                <button
                    @click="mobileMenuOpen = true"
                    class="lg:hidden flex items-center justify-center h-10 w-10 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <!-- Page header slot -->
                <div class="flex-1">
                    <slot name="header" />
                </div>

                <!-- Right side actions -->
                <div class="flex items-center gap-3">
                    <!-- Notifications placeholder -->
                    <button class="flex items-center justify-center h-10 w-10 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition relative">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                        <span class="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-indigo-500"></span>
                    </button>
                </div>
            </header>

            <!-- Page Content -->
            <main class="p-4 lg:p-8">
                <slot />
            </main>
        </div>
    </div>
</template>
