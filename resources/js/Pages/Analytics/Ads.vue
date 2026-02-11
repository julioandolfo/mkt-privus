<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps<{
    brand: any;
    summaries: any[];
    campaigns: any[];
    filters: { start_date: string; end_date: string };
}>();

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);

const chartWidth = 800;
const chartHeight = 260;
const padding = { top: 20, right: 20, bottom: 30, left: 60 };

function applyDates() {
    router.get(route('analytics.ads'), { start_date: startDate.value, end_date: endDate.value }, { preserveState: true, preserveScroll: true });
}

const totals = computed(() => {
    const apiSpend = props.summaries.reduce((a: number, b: any) => a + parseFloat(b.ad_spend || 0), 0);
    const manualSpend = props.summaries.reduce((a: number, b: any) => a + parseFloat(b.manual_ad_spend || 0), 0);
    const totalSpend = props.summaries.reduce((a: number, b: any) => a + parseFloat(b.total_spend || 0), 0);
    const wcRevenue = props.summaries.reduce((a: number, b: any) => a + parseFloat(b.wc_revenue || 0), 0);

    return {
        apiSpend,
        manualSpend,
        totalSpend: totalSpend || (apiSpend + manualSpend),
        impressions: props.summaries.reduce((a: number, b: any) => a + (b.ad_impressions || 0), 0),
        clicks: props.summaries.reduce((a: number, b: any) => a + (b.ad_clicks || 0), 0),
        conversions: props.summaries.reduce((a: number, b: any) => a + (b.ad_conversions || 0), 0),
        revenue: props.summaries.reduce((a: number, b: any) => a + parseFloat(b.ad_revenue || 0), 0),
        wcRevenue,
        ctr: props.summaries.length > 0 ? props.summaries.reduce((a: number, b: any) => a + parseFloat(b.ad_ctr || 0), 0) / props.summaries.length : 0,
        cpc: props.summaries.length > 0 ? props.summaries.reduce((a: number, b: any) => a + parseFloat(b.ad_cpc || 0), 0) / props.summaries.length : 0,
        roas: props.summaries.length > 0 ? props.summaries.reduce((a: number, b: any) => a + parseFloat(b.ad_roas || 0), 0) / props.summaries.length : 0,
        realRoas: props.summaries.length > 0 ? props.summaries.reduce((a: number, b: any) => a + parseFloat(b.real_roas || 0), 0) / props.summaries.length : 0,
    };
});

const kpis = computed(() => {
    const items = [
        { label: 'Invest. Total', value: 'R$ ' + totals.value.totalSpend.toLocaleString('pt-BR', { minimumFractionDigits: 2 }), color: 'text-amber-400', sub: '' },
    ];
    if (totals.value.manualSpend > 0) {
        items.push({ label: 'Manual', value: 'R$ ' + totals.value.manualSpend.toLocaleString('pt-BR', { minimumFractionDigits: 2 }), color: 'text-orange-400', sub: '' });
    }
    if (totals.value.apiSpend > 0) {
        items.push({ label: 'API Ads', value: 'R$ ' + totals.value.apiSpend.toLocaleString('pt-BR', { minimumFractionDigits: 2 }), color: 'text-yellow-400', sub: '' });
    }
    items.push(
        { label: 'Impressões', value: totals.value.impressions.toLocaleString('pt-BR'), color: 'text-blue-400', sub: '' },
        { label: 'Cliques', value: totals.value.clicks.toLocaleString('pt-BR'), color: 'text-orange-400', sub: '' },
        { label: 'ROAS Ads', value: totals.value.roas.toFixed(2) + 'x', color: 'text-teal-400', sub: '' },
    );
    if (totals.value.wcRevenue > 0) {
        items.push(
            { label: 'Receita Loja', value: 'R$ ' + totals.value.wcRevenue.toLocaleString('pt-BR', { minimumFractionDigits: 2 }), color: 'text-purple-400', sub: '' },
            { label: 'ROAS Real', value: totals.value.realRoas.toFixed(2) + 'x', color: 'text-rose-400', sub: '' },
        );
    }
    return items;
});

const selectedMetric = ref('total_spend');
const metricOpts = computed(() => {
    const opts = [
        { value: 'total_spend', label: 'Invest. Total', color: '#F59E0B' },
        { value: 'ad_spend', label: 'Invest. API', color: '#D97706' },
        { value: 'ad_clicks', label: 'Cliques', color: '#F97316' },
        { value: 'ad_impressions', label: 'Impressões', color: '#3B82F6' },
        { value: 'ad_conversions', label: 'Conversões', color: '#10B981' },
        { value: 'ad_roas', label: 'ROAS Ads', color: '#14B8A6' },
    ];
    if (totals.value.wcRevenue > 0) {
        opts.push(
            { value: 'wc_revenue', label: 'Receita Loja', color: '#A855F7' },
            { value: 'real_roas', label: 'ROAS Real', color: '#EC4899' },
        );
    }
    return opts;
});

const chartData = computed(() => {
    if (props.summaries.length === 0) return null;
    const values = props.summaries.map((s: any) => parseFloat(s[selectedMetric.value] || 0));
    const dates = props.summaries.map((s: any) => { const d = new Date(s.date); return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}`; });
    const maxVal = Math.max(...values, 1);
    const innerW = chartWidth - padding.left - padding.right;
    const innerH = chartHeight - padding.top - padding.bottom;
    const points = values.map((v, i) => ({ x: padding.left + (i / Math.max(values.length - 1, 1)) * innerW, y: padding.top + innerH - (v / maxVal) * innerH, value: v, label: dates[i] }));
    const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');
    const areaPath = linePath + ` L${points[points.length-1].x},${padding.top+innerH} L${points[0].x},${padding.top+innerH} Z`;
    const color = metricOpts.value.find(m => m.value === selectedMetric.value)?.color || '#F59E0B';
    const isDecimal = ['ad_roas', 'real_roas'].includes(selectedMetric.value);
    const yLabels = Array.from({length: 5}, (_, i) => ({ value: ((maxVal / 4) * (4 - i)).toFixed(isDecimal ? 2 : 0), y: padding.top + (i / 4) * innerH }));
    const step = Math.max(1, Math.floor(dates.length / 8));
    const xLabels = dates.filter((_, i) => i % step === 0).map(label => ({ label, x: padding.left + (dates.indexOf(label) / Math.max(dates.length - 1, 1)) * innerW }));
    return { points, linePath, areaPath, yLabels, xLabels, color };
});
</script>

<template>
    <Head title="Analytics - Ads" />
    <AuthenticatedLayout>
        <div class="p-4 lg:p-6 space-y-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <Link :href="route('analytics.index')" class="text-gray-400 hover:text-white transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M15 19l-7-7 7-7"/></svg>
                        </Link>
                        <h1 class="text-2xl font-bold text-white">Ads Analytics</h1>
                    </div>
                    <p class="text-sm text-gray-400 mt-1 ml-8">Investimentos (API + Manual) — Performance consolidada</p>
                </div>
                <div class="flex items-center gap-2">
                    <input v-model="startDate" type="date" class="bg-gray-800 border border-gray-700 text-white text-xs rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                    <span class="text-gray-500 text-xs">até</span>
                    <input v-model="endDate" type="date" class="bg-gray-800 border border-gray-700 text-white text-xs rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                    <button @click="applyDates" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs rounded-xl transition">Aplicar</button>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3">
                <div v-for="kpi in kpis" :key="kpi.label" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-3">
                    <p class="text-[10px] text-gray-500 mb-1">{{ kpi.label }}</p>
                    <p :class="['text-sm font-bold', kpi.color]">{{ kpi.value }}</p>
                </div>
            </div>

            <!-- Chart -->
            <div class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-white">Evolução Diária</h3>
                    <div class="flex flex-wrap gap-1">
                        <button v-for="opt in metricOpts" :key="opt.value" @click="selectedMetric = opt.value"
                            :class="['px-2.5 py-1 text-[11px] font-medium rounded-lg transition', selectedMetric === opt.value ? 'text-white' : 'text-gray-500 hover:text-gray-300 hover:bg-gray-800']"
                            :style="selectedMetric === opt.value ? {backgroundColor: opt.color} : {}">{{ opt.label }}</button>
                    </div>
                </div>
                <div v-if="chartData" class="w-full overflow-x-auto">
                    <svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" class="w-full h-auto" preserveAspectRatio="xMidYMid meet">
                        <line v-for="yl in chartData.yLabels" :key="'gl'+yl.y" :x1="padding.left" :y1="yl.y" :x2="chartWidth-padding.right" :y2="yl.y" stroke="#1F2937" stroke-width="0.5"/>
                        <defs><linearGradient id="adsAreaGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" :stop-color="chartData.color" stop-opacity="0.3"/><stop offset="100%" :stop-color="chartData.color" stop-opacity="0"/></linearGradient></defs>
                        <path :d="chartData.areaPath" fill="url(#adsAreaGrad)"/>
                        <path :d="chartData.linePath" fill="none" :stroke="chartData.color" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                        <circle v-for="(p,i) in chartData.points" :key="'p'+i" :cx="p.x" :cy="p.y" r="3" :fill="chartData.color" stroke="#111827" stroke-width="1.5"/>
                        <text v-for="yl in chartData.yLabels" :key="'yl'+yl.y" :x="padding.left-8" :y="yl.y+3" text-anchor="end" fill="#6B7280" font-size="9">{{ yl.value }}</text>
                        <text v-for="xl in chartData.xLabels" :key="'xl'+xl.label" :x="xl.x" :y="chartHeight-5" text-anchor="middle" fill="#6B7280" font-size="9">{{ xl.label }}</text>
                    </svg>
                </div>
                <div v-else class="flex items-center justify-center h-48 text-gray-600 text-sm">Sem dados para o período</div>
            </div>

            <!-- Campaigns -->
            <div v-if="campaigns.length > 0" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                <h3 class="text-sm font-semibold text-white mb-4">Campanhas</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[10px] text-gray-500 uppercase tracking-wider border-b border-gray-800">
                                <th class="pb-2 pr-4">#</th>
                                <th class="pb-2 pr-4">Campanha</th>
                                <th class="pb-2 pr-4">Plataforma</th>
                                <th class="pb-2 pr-4 text-right">Investimento</th>
                                <th class="pb-2 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800/50">
                            <tr v-for="(camp, i) in campaigns" :key="i" class="hover:bg-gray-800/30">
                                <td class="py-2.5 pr-4 text-xs text-gray-500">{{ i + 1 }}</td>
                                <td class="py-2.5 pr-4 text-xs text-gray-300">{{ camp.name }}</td>
                                <td class="py-2.5 pr-4">
                                    <span :class="['text-[10px] px-2 py-0.5 rounded-full', camp.platform === 'google_ads' ? 'bg-blue-500/10 text-blue-400' : 'bg-blue-600/10 text-blue-300']">
                                        {{ camp.platform === 'google_ads' ? 'Google Ads' : 'Meta Ads' }}
                                    </span>
                                </td>
                                <td class="py-2.5 pr-4 text-xs text-amber-400 text-right tabular-nums">
                                    R$ {{ Number(camp.value).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}
                                </td>
                                <td class="py-2.5 text-right">
                                    <span v-if="camp.extra?.status" :class="['text-[10px] px-2 py-0.5 rounded-full',
                                        camp.extra.status === 'ACTIVE' || camp.extra.status === 'ENABLED' ? 'bg-emerald-500/10 text-emerald-400' :
                                        camp.extra.status === 'PAUSED' ? 'bg-amber-500/10 text-amber-400' : 'bg-gray-800 text-gray-500'
                                    ]">{{ camp.extra.status }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
