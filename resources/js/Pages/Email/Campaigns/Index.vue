<script setup>
import { router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    campaigns: Object,
});

const page = usePage();
const flash = page.props.flash || {};

const campaignData = props.campaigns?.data ?? [];
const links = props.campaigns?.links ?? [];

const statusConfig = {
    draft: { label: 'Rascunho', class: 'bg-gray-700/50 text-gray-400 border-gray-600' },
    scheduled: { label: 'Agendado', class: 'bg-blue-900/40 text-blue-400 border-blue-600/50' },
    sending: { label: 'Enviando', class: 'bg-amber-900/40 text-amber-400 border-amber-600/50 animate-pulse' },
    sent: { label: 'Enviado', class: 'bg-emerald-900/40 text-emerald-400 border-emerald-600/50' },
    paused: { label: 'Pausado', class: 'bg-orange-900/40 text-orange-400 border-orange-600/50' },
    cancelled: { label: 'Cancelado', class: 'bg-red-900/40 text-red-400 border-red-600/50' },
    failed: { label: 'Falhou', class: 'bg-red-900/40 text-red-400 border-red-600/50' },
};

function getStatusBadge(status) {
    return statusConfig[status] || statusConfig.draft;
}

function goToCampaign(campaign) {
    router.visit(route('email.campaigns.show', campaign.id));
}

function formatNumber(n) {
    if (n == null) return '-';
    return new Intl.NumberFormat('pt-BR').format(n);
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-white">Campanhas</h1>
                <a
                    :href="route('email.campaigns.create')"
                    class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 transition"
                >
                    + Nova Campanha
                </a>
            </div>
        </template>

        <div v-if="flash?.success" class="mb-6 rounded-lg border border-emerald-700/50 bg-emerald-900/30 px-4 py-3 text-sm text-emerald-300">
            {{ flash.success }}
        </div>

        <div v-if="!campaignData.length" class="rounded-xl border border-gray-800 bg-gray-900 p-12 text-center">
            <svg class="mx-auto mb-4 h-16 w-16 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            <p class="mb-4 text-gray-400">Nenhuma campanha criada ainda.</p>
            <a :href="route('email.campaigns.create')" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white">Criar Primeira Campanha</a>
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 text-left text-xs uppercase text-gray-500">
                            <th class="py-3 px-4">Nome</th>
                            <th class="py-3 px-4">Assunto</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4 text-right">Enviados</th>
                            <th class="py-3 px-4 text-right">Aberturas</th>
                            <th class="py-3 px-4 text-right">Cliques</th>
                            <th class="py-3 px-4 text-right">Open Rate</th>
                            <th class="py-3 px-4 text-right">Click Rate</th>
                            <th class="py-3 px-4 text-right">Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in campaignData"
                            :key="c.id"
                            class="cursor-pointer border-b border-gray-800/50 transition hover:bg-gray-800/30"
                            @click="goToCampaign(c)"
                        >
                            <td class="py-3 px-4">
                                <p class="font-medium text-white">{{ c.name }}</p>
                            </td>
                            <td class="py-3 px-4 text-gray-400">{{ c.subject || '-' }}</td>
                            <td class="py-3 px-4">
                                <span
                                    :class="[
                                        'inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium',
                                        getStatusBadge(c.status).class,
                                    ]"
                                >
                                    {{ getStatusBadge(c.status).label }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right text-gray-300">{{ formatNumber(c.total_sent) }}</td>
                            <td class="py-3 px-4 text-right text-gray-300">{{ formatNumber(c.total_opened) }}</td>
                            <td class="py-3 px-4 text-right text-gray-300">{{ formatNumber(c.total_clicked) }}</td>
                            <td class="py-3 px-4 text-right font-semibold text-indigo-400">
                                {{ c.open_rate != null ? c.open_rate + '%' : '-' }}
                            </td>
                            <td class="py-3 px-4 text-right font-semibold text-emerald-400">
                                {{ c.click_rate != null ? c.click_rate + '%' : '-' }}
                            </td>
                            <td class="py-3 px-4 text-right text-xs text-gray-500">
                                {{ c.started_at || c.scheduled_at || c.created_at }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="links.length > 1" class="flex flex-wrap items-center justify-center gap-1 border-t border-gray-800 p-3">
                <template v-for="link in links" :key="link.label">
                    <a
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-sm transition',
                            link.active ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                        ]"
                        v-html="link.label"
                    />
                    <span v-else class="px-3 py-1.5 text-sm text-gray-600" v-html="link.label" />
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
