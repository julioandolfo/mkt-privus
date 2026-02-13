<script setup>
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    kpis: Object,
    recentCampaigns: Array,
    dailyStats: Array,
    statusDistribution: Object,
    period: String,
    templates_count: Number,
    migrationPending: Boolean,
});

const activePeriod = ref(props.period || 'this_month');

function changePeriod(p) {
    activePeriod.value = p;
    router.get(route('sms.dashboard'), { period: p }, { preserveState: true });
}

const statusLabels = {
    draft: 'Rascunho',
    scheduled: 'Agendada',
    sending: 'Enviando',
    sent: 'Enviada',
    paused: 'Pausada',
    cancelled: 'Cancelada',
};

const statusColors = {
    draft: 'bg-gray-900/30 text-gray-400',
    scheduled: 'bg-blue-900/30 text-blue-400',
    sending: 'bg-yellow-900/30 text-yellow-400',
    sent: 'bg-green-900/30 text-green-400',
    paused: 'bg-orange-900/30 text-orange-400',
    cancelled: 'bg-red-900/30 text-red-400',
};

const periods = [
    { value: 'today', label: 'Hoje' },
    { value: 'yesterday', label: 'Ontem' },
    { value: 'last_7_days', label: '7 Dias' },
    { value: 'this_month', label: 'Este Mês' },
    { value: 'last_month', label: 'Mês Passado' },
    { value: 'last_30_days', label: '30 Dias' },
];

function fmt(n) {
    return (n || 0).toLocaleString('pt-BR');
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">SMS Marketing</h1>
                    <p class="text-sm text-gray-400 mt-1">Gerencie suas campanhas de SMS</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="flex bg-gray-900 rounded-lg border border-gray-800 p-0.5">
                        <button
                            v-for="p in periods" :key="p.value"
                            @click="changePeriod(p.value)"
                            :class="['px-3 py-1.5 text-xs rounded-md transition', activePeriod === p.value ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white']"
                        >{{ p.label }}</button>
                    </div>
                    <Link :href="route('sms.campaigns.create')" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition">
                        + Nova Campanha
                    </Link>
                </div>
            </div>
        </template>

        <!-- Migration Pending Alert -->
        <div v-if="migrationPending" class="mb-6 px-4 py-3 rounded-lg bg-yellow-900/30 border border-yellow-700/50 text-yellow-300 text-sm">
            As tabelas SMS ainda nao foram criadas. Execute <code class="bg-gray-800 px-1 rounded">php artisan migrate</code> no servidor para ativar o modulo SMS.
        </div>

        <!-- KPIs -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Campanhas</p>
                <p class="text-2xl font-bold text-white mt-1">{{ fmt(kpis.total_campaigns) }}</p>
            </div>
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                <p class="text-xs text-gray-500 uppercase tracking-wide">SMS Enviados</p>
                <p class="text-2xl font-bold text-white mt-1">{{ fmt(kpis.total_sent) }}</p>
            </div>
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Taxa Entrega</p>
                <p class="text-2xl font-bold text-green-400 mt-1">{{ kpis.delivery_rate }}%</p>
            </div>
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Taxa Cliques</p>
                <p class="text-2xl font-bold text-blue-400 mt-1">{{ kpis.click_rate }}%</p>
            </div>
        </div>

        <!-- Detalhes e Ações Rápidas -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Detalhes de Envio -->
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-5 lg:col-span-2">
                <h3 class="text-sm font-semibold text-white mb-4">Detalhes de Envio</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <p class="text-xs text-gray-500">Entregues</p>
                        <p class="text-lg font-bold text-green-400">{{ fmt(kpis.total_delivered) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Falhas</p>
                        <p class="text-lg font-bold text-red-400">{{ fmt(kpis.total_failed) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Cliques</p>
                        <p class="text-lg font-bold text-blue-400">{{ fmt(kpis.total_clicked) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Taxa Falha</p>
                        <p class="text-lg font-bold text-red-400">{{ kpis.failure_rate }}%</p>
                    </div>
                </div>
            </div>

            <!-- Ações Rápidas -->
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                <h3 class="text-sm font-semibold text-white mb-4">Ações Rápidas</h3>
                <div class="space-y-2">
                    <Link :href="route('sms.campaigns.create')" class="block w-full text-left px-4 py-2.5 rounded-lg bg-gray-800 text-gray-300 text-sm hover:bg-gray-700 transition">
                        Nova Campanha SMS
                    </Link>
                    <Link :href="route('sms.templates.create')" class="block w-full text-left px-4 py-2.5 rounded-lg bg-gray-800 text-gray-300 text-sm hover:bg-gray-700 transition">
                        Novo Template SMS
                    </Link>
                    <Link :href="route('email.providers.index')" class="block w-full text-left px-4 py-2.5 rounded-lg bg-gray-800 text-gray-300 text-sm hover:bg-gray-700 transition">
                        Configurar Provedor SMS
                    </Link>
                </div>
                <div class="mt-4 pt-3 border-t border-gray-800">
                    <p class="text-xs text-gray-500">Templates ativos: <span class="text-white font-medium">{{ templates_count }}</span></p>
                </div>
            </div>
        </div>

        <!-- Campanhas Recentes -->
        <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-white">Campanhas Recentes</h3>
                <Link :href="route('sms.campaigns.index')" class="text-xs text-indigo-400 hover:text-indigo-300">Ver todas</Link>
            </div>

            <div v-if="!recentCampaigns?.length" class="text-center py-8">
                <p class="text-gray-500 text-sm">Nenhuma campanha SMS ainda.</p>
                <Link :href="route('sms.campaigns.create')" class="text-indigo-400 text-sm mt-2 inline-block">Criar primeira campanha</Link>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-gray-500 text-xs uppercase">
                            <th class="text-left py-2 pr-4">Nome</th>
                            <th class="text-left py-2 pr-4">Status</th>
                            <th class="text-right py-2 pr-4">Enviados</th>
                            <th class="text-right py-2 pr-4">Entregues</th>
                            <th class="text-right py-2 pr-4">Taxa</th>
                            <th class="text-right py-2">Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in recentCampaigns" :key="c.id" class="border-t border-gray-800">
                            <td class="py-2.5 pr-4">
                                <Link :href="route('sms.campaigns.show', c.id)" class="text-white hover:text-indigo-400 transition">{{ c.name }}</Link>
                            </td>
                            <td class="py-2.5 pr-4">
                                <span :class="['px-2 py-0.5 text-xs rounded-full', statusColors[c.status]]">
                                    {{ statusLabels[c.status] || c.status }}
                                </span>
                            </td>
                            <td class="py-2.5 pr-4 text-right text-gray-300">{{ fmt(c.total_sent) }}</td>
                            <td class="py-2.5 pr-4 text-right text-gray-300">{{ fmt(c.total_delivered) }}</td>
                            <td class="py-2.5 pr-4 text-right text-green-400 font-medium">{{ c.delivery_rate }}%</td>
                            <td class="py-2.5 text-right text-gray-500">{{ c.created_at }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
