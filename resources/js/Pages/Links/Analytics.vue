<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps<{
    page: { id: number; title: string; slug: string; public_url: string; total_views: number; total_clicks: number };
    period: string;
    clicksByDay: { date: string; clicks: number }[];
    topBlocks: { block_index: number; block_label: string; block_type: string; clicks: number }[];
    devices: Record<string, number>;
    referrers: { source: string; total: number }[];
}>();

const activePeriod = ref(props.period);

function changePeriod(p: string) {
    activePeriod.value = p;
    router.get(route('links.analytics', props.page.id), { period: p }, { preserveState: true, preserveScroll: true });
}

const ctr = computed(() => {
    if (!props.page.total_views) return '0%';
    return ((props.page.total_clicks / props.page.total_views) * 100).toFixed(1) + '%';
});

const maxClicks = computed(() => Math.max(...props.clicksByDay.map(d => d.clicks), 1));

const totalDevices = computed(() => Object.values(props.devices).reduce((a, b) => a + b, 0) || 1);

const deviceLabels: Record<string, string> = { mobile: 'Mobile', desktop: 'Desktop', tablet: 'Tablet' };

function formatNumber(n: number): string {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return String(n);
}
</script>

<template>
    <Head :title="`Analytics - ${page.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('links.index')" class="text-gray-500 hover:text-white transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                    </Link>
                    <div>
                        <h1 class="text-xl font-semibold text-white">Analytics: {{ page.title }}</h1>
                        <p class="text-xs text-gray-500">{{ page.public_url }}</p>
                    </div>
                </div>
                <Link :href="route('links.editor', page.id)" class="rounded-xl border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:text-white transition">
                    Editar
                </Link>
            </div>
        </template>

        <!-- Period selector -->
        <div class="flex gap-1 mb-6 bg-gray-900 rounded-xl p-1 w-fit">
            <button v-for="p in [{ key: '24h', label: '24h' }, { key: '7d', label: '7 dias' }, { key: '30d', label: '30 dias' }, { key: '90d', label: '90 dias' }]"
                :key="p.key" @click="changePeriod(p.key)"
                :class="['rounded-lg px-4 py-2 text-xs font-medium transition',
                    activePeriod === p.key ? 'bg-indigo-600 text-white' : 'text-gray-500 hover:text-gray-300']">
                {{ p.label }}
            </button>
        </div>

        <!-- Overview stats -->
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5 text-center">
                <p class="text-3xl font-bold text-white">{{ formatNumber(page.total_views) }}</p>
                <p class="text-xs text-gray-500 mt-1">Visualizações</p>
            </div>
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5 text-center">
                <p class="text-3xl font-bold text-indigo-400">{{ formatNumber(page.total_clicks) }}</p>
                <p class="text-xs text-gray-500 mt-1">Cliques</p>
            </div>
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5 text-center">
                <p class="text-3xl font-bold text-emerald-400">{{ ctr }}</p>
                <p class="text-xs text-gray-500 mt-1">CTR</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Clicks chart -->
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                <h3 class="text-sm font-semibold text-white mb-4">Cliques por Dia</h3>
                <div v-if="clicksByDay.length > 0" class="space-y-1.5">
                    <div v-for="day in clicksByDay" :key="day.date" class="flex items-center gap-2">
                        <span class="text-[10px] text-gray-500 w-16 shrink-0">{{ day.date.slice(5) }}</span>
                        <div class="flex-1 bg-gray-800 rounded-full h-4 overflow-hidden">
                            <div class="h-full bg-indigo-600 rounded-full transition-all" :style="{ width: (day.clicks / maxClicks * 100) + '%' }" />
                        </div>
                        <span class="text-[10px] text-gray-400 w-8 text-right shrink-0">{{ day.clicks }}</span>
                    </div>
                </div>
                <p v-else class="text-xs text-gray-500">Sem dados no período.</p>
            </div>

            <!-- Top blocks -->
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                <h3 class="text-sm font-semibold text-white mb-4">Links Mais Clicados</h3>
                <div v-if="topBlocks.length > 0" class="space-y-2">
                    <div v-for="(block, i) in topBlocks" :key="i" class="flex items-center justify-between py-2 border-b border-gray-800 last:border-0">
                        <div class="min-w-0">
                            <p class="text-sm text-white truncate">{{ block.block_label || `Bloco #${block.block_index}` }}</p>
                            <p class="text-[10px] text-gray-600">{{ block.block_type }}</p>
                        </div>
                        <span class="text-sm font-bold text-indigo-400 shrink-0">{{ block.clicks }}</span>
                    </div>
                </div>
                <p v-else class="text-xs text-gray-500">Sem cliques no período.</p>
            </div>

            <!-- Devices -->
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                <h3 class="text-sm font-semibold text-white mb-4">Dispositivos</h3>
                <div v-if="Object.keys(devices).length > 0" class="space-y-3">
                    <div v-for="(count, device) in devices" :key="device">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-sm text-gray-300">{{ deviceLabels[device] || device }}</span>
                            <span class="text-xs text-gray-500">{{ ((count / totalDevices) * 100).toFixed(0) }}%</span>
                        </div>
                        <div class="w-full bg-gray-800 rounded-full h-2">
                            <div class="h-full rounded-full transition-all"
                                :class="device === 'mobile' ? 'bg-indigo-500' : device === 'desktop' ? 'bg-emerald-500' : 'bg-purple-500'"
                                :style="{ width: (count / totalDevices * 100) + '%' }" />
                        </div>
                    </div>
                </div>
                <p v-else class="text-xs text-gray-500">Sem dados.</p>
            </div>

            <!-- Referrers -->
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                <h3 class="text-sm font-semibold text-white mb-4">Fontes de Tráfego</h3>
                <div v-if="referrers.length > 0" class="space-y-2">
                    <div v-for="ref in referrers" :key="ref.source" class="flex items-center justify-between py-1.5">
                        <span class="text-sm text-gray-300 truncate">{{ ref.source }}</span>
                        <span class="text-xs text-gray-500 shrink-0 ml-2">{{ ref.total }}</span>
                    </div>
                </div>
                <p v-else class="text-xs text-gray-500">Sem dados de referência.</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
