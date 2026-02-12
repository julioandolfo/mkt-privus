<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';

interface KPI {
    key: string;
    label: string;
    value: number;
    format: string;
    icon: string;
    color: string;
    inverse?: boolean;
    variation?: number;
    compareValue?: number;
}

interface Connection {
    id: number;
    platform: string;
    platform_label: string;
    platform_color: string;
    name: string;
    external_name: string;
    is_active: boolean;
    sync_status: string;
    sync_error: string | null;
    last_synced_at: string | null;
}

const props = defineProps<{
    hasBrand: boolean;
    brand?: any;
    brands?: any[];
    brandFilter?: string;
    dashboardData?: {
        kpis: KPI[];
        charts: {
            dates: string[];
            website: Record<string, number[]>;
            ads: Record<string, number[]>;
            ecommerce: Record<string, number[]>;
            seo: Record<string, number[]>;
        };
        topDimensions: Record<string, any[]>;
        connections: Connection[];
    };
    connections?: Connection[];
    filters?: {
        start_date: string;
        end_date: string;
        preset: string;
        compare: boolean;
    };
}>();

const activeTab = ref('overview');
const activeBrandFilter = ref(props.brandFilter || 'all');
const datePreset = ref(props.filters?.preset || 'this_month');
const startDate = ref(props.filters?.start_date || '');
const endDate = ref(props.filters?.end_date || '');
const showCompare = ref(props.filters?.compare ?? true);
const syncing = ref(false);
const selectedChart = ref('sessions');

const presets = [
    { value: 'this_month', label: 'Este Mês' },
    { value: 'last_month', label: 'Mês Passado' },
    { value: '7d', label: '7 dias' },
    { value: '14d', label: '14 dias' },
    { value: '30d', label: '30 dias' },
    { value: '60d', label: '60 dias' },
    { value: '90d', label: '90 dias' },
    { value: 'custom', label: 'Personalizado' },
];

const guideSteps = [
    { title: 'Conecte suas plataformas', description: 'Vá em Conexões e integre Google Analytics, Google Ads, Meta Ads e Search Console via OAuth.' },
    { title: 'Sincronize os dados', description: 'Após conectar, clique em "Sincronizar Tudo" para importar os dados das plataformas.' },
    { title: 'Analise os resultados', description: 'Use os filtros de data, compare períodos e explore os detalhamentos de Website, Ads e SEO.' },
];

const guideTips = [
    'Os dados são armazenados localmente para consultas rápidas e comparações históricas',
    'Configure sincronizações automáticas nas Configurações do sistema',
    'Use o comparativo de períodos para identificar tendências de crescimento',
];

function applyPreset(preset: string) {
    datePreset.value = preset;
    if (preset === 'custom') return;

    const now = new Date();

    if (preset === 'this_month') {
        const start = new Date(now.getFullYear(), now.getMonth(), 1);
        startDate.value = start.toISOString().split('T')[0];
        endDate.value = now.toISOString().split('T')[0];
    } else if (preset === 'last_month') {
        const start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        const end = new Date(now.getFullYear(), now.getMonth(), 0);
        startDate.value = start.toISOString().split('T')[0];
        endDate.value = end.toISOString().split('T')[0];
    } else {
        const days = parseInt(preset);
        const end = new Date();
        const start = new Date();
        start.setDate(start.getDate() - days + 1);
        startDate.value = start.toISOString().split('T')[0];
        endDate.value = end.toISOString().split('T')[0];
    }

    router.get(route('analytics.index'), {
        start_date: startDate.value,
        end_date: endDate.value,
        preset: preset,
        compare: showCompare.value ? 'true' : 'false',
        brand_id: activeBrandFilter.value,
    }, { preserveState: true, preserveScroll: true });
}

function applyCustomDates() {
    if (!startDate.value || !endDate.value) return;
    router.get(route('analytics.index'), {
        start_date: startDate.value,
        end_date: endDate.value,
        preset: 'custom',
        compare: showCompare.value ? 'true' : 'false',
        brand_id: activeBrandFilter.value,
    }, { preserveState: true, preserveScroll: true });
}

function changeBrandFilter(brandId: string) {
    activeBrandFilter.value = brandId;
    router.get(route('analytics.index'), {
        start_date: startDate.value,
        end_date: endDate.value,
        preset: datePreset.value,
        compare: showCompare.value ? 'true' : 'false',
        brand_id: brandId,
    }, { preserveState: true, preserveScroll: true });
}

function syncAll() {
    syncing.value = true;
    router.post(route('analytics.sync-all'), {
        brand_id: props.brand?.id,
        start_date: startDate.value,
        end_date: endDate.value,
    }, {
        preserveScroll: true,
        onFinish: () => syncing.value = false,
    });
}

function formatValue(value: number, format: string): string {
    if (format === 'currency') return 'R$ ' + value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (format === 'percent') return value.toFixed(1) + '%';
    if (format === 'decimal') return value.toFixed(2);
    if (format === 'duration') {
        const mins = Math.floor(value / 60);
        const secs = Math.floor(value % 60);
        return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
    }
    return value.toLocaleString('pt-BR');
}

function getVariationColor(variation: number | undefined, inverse: boolean = false): string {
    if (variation === undefined) return 'text-gray-400';
    const isPositive = inverse ? variation < 0 : variation > 0;
    if (isPositive) return 'text-emerald-400';
    if ((inverse ? variation > 0 : variation < 0)) return 'text-red-400';
    return 'text-gray-400';
}

const kpiGroups = computed(() => {
    if (!props.dashboardData?.kpis) return [];
    const kpis = props.dashboardData.kpis;
    const groups = [
        { title: 'Website', kpis: kpis.filter(k => ['sessions', 'users', 'pageviews', 'bounce_rate', 'avg_session_duration'].includes(k.key)) },
        { title: 'Investimentos', kpis: kpis.filter(k => ['total_spend', 'ad_spend', 'manual_ad_spend', 'ad_clicks', 'ad_conversions', 'ad_roas'].includes(k.key)) },
    ];
    // Adicionar E-commerce apenas se houver dados
    const ecomKpis = kpis.filter(k => ['wc_orders', 'wc_revenue', 'wc_avg_order_value', 'real_roas'].includes(k.key));
    const hasEcomData = ecomKpis.some(k => k.value > 0);
    if (hasEcomData) {
        groups.push({ title: 'E-commerce', kpis: ecomKpis });
    }
    groups.push({ title: 'SEO', kpis: kpis.filter(k => ['search_clicks', 'search_impressions', 'search_position'].includes(k.key)) });
    return groups;
});

// SVG Chart
const chartWidth = 800;
const chartHeight = 280;
const chartPadding = { top: 20, right: 20, bottom: 30, left: 60 };

const chartOptions = [
    { value: 'sessions', label: 'Sessões', group: 'website', color: '#6366F1' },
    { value: 'users', label: 'Usuários', group: 'website', color: '#8B5CF6' },
    { value: 'pageviews', label: 'Pageviews', group: 'website', color: '#A78BFA' },
    { value: 'bounce_rate', label: 'Bounce Rate', group: 'website', color: '#EF4444' },
    { value: 'total_spend', label: 'Invest. Total', group: 'ads', color: '#F59E0B' },
    { value: 'spend', label: 'Invest. API', group: 'ads', color: '#D97706' },
    { value: 'clicks', label: 'Cliques Ads', group: 'ads', color: '#F97316' },
    { value: 'conversions', label: 'Conversões', group: 'ads', color: '#10B981' },
    { value: 'revenue', label: 'Receita Loja', group: 'ecommerce', color: '#A855F7' },
    { value: 'orders', label: 'Pedidos', group: 'ecommerce', color: '#8B5CF6' },
    { value: 'real_roas', label: 'ROAS Real', group: 'ecommerce', color: '#EC4899' },
];

const chartData = computed(() => {
    if (!props.dashboardData?.charts) return null;
    const charts = props.dashboardData.charts;
    const opt = chartOptions.find(o => o.value === selectedChart.value);
    if (!opt) return null;

    const groupData = charts[opt.group as keyof typeof charts] as Record<string, number[]>;
    const values = groupData?.[opt.value] || [];
    const dates = charts.dates || [];

    if (values.length === 0) return null;

    const maxVal = Math.max(...values, 1);
    const innerW = chartWidth - chartPadding.left - chartPadding.right;
    const innerH = chartHeight - chartPadding.top - chartPadding.bottom;

    const points = values.map((v, i) => ({
        x: chartPadding.left + (i / Math.max(values.length - 1, 1)) * innerW,
        y: chartPadding.top + innerH - (v / maxVal) * innerH,
        value: v,
        label: dates[i] || '',
    }));

    const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');
    const areaPath = linePath + ` L${points[points.length - 1].x},${chartPadding.top + innerH} L${points[0].x},${chartPadding.top + innerH} Z`;

    // Y axis labels
    const yLabels = [];
    for (let i = 0; i <= 4; i++) {
        const val = (maxVal / 4) * (4 - i);
        yLabels.push({
            value: opt.value === 'bounce_rate' ? val.toFixed(1) + '%' : formatValue(val, val >= 1000 ? 'number' : 'decimal'),
            y: chartPadding.top + (i / 4) * innerH,
        });
    }

    // X axis labels (show every Nth)
    const step = Math.max(1, Math.floor(dates.length / 8));
    const xLabels = dates.filter((_, i) => i % step === 0 || i === dates.length - 1).map((label, idx, arr) => ({
        label,
        x: chartPadding.left + (dates.indexOf(label) / Math.max(dates.length - 1, 1)) * innerW,
    }));

    return { points, linePath, areaPath, yLabels, xLabels, color: opt.color, label: opt.label };
});
</script>

<template>
    <Head title="Analytics" />
    <AuthenticatedLayout>
        <div class="p-4 lg:p-6 space-y-6">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white">Analytics</h1>
                    <p class="text-sm text-gray-400 mt-1">
                        Visão consolidada de todas as plataformas
                        <span v-if="brand" class="text-indigo-400">• {{ brand.name }}</span>
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        v-if="hasBrand && connections && connections.length > 0"
                        @click="syncAll"
                        :disabled="syncing"
                        class="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium transition"
                    >
                        <svg :class="['w-4 h-4', { 'animate-spin': syncing }]" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        {{ syncing ? 'Sincronizando...' : 'Sincronizar Tudo' }}
                    </button>
                    <Link :href="route('analytics.connections')" class="flex items-center gap-2 px-4 py-2 bg-gray-800 hover:bg-gray-700 text-white rounded-xl text-sm transition border border-gray-700">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                        </svg>
                        Conexões
                    </Link>
                </div>
            </div>

            <!-- Guide -->
            <GuideBox
                title="Como usar o Analytics"
                description="O módulo de Analytics centraliza dados de Google Analytics, Google Ads, Meta Ads e Search Console. Conecte suas plataformas e acompanhe tudo em um só lugar."
                :steps="guideSteps"
                :tips="guideTips"
                color="blue"
                storage-key="analytics-guide"
            />

            <!-- No brand -->
            <div v-if="!hasBrand" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-12 text-center">
                <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path d="M3 3v18h18"/>
                    <path d="M18 17V9m-4 8V5m-4 12v-4m-4 4v-2"/>
                </svg>
                <h3 class="text-lg font-semibold text-gray-400 mb-2">Nenhuma marca cadastrada</h3>
                <p class="text-sm text-gray-500 mb-4">Cadastre uma marca para começar a usar o Analytics.</p>
                <Link :href="route('brands.create')" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm transition">
                    Criar Marca
                </Link>
            </div>

            <template v-else>
                <!-- Filters -->
                <div class="bg-gray-900/50 rounded-2xl border border-gray-800 p-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <!-- Brand filter -->
                        <select v-if="brands && brands.length > 0"
                            :value="activeBrandFilter"
                            @change="changeBrandFilter(($event.target as HTMLSelectElement).value)"
                            class="rounded-xl bg-gray-800 border border-gray-700 text-sm text-white px-3 py-1.5 focus:border-indigo-500 focus:ring-indigo-500 min-w-[150px]">
                            <option value="all">Todas as Empresas</option>
                            <option v-for="b in brands" :key="b.id" :value="String(b.id)">{{ b.name }}</option>
                        </select>

                        <div class="w-px h-6 bg-gray-700" />

                        <!-- Presets -->
                        <div class="flex items-center gap-1 bg-gray-800/50 rounded-xl p-1">
                            <button
                                v-for="preset in presets"
                                :key="preset.value"
                                @click="applyPreset(preset.value)"
                                :class="[
                                    'px-3 py-1.5 text-xs font-medium rounded-lg transition',
                                    datePreset === preset.value ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-700'
                                ]"
                            >
                                {{ preset.label }}
                            </button>
                        </div>

                        <!-- Custom dates -->
                        <div v-if="datePreset === 'custom'" class="flex items-center gap-2">
                            <input v-model="startDate" type="date" class="bg-gray-800 border border-gray-700 text-white text-xs rounded-lg px-3 py-1.5 focus:ring-indigo-500 focus:border-indigo-500" />
                            <span class="text-gray-500 text-xs">até</span>
                            <input v-model="endDate" type="date" class="bg-gray-800 border border-gray-700 text-white text-xs rounded-lg px-3 py-1.5 focus:ring-indigo-500 focus:border-indigo-500" />
                            <button @click="applyCustomDates" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs rounded-lg transition">Aplicar</button>
                        </div>

                        <!-- Compare toggle -->
                        <label class="flex items-center gap-2 ml-auto cursor-pointer">
                            <input v-model="showCompare" type="checkbox" class="rounded border-gray-700 bg-gray-800 text-indigo-600 focus:ring-indigo-500" @change="applyPreset(datePreset)" />
                            <span class="text-xs text-gray-400">Comparar com período anterior</span>
                        </label>
                    </div>
                </div>

                <!-- No connections -->
                <div v-if="!connections || connections.length === 0" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-12 text-center">
                    <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                    <h3 class="text-lg font-semibold text-gray-400 mb-2">Nenhuma plataforma conectada</h3>
                    <p class="text-sm text-gray-500 mb-4">Conecte Google Analytics, Google Ads, Meta Ads ou Search Console para visualizar dados.</p>
                    <Link :href="route('analytics.connections')" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
                        Conectar Plataforma
                    </Link>
                </div>

                <template v-else-if="dashboardData">
                    <!-- KPI Groups -->
                    <div v-for="group in kpiGroups" :key="group.title" class="space-y-3">
                        <h3 v-if="group.kpis.length > 0" class="text-sm font-semibold text-gray-400 uppercase tracking-wider">{{ group.title }}</h3>
                        <div v-if="group.kpis.length > 0" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                            <div
                                v-for="kpi in group.kpis"
                                :key="kpi.key"
                                class="bg-gray-900/50 rounded-2xl border border-gray-800 p-4 hover:border-gray-700 transition"
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs text-gray-500 font-medium">{{ kpi.label }}</span>
                                    <span v-if="kpi.variation !== undefined" :class="['text-xs font-semibold', getVariationColor(kpi.variation, kpi.inverse)]">
                                        {{ kpi.variation > 0 ? '+' : '' }}{{ kpi.variation }}%
                                    </span>
                                </div>
                                <p class="text-xl font-bold text-white">{{ formatValue(kpi.value, kpi.format) }}</p>
                                <p v-if="kpi.compareValue !== undefined" class="text-[10px] text-gray-600 mt-1">
                                    vs. {{ formatValue(kpi.compareValue, kpi.format) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Chart -->
                    <div class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                            <h3 class="text-sm font-semibold text-white">Evolução Temporal</h3>
                            <div class="flex flex-wrap gap-1">
                                <button
                                    v-for="opt in chartOptions"
                                    :key="opt.value"
                                    @click="selectedChart = opt.value"
                                    :class="[
                                        'px-2.5 py-1 text-[11px] font-medium rounded-lg transition',
                                        selectedChart === opt.value
                                            ? 'text-white'
                                            : 'text-gray-500 hover:text-gray-300 hover:bg-gray-800'
                                    ]"
                                    :style="selectedChart === opt.value ? { backgroundColor: opt.color } : {}"
                                >
                                    {{ opt.label }}
                                </button>
                            </div>
                        </div>

                        <div v-if="chartData" class="w-full overflow-x-auto">
                            <svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" class="w-full h-auto" preserveAspectRatio="xMidYMid meet">
                                <!-- Grid lines -->
                                <line v-for="yl in chartData.yLabels" :key="'gl'+yl.y" :x1="chartPadding.left" :y1="yl.y" :x2="chartWidth - chartPadding.right" :y2="yl.y" stroke="#1F2937" stroke-width="0.5"/>

                                <!-- Area -->
                                <defs>
                                    <linearGradient :id="'areaGrad-' + selectedChart" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" :stop-color="chartData.color" stop-opacity="0.3"/>
                                        <stop offset="100%" :stop-color="chartData.color" stop-opacity="0"/>
                                    </linearGradient>
                                </defs>
                                <path :d="chartData.areaPath" :fill="`url(#areaGrad-${selectedChart})`"/>

                                <!-- Line -->
                                <path :d="chartData.linePath" fill="none" :stroke="chartData.color" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>

                                <!-- Points -->
                                <circle v-for="(p, i) in chartData.points" :key="'p'+i" :cx="p.x" :cy="p.y" r="3" :fill="chartData.color" stroke="#111827" stroke-width="1.5"/>

                                <!-- Y Labels -->
                                <text v-for="yl in chartData.yLabels" :key="'yl'+yl.y" :x="chartPadding.left - 8" :y="yl.y + 3" text-anchor="end" fill="#6B7280" font-size="9">{{ yl.value }}</text>

                                <!-- X Labels -->
                                <text v-for="xl in chartData.xLabels" :key="'xl'+xl.label" :x="xl.x" :y="chartHeight - 5" text-anchor="middle" fill="#6B7280" font-size="9">{{ xl.label }}</text>
                            </svg>
                        </div>
                        <div v-else class="flex items-center justify-center h-48 text-gray-600 text-sm">
                            Sem dados para o período selecionado
                        </div>
                    </div>

                    <!-- Detailed Tables -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Top Sources -->
                        <div v-if="dashboardData.topDimensions?.sources?.length" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                            <h3 class="text-sm font-semibold text-white mb-3">Top Fontes de Tráfego</h3>
                            <div class="space-y-2">
                                <div v-for="(item, i) in dashboardData.topDimensions.sources.slice(0, 8)" :key="i" class="flex items-center gap-3">
                                    <span class="text-xs text-gray-500 w-5 text-right">{{ i + 1 }}.</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs text-gray-300 truncate">{{ item.name }}</span>
                                            <span class="text-xs text-gray-400 font-medium tabular-nums">{{ Number(item.value).toLocaleString('pt-BR') }}</span>
                                        </div>
                                        <div class="w-full bg-gray-800 rounded-full h-1">
                                            <div class="bg-indigo-500 h-1 rounded-full" :style="{ width: Math.min(100, (item.value / (dashboardData.topDimensions.sources[0]?.value || 1)) * 100) + '%' }"/>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Top Pages -->
                        <div v-if="dashboardData.topDimensions?.pages?.length" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                            <h3 class="text-sm font-semibold text-white mb-3">Páginas Mais Visitadas</h3>
                            <div class="space-y-2">
                                <div v-for="(item, i) in dashboardData.topDimensions.pages.slice(0, 8)" :key="i" class="flex items-center gap-3">
                                    <span class="text-xs text-gray-500 w-5 text-right">{{ i + 1 }}.</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs text-gray-300 truncate" :title="item.name">{{ item.name }}</span>
                                            <span class="text-xs text-gray-400 font-medium tabular-nums">{{ Number(item.value).toLocaleString('pt-BR') }}</span>
                                        </div>
                                        <div class="w-full bg-gray-800 rounded-full h-1">
                                            <div class="bg-purple-500 h-1 rounded-full" :style="{ width: Math.min(100, (item.value / (dashboardData.topDimensions.pages[0]?.value || 1)) * 100) + '%' }"/>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Top Queries (SEO) -->
                        <div v-if="dashboardData.topDimensions?.queries?.length" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                            <h3 class="text-sm font-semibold text-white mb-3">Principais Termos de Busca (SEO)</h3>
                            <div class="space-y-2">
                                <div v-for="(item, i) in dashboardData.topDimensions.queries.slice(0, 10)" :key="i" class="flex items-center gap-3">
                                    <span class="text-xs text-gray-500 w-5 text-right">{{ i + 1 }}.</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs text-gray-300 truncate">{{ item.name }}</span>
                                            <div class="flex items-center gap-3">
                                                <span class="text-[10px] text-gray-500">pos {{ item.extra?.position?.toFixed(1) || '-' }}</span>
                                                <span class="text-xs text-gray-400 font-medium tabular-nums">{{ Number(item.value).toLocaleString('pt-BR') }} cliques</span>
                                            </div>
                                        </div>
                                        <div class="w-full bg-gray-800 rounded-full h-1">
                                            <div class="bg-green-500 h-1 rounded-full" :style="{ width: Math.min(100, (item.value / (dashboardData.topDimensions.queries[0]?.value || 1)) * 100) + '%' }"/>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Campaigns -->
                        <div v-if="dashboardData.topDimensions?.campaigns?.length" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                            <h3 class="text-sm font-semibold text-white mb-3">Campanhas de Ads</h3>
                            <div class="space-y-2">
                                <div v-for="(item, i) in dashboardData.topDimensions.campaigns.slice(0, 8)" :key="i" class="flex items-center gap-3">
                                    <span class="text-xs text-gray-500 w-5 text-right">{{ i + 1 }}.</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between mb-1">
                                            <div class="flex items-center gap-2 truncate">
                                                <span
                                                    class="w-2 h-2 rounded-full shrink-0"
                                                    :class="item.platform === 'google_ads' ? 'bg-blue-400' : 'bg-blue-600'"
                                                />
                                                <span class="text-xs text-gray-300 truncate">{{ item.name }}</span>
                                            </div>
                                            <span class="text-xs text-gray-400 font-medium tabular-nums">
                                                R$ {{ Number(item.value).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}
                                            </span>
                                        </div>
                                        <div class="w-full bg-gray-800 rounded-full h-1">
                                            <div class="bg-amber-500 h-1 rounded-full" :style="{ width: Math.min(100, (item.value / (dashboardData.topDimensions.campaigns[0]?.value || 1)) * 100) + '%' }"/>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Top Products (WooCommerce) -->
                        <div v-if="dashboardData.topDimensions?.products?.length" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                            <h3 class="text-sm font-semibold text-white mb-3">Top Produtos (WooCommerce)</h3>
                            <div class="space-y-2">
                                <div v-for="(item, i) in dashboardData.topDimensions.products.slice(0, 8)" :key="i" class="flex items-center gap-3">
                                    <span class="text-xs text-gray-500 w-5 text-right">{{ i + 1 }}.</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs text-gray-300 truncate">{{ item.name }}</span>
                                            <div class="flex items-center gap-2">
                                                <span v-if="item.extra?.quantity" class="text-[10px] text-gray-500">{{ item.extra.quantity }}x</span>
                                                <span class="text-xs text-purple-400 font-medium tabular-nums">R$ {{ Number(item.value).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}</span>
                                            </div>
                                        </div>
                                        <div class="w-full bg-gray-800 rounded-full h-1">
                                            <div class="bg-purple-500 h-1 rounded-full" :style="{ width: Math.min(100, (item.value / (dashboardData.topDimensions.products[0]?.value || 1)) * 100) + '%' }"/>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Devices -->
                        <div v-if="dashboardData.topDimensions?.devices?.length" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                            <h3 class="text-sm font-semibold text-white mb-3">Dispositivos</h3>
                            <div class="space-y-3">
                                <div v-for="(item, i) in dashboardData.topDimensions.devices" :key="i" class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-gray-800 flex items-center justify-center">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                            <path v-if="item.name === 'desktop'" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                            <path v-else-if="item.name === 'mobile'" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                            <path v-else d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs text-gray-300 capitalize">{{ item.name }}</span>
                                            <span class="text-xs text-gray-400 font-medium tabular-nums">{{ Number(item.value).toLocaleString('pt-BR') }}</span>
                                        </div>
                                        <div class="w-full bg-gray-800 rounded-full h-1.5">
                                            <div class="bg-cyan-500 h-1.5 rounded-full" :style="{ width: Math.min(100, (item.value / (dashboardData.topDimensions.devices[0]?.value || 1)) * 100) + '%' }"/>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Connected Platforms Status -->
                    <div class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-semibold text-white">Plataformas Conectadas</h3>
                            <Link :href="route('analytics.connections')" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Gerenciar</Link>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div
                                v-for="conn in connections"
                                :key="conn.id"
                                class="flex items-center gap-3 p-3 bg-gray-800/50 rounded-xl border border-gray-800"
                            >
                                <div class="w-3 h-3 rounded-full shrink-0" :style="{ backgroundColor: conn.platform_color }"/>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-medium text-gray-300 truncate">{{ conn.name }}</p>
                                    <p class="text-[10px] text-gray-500">{{ conn.platform_label }}</p>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span
                                        :class="[
                                            'w-2 h-2 rounded-full',
                                            conn.sync_status === 'success' ? 'bg-emerald-400' :
                                            conn.sync_status === 'syncing' ? 'bg-amber-400 animate-pulse' :
                                            conn.sync_status === 'error' ? 'bg-red-400' : 'bg-gray-600'
                                        ]"
                                    />
                                    <span class="text-[10px] text-gray-500">
                                        {{ conn.sync_status === 'success' ? conn.last_synced_at || 'Sincronizado' :
                                           conn.sync_status === 'syncing' ? 'Sincronizando...' :
                                           conn.sync_status === 'error' ? 'Erro' : 'Pendente' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Nav -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <Link :href="route('analytics.website')" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5 hover:border-orange-500/30 transition group">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-orange-500/10 flex items-center justify-center">
                                    <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-white group-hover:text-orange-300 transition">Website</h4>
                                    <p class="text-xs text-gray-500">Tráfego, sessões, páginas e dispositivos</p>
                                </div>
                            </div>
                        </Link>
                        <Link :href="route('analytics.ads')" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5 hover:border-amber-500/30 transition group">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center">
                                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-white group-hover:text-amber-300 transition">Ads</h4>
                                    <p class="text-xs text-gray-500">Investimento, cliques, ROAS e campanhas</p>
                                </div>
                            </div>
                        </Link>
                        <Link :href="route('analytics.seo')" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5 hover:border-green-500/30 transition group">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-green-500/10 flex items-center justify-center">
                                    <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-white group-hover:text-green-300 transition">SEO</h4>
                                    <p class="text-xs text-gray-500">Posição, impressões, CTR e queries</p>
                                </div>
                            </div>
                        </Link>
                    </div>
                </template>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
