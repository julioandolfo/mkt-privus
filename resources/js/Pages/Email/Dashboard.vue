<script setup>
import { ref, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    overallStats: Object,
    dailyChart: Array,
    topCampaigns: Array,
    contactStats: Object,
    comparison: Object,
    period: String,
    dates: Object,
});

const selectedPeriod = ref(props.period || 'this_month');
const periods = [
    { value: 'today', label: 'Hoje' },
    { value: 'yesterday', label: 'Ontem' },
    { value: 'this_week', label: 'Esta Semana' },
    { value: 'this_month', label: 'Este Mês' },
    { value: 'last_month', label: 'Mês Passado' },
    { value: 'last_30', label: 'Últimos 30 dias' },
    { value: 'last_90', label: 'Últimos 90 dias' },
];

function changePeriod(period) {
    selectedPeriod.value = period;
    router.get(route('email.dashboard'), { period }, { preserveState: true });
}

function formatNumber(n) {
    if (!n) return '0';
    return new Intl.NumberFormat('pt-BR').format(n);
}

function getDelta(current, prev) {
    if (!prev || prev === 0) return null;
    return ((current - prev) / prev * 100).toFixed(1);
}

const stats = computed(() => props.overallStats || {});
const comp = computed(() => props.comparison || {});

// Calcular dados do grafico
const chartDays = computed(() => {
    const data = props.dailyChart || [];
    return data.map(d => ({
        date: new Date(d.date).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }),
        sent: d.sent || 0,
        opened: d.opened || 0,
        clicked: d.clicked || 0,
    }));
});

const maxChartValue = computed(() => {
    return Math.max(...chartDays.value.map(d => Math.max(d.sent, d.opened, d.clicked)), 1);
});
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-white">Email Marketing</h1>
                <div class="flex items-center gap-2">
                    <button
                        v-for="p in periods" :key="p.value"
                        @click="changePeriod(p.value)"
                        :class="[
                            'px-3 py-1.5 rounded-lg text-xs font-medium transition',
                            selectedPeriod === p.value
                                ? 'bg-indigo-600 text-white'
                                : 'bg-gray-800 text-gray-400 hover:text-white hover:bg-gray-700'
                        ]"
                    >{{ p.label }}</button>
                </div>
            </div>
        </template>

        <!-- KPIs principais -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-8">
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500 mb-1">Campanhas Enviadas</p>
                <p class="text-2xl font-bold text-white">{{ formatNumber(stats.sent_campaigns) }}</p>
            </div>
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500 mb-1">Total Enviados</p>
                <p class="text-2xl font-bold text-white">{{ formatNumber(stats.total_sent) }}</p>
                <p v-if="getDelta(stats.total_sent, comp.prev_sent)" class="text-xs mt-1" :class="getDelta(stats.total_sent, comp.prev_sent) >= 0 ? 'text-green-400' : 'text-red-400'">
                    {{ getDelta(stats.total_sent, comp.prev_sent) > 0 ? '+' : '' }}{{ getDelta(stats.total_sent, comp.prev_sent) }}%
                </p>
            </div>
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500 mb-1">Entregues</p>
                <p class="text-2xl font-bold text-white">{{ formatNumber(stats.total_delivered) }}</p>
                <p class="text-xs text-gray-400 mt-1">{{ stats.delivery_rate }}% taxa</p>
            </div>
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500 mb-1">Taxa Abertura</p>
                <p class="text-2xl font-bold text-indigo-400">{{ stats.open_rate }}%</p>
                <p v-if="comp.prev_open_rate" class="text-xs mt-1" :class="stats.open_rate >= comp.prev_open_rate ? 'text-green-400' : 'text-red-400'">
                    vs {{ comp.prev_open_rate }}% anterior
                </p>
            </div>
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500 mb-1">Taxa Cliques</p>
                <p class="text-2xl font-bold text-emerald-400">{{ stats.click_rate }}%</p>
                <p v-if="comp.prev_click_rate" class="text-xs mt-1" :class="stats.click_rate >= comp.prev_click_rate ? 'text-green-400' : 'text-red-400'">
                    vs {{ comp.prev_click_rate }}% anterior
                </p>
            </div>
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500 mb-1">Bounce Rate</p>
                <p class="text-2xl font-bold" :class="stats.bounce_rate > 5 ? 'text-red-400' : 'text-white'">{{ stats.bounce_rate }}%</p>
            </div>
        </div>

        <!-- Grafico diario + Contatos -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Chart -->
            <div class="lg:col-span-2 bg-gray-900 rounded-xl border border-gray-800 p-6">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">Atividade Diária</h3>
                <div v-if="chartDays.length === 0" class="text-center py-12 text-gray-500">
                    Nenhum dado de envio no período selecionado.
                </div>
                <div v-else class="space-y-2">
                    <div v-for="day in chartDays" :key="day.date" class="flex items-center gap-3">
                        <span class="text-xs text-gray-500 w-12 shrink-0">{{ day.date }}</span>
                        <div class="flex-1 flex flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <div class="h-2 rounded bg-indigo-600" :style="{ width: (day.sent / maxChartValue * 100) + '%', minWidth: day.sent > 0 ? '4px' : '0' }"></div>
                                <span class="text-xs text-gray-500">{{ day.sent }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="h-2 rounded bg-emerald-500" :style="{ width: (day.opened / maxChartValue * 100) + '%', minWidth: day.opened > 0 ? '4px' : '0' }"></div>
                                <span class="text-xs text-gray-500">{{ day.opened }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="h-2 rounded bg-amber-500" :style="{ width: (day.clicked / maxChartValue * 100) + '%', minWidth: day.clicked > 0 ? '4px' : '0' }"></div>
                                <span class="text-xs text-gray-500">{{ day.clicked }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4 mt-4 text-xs text-gray-500">
                    <span class="flex items-center gap-1"><span class="w-3 h-2 rounded bg-indigo-600"></span> Enviados</span>
                    <span class="flex items-center gap-1"><span class="w-3 h-2 rounded bg-emerald-500"></span> Aberturas</span>
                    <span class="flex items-center gap-1"><span class="w-3 h-2 rounded bg-amber-500"></span> Cliques</span>
                </div>
            </div>

            <!-- Contacts sidebar -->
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-6">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">Contatos</h3>
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-400">Total</span>
                        <span class="text-lg font-bold text-white">{{ formatNumber(contactStats?.total) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-400">Ativos</span>
                        <span class="text-lg font-semibold text-green-400">{{ formatNumber(contactStats?.active) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-400">Descadastrados</span>
                        <span class="text-sm text-red-400">{{ formatNumber(contactStats?.unsubscribed) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-400">Bounced</span>
                        <span class="text-sm text-orange-400">{{ formatNumber(contactStats?.bounced) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-400">Listas</span>
                        <span class="text-sm text-gray-200">{{ contactStats?.lists || 0 }}</span>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-gray-800 flex flex-col gap-2">
                    <a :href="route('email.lists.index')" class="text-sm text-indigo-400 hover:text-indigo-300">Gerenciar Listas →</a>
                    <a :href="route('email.campaigns.create')" class="text-sm text-indigo-400 hover:text-indigo-300">Nova Campanha →</a>
                </div>
            </div>
        </div>

        <!-- Top Campanhas -->
        <div class="bg-gray-900 rounded-xl border border-gray-800 p-6">
            <h3 class="text-sm font-semibold text-gray-300 mb-4">Top Campanhas do Período</h3>
            <div v-if="!topCampaigns?.length" class="text-center py-8 text-gray-500">
                Nenhuma campanha enviada neste período.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-gray-500 text-xs uppercase border-b border-gray-800">
                            <th class="text-left py-2 px-3">Campanha</th>
                            <th class="text-right py-2 px-3">Enviados</th>
                            <th class="text-right py-2 px-3">Entregues</th>
                            <th class="text-right py-2 px-3">Aberturas</th>
                            <th class="text-right py-2 px-3">Cliques</th>
                            <th class="text-right py-2 px-3">Open Rate</th>
                            <th class="text-right py-2 px-3">Click Rate</th>
                            <th class="text-right py-2 px-3">Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in topCampaigns" :key="c.id" class="border-b border-gray-800/50 hover:bg-gray-800/30 cursor-pointer" @click="router.visit(route('email.campaigns.show', c.id))">
                            <td class="py-3 px-3">
                                <p class="text-gray-200 font-medium">{{ c.name }}</p>
                                <p class="text-xs text-gray-500 truncate max-w-[200px]">{{ c.subject }}</p>
                            </td>
                            <td class="text-right py-3 px-3 text-gray-300">{{ formatNumber(c.total_sent) }}</td>
                            <td class="text-right py-3 px-3 text-gray-300">{{ formatNumber(c.total_delivered) }}</td>
                            <td class="text-right py-3 px-3 text-gray-300">{{ formatNumber(c.unique_opens) }}</td>
                            <td class="text-right py-3 px-3 text-gray-300">{{ formatNumber(c.unique_clicks) }}</td>
                            <td class="text-right py-3 px-3 font-semibold text-indigo-400">{{ c.open_rate }}%</td>
                            <td class="text-right py-3 px-3 font-semibold text-emerald-400">{{ c.click_rate }}%</td>
                            <td class="text-right py-3 px-3 text-gray-500 text-xs">{{ c.started_at }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
