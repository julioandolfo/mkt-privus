<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps<{
    brand: any;
    summaries: any[];
    topDimensions: Record<string, any[]>;
    filters: { start_date: string; end_date: string };
}>();

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);

const chartWidth = 800;
const chartHeight = 260;
const padding = { top: 20, right: 20, bottom: 30, left: 60 };

function applyDates() {
    router.get(route('analytics.seo'), { start_date: startDate.value, end_date: endDate.value }, { preserveState: true, preserveScroll: true });
}

const totals = computed(() => ({
    clicks: props.summaries.reduce((a: number, b: any) => a + (b.search_clicks || 0), 0),
    impressions: props.summaries.reduce((a: number, b: any) => a + (b.search_impressions || 0), 0),
    ctr: props.summaries.length > 0 ? props.summaries.reduce((a: number, b: any) => a + parseFloat(b.search_ctr || 0), 0) / props.summaries.length : 0,
    position: props.summaries.length > 0 ? props.summaries.reduce((a: number, b: any) => a + parseFloat(b.search_position || 0), 0) / props.summaries.length : 0,
}));

const kpis = computed(() => [
    { label: 'Cliques Orgânicos', value: totals.value.clicks.toLocaleString('pt-BR'), color: 'text-green-400' },
    { label: 'Impressões', value: totals.value.impressions.toLocaleString('pt-BR'), color: 'text-emerald-400' },
    { label: 'CTR Médio', value: (totals.value.ctr * 100).toFixed(2) + '%', color: 'text-teal-400' },
    { label: 'Posição Média', value: totals.value.position.toFixed(1), color: 'text-lime-400' },
]);

const selectedMetric = ref('search_clicks');
const metricOpts = [
    { value: 'search_clicks', label: 'Cliques', color: '#22C55E' },
    { value: 'search_impressions', label: 'Impressões', color: '#10B981' },
    { value: 'search_position', label: 'Posição', color: '#84CC16' },
];

const chartData = computed(() => {
    if (props.summaries.length === 0) return null;
    const values = props.summaries.map((s: any) => parseFloat(s[selectedMetric.value] || 0));
    const dates = props.summaries.map((s: any) => { const d = new Date(s.date); return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}`; });
    const maxVal = Math.max(...values, 1);
    const innerW = chartWidth - padding.left - padding.right;
    const innerH = chartHeight - padding.top - padding.bottom;

    // Para posição, inverter (menor é melhor)
    const invert = selectedMetric.value === 'search_position';
    const points = values.map((v, i) => ({
        x: padding.left + (i / Math.max(values.length - 1, 1)) * innerW,
        y: invert
            ? padding.top + (v / maxVal) * innerH
            : padding.top + innerH - (v / maxVal) * innerH,
        value: v,
        label: dates[i],
    }));

    const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');
    const areaPath = invert
        ? linePath + ` L${points[points.length-1].x},${padding.top} L${points[0].x},${padding.top} Z`
        : linePath + ` L${points[points.length-1].x},${padding.top+innerH} L${points[0].x},${padding.top+innerH} Z`;

    const color = metricOpts.find(m => m.value === selectedMetric.value)?.color || '#22C55E';

    const yLabels = Array.from({length: 5}, (_, i) => {
        const val = invert ? (maxVal / 4) * i : (maxVal / 4) * (4 - i);
        return { value: val.toFixed(selectedMetric.value === 'search_position' ? 1 : 0), y: padding.top + (i / 4) * innerH };
    });

    const step = Math.max(1, Math.floor(dates.length / 8));
    const xLabels = dates.filter((_, i) => i % step === 0).map(label => ({ label, x: padding.left + (dates.indexOf(label) / Math.max(dates.length - 1, 1)) * innerW }));
    return { points, linePath, areaPath, yLabels, xLabels, color };
});
</script>

<template>
    <Head title="Analytics - SEO" />
    <AuthenticatedLayout>
        <div class="p-4 lg:p-6 space-y-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <Link :href="route('analytics.index')" class="text-gray-400 hover:text-white transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M15 19l-7-7 7-7"/></svg>
                        </Link>
                        <h1 class="text-2xl font-bold text-white">SEO Analytics</h1>
                    </div>
                    <p class="text-sm text-gray-400 mt-1 ml-8">Google Search Console — Performance de busca orgânica</p>
                </div>
                <div class="flex items-center gap-2">
                    <input v-model="startDate" type="date" class="bg-gray-800 border border-gray-700 text-white text-xs rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                    <span class="text-gray-500 text-xs">até</span>
                    <input v-model="endDate" type="date" class="bg-gray-800 border border-gray-700 text-white text-xs rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                    <button @click="applyDates" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs rounded-xl transition">Aplicar</button>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div v-for="kpi in kpis" :key="kpi.label" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-4">
                    <p class="text-xs text-gray-500 mb-1">{{ kpi.label }}</p>
                    <p :class="['text-xl font-bold', kpi.color]">{{ kpi.value }}</p>
                </div>
            </div>

            <!-- Chart -->
            <div class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-white">Evolução Diária</h3>
                    <div class="flex gap-1">
                        <button v-for="opt in metricOpts" :key="opt.value" @click="selectedMetric = opt.value"
                            :class="['px-2.5 py-1 text-[11px] font-medium rounded-lg transition', selectedMetric === opt.value ? 'text-white' : 'text-gray-500 hover:text-gray-300 hover:bg-gray-800']"
                            :style="selectedMetric === opt.value ? {backgroundColor: opt.color} : {}">{{ opt.label }}</button>
                    </div>
                </div>
                <div v-if="chartData" class="w-full overflow-x-auto">
                    <svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" class="w-full h-auto" preserveAspectRatio="xMidYMid meet">
                        <line v-for="yl in chartData.yLabels" :key="'gl'+yl.y" :x1="padding.left" :y1="yl.y" :x2="chartWidth-padding.right" :y2="yl.y" stroke="#1F2937" stroke-width="0.5"/>
                        <defs><linearGradient id="seoAreaGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" :stop-color="chartData.color" stop-opacity="0.3"/><stop offset="100%" :stop-color="chartData.color" stop-opacity="0"/></linearGradient></defs>
                        <path :d="chartData.areaPath" fill="url(#seoAreaGrad)"/>
                        <path :d="chartData.linePath" fill="none" :stroke="chartData.color" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                        <circle v-for="(p,i) in chartData.points" :key="'p'+i" :cx="p.x" :cy="p.y" r="3" :fill="chartData.color" stroke="#111827" stroke-width="1.5"/>
                        <text v-for="yl in chartData.yLabels" :key="'yl'+yl.y" :x="padding.left-8" :y="yl.y+3" text-anchor="end" fill="#6B7280" font-size="9">{{ yl.value }}</text>
                        <text v-for="xl in chartData.xLabels" :key="'xl'+xl.label" :x="xl.x" :y="chartHeight-5" text-anchor="middle" fill="#6B7280" font-size="9">{{ xl.label }}</text>
                    </svg>
                </div>
                <div v-else class="flex items-center justify-center h-48 text-gray-600 text-sm">Sem dados para o período</div>
            </div>

            <!-- Tables -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Top Queries -->
                <div v-if="topDimensions?.queries?.length" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-white mb-4">Termos de Busca</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-[10px] text-gray-500 uppercase tracking-wider border-b border-gray-800">
                                    <th class="pb-2 pr-3">#</th>
                                    <th class="pb-2 pr-3">Query</th>
                                    <th class="pb-2 pr-3 text-right">Cliques</th>
                                    <th class="pb-2 pr-3 text-right">Impr.</th>
                                    <th class="pb-2 pr-3 text-right">CTR</th>
                                    <th class="pb-2 text-right">Pos.</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-800/50">
                                <tr v-for="(q, i) in topDimensions.queries" :key="i" class="hover:bg-gray-800/30">
                                    <td class="py-2 pr-3 text-xs text-gray-500">{{ i + 1 }}</td>
                                    <td class="py-2 pr-3 text-xs text-gray-300 max-w-xs truncate">{{ q.name }}</td>
                                    <td class="py-2 pr-3 text-xs text-green-400 text-right tabular-nums">{{ Number(q.value).toLocaleString('pt-BR') }}</td>
                                    <td class="py-2 pr-3 text-xs text-gray-400 text-right tabular-nums">{{ q.extra?.impressions ? Number(q.extra.impressions).toLocaleString('pt-BR') : '-' }}</td>
                                    <td class="py-2 pr-3 text-xs text-teal-400 text-right tabular-nums">{{ q.extra?.ctr ? (q.extra.ctr * 100).toFixed(1) + '%' : '-' }}</td>
                                    <td class="py-2 text-xs text-lime-400 text-right tabular-nums">{{ q.extra?.position ? q.extra.position.toFixed(1) : '-' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Pages -->
                <div v-if="topDimensions?.pages?.length" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-white mb-4">Páginas</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-[10px] text-gray-500 uppercase tracking-wider border-b border-gray-800">
                                    <th class="pb-2 pr-3">#</th>
                                    <th class="pb-2 pr-3">Página</th>
                                    <th class="pb-2 pr-3 text-right">Cliques</th>
                                    <th class="pb-2 pr-3 text-right">Impr.</th>
                                    <th class="pb-2 text-right">Pos.</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-800/50">
                                <tr v-for="(p, i) in topDimensions.pages" :key="i" class="hover:bg-gray-800/30">
                                    <td class="py-2 pr-3 text-xs text-gray-500">{{ i + 1 }}</td>
                                    <td class="py-2 pr-3 text-xs text-gray-300 max-w-xs truncate" :title="p.name">{{ p.name }}</td>
                                    <td class="py-2 pr-3 text-xs text-green-400 text-right tabular-nums">{{ Number(p.value).toLocaleString('pt-BR') }}</td>
                                    <td class="py-2 pr-3 text-xs text-gray-400 text-right tabular-nums">{{ p.extra?.impressions ? Number(p.extra.impressions).toLocaleString('pt-BR') : '-' }}</td>
                                    <td class="py-2 text-xs text-lime-400 text-right tabular-nums">{{ p.extra?.position ? p.extra.position.toFixed(1) : '-' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Devices -->
                <div v-if="topDimensions?.devices?.length" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-white mb-3">Dispositivos</h3>
                    <div class="space-y-3">
                        <div v-for="(item, i) in topDimensions.devices" :key="i" class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-gray-800 flex items-center justify-center">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path v-if="item.name === 'DESKTOP'" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    <path v-else-if="item.name === 'MOBILE'" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                    <path v-else d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs text-gray-300 capitalize">{{ item.name.toLowerCase() }}</span>
                                    <span class="text-xs text-gray-400 tabular-nums">{{ Number(item.value).toLocaleString('pt-BR') }} cliques</span>
                                </div>
                                <div class="w-full bg-gray-800 rounded-full h-1.5"><div class="bg-green-500 h-1.5 rounded-full" :style="{width: (item.value/(topDimensions.devices[0]?.value||1))*100+'%'}"/></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Countries -->
                <div v-if="topDimensions?.countries?.length" class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-white mb-3">Países</h3>
                    <div class="space-y-2">
                        <div v-for="(item, i) in topDimensions.countries" :key="i" class="flex items-center gap-3">
                            <span class="text-xs text-gray-500 w-5 text-right">{{ i + 1 }}.</span>
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs text-gray-300">{{ item.name }}</span>
                                    <div class="flex items-center gap-3">
                                        <span class="text-[10px] text-gray-500">pos {{ item.extra?.position?.toFixed(1) || '-' }}</span>
                                        <span class="text-xs text-gray-400 tabular-nums">{{ Number(item.value).toLocaleString('pt-BR') }}</span>
                                    </div>
                                </div>
                                <div class="w-full bg-gray-800 rounded-full h-1"><div class="bg-teal-500 h-1 rounded-full" :style="{width: (item.value/(topDimensions.countries[0]?.value||1))*100+'%'}"/></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
