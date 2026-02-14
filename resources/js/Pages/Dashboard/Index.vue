<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

interface SocialAccountData {
    id: number;
    platform: string;
    display_name: string;
    username: string;
    avatar_url: string | null;
    followers_count: number | null;
    followers_variation: number | null;
    net_followers: number | null;
    engagement: number | null;
    engagement_rate: number | null;
    reach: number | null;
    impressions: number | null;
    likes: number | null;
    comments: number | null;
    saves: number | null;
    last_sync: string | null;
}

interface MetricData {
    id: number;
    name: string;
    description: string | null;
    category: string | null;
    unit: string;
    color: string;
    icon: string;
    direction: string;
    platform: string | null;
    auto_sync: boolean;
    latest_value: number | null;
    latest_date: string | null;
    formatted_value: string;
    variation: number | null;
    variation_positive: boolean;
    goal_value: number | null;
    goal_progress: number | null;
    goal_name: string | null;
    sparkline: number[];
}

interface GoalData {
    id: number;
    name: string;
    metric_name: string;
    metric_color: string;
    target_value: number;
    target_formatted: string;
    current_value: number | null;
    current_formatted: string;
    progress: number;
    period: string;
    start_date: string;
    end_date: string;
    days_remaining: number;
    time_elapsed: number;
    is_on_track: boolean;
    achieved: boolean;
}

interface ChartPoint {
    date: string;
    date_full: string;
    followers: number;
    engagement: number;
    reach: number;
    impressions: number;
}

interface EmailSummary {
    has_email: boolean;
    campaigns_sent: number;
    total_sent: number;
    total_delivered: number;
    total_bounced: number;
    total_opened: number;
    total_clicked: number;
    total_unsubscribed: number;
    unique_opens: number;
    unique_clicks: number;
    open_rate: number;
    click_rate: number;
    bounce_rate: number;
    delivery_rate: number;
    total_contacts: number;
    active_contacts: number;
    prev_total_sent: number;
    prev_open_rate: number;
    prev_click_rate: number;
    pending_suggestions: number;
}

interface AnalyticsSummary {
    sessions: number;
    sessions_variation: number | null;
    users: number;
    users_variation: number | null;
    pageviews: number;
    bounce_rate: number;
    avg_session_duration: number;
    ad_spend: number;
    ad_spend_variation: number | null;
    manual_spend: number;
    total_spend: number;
    ad_clicks: number;
    ad_conversions: number;
    ad_impressions: number;
    ad_roas: number;
    search_clicks: number;
    search_clicks_variation: number | null;
    search_impressions: number;
    search_position: number;
    wc_revenue: number;
    wc_revenue_variation: number | null;
    wc_orders: number;
    wc_avg_order_value: number;
    real_roas: number;
    has_website: boolean;
    has_ads: boolean;
    has_seo: boolean;
    has_wc: boolean;
    has_any: boolean;
    connections: { platform: string; name: string; label: string; color: string; sync_status: string; last_synced_at: string | null }[];
    connections_count: number;
}

const props = defineProps<{
    stats: {
        posts_this_month: number;
        scheduled_posts: number;
        published_posts: number;
        connected_platforms: number;
    };
    socialAccounts: SocialAccountData[];
    socialSummary: {
        total_followers: number;
        followers_growth: number | null;
        total_accounts: number;
    };
    metrics: MetricData[];
    activeGoals: GoalData[];
    followersChart: ChartPoint[];
    recentActivity: any[];
    analyticsSummary?: AnalyticsSummary | null;
    emailSummary?: EmailSummary | null;
    smsSummary?: {
        has_sms: boolean;
        campaigns_sent: number;
        total_sent: number;
        total_delivered: number;
        total_failed: number;
        total_clicked: number;
        delivery_rate: number;
        click_rate: number;
        prev_total_sent: number;
        pending_suggestions: number;
    } | null;
    period?: string;
    periodLabel?: string;
    periodStart?: string;
    periodEnd?: string;
    brandFilter?: string;
    allBrands?: { id: number; name: string }[];
}>();

const chartMetric = ref<'followers' | 'engagement' | 'reach' | 'impressions'>('followers');

// Brand filter - sincroniza com props do server
const activeBrandFilter = ref(props.brandFilter || 'all');
watch(() => props.brandFilter, (v) => { if (v !== undefined) activeBrandFilter.value = v || 'all'; });

function changeBrand(brandId: string) {
    const params: Record<string, string> = { brand: brandId, period: activePeriod.value };
    if (activePeriod.value === 'custom' && customStart.value && customEnd.value) {
        params.start = customStart.value;
        params.end = customEnd.value;
    }
    router.get(route('dashboard'), params, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => { activeBrandFilter.value = brandId; },
    });
}

// Period filter - sincroniza com props do server
const activePeriod = ref(props.period || 'this_month');
const showCustomPicker = ref(false);
const customStart = ref(props.periodStart || '');
const customEnd = ref(props.periodEnd || '');

// Manter refs sincronizados quando o server retorna novos dados
watch(() => props.period, (v) => { if (v) activePeriod.value = v; });
watch(() => props.periodStart, (v) => { if (v) customStart.value = v; });
watch(() => props.periodEnd, (v) => { if (v) customEnd.value = v; });
const loadingPeriod = ref(false);

const periodFilters = [
    { value: 'today', label: 'Hoje' },
    { value: 'yesterday', label: 'Ontem' },
    { value: 'this_week', label: 'Esta Semana' },
    { value: 'this_month', label: 'Este Mes' },
    { value: 'last_month', label: 'Mes Passado' },
    { value: 'last_7', label: '7 dias' },
    { value: 'last_30', label: '30 dias' },
    { value: 'custom', label: 'Personalizado' },
];

function changePeriod(period: string) {
    if (period === 'custom') {
        showCustomPicker.value = true;
        return;
    }
    showCustomPicker.value = false;
    loadingPeriod.value = true;
    router.get(route('dashboard'), { period, brand: activeBrandFilter.value }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => { activePeriod.value = period; },
        onError: () => { /* manter periodo anterior se requisicao falhar */ },
        onFinish: () => { loadingPeriod.value = false; },
    });
}

function applyCustomPeriod() {
    if (!customStart.value || !customEnd.value) return;
    showCustomPicker.value = false;
    loadingPeriod.value = true;
    router.get(route('dashboard'), { period: 'custom', start: customStart.value, end: customEnd.value, brand: activeBrandFilter.value }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => { activePeriod.value = 'custom'; },
        onFinish: () => { loadingPeriod.value = false; },
    });
}

const platformInfo: Record<string, { name: string; color: string; bg: string }> = {
    instagram: { name: 'Instagram', color: '#E4405F', bg: 'bg-pink-500/10' },
    facebook: { name: 'Facebook', color: '#1877F2', bg: 'bg-blue-500/10' },
    youtube: { name: 'YouTube', color: '#FF0000', bg: 'bg-red-500/10' },
    tiktok: { name: 'TikTok', color: '#000000', bg: 'bg-gray-500/10' },
    linkedin: { name: 'LinkedIn', color: '#0A66C2', bg: 'bg-blue-600/10' },
    pinterest: { name: 'Pinterest', color: '#E60023', bg: 'bg-red-600/10' },
};

const statusColors: Record<string, string> = {
    published: 'text-emerald-400',
    scheduled: 'text-blue-400',
    draft: 'text-gray-400',
    failed: 'text-red-400',
};

const statusLabels: Record<string, string> = {
    published: 'Publicado',
    scheduled: 'Agendado',
    draft: 'Rascunho',
    failed: 'Falhou',
};

function formatNumber(num: number | null | undefined): string {
    if (num === null || num === undefined) return '--';
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toLocaleString('pt-BR');
}

function formatCurrency(num: number): string {
    return 'R$ ' + num.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function deltaPercent(current: number, previous: number): number | null {
    if (!previous || previous === 0) return current > 0 ? 100 : null;
    return Math.round(((current - previous) / Math.abs(previous)) * 1000) / 10;
}

function formatDuration(seconds: number): string {
    if (seconds < 60) return seconds + 's';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}m ${secs}s`;
}

function sparklinePath(data: number[], width: number = 100, height: number = 32): string {
    if (!data || data.length < 2) return '';
    const min = Math.min(...data);
    const max = Math.max(...data);
    const range = max - min || 1;
    const step = width / (data.length - 1);
    return data.map((v, i) => {
        const x = i * step;
        const y = height - ((v - min) / range) * (height - 4) - 2;
        return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
}

// Chart SVG
const chartMaxValue = computed(() => {
    if (!props.followersChart.length) return 1;
    return Math.max(...props.followersChart.map(p => p[chartMetric.value] ?? 0), 1);
});

const chartPath = computed(() => {
    const data = props.followersChart;
    if (data.length < 2) return '';
    const w = 800;
    const h = 200;
    const max = chartMaxValue.value;
    const step = w / (data.length - 1);
    return data.map((p, i) => {
        const x = i * step;
        const y = h - ((p[chartMetric.value] ?? 0) / max) * (h - 20) - 10;
        return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
});

const chartAreaPath = computed(() => {
    if (!chartPath.value) return '';
    const w = 800;
    return chartPath.value + ` L${w},200 L0,200 Z`;
});

const chartLabels = computed(() => {
    const data = props.followersChart;
    if (!data.length) return [];
    const step = Math.max(1, Math.floor(data.length / 6));
    return data.filter((_, i) => i % step === 0 || i === data.length - 1).map(p => p.date);
});

const periodOptions = [
    { value: 'followers' as const, label: 'Seguidores' },
    { value: 'engagement' as const, label: 'Engajamento' },
    { value: 'reach' as const, label: 'Alcance' },
    { value: 'impressions' as const, label: 'Impressoes' },
];
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between flex-wrap gap-3">
                <h1 class="text-xl font-semibold text-white">Dashboard</h1>
                <div class="flex items-center gap-2">
                    <Link :href="route('analytics.index')" class="rounded-xl bg-gray-800 px-4 py-2 text-xs font-medium text-gray-300 hover:bg-gray-700 transition border border-gray-700">
                        Analytics
                    </Link>
                    <Link :href="route('metrics.create')" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                        + Nova Metrica
                    </Link>
                </div>
            </div>
        </template>

        <!-- Brand + Period Filter Bar -->
        <div class="mb-6 flex items-center gap-3 flex-wrap">
            <!-- Brand filter -->
            <select v-if="allBrands && allBrands.length > 0"
                :value="activeBrandFilter"
                @change="changeBrand(($event.target as HTMLSelectElement).value)"
                class="rounded-xl bg-gray-900 border border-gray-800 text-sm text-white px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500 min-w-[160px]">
                <option value="all">Todas as Empresas</option>
                <option v-for="b in allBrands" :key="b.id" :value="String(b.id)">{{ b.name }}</option>
            </select>

            <div class="w-px h-6 bg-gray-800" />
            <div class="flex items-center gap-1 bg-gray-900 rounded-xl p-1 border border-gray-800 flex-wrap">
                <button v-for="pf in periodFilters" :key="pf.value"
                    @click="changePeriod(pf.value)"
                    :class="['rounded-lg px-3 py-1.5 text-xs font-medium transition whitespace-nowrap',
                        activePeriod === pf.value ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800']">
                    {{ pf.label }}
                </button>
            </div>
            <span v-if="loadingPeriod" class="text-xs text-gray-500 flex items-center gap-1.5">
                <svg class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                Carregando...
            </span>
            <span v-else class="text-xs text-gray-500">{{ periodLabel }}</span>

            <!-- Custom date picker -->
            <div v-if="showCustomPicker" class="flex items-center gap-2 ml-2">
                <input v-model="customStart" type="date" class="bg-gray-800 border border-gray-700 rounded-lg px-2.5 py-1.5 text-xs text-white" />
                <span class="text-gray-500 text-xs">ate</span>
                <input v-model="customEnd" type="date" class="bg-gray-800 border border-gray-700 rounded-lg px-2.5 py-1.5 text-xs text-white" />
                <button @click="applyCustomPeriod" :disabled="!customStart || !customEnd"
                    class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 transition disabled:opacity-50">
                    Aplicar
                </button>
                <button @click="showCustomPicker = false; activePeriod = props.period || 'this_month'" class="text-gray-500 hover:text-white text-xs">&times;</button>
            </div>
        </div>

        <div class="space-y-6">
            <!-- ===== ROW 1: Stats Gerais ===== -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Seguidores Total</p>
                        <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    </div>
                    <p class="text-2xl font-bold text-white">{{ formatNumber(socialSummary.total_followers) }}</p>
                    <p v-if="socialSummary.followers_growth !== null" :class="['text-xs font-medium mt-1', socialSummary.followers_growth >= 0 ? 'text-emerald-400' : 'text-red-400']">
                        {{ socialSummary.followers_growth >= 0 ? '+' : '' }}{{ socialSummary.followers_growth }}% vs ontem
                    </p>
                    <p v-else class="text-xs text-gray-600 mt-1">{{ socialSummary.total_accounts }} contas conectadas</p>
                </div>

                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Posts este Mes</p>
                        <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" /></svg>
                    </div>
                    <p class="text-2xl font-bold text-white">{{ stats.posts_this_month }}</p>
                    <p class="text-xs text-gray-600 mt-1">{{ stats.published_posts }} publicados • {{ stats.scheduled_posts }} agendados</p>
                </div>

                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Plataformas</p>
                        <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
                    </div>
                    <p class="text-2xl font-bold text-white">{{ stats.connected_platforms + (analyticsSummary?.connections_count ?? 0) }}</p>
                    <p class="text-xs text-gray-600 mt-1">{{ stats.connected_platforms }} social • {{ analyticsSummary?.connections_count ?? 0 }} analytics</p>
                </div>

                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Metricas Ativas</p>
                        <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    </div>
                    <p class="text-2xl font-bold text-white">{{ metrics.length }}</p>
                    <p class="text-xs text-gray-600 mt-1">{{ activeGoals.length }} metas ativas</p>
                </div>
            </div>

            <!-- ===== ANALYTICS OVERVIEW ===== -->
            <div v-if="analyticsSummary?.has_any">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-medium text-gray-400 uppercase tracking-wider">Analytics — {{ periodLabel || 'Este Mes' }}</h2>
                    <Link :href="route('analytics.index')" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Ver completo</Link>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <!-- Website -->
                    <div v-if="analyticsSummary.has_website" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Sessoes</p>
                        <p class="text-xl font-bold text-blue-400">{{ formatNumber(analyticsSummary.sessions) }}</p>
                        <p v-if="analyticsSummary.sessions_variation !== null" :class="['text-[10px] font-medium mt-0.5', analyticsSummary.sessions_variation >= 0 ? 'text-emerald-400' : 'text-red-400']">
                            {{ analyticsSummary.sessions_variation >= 0 ? '+' : '' }}{{ analyticsSummary.sessions_variation }}%
                        </p>
                    </div>
                    <div v-if="analyticsSummary.has_website" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Usuarios</p>
                        <p class="text-xl font-bold text-indigo-400">{{ formatNumber(analyticsSummary.users) }}</p>
                        <p v-if="analyticsSummary.users_variation !== null" :class="['text-[10px] font-medium mt-0.5', analyticsSummary.users_variation >= 0 ? 'text-emerald-400' : 'text-red-400']">
                            {{ analyticsSummary.users_variation >= 0 ? '+' : '' }}{{ analyticsSummary.users_variation }}%
                        </p>
                    </div>
                    <div v-if="analyticsSummary.has_website" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Pageviews</p>
                        <p class="text-xl font-bold text-purple-400">{{ formatNumber(analyticsSummary.pageviews) }}</p>
                        <p class="text-[10px] text-gray-600 mt-0.5">Bounce: {{ analyticsSummary.bounce_rate }}%</p>
                    </div>

                    <!-- Ads -->
                    <div v-if="analyticsSummary.has_ads" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Investimento</p>
                        <p class="text-xl font-bold text-amber-400">{{ formatCurrency(analyticsSummary.total_spend) }}</p>
                        <div class="flex items-center gap-1 mt-0.5">
                            <span v-if="analyticsSummary.ad_spend > 0" class="text-[10px] text-gray-600">API {{ formatCurrency(analyticsSummary.ad_spend) }}</span>
                            <span v-if="analyticsSummary.manual_spend > 0" class="text-[10px] text-orange-400">+Manual</span>
                        </div>
                    </div>
                    <div v-if="analyticsSummary.has_ads && analyticsSummary.ad_clicks > 0" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Conversoes</p>
                        <p class="text-xl font-bold text-emerald-400">{{ formatNumber(analyticsSummary.ad_conversions) }}</p>
                        <p class="text-[10px] text-gray-600 mt-0.5">{{ formatNumber(analyticsSummary.ad_clicks) }} cliques</p>
                    </div>
                    <div v-if="analyticsSummary.has_ads && analyticsSummary.ad_roas > 0" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">ROAS Ads</p>
                        <p class="text-xl font-bold text-teal-400">{{ analyticsSummary.ad_roas.toFixed(2) }}x</p>
                        <p v-if="analyticsSummary.has_wc && analyticsSummary.real_roas > 0" class="text-[10px] text-rose-400 mt-0.5">Real: {{ analyticsSummary.real_roas.toFixed(2) }}x</p>
                    </div>

                    <!-- SEO -->
                    <div v-if="analyticsSummary.has_seo" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Cliques SEO</p>
                        <p class="text-xl font-bold text-green-400">{{ formatNumber(analyticsSummary.search_clicks) }}</p>
                        <p v-if="analyticsSummary.search_clicks_variation !== null" :class="['text-[10px] font-medium mt-0.5', analyticsSummary.search_clicks_variation >= 0 ? 'text-emerald-400' : 'text-red-400']">
                            {{ analyticsSummary.search_clicks_variation >= 0 ? '+' : '' }}{{ analyticsSummary.search_clicks_variation }}%
                        </p>
                    </div>
                    <div v-if="analyticsSummary.has_seo" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Impressoes SEO</p>
                        <p class="text-xl font-bold text-lime-400">{{ formatNumber(analyticsSummary.search_impressions) }}</p>
                        <p class="text-[10px] text-gray-600 mt-0.5">Pos. media: {{ analyticsSummary.search_position }}</p>
                    </div>

                    <!-- E-commerce -->
                    <div v-if="analyticsSummary.has_wc" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Receita Loja</p>
                        <p class="text-xl font-bold text-purple-400">{{ formatCurrency(analyticsSummary.wc_revenue) }}</p>
                        <p v-if="analyticsSummary.wc_revenue_variation !== null" :class="['text-[10px] font-medium mt-0.5', analyticsSummary.wc_revenue_variation >= 0 ? 'text-emerald-400' : 'text-red-400']">
                            {{ analyticsSummary.wc_revenue_variation >= 0 ? '+' : '' }}{{ analyticsSummary.wc_revenue_variation }}%
                        </p>
                    </div>
                    <div v-if="analyticsSummary.has_wc" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Pedidos</p>
                        <p class="text-xl font-bold text-violet-400">{{ analyticsSummary.wc_orders }}</p>
                        <p class="text-[10px] text-gray-600 mt-0.5">Ticket: {{ formatCurrency(analyticsSummary.wc_avg_order_value) }}</p>
                    </div>
                    <div v-if="analyticsSummary.has_wc && analyticsSummary.has_ads && analyticsSummary.real_roas > 0" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">ROAS Real</p>
                        <p class="text-xl font-bold" :class="analyticsSummary.real_roas >= 1 ? 'text-emerald-400' : 'text-red-400'">{{ analyticsSummary.real_roas.toFixed(2) }}x</p>
                        <p class="text-[10px] text-gray-600 mt-0.5">Receita / investimento</p>
                    </div>
                </div>

                <!-- Analytics Connections -->
                <div v-if="analyticsSummary.connections?.length" class="flex items-center gap-2 mt-3 flex-wrap">
                    <div v-for="conn in analyticsSummary.connections" :key="conn.platform + conn.name" class="flex items-center gap-1.5 px-2.5 py-1 bg-gray-800/50 rounded-lg border border-gray-800">
                        <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: conn.color }"></span>
                        <span class="text-[10px] text-gray-400">{{ conn.label }}</span>
                        <span :class="['w-1.5 h-1.5 rounded-full', conn.sync_status === 'success' ? 'bg-emerald-400' : conn.sync_status === 'error' ? 'bg-red-400' : 'bg-gray-600']"></span>
                    </div>
                </div>
            </div>

            <!-- ===== EMAIL MARKETING ===== -->
            <div v-if="emailSummary?.has_email">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-medium text-gray-400 uppercase tracking-wider">Email Marketing — {{ periodLabel || 'Este Mes' }}</h2>
                    <Link :href="route('email.dashboard')" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Ver Dashboard Completo</Link>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-3">
                    <!-- Campanhas Enviadas -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Campanhas</p>
                        <p class="text-xl font-bold text-indigo-400">{{ emailSummary.campaigns_sent }}</p>
                        <p class="text-[10px] text-gray-600 mt-0.5">enviadas no periodo</p>
                    </div>

                    <!-- Emails Enviados -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Emails Enviados</p>
                        <p class="text-xl font-bold text-blue-400">{{ formatNumber(emailSummary.total_sent) }}</p>
                        <p v-if="deltaPercent(emailSummary.total_sent, emailSummary.prev_total_sent) !== null"
                            :class="['text-[10px] font-medium mt-0.5', deltaPercent(emailSummary.total_sent, emailSummary.prev_total_sent)! >= 0 ? 'text-emerald-400' : 'text-red-400']">
                            {{ deltaPercent(emailSummary.total_sent, emailSummary.prev_total_sent)! >= 0 ? '+' : '' }}{{ deltaPercent(emailSummary.total_sent, emailSummary.prev_total_sent) }}%
                        </p>
                    </div>

                    <!-- Entregues -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Entregues</p>
                        <p class="text-xl font-bold text-emerald-400">{{ formatNumber(emailSummary.total_delivered) }}</p>
                        <p class="text-[10px] text-gray-600 mt-0.5">{{ emailSummary.delivery_rate }}% entrega</p>
                    </div>

                    <!-- Taxa de Abertura -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Taxa Abertura</p>
                        <p class="text-xl font-bold text-indigo-400">{{ emailSummary.open_rate }}%</p>
                        <p v-if="emailSummary.prev_open_rate > 0"
                            :class="['text-[10px] font-medium mt-0.5', emailSummary.open_rate >= emailSummary.prev_open_rate ? 'text-emerald-400' : 'text-red-400']">
                            {{ emailSummary.open_rate >= emailSummary.prev_open_rate ? '+' : '' }}{{ (emailSummary.open_rate - emailSummary.prev_open_rate).toFixed(1) }}pp
                        </p>
                        <p v-else class="text-[10px] text-gray-600 mt-0.5">{{ formatNumber(emailSummary.unique_opens) }} aberturas</p>
                    </div>

                    <!-- Taxa de Cliques -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Taxa Cliques</p>
                        <p class="text-xl font-bold text-emerald-400">{{ emailSummary.click_rate }}%</p>
                        <p v-if="emailSummary.prev_click_rate > 0"
                            :class="['text-[10px] font-medium mt-0.5', emailSummary.click_rate >= emailSummary.prev_click_rate ? 'text-emerald-400' : 'text-red-400']">
                            {{ emailSummary.click_rate >= emailSummary.prev_click_rate ? '+' : '' }}{{ (emailSummary.click_rate - emailSummary.prev_click_rate).toFixed(1) }}pp
                        </p>
                        <p v-else class="text-[10px] text-gray-600 mt-0.5">{{ formatNumber(emailSummary.unique_clicks) }} cliques</p>
                    </div>

                    <!-- Bounce Rate -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Bounce Rate</p>
                        <p class="text-xl font-bold" :class="emailSummary.bounce_rate > 5 ? 'text-red-400' : 'text-emerald-400'">{{ emailSummary.bounce_rate }}%</p>
                        <p class="text-[10px] text-gray-600 mt-0.5">{{ formatNumber(emailSummary.total_bounced) }} bounces</p>
                    </div>

                    <!-- Contatos + IA -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Contatos</p>
                        <p class="text-xl font-bold text-purple-400">{{ formatNumber(emailSummary.active_contacts) }}</p>
                        <p class="text-[10px] text-gray-600 mt-0.5">de {{ formatNumber(emailSummary.total_contacts) }} total</p>
                        <Link v-if="emailSummary.pending_suggestions > 0" :href="route('email.ai-suggestions.index')"
                            class="inline-flex items-center gap-1 mt-1.5 text-[10px] font-medium text-amber-400 hover:text-amber-300 transition">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                            </svg>
                            {{ emailSummary.pending_suggestions }} sugestoes IA
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ===== SMS MARKETING ===== -->
            <div v-if="smsSummary?.has_sms">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-medium text-gray-400 uppercase tracking-wider">SMS Marketing — {{ periodLabel || 'Este Mes' }}</h2>
                    <Link :href="route('sms.dashboard')" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Ver Dashboard SMS</Link>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Campanhas SMS</p>
                        <p class="text-xl font-bold text-green-400">{{ smsSummary.campaigns_sent }}</p>
                        <p class="text-[10px] text-gray-600 mt-0.5">no período</p>
                    </div>

                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">SMS Enviados</p>
                        <p class="text-xl font-bold text-blue-400">{{ formatNumber(smsSummary.total_sent) }}</p>
                        <p v-if="deltaPercent(smsSummary.total_sent, smsSummary.prev_total_sent) !== null"
                            :class="['text-[10px] font-medium mt-0.5', deltaPercent(smsSummary.total_sent, smsSummary.prev_total_sent)! >= 0 ? 'text-emerald-400' : 'text-red-400']">
                            {{ deltaPercent(smsSummary.total_sent, smsSummary.prev_total_sent)! >= 0 ? '+' : '' }}{{ deltaPercent(smsSummary.total_sent, smsSummary.prev_total_sent) }}%
                        </p>
                    </div>

                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Entregues</p>
                        <p class="text-xl font-bold text-emerald-400">{{ formatNumber(smsSummary.total_delivered) }}</p>
                        <p class="text-[10px] text-gray-600 mt-0.5">{{ smsSummary.delivery_rate }}% entrega</p>
                    </div>

                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Taxa Cliques</p>
                        <p class="text-xl font-bold text-indigo-400">{{ smsSummary.click_rate }}%</p>
                        <p class="text-[10px] text-gray-600 mt-0.5">{{ formatNumber(smsSummary.total_clicked) }} cliques</p>
                    </div>

                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Falhas</p>
                        <p class="text-xl font-bold" :class="smsSummary.total_failed > 0 ? 'text-red-400' : 'text-emerald-400'">{{ formatNumber(smsSummary.total_failed) }}</p>
                        <Link v-if="smsSummary.pending_suggestions > 0" :href="route('sms.dashboard')"
                            class="inline-flex items-center gap-1 mt-1 text-[10px] font-medium text-amber-400 hover:text-amber-300 transition">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                            </svg>
                            {{ smsSummary.pending_suggestions }} sugestoes IA
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ===== CONTAS SOCIAIS ===== -->
            <div v-if="socialAccounts.length > 0">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-medium text-gray-400 uppercase tracking-wider">Contas Sociais</h2>
                    <Link :href="route('social.accounts.index')" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Ver todas</Link>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div v-for="account in socialAccounts" :key="account.id" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                        <div class="flex items-center gap-3 mb-3">
                            <img v-if="account.avatar_url" :src="account.avatar_url" class="w-10 h-10 rounded-xl object-cover" @error="($event.target as HTMLImageElement).style.display = 'none'" />
                            <div v-else class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-sm font-bold" :class="platformInfo[account.platform]?.bg ?? 'bg-gray-700'" :style="{ color: platformInfo[account.platform]?.color ?? '#888' }">
                                {{ (account.display_name || account.username || 'A')[0].toUpperCase() }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-white truncate">{{ account.display_name || account.username }}</p>
                                <p class="text-xs" :style="{ color: platformInfo[account.platform]?.color ?? '#888' }">{{ platformInfo[account.platform]?.name ?? account.platform }}</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div class="text-center">
                                <p class="text-lg font-bold text-white">{{ formatNumber(account.followers_count) }}</p>
                                <p class="text-[10px] text-gray-500">Seguidores</p>
                                <p v-if="account.followers_variation !== null" :class="['text-[10px] font-medium', account.followers_variation >= 0 ? 'text-emerald-400' : 'text-red-400']">
                                    {{ account.followers_variation >= 0 ? '+' : '' }}{{ account.followers_variation }}%
                                </p>
                            </div>
                            <div class="text-center">
                                <p class="text-lg font-bold text-white">{{ formatNumber(account.engagement) }}</p>
                                <p class="text-[10px] text-gray-500">Engajamento</p>
                                <p v-if="account.engagement_rate" class="text-[10px] text-indigo-400">{{ account.engagement_rate }}%</p>
                            </div>
                            <div class="text-center">
                                <p class="text-lg font-bold text-white">{{ formatNumber(account.reach) }}</p>
                                <p class="text-[10px] text-gray-500">Alcance</p>
                            </div>
                        </div>
                        <!-- Metricas secundarias -->
                        <div class="grid grid-cols-4 gap-1.5 mt-2">
                            <div v-if="account.impressions" class="text-center bg-gray-800/40 rounded-lg py-1.5">
                                <p class="text-xs font-semibold text-white">{{ formatNumber(account.impressions) }}</p>
                                <p class="text-[9px] text-gray-500">Impressoes</p>
                            </div>
                            <div v-if="account.profile_views" class="text-center bg-gray-800/40 rounded-lg py-1.5">
                                <p class="text-xs font-semibold text-white">{{ formatNumber(account.profile_views) }}</p>
                                <p class="text-[9px] text-gray-500">Visitas</p>
                            </div>
                            <div v-if="account.clicks" class="text-center bg-gray-800/40 rounded-lg py-1.5">
                                <p class="text-xs font-semibold text-white">{{ formatNumber(account.clicks) }}</p>
                                <p class="text-[9px] text-gray-500">Cliques</p>
                            </div>
                            <div v-if="account.shares" class="text-center bg-gray-800/40 rounded-lg py-1.5">
                                <p class="text-xs font-semibold text-white">{{ formatNumber(account.shares) }}</p>
                                <p class="text-[9px] text-gray-500">Compartilh.</p>
                            </div>
                            <div v-if="account.video_views" class="text-center bg-gray-800/40 rounded-lg py-1.5">
                                <p class="text-xs font-semibold text-white">{{ formatNumber(account.video_views) }}</p>
                                <p class="text-[9px] text-gray-500">Views</p>
                            </div>
                            <div v-if="account.stories_count" class="text-center bg-gray-800/40 rounded-lg py-1.5">
                                <p class="text-xs font-semibold text-white">{{ account.stories_count }}</p>
                                <p class="text-[9px] text-gray-500">Stories</p>
                            </div>
                            <div v-if="account.reels_count" class="text-center bg-gray-800/40 rounded-lg py-1.5">
                                <p class="text-xs font-semibold text-white">{{ account.reels_count }}</p>
                                <p class="text-[9px] text-gray-500">Reels</p>
                            </div>
                            <div v-if="account.avg_reach_per_post" class="text-center bg-gray-800/40 rounded-lg py-1.5">
                                <p class="text-xs font-semibold text-white">{{ formatNumber(account.avg_reach_per_post) }}</p>
                                <p class="text-[9px] text-gray-500">Alc/Post</p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-800">
                            <div class="flex items-center gap-3 text-[10px] text-gray-500">
                                <span v-if="account.likes !== null">{{ formatNumber(account.likes) }} curtidas</span>
                                <span v-if="account.comments !== null">{{ formatNumber(account.comments) }} comentarios</span>
                                <span v-if="account.saves !== null">{{ formatNumber(account.saves) }} salvos</span>
                                <span v-if="account.posts_total_30d" class="text-gray-600">{{ account.posts_total_30d }} posts/30d</span>
                            </div>
                            <span v-if="account.last_sync" class="text-[10px] text-gray-600">{{ account.last_sync }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== GRAFICO + METAS ===== -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-medium text-white">Evolucao Social — {{ periodLabel || 'Este Mes' }}</h2>
                        <div class="flex items-center gap-1">
                            <button v-for="opt in periodOptions" :key="opt.value" @click="chartMetric = opt.value"
                                :class="['rounded-lg px-2.5 py-1 text-[10px] font-medium transition', chartMetric === opt.value ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/30' : 'text-gray-500 hover:text-white']">
                                {{ opt.label }}
                            </button>
                        </div>
                    </div>
                    <div v-if="followersChart.length >= 2" class="relative">
                        <svg viewBox="0 0 800 220" class="w-full h-48" preserveAspectRatio="none">
                            <line v-for="i in 4" :key="'grid-' + i" x1="0" :y1="i * 50" x2="800" :y2="i * 50" stroke="rgba(255,255,255,0.03)" stroke-width="1" />
                            <path :d="chartAreaPath" fill="url(#chartGradient)" opacity="0.3" />
                            <path :d="chartPath" fill="none" stroke="#6366F1" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                            <circle v-for="(point, i) in followersChart" :key="'dot-' + i"
                                :cx="i * (800 / Math.max(followersChart.length - 1, 1))"
                                :cy="200 - ((point[chartMetric] ?? 0) / chartMaxValue) * 180 - 10"
                                r="3" fill="#6366F1" class="opacity-0 hover:opacity-100 transition" />
                            <defs>
                                <linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#6366F1" stop-opacity="0.4" />
                                    <stop offset="100%" stop-color="#6366F1" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                        </svg>
                        <div class="flex justify-between mt-1 px-1">
                            <span v-for="label in chartLabels" :key="label" class="text-[10px] text-gray-600">{{ label }}</span>
                        </div>
                    </div>
                    <div v-else class="flex items-center justify-center h-48 text-gray-600 text-sm">
                        <div class="text-center">
                            <svg class="w-8 h-8 mx-auto mb-2 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                            </svg>
                            <p>Dados insuficientes para o grafico</p>
                            <p class="text-[10px] text-gray-700 mt-1">Os insights serao coletados automaticamente</p>
                        </div>
                    </div>
                </div>

                <!-- Metas Ativas -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-medium text-white">Metas Ativas</h2>
                        <Link :href="route('metrics.index')" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Ver todas</Link>
                    </div>
                    <div v-if="activeGoals.length > 0" class="space-y-3">
                        <div v-for="goal in activeGoals" :key="goal.id" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                            <div class="flex items-center justify-between mb-1.5">
                                <p class="text-xs font-medium text-white truncate flex-1">{{ goal.metric_name }}</p>
                                <span :class="['text-[10px] font-medium px-1.5 py-0.5 rounded', goal.achieved ? 'bg-emerald-500/20 text-emerald-400' : goal.is_on_track ? 'bg-blue-500/20 text-blue-400' : 'bg-amber-500/20 text-amber-400']">
                                    {{ goal.achieved ? 'Atingida!' : goal.is_on_track ? 'No ritmo' : 'Atencao' }}
                                </span>
                            </div>
                            <p class="text-[10px] text-gray-500 mb-2">{{ goal.name }} · {{ goal.days_remaining }}d restantes</p>
                            <div class="relative h-2 bg-gray-700 rounded-full overflow-hidden">
                                <div class="absolute h-full rounded-full transition-all duration-500"
                                    :class="goal.achieved ? 'bg-emerald-500' : goal.is_on_track ? 'bg-indigo-500' : 'bg-amber-500'"
                                    :style="{ width: Math.min(goal.progress, 100) + '%' }"></div>
                                <div class="absolute top-0 h-full w-0.5 bg-white/20" :style="{ left: Math.min(goal.time_elapsed, 100) + '%' }"></div>
                            </div>
                            <div class="flex items-center justify-between mt-1.5">
                                <span class="text-[10px] text-gray-400">{{ goal.current_formatted }}</span>
                                <span class="text-[10px] font-medium" :style="{ color: goal.metric_color }">{{ goal.progress }}%</span>
                                <span class="text-[10px] text-gray-400">{{ goal.target_formatted }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="flex flex-col items-center justify-center py-8 text-center">
                        <svg class="w-8 h-8 text-gray-700 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0l2.77-.693a9 9 0 016.208.682l.108.054a9 9 0 006.086.71l3.114-.732a48.524 48.524 0 01-.005-10.499l-3.11.732a9 9 0 01-6.085-.711l-.108-.054a9 9 0 00-6.208-.682L3 4.5M3 15V4.5" /></svg>
                        <p class="text-xs text-gray-500">Nenhuma meta ativa</p>
                        <Link :href="route('metrics.create')" class="text-xs text-indigo-400 hover:text-indigo-300 mt-1 transition">Criar metrica com meta</Link>
                    </div>
                </div>
            </div>

            <!-- ===== METRICAS ===== -->
            <div v-if="metrics.length > 0">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-medium text-gray-400 uppercase tracking-wider">Metricas</h2>
                    <Link :href="route('metrics.index')" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Ver todas</Link>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                    <Link v-for="metric in metrics" :key="metric.id" :href="route('metrics.show', metric.id)"
                        class="rounded-2xl bg-gray-900 border border-gray-800 p-4 hover:border-gray-700 transition group">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :style="{ backgroundColor: metric.color }"></span>
                                <p class="text-xs font-medium text-gray-400 truncate">{{ metric.name }}</p>
                            </div>
                            <span v-if="metric.auto_sync" class="text-[9px] text-indigo-400 bg-indigo-500/10 rounded px-1.5 py-0.5">auto</span>
                        </div>
                        <div class="flex items-end justify-between">
                            <div>
                                <p class="text-xl font-bold text-white">{{ metric.formatted_value }}</p>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <span v-if="metric.variation !== null" :class="['text-xs font-medium', metric.variation_positive ? 'text-emerald-400' : 'text-red-400']">
                                        {{ metric.variation >= 0 ? '+' : '' }}{{ metric.variation }}%
                                    </span>
                                    <span v-if="metric.latest_date" class="text-[10px] text-gray-600">{{ metric.latest_date }}</span>
                                </div>
                            </div>
                            <svg v-if="metric.sparkline.length >= 2" viewBox="0 0 100 32" class="w-20 h-8 flex-shrink-0">
                                <path :d="sparklinePath(metric.sparkline)" fill="none"
                                    :stroke="metric.variation_positive ? '#10B981' : '#EF4444'"
                                    stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </div>
                        <div v-if="metric.goal_progress !== null" class="mt-2.5">
                            <div class="flex items-center justify-between text-[10px] mb-1">
                                <span class="text-gray-500">{{ metric.goal_name ?? 'Meta' }}</span>
                                <span class="text-gray-400">{{ metric.goal_progress }}%</span>
                            </div>
                            <div class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all" :style="{ width: Math.min(metric.goal_progress, 100) + '%', backgroundColor: metric.color }"></div>
                            </div>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- ===== ATIVIDADE RECENTE ===== -->
            <div v-if="recentActivity.length > 0" class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-medium text-white">Atividade Recente</h2>
                    <Link :href="route('social.posts.index')" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Ver todos</Link>
                </div>
                <div class="space-y-2">
                    <div v-for="(activity, i) in recentActivity" :key="i" class="flex items-center gap-3 py-2" :class="i < recentActivity.length - 1 ? 'border-b border-gray-800/50' : ''">
                        <div class="w-8 h-8 rounded-lg bg-gray-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" /></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-white truncate">{{ activity.title }}</p>
                            <p class="text-[10px] text-gray-500">{{ activity.date }}</p>
                        </div>
                        <span :class="['text-[10px] font-medium', statusColors[activity.status] ?? 'text-gray-500']">
                            {{ statusLabels[activity.status] ?? activity.status }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Estado vazio -->
            <div v-if="socialAccounts.length === 0 && metrics.length === 0 && !analyticsSummary?.has_any" class="rounded-2xl bg-gray-900 border border-gray-800 border-dashed p-10 text-center">
                <svg class="w-16 h-16 mx-auto text-gray-700 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" />
                </svg>
                <h3 class="text-lg font-semibold text-gray-300 mb-2">Comece a usar o MKT Privus</h3>
                <p class="text-sm text-gray-500 max-w-md mx-auto mb-6">Conecte suas redes sociais, plataformas de analytics e crie metricas para acompanhar o desempenho da sua marca.</p>
                <div class="flex items-center justify-center gap-3 flex-wrap">
                    <Link :href="route('social.accounts.index')" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                        Conectar Redes Sociais
                    </Link>
                    <Link :href="route('analytics.connections')" class="rounded-xl border border-gray-700 px-5 py-2.5 text-sm font-medium text-gray-400 hover:text-white hover:border-gray-600 transition">
                        Conectar Analytics
                    </Link>
                    <Link :href="route('metrics.create')" class="rounded-xl border border-gray-700 px-5 py-2.5 text-sm font-medium text-gray-400 hover:text-white hover:border-gray-600 transition">
                        Criar Metrica
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
