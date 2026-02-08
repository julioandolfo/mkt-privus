<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface Category {
    id: number;
    name: string;
    slug: string;
    color: string;
    icon: string;
    metrics_count: number;
}

interface Metric {
    id: number;
    name: string;
    description: string | null;
    category: string | null;
    category_id: number | null;
    category_color: string;
    unit: string;
    value_type: string;
    color: string;
    icon: string;
    direction: string;
    platform: string | null;
    tags: string[];
    tracking_frequency: string;
    goal_value: number | null;
    goal_period: string | null;
    goal_name: string | null;
    goal_days_remaining: number | null;
    goal_time_elapsed: number | null;
    latest_value: number | null;
    latest_date: string | null;
    formatted_value: string;
    variation: number | null;
    variation_positive: boolean;
    goal_progress: number | null;
    entries_count: number;
}

interface Summary {
    total_metrics: number;
    with_goals: number;
    on_track: number;
    needs_attention: number;
}

const props = defineProps<{
    metrics: Metric[];
    categories: Category[];
    allTags: string[];
    usedPlatforms: string[];
    availablePlatforms: Record<string, string>;
    summary: Summary;
}>();

const selectedCategory = ref<number | null>(null);
const selectedPlatform = ref<string | null>(null);
const selectedTag = ref<string | null>(null);
const searchQuery = ref('');
const viewMode = ref<'grid' | 'list'>('grid');

const filteredMetrics = computed(() => {
    let result = props.metrics;

    if (selectedCategory.value) {
        result = result.filter(m => m.category_id === selectedCategory.value);
    }

    if (selectedPlatform.value) {
        result = result.filter(m => m.platform === selectedPlatform.value);
    }

    if (selectedTag.value) {
        result = result.filter(m => m.tags?.includes(selectedTag.value!));
    }

    if (searchQuery.value) {
        const q = searchQuery.value.toLowerCase();
        result = result.filter(m =>
            m.name.toLowerCase().includes(q) ||
            m.description?.toLowerCase().includes(q) ||
            m.category?.toLowerCase().includes(q) ||
            m.tags?.some(t => t.toLowerCase().includes(q))
        );
    }

    return result;
});

const hasActiveFilters = computed(() => selectedCategory.value || selectedPlatform.value || selectedTag.value || searchQuery.value);

function clearFilters() {
    selectedCategory.value = null;
    selectedPlatform.value = null;
    selectedTag.value = null;
    searchQuery.value = '';
}

function deleteMetric(metric: Metric) {
    if (confirm(`Excluir a metrica "${metric.name}"?`)) {
        router.delete(route('metrics.destroy', metric.id));
    }
}

const platformLabels: Record<string, string> = {
    instagram: 'Instagram', facebook: 'Facebook', linkedin: 'LinkedIn',
    tiktok: 'TikTok', youtube: 'YouTube', pinterest: 'Pinterest',
    google_ads: 'Google Ads', meta_ads: 'Meta Ads', google_analytics: 'Analytics',
    website: 'Website', email_marketing: 'Email Mkt', other: 'Outro',
};

const frequencyLabels: Record<string, string> = {
    daily: 'Diario', weekly: 'Semanal', biweekly: 'Quinzenal',
    monthly: 'Mensal', quarterly: 'Trimestral', yearly: 'Anual', custom: 'Custom',
};
</script>

<template>
    <Head title="Metricas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-white">Metricas</h1>
                <Link :href="route('metrics.create')" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
                    Nova Metrica
                </Link>
            </div>
        </template>

        <!-- Summary cards -->
        <div v-if="metrics.length > 0" class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-6">
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-2xl font-bold text-white">{{ summary.total_metrics }}</p>
                <p class="text-xs text-gray-500 mt-0.5">Metricas ativas</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-2xl font-bold text-indigo-400">{{ summary.with_goals }}</p>
                <p class="text-xs text-gray-500 mt-0.5">Com metas definidas</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-2xl font-bold text-emerald-400">{{ summary.on_track }}</p>
                <p class="text-xs text-gray-500 mt-0.5">No ritmo da meta</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-2xl font-bold" :class="summary.needs_attention > 0 ? 'text-amber-400' : 'text-gray-600'">{{ summary.needs_attention }}</p>
                <p class="text-xs text-gray-500 mt-0.5">Atencao necessaria</p>
            </div>
        </div>

        <!-- Filters -->
        <div v-if="metrics.length > 0" class="space-y-3 mb-6">
            <!-- Search -->
            <div class="flex items-center gap-3">
                <div class="relative flex-1 max-w-sm">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
                    <input v-model="searchQuery" type="text" placeholder="Buscar metrica..." class="w-full pl-10 rounded-xl bg-gray-900 border-gray-800 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
                <button v-if="hasActiveFilters" @click="clearFilters" class="rounded-lg bg-gray-800 px-3 py-2 text-xs text-gray-400 hover:text-white transition">
                    Limpar filtros
                </button>
            </div>

            <!-- Category + Platform + Tag filters -->
            <div class="flex items-center gap-2 flex-wrap">
                <!-- Categories -->
                <button
                    @click="selectedCategory = null"
                    :class="['rounded-lg px-3 py-1.5 text-xs font-medium transition', !selectedCategory ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white']"
                >
                    Todas
                </button>
                <button
                    v-for="cat in categories"
                    :key="cat.id"
                    @click="selectedCategory = selectedCategory === cat.id ? null : cat.id"
                    :class="['rounded-lg px-3 py-1.5 text-xs font-medium transition border', selectedCategory === cat.id ? 'text-white border-current' : 'border-transparent bg-gray-800 text-gray-400 hover:text-white']"
                    :style="selectedCategory === cat.id ? { borderColor: cat.color, color: cat.color } : {}"
                >
                    {{ cat.name }} ({{ cat.metrics_count }})
                </button>

                <!-- Separador -->
                <span v-if="usedPlatforms.length > 0" class="text-gray-700">|</span>

                <!-- Platforms -->
                <button
                    v-for="p in usedPlatforms"
                    :key="p"
                    @click="selectedPlatform = selectedPlatform === p ? null : p"
                    :class="['rounded-lg px-2.5 py-1.5 text-[11px] font-medium transition', selectedPlatform === p ? 'bg-purple-600/20 text-purple-400 border border-purple-500/30' : 'bg-gray-800 text-gray-500 hover:text-white']"
                >
                    {{ platformLabels[p] || p }}
                </button>
            </div>

            <!-- Tags -->
            <div v-if="allTags.length > 0" class="flex items-center gap-1.5 flex-wrap">
                <span class="text-[10px] text-gray-600 uppercase tracking-wider mr-1">Tags:</span>
                <button
                    v-for="tag in allTags"
                    :key="tag"
                    @click="selectedTag = selectedTag === tag ? null : tag"
                    :class="['rounded-md px-2 py-0.5 text-[10px] font-medium transition', selectedTag === tag ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/30' : 'bg-gray-800/50 text-gray-500 hover:text-white']"
                >
                    {{ tag }}
                </button>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="metrics.length === 0" class="flex flex-col items-center justify-center rounded-2xl bg-gray-900 border border-gray-800 p-16 text-center">
            <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-600/20 mb-6">
                <svg class="w-8 h-8 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18" /><polyline points="17 6 23 6 23 12" /></svg>
            </div>
            <h3 class="text-xl font-semibold text-white mb-2">Nenhuma metrica criada</h3>
            <p class="text-gray-400 mb-6 max-w-md">
                Crie metricas personalizadas para acompanhar KPIs, custos de campanhas, crescimento de seguidores, ROI, engajamento e qualquer outro indicador.
            </p>
            <Link :href="route('metrics.create')" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                Criar primeira metrica
            </Link>
        </div>

        <!-- No results -->
        <div v-else-if="filteredMetrics.length === 0" class="rounded-2xl bg-gray-900 border border-gray-800 p-10 text-center">
            <p class="text-gray-500">Nenhuma metrica encontrada com os filtros selecionados.</p>
            <button @click="clearFilters" class="mt-3 text-sm text-indigo-400 hover:text-indigo-300">Limpar filtros</button>
        </div>

        <!-- Metrics grid -->
        <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="metric in filteredMetrics"
                :key="metric.id"
                :href="route('metrics.show', metric.id)"
                class="group rounded-2xl bg-gray-900 border border-gray-800 p-5 hover:border-gray-700 transition-all"
            >
                <!-- Header -->
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl shrink-0" :style="{ backgroundColor: metric.color + '20' }">
                            <svg class="w-4 h-4" :style="{ color: metric.color }" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18" /><polyline points="17 6 23 6 23 12" /></svg>
                        </div>
                        <div class="min-w-0">
                            <h3 class="font-medium text-white text-sm group-hover:text-indigo-400 transition truncate">{{ metric.name }}</h3>
                            <div class="flex items-center gap-1.5 mt-0.5">
                                <span v-if="metric.category" class="text-[10px] text-gray-500">{{ metric.category }}</span>
                                <span v-if="metric.platform" class="text-[10px] text-purple-500">{{ platformLabels[metric.platform] || metric.platform }}</span>
                            </div>
                        </div>
                    </div>
                    <button @click.prevent="deleteMetric(metric)" class="opacity-0 group-hover:opacity-100 flex items-center justify-center h-6 w-6 rounded-lg text-gray-600 hover:bg-red-500/10 hover:text-red-400 transition shrink-0">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></svg>
                    </button>
                </div>

                <!-- Value -->
                <div class="mb-3">
                    <span class="text-2xl font-bold text-white">{{ metric.formatted_value }}</span>
                    <span v-if="metric.variation !== null" :class="['ml-2 text-xs font-semibold', metric.variation_positive ? 'text-emerald-400' : 'text-red-400']">
                        {{ metric.variation >= 0 ? '+' : '' }}{{ metric.variation }}%
                        <span class="text-[10px]">{{ metric.direction === 'up' ? '↑' : metric.direction === 'down' ? '↓' : '→' }}</span>
                    </span>
                </div>

                <!-- Goal progress -->
                <div v-if="metric.goal_value && metric.goal_progress !== null" class="mb-3">
                    <div class="flex items-center justify-between text-[10px] text-gray-500 mb-1">
                        <span>{{ metric.goal_name || 'Meta' }}</span>
                        <span>{{ Math.round(metric.goal_progress) }}%</span>
                    </div>
                    <div class="h-1.5 rounded-full bg-gray-800 overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-500" :style="{ width: Math.min(100, metric.goal_progress) + '%', backgroundColor: metric.color }" />
                    </div>
                    <div v-if="metric.goal_days_remaining !== null" class="text-[10px] text-gray-600 mt-1">
                        {{ metric.goal_days_remaining }} dias restantes
                    </div>
                </div>

                <!-- Tags + footer -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-1 flex-wrap">
                        <span v-for="tag in (metric.tags || []).slice(0, 3)" :key="tag" class="rounded-md bg-gray-800 px-1.5 py-0.5 text-[9px] text-gray-500">{{ tag }}</span>
                    </div>
                    <div class="text-[10px] text-gray-600">
                        <span v-if="metric.latest_date">{{ metric.latest_date }}</span>
                        <span v-else>Sem registros</span>
                    </div>
                </div>
            </Link>
        </div>
    </AuthenticatedLayout>
</template>
