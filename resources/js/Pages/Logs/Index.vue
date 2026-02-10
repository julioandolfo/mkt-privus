<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

interface LogEntry {
    id: number;
    channel: string;
    level: string;
    action: string;
    message: string;
    context: Record<string, any> | null;
    user_id: number | null;
    brand_id: number | null;
    ip: string | null;
    created_at: string;
    created_at_diff: string;
}

interface PaginatedLogs {
    data: LogEntry[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    logs: PaginatedLogs;
    stats: {
        total: number;
        today: number;
        errors_today: number;
        channels: Record<string, number>;
    };
    channels: string[];
    filters: {
        channel: string;
        level: string;
        search: string;
        per_page: number;
    };
}>();

const page = usePage();

// Filtros locais
const filterChannel = ref(props.filters.channel);
const filterLevel = ref(props.filters.level);
const filterSearch = ref(props.filters.search);

// Log expandido
const expandedLog = ref<number | null>(null);

// Auto-refresh
const autoRefresh = ref(false);
let refreshInterval: ReturnType<typeof setInterval> | null = null;

function applyFilters() {
    router.get(route('logs.index'), {
        channel: filterChannel.value !== 'all' ? filterChannel.value : undefined,
        level: filterLevel.value !== 'all' ? filterLevel.value : undefined,
        search: filterSearch.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function goToPage(url: string | null) {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function toggleExpand(id: number) {
    expandedLog.value = expandedLog.value === id ? null : id;
}

function clearLogs(channel?: string) {
    const msg = channel
        ? `Tem certeza que deseja limpar todos os logs do canal "${channel}"?`
        : 'Tem certeza que deseja limpar TODOS os logs?';

    if (confirm(msg)) {
        router.post(route('logs.clear'), { channel: channel || undefined }, {
            preserveScroll: true,
        });
    }
}

function cleanupOldLogs() {
    if (confirm('Remover logs com mais de 30 dias?')) {
        router.post(route('logs.cleanup'), { days: 30 }, { preserveScroll: true });
    }
}

function refreshLogs() {
    router.reload({ only: ['logs', 'stats'] });
}

function toggleAutoRefresh() {
    autoRefresh.value = !autoRefresh.value;
    if (autoRefresh.value) {
        refreshInterval = setInterval(refreshLogs, 5000);
    } else {
        if (refreshInterval) clearInterval(refreshInterval);
        refreshInterval = null;
    }
}

// Cores por level
const levelColors: Record<string, { bg: string; text: string; border: string }> = {
    debug: { bg: 'bg-gray-500/10', text: 'text-gray-400', border: 'border-gray-500/30' },
    info: { bg: 'bg-blue-500/10', text: 'text-blue-400', border: 'border-blue-500/30' },
    warning: { bg: 'bg-amber-500/10', text: 'text-amber-400', border: 'border-amber-500/30' },
    error: { bg: 'bg-red-500/10', text: 'text-red-400', border: 'border-red-500/30' },
    critical: { bg: 'bg-red-600/20', text: 'text-red-300', border: 'border-red-500/50' },
};

const channelColors: Record<string, string> = {
    oauth: '#6366f1',
    social: '#ec4899',
    analytics: '#14b8a6',
    ai: '#f59e0b',
    system: '#64748b',
    error: '#ef4444',
};

function getChannelColor(channel: string): string {
    return channelColors[channel] || '#6366f1';
}

function getLevelColor(level: string) {
    return levelColors[level] || levelColors.info;
}

function formatContext(ctx: any): string {
    if (!ctx) return '';
    try {
        return JSON.stringify(ctx, null, 2);
    } catch {
        return String(ctx);
    }
}

// Debounce search
let searchTimeout: ReturnType<typeof setTimeout> | null = null;
watch(filterSearch, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 500);
});
</script>

<template>
    <Head title="Logs do Sistema" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-white">Logs do Sistema</h1>
                    <p class="text-sm text-gray-500 mt-0.5">
                        {{ stats.total }} log(s) total · {{ stats.today }} hoje
                        <span v-if="stats.errors_today > 0" class="text-red-400"> · {{ stats.errors_today }} erro(s) hoje</span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="toggleAutoRefresh"
                        :class="['rounded-xl px-3 py-2 text-sm font-medium transition border', autoRefresh ? 'bg-emerald-600/20 border-emerald-500/30 text-emerald-400' : 'bg-gray-800 border-gray-700 text-gray-400 hover:text-white']">
                        <span class="inline-flex items-center gap-1.5">
                            <span v-if="autoRefresh" class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            {{ autoRefresh ? 'Auto-refresh ON' : 'Auto-refresh' }}
                        </span>
                    </button>
                    <button @click="refreshLogs" class="rounded-xl bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-gray-400 hover:text-white transition">
                        ↻ Atualizar
                    </button>
                    <button @click="cleanupOldLogs" class="rounded-xl bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-gray-400 hover:text-white transition">
                        Limpar antigos
                    </button>
                    <button @click="clearLogs()" class="rounded-xl bg-red-600/20 border border-red-500/30 px-3 py-2 text-sm text-red-400 hover:bg-red-600/30 transition">
                        Limpar todos
                    </button>
                </div>
            </div>
        </template>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Total</p>
                <p class="text-2xl font-bold text-white mt-1">{{ stats.total }}</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Hoje</p>
                <p class="text-2xl font-bold text-blue-400 mt-1">{{ stats.today }}</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Erros hoje</p>
                <p class="text-2xl font-bold mt-1" :class="stats.errors_today > 0 ? 'text-red-400' : 'text-emerald-400'">{{ stats.errors_today }}</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Canais</p>
                <div class="flex flex-wrap gap-1 mt-2">
                    <button
                        v-for="(count, ch) in stats.channels" :key="ch"
                        @click="filterChannel = String(ch); applyFilters()"
                        class="rounded-md px-1.5 py-0.5 text-[10px] font-medium text-white transition hover:opacity-80"
                        :style="{ backgroundColor: getChannelColor(String(ch)) }"
                    >
                        {{ ch }} ({{ count }})
                    </button>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-4 mb-4 flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-2">
                <label class="text-xs text-gray-500">Canal:</label>
                <select v-model="filterChannel" @change="applyFilters" class="rounded-lg bg-gray-800 border-gray-700 text-white text-sm py-1.5 px-3 focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="all">Todos</option>
                    <option v-for="ch in channels" :key="ch" :value="ch">{{ ch }}</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <label class="text-xs text-gray-500">Level:</label>
                <select v-model="filterLevel" @change="applyFilters" class="rounded-lg bg-gray-800 border-gray-700 text-white text-sm py-1.5 px-3 focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="all">Todos</option>
                    <option value="debug">Debug</option>
                    <option value="info">Info</option>
                    <option value="warning">Warning</option>
                    <option value="error">Error</option>
                    <option value="critical">Critical</option>
                </select>
            </div>

            <div class="flex-1 min-w-[200px]">
                <input v-model="filterSearch" type="text" placeholder="Buscar em mensagem, action, contexto..."
                    class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-sm py-1.5 px-3 focus:border-indigo-500 focus:ring-indigo-500" />
            </div>

            <button v-if="filterChannel !== 'all' || filterLevel !== 'all' || filterSearch"
                @click="filterChannel = 'all'; filterLevel = 'all'; filterSearch = ''; applyFilters()"
                class="text-xs text-gray-400 hover:text-white transition">
                Limpar filtros
            </button>
        </div>

        <!-- Lista de logs -->
        <div class="rounded-xl bg-gray-900 border border-gray-800 overflow-hidden">
            <div v-if="logs.data.length === 0" class="p-12 text-center">
                <div class="w-16 h-16 mx-auto rounded-2xl bg-gray-800 flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-gray-300">Nenhum log encontrado</h3>
                <p class="mt-2 text-sm text-gray-500">Os logs aparecerao aqui conforme as acoes do sistema.</p>
            </div>

            <div v-else>
                <!-- Tabela de logs -->
                <div class="divide-y divide-gray-800/50">
                    <div v-for="log in logs.data" :key="log.id"
                        class="hover:bg-gray-800/30 transition cursor-pointer"
                        @click="toggleExpand(log.id)">
                        <!-- Linha principal -->
                        <div class="flex items-center gap-3 px-4 py-3">
                            <!-- Level indicator -->
                            <div :class="['w-2 h-2 rounded-full shrink-0', getLevelColor(log.level).bg.replace('/10', '')]"
                                :style="{ backgroundColor: log.level === 'error' || log.level === 'critical' ? '#ef4444' : log.level === 'warning' ? '#f59e0b' : log.level === 'info' ? '#3b82f6' : '#6b7280' }">
                            </div>

                            <!-- Channel badge -->
                            <span class="rounded-md px-2 py-0.5 text-[10px] font-medium text-white shrink-0"
                                :style="{ backgroundColor: getChannelColor(log.channel) }">
                                {{ log.channel }}
                            </span>

                            <!-- Level badge -->
                            <span :class="['rounded-md border px-1.5 py-0.5 text-[10px] font-medium shrink-0', getLevelColor(log.level).bg, getLevelColor(log.level).text, getLevelColor(log.level).border]">
                                {{ log.level }}
                            </span>

                            <!-- Action -->
                            <span class="text-xs text-gray-500 font-mono shrink-0 hidden sm:inline">{{ log.action }}</span>

                            <!-- Message -->
                            <p class="text-sm text-gray-300 flex-1 min-w-0 truncate">{{ log.message }}</p>

                            <!-- Time -->
                            <span class="text-[10px] text-gray-600 shrink-0" :title="log.created_at">{{ log.created_at_diff }}</span>

                            <!-- Expand icon -->
                            <svg :class="['w-4 h-4 text-gray-600 shrink-0 transition-transform', expandedLog === log.id ? 'rotate-180' : '']"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <polyline points="6 9 12 15 18 9" />
                            </svg>
                        </div>

                        <!-- Detalhes expandidos -->
                        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-[500px]"
                            leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100 max-h-[500px]" leave-to-class="opacity-0 max-h-0">
                            <div v-if="expandedLog === log.id" class="bg-gray-800/50 border-t border-gray-800/50 overflow-hidden">
                                <div class="px-4 py-3 space-y-3">
                                    <!-- Meta -->
                                    <div class="flex flex-wrap gap-4 text-xs">
                                        <div><span class="text-gray-500">ID:</span> <span class="text-gray-300 font-mono">{{ log.id }}</span></div>
                                        <div><span class="text-gray-500">Action:</span> <span class="text-gray-300 font-mono">{{ log.action }}</span></div>
                                        <div><span class="text-gray-500">Data:</span> <span class="text-gray-300">{{ log.created_at }}</span></div>
                                        <div v-if="log.user_id"><span class="text-gray-500">User ID:</span> <span class="text-gray-300">{{ log.user_id }}</span></div>
                                        <div v-if="log.brand_id"><span class="text-gray-500">Brand ID:</span> <span class="text-gray-300">{{ log.brand_id }}</span></div>
                                        <div v-if="log.ip"><span class="text-gray-500">IP:</span> <span class="text-gray-300 font-mono">{{ log.ip }}</span></div>
                                    </div>

                                    <!-- Mensagem completa -->
                                    <div>
                                        <p class="text-xs text-gray-500 mb-1">Mensagem:</p>
                                        <p class="text-sm text-gray-200 bg-gray-900 rounded-lg p-3 font-mono text-[12px] leading-relaxed whitespace-pre-wrap break-all">{{ log.message }}</p>
                                    </div>

                                    <!-- Context JSON -->
                                    <div v-if="log.context">
                                        <p class="text-xs text-gray-500 mb-1">Context (JSON):</p>
                                        <pre class="text-[11px] text-emerald-400 bg-gray-900 rounded-lg p-3 overflow-x-auto max-h-64 font-mono leading-relaxed">{{ formatContext(log.context) }}</pre>
                                    </div>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>

                <!-- Paginacao -->
                <div v-if="logs.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t border-gray-800">
                    <p class="text-xs text-gray-500">
                        Mostrando {{ (logs.current_page - 1) * logs.per_page + 1 }} a {{ Math.min(logs.current_page * logs.per_page, logs.total) }} de {{ logs.total }}
                    </p>
                    <div class="flex gap-1">
                        <button
                            v-for="link in logs.links" :key="link.label"
                            @click="goToPage(link.url)"
                            :disabled="!link.url"
                            :class="[
                                'rounded-lg px-3 py-1.5 text-xs font-medium transition',
                                link.active ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white',
                                !link.url ? 'opacity-30 cursor-not-allowed' : 'cursor-pointer',
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
