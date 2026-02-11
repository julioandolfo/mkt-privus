<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

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
    ecommerceSummary?: {
        wc_revenue: number;
        wc_orders: number;
        wc_avg_order_value: number;
        total_spend: number;
        manual_spend: number;
        api_spend: number;
        real_roas: number;
        has_wc: boolean;
        has_spend: boolean;
    } | null;
}>();

const chartMetric = ref<'followers' | 'engagement' | 'reach' | 'impressions'>('followers');

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

function formatCompact(num: number | null | undefined): string {
    if (num === null || num === undefined) return '--';
    return num.toLocaleString('pt-BR');
}

// Sparkline SVG path generator
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
    const data = props.followersChart;
    const w = 800;
    return chartPath.value + ` L${w},200 L0,200 Z`;
});

const chartLabels = computed(() => {
    const data = props.followersChart;
    if (!data.length) return [];
    // Show ~6 labels
    const step = Math.max(1, Math.floor(data.length / 6));
    return data.filter((_, i) => i % step === 0 || i === data.length - 1).map(p => p.date);
});

const chartMetricLabel = computed(() => ({
    followers: 'Seguidores',
    engagement: 'Engajamento',
    reach: 'Alcance',
    impressions: 'Impressoes',
}[chartMetric.value]));

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
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-white">Dashboard</h1>
                <div class="flex items-center gap-2">
                    <Link :href="route('metrics.create')" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                        + Nova Metrica
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- ===== ROW 1: Stats Gerais ===== -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Total Seguidores -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Seguidores Total</p>
                        <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <p class="text-2xl font-bold text-white">{{ formatNumber(socialSummary.total_followers) }}</p>
                    <p v-if="socialSummary.followers_growth !== null" :class="['text-xs font-medium mt-1', socialSummary.followers_growth >= 0 ? 'text-emerald-400' : 'text-red-400']">
                        {{ socialSummary.followers_growth >= 0 ? '+' : '' }}{{ socialSummary.followers_growth }}% vs ontem
                    </p>
                    <p v-else class="text-xs text-gray-600 mt-1">{{ socialSummary.total_accounts }} contas conectadas</p>
                </div>

                <!-- Posts este mes -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Posts este Mes</p>
                        <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" />
                        </svg>
                    </div>
                    <p class="text-2xl font-bold text-white">{{ stats.posts_this_month }}</p>
                    <p class="text-xs text-gray-600 mt-1">{{ stats.published_posts }} publicados</p>
                </div>

                <!-- Agendados -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Agendados</p>
                        <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <p class="text-2xl font-bold text-white">{{ stats.scheduled_posts }}</p>
                    <p class="text-xs text-gray-600 mt-1">prontos para publicar</p>
                </div>

                <!-- Plataformas -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Plataformas</p>
                        <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                    </div>
                    <p class="text-2xl font-bold text-white">{{ stats.connected_platforms }}</p>
                    <p class="text-xs text-gray-600 mt-1">conectadas</p>
                </div>
            </div>

            <!-- ===== E-COMMERCE / INVESTIMENTO ===== -->
            <div v-if="ecommerceSummary" class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Investimento Total -->
                <div v-if="ecommerceSummary.has_spend" class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Investimento 30d</p>
                        <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 9v1m9-5a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <p class="text-2xl font-bold text-amber-400">R$ {{ ecommerceSummary.total_spend.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}</p>
                    <div class="flex items-center gap-2 mt-1">
                        <span v-if="ecommerceSummary.api_spend > 0" class="text-[10px] text-gray-500">API: R$ {{ ecommerceSummary.api_spend.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}</span>
                        <span v-if="ecommerceSummary.manual_spend > 0" class="text-[10px] text-orange-400">Manual: R$ {{ ecommerceSummary.manual_spend.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}</span>
                    </div>
                </div>

                <!-- Receita WooCommerce -->
                <div v-if="ecommerceSummary.has_wc" class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Receita Loja 30d</p>
                        <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4zM3 6h18M16 10a4 4 0 01-8 0"/>
                        </svg>
                    </div>
                    <p class="text-2xl font-bold text-purple-400">R$ {{ ecommerceSummary.wc_revenue.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}</p>
                    <p class="text-xs text-gray-600 mt-1">{{ ecommerceSummary.wc_orders }} pedidos • Ticket: R$ {{ ecommerceSummary.wc_avg_order_value.toFixed(2) }}</p>
                </div>

                <!-- ROAS Real -->
                <div v-if="ecommerceSummary.has_wc && ecommerceSummary.has_spend && ecommerceSummary.real_roas > 0" class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">ROAS Real</p>
                        <svg class="w-4 h-4 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2 12l5 4 5-6 5 3 5-7"/>
                        </svg>
                    </div>
                    <p class="text-2xl font-bold" :class="ecommerceSummary.real_roas >= 1 ? 'text-emerald-400' : 'text-red-400'">{{ ecommerceSummary.real_roas.toFixed(2) }}x</p>
                    <p class="text-xs text-gray-600 mt-1">Receita real / investimento total</p>
                </div>

                <!-- Pedidos -->
                <div v-if="ecommerceSummary.has_wc" class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Pedidos 30d</p>
                        <svg class="w-4 h-4 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                        </svg>
                    </div>
                    <p class="text-2xl font-bold text-violet-400">{{ ecommerceSummary.wc_orders }}</p>
                    <Link :href="route('analytics.ads')" class="text-xs text-indigo-400 hover:text-indigo-300 transition mt-1 inline-block">Ver analytics</Link>
                </div>
            </div>

            <!-- ===== ROW 2: Contas Sociais ===== -->
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

                        <!-- Mini stats row -->
                        <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-800">
                            <div class="flex items-center gap-3 text-[10px] text-gray-500">
                                <span v-if="account.likes !== null">{{ formatNumber(account.likes) }} curtidas</span>
                                <span v-if="account.comments !== null">{{ formatNumber(account.comments) }} comentarios</span>
                                <span v-if="account.saves !== null">{{ formatNumber(account.saves) }} salvos</span>
                            </div>
                            <span v-if="account.last_sync" class="text-[10px] text-gray-600">{{ account.last_sync }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== ROW 3: Grafico + Metas ===== -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Grafico (2/3) -->
                <div class="lg:col-span-2 rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-medium text-white">Evolucao - Ultimos 30 dias</h2>
                        <div class="flex items-center gap-1">
                            <button
                                v-for="opt in periodOptions"
                                :key="opt.value"
                                @click="chartMetric = opt.value"
                                :class="[
                                    'rounded-lg px-2.5 py-1 text-[10px] font-medium transition',
                                    chartMetric === opt.value
                                        ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/30'
                                        : 'text-gray-500 hover:text-white'
                                ]"
                            >
                                {{ opt.label }}
                            </button>
                        </div>
                    </div>

                    <div v-if="followersChart.length >= 2" class="relative">
                        <svg viewBox="0 0 800 220" class="w-full h-48" preserveAspectRatio="none">
                            <!-- Grid lines -->
                            <line v-for="i in 4" :key="'grid-' + i" x1="0" :y1="i * 50" x2="800" :y2="i * 50" stroke="rgba(255,255,255,0.03)" stroke-width="1" />

                            <!-- Area fill -->
                            <path :d="chartAreaPath" fill="url(#chartGradient)" opacity="0.3" />

                            <!-- Line -->
                            <path :d="chartPath" fill="none" stroke="#6366F1" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />

                            <!-- Dots -->
                            <circle
                                v-for="(point, i) in followersChart"
                                :key="'dot-' + i"
                                :cx="i * (800 / Math.max(followersChart.length - 1, 1))"
                                :cy="200 - ((point[chartMetric] ?? 0) / chartMaxValue) * 180 - 10"
                                r="3"
                                fill="#6366F1"
                                class="opacity-0 hover:opacity-100 transition"
                            />

                            <defs>
                                <linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#6366F1" stop-opacity="0.4" />
                                    <stop offset="100%" stop-color="#6366F1" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                        </svg>

                        <!-- X labels -->
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

                <!-- Metas Ativas (1/3) -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-medium text-white">Metas Ativas</h2>
                        <Link :href="route('metrics.index')" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Ver todas</Link>
                    </div>

                    <div v-if="activeGoals.length > 0" class="space-y-3">
                        <div v-for="goal in activeGoals" :key="goal.id" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                            <div class="flex items-center justify-between mb-1.5">
                                <p class="text-xs font-medium text-white truncate flex-1">{{ goal.metric_name }}</p>
                                <span :class="[
                                    'text-[10px] font-medium px-1.5 py-0.5 rounded',
                                    goal.achieved
                                        ? 'bg-emerald-500/20 text-emerald-400'
                                        : goal.is_on_track
                                            ? 'bg-blue-500/20 text-blue-400'
                                            : 'bg-amber-500/20 text-amber-400'
                                ]">
                                    {{ goal.achieved ? 'Atingida!' : goal.is_on_track ? 'No ritmo' : 'Atencao' }}
                                </span>
                            </div>

                            <p class="text-[10px] text-gray-500 mb-2">{{ goal.name }} · {{ goal.days_remaining }}d restantes</p>

                            <!-- Progress bar -->
                            <div class="relative h-2 bg-gray-700 rounded-full overflow-hidden">
                                <div
                                    class="absolute h-full rounded-full transition-all duration-500"
                                    :class="goal.achieved ? 'bg-emerald-500' : goal.is_on_track ? 'bg-indigo-500' : 'bg-amber-500'"
                                    :style="{ width: Math.min(goal.progress, 100) + '%' }"
                                ></div>
                                <!-- Time elapsed marker -->
                                <div
                                    class="absolute top-0 h-full w-0.5 bg-white/20"
                                    :style="{ left: Math.min(goal.time_elapsed, 100) + '%' }"
                                ></div>
                            </div>

                            <div class="flex items-center justify-between mt-1.5">
                                <span class="text-[10px] text-gray-400">{{ goal.current_formatted }}</span>
                                <span class="text-[10px] font-medium" :style="{ color: goal.metric_color }">{{ goal.progress }}%</span>
                                <span class="text-[10px] text-gray-400">{{ goal.target_formatted }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-else class="flex flex-col items-center justify-center py-8 text-center">
                        <svg class="w-8 h-8 text-gray-700 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0l2.77-.693a9 9 0 016.208.682l.108.054a9 9 0 006.086.71l3.114-.732a48.524 48.524 0 01-.005-10.499l-3.11.732a9 9 0 01-6.085-.711l-.108-.054a9 9 0 00-6.208-.682L3 4.5M3 15V4.5" />
                        </svg>
                        <p class="text-xs text-gray-500">Nenhuma meta ativa</p>
                        <Link :href="route('metrics.create')" class="text-xs text-indigo-400 hover:text-indigo-300 mt-1 transition">Criar metrica com meta</Link>
                    </div>
                </div>
            </div>

            <!-- ===== ROW 4: Metricas ===== -->
            <div v-if="metrics.length > 0">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-medium text-gray-400 uppercase tracking-wider">Metricas</h2>
                    <Link :href="route('metrics.index')" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Ver todas</Link>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                    <Link
                        v-for="metric in metrics"
                        :key="metric.id"
                        :href="route('metrics.show', metric.id)"
                        class="rounded-2xl bg-gray-900 border border-gray-800 p-4 hover:border-gray-700 transition group"
                    >
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

                            <!-- Sparkline -->
                            <svg v-if="metric.sparkline.length >= 2" viewBox="0 0 100 32" class="w-20 h-8 flex-shrink-0">
                                <path
                                    :d="sparklinePath(metric.sparkline)"
                                    fill="none"
                                    :stroke="metric.variation_positive ? '#10B981' : '#EF4444'"
                                    stroke-width="1.5"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </div>

                        <!-- Goal progress -->
                        <div v-if="metric.goal_progress !== null" class="mt-2.5">
                            <div class="flex items-center justify-between text-[10px] mb-1">
                                <span class="text-gray-500">{{ metric.goal_name ?? 'Meta' }}</span>
                                <span class="text-gray-400">{{ metric.goal_progress }}%</span>
                            </div>
                            <div class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :style="{ width: Math.min(metric.goal_progress, 100) + '%', backgroundColor: metric.color }"
                                ></div>
                            </div>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- ===== ROW 5: Atividade Recente ===== -->
            <div v-if="recentActivity.length > 0" class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-medium text-white">Atividade Recente</h2>
                    <Link :href="route('social.posts.index')" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Ver todos</Link>
                </div>
                <div class="space-y-2">
                    <div v-for="(activity, i) in recentActivity" :key="i" class="flex items-center gap-3 py-2" :class="i < recentActivity.length - 1 ? 'border-b border-gray-800/50' : ''">
                        <div class="w-8 h-8 rounded-lg bg-gray-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" />
                            </svg>
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

            <!-- Estado vazio: Sem dados -->
            <div v-if="socialAccounts.length === 0 && metrics.length === 0" class="rounded-2xl bg-gray-900 border border-gray-800 border-dashed p-10 text-center">
                <svg class="w-16 h-16 mx-auto text-gray-700 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" />
                </svg>
                <h3 class="text-lg font-semibold text-gray-300 mb-2">Comece a usar o MKT Privus</h3>
                <p class="text-sm text-gray-500 max-w-md mx-auto mb-6">Conecte suas redes sociais e crie metricas para acompanhar o desempenho da sua marca em tempo real.</p>
                <div class="flex items-center justify-center gap-3">
                    <Link :href="route('social.accounts.index')" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                        Conectar Redes Sociais
                    </Link>
                    <Link :href="route('metrics.create')" class="rounded-xl border border-gray-700 px-5 py-2.5 text-sm font-medium text-gray-400 hover:text-white hover:border-gray-600 transition">
                        Criar Metrica
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
