<script setup>
import { ref } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    campaigns: Object,
    filters: Object,
});

const page = usePage();
const flash = page.props.flash || {};
const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');

function applyFilters() {
    router.get(route('sms.campaigns.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
    }, { preserveState: true });
}

function deleteCampaign(id) {
    if (confirm('Tem certeza que deseja remover esta campanha?')) {
        router.delete(route('sms.campaigns.destroy', id));
    }
}

const statusLabels = {
    draft: 'Rascunho', scheduled: 'Agendada', sending: 'Enviando',
    sent: 'Enviada', paused: 'Pausada', cancelled: 'Cancelada',
};
const statusColors = {
    draft: 'bg-gray-900/30 text-gray-400', scheduled: 'bg-blue-900/30 text-blue-400',
    sending: 'bg-yellow-900/30 text-yellow-400', sent: 'bg-green-900/30 text-green-400',
    paused: 'bg-orange-900/30 text-orange-400', cancelled: 'bg-red-900/30 text-red-400',
};

function fmt(n) { return (n || 0).toLocaleString('pt-BR'); }
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-white">Campanhas SMS</h1>
                <Link :href="route('sms.campaigns.create')" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition">
                    + Nova Campanha
                </Link>
            </div>
        </template>

        <!-- Flash -->
        <div v-if="flash?.success" class="mb-4 px-4 py-3 rounded-lg bg-green-900/30 border border-green-700/50 text-green-300 text-sm">{{ flash.success }}</div>
        <div v-if="flash?.error" class="mb-4 px-4 py-3 rounded-lg bg-red-900/30 border border-red-700/50 text-red-300 text-sm">{{ flash.error }}</div>

        <!-- Filtros -->
        <div class="flex items-center gap-3 mb-6">
            <input
                v-model="search" @keyup.enter="applyFilters" placeholder="Buscar campanhas..."
                class="flex-1 bg-gray-900 border border-gray-800 rounded-lg px-3 py-2 text-white text-sm placeholder-gray-500"
            />
            <select v-model="statusFilter" @change="applyFilters" class="bg-gray-900 border border-gray-800 rounded-lg px-3 py-2 text-white text-sm">
                <option value="">Todos os Status</option>
                <option value="draft">Rascunho</option>
                <option value="scheduled">Agendada</option>
                <option value="sending">Enviando</option>
                <option value="sent">Enviada</option>
                <option value="paused">Pausada</option>
                <option value="cancelled">Cancelada</option>
            </select>
        </div>

        <!-- Lista -->
        <div class="bg-gray-900 rounded-xl border border-gray-800">
            <div v-if="!campaigns?.data?.length" class="p-12 text-center">
                <p class="text-gray-500 mb-4">Nenhuma campanha SMS encontrada.</p>
                <Link :href="route('sms.campaigns.create')" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">Criar Primeira Campanha</Link>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-gray-500 text-xs uppercase border-b border-gray-800">
                            <th class="text-left px-5 py-3">Nome</th>
                            <th class="text-left px-3 py-3">Status</th>
                            <th class="text-right px-3 py-3">Destinatários</th>
                            <th class="text-right px-3 py-3">Enviados</th>
                            <th class="text-right px-3 py-3">Entregues</th>
                            <th class="text-right px-3 py-3">Taxa</th>
                            <th class="text-right px-3 py-3">Segmentos</th>
                            <th class="text-center px-3 py-3">Listas</th>
                            <th class="text-right px-3 py-3">Data</th>
                            <th class="text-right px-5 py-3">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in campaigns.data" :key="c.id" class="border-t border-gray-800 hover:bg-gray-800/50 transition">
                            <td class="px-5 py-3">
                                <Link :href="route('sms.campaigns.show', c.id)" class="text-white font-medium hover:text-indigo-400 transition">{{ c.name }}</Link>
                                <p class="text-xs text-gray-500 mt-0.5">{{ c.sender_name }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <span :class="['px-2 py-0.5 text-xs rounded-full', statusColors[c.status]]">{{ statusLabels[c.status] }}</span>
                            </td>
                            <td class="px-3 py-3 text-right text-gray-300">{{ fmt(c.total_recipients) }}</td>
                            <td class="px-3 py-3 text-right text-gray-300">{{ fmt(c.total_sent) }}</td>
                            <td class="px-3 py-3 text-right text-gray-300">{{ fmt(c.total_delivered) }}</td>
                            <td class="px-3 py-3 text-right font-medium" :class="c.delivery_rate >= 90 ? 'text-green-400' : c.delivery_rate >= 70 ? 'text-yellow-400' : 'text-red-400'">
                                {{ c.delivery_rate }}%
                            </td>
                            <td class="px-3 py-3 text-right text-gray-400">{{ c.segments }}</td>
                            <td class="px-3 py-3 text-center">
                                <span v-for="list in c.lists" :key="list" class="inline-block px-2 py-0.5 text-[10px] rounded bg-gray-800 text-gray-400 mr-1">{{ list }}</span>
                            </td>
                            <td class="px-3 py-3 text-right text-gray-500 text-xs">{{ c.created_at }}</td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <Link :href="route('sms.campaigns.show', c.id)" class="px-2 py-1 text-xs bg-gray-800 text-gray-300 rounded hover:bg-gray-700">Ver</Link>
                                    <button v-if="c.status === 'draft'" @click="deleteCampaign(c.id)" class="px-2 py-1 text-xs bg-red-900/30 text-red-400 rounded hover:bg-red-900/50">Remover</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginação -->
            <div v-if="campaigns.last_page > 1" class="flex items-center justify-between px-5 py-3 border-t border-gray-800">
                <p class="text-xs text-gray-500">{{ campaigns.total }} campanha(s)</p>
                <div class="flex items-center gap-1">
                    <template v-for="link in campaigns.links" :key="link.label">
                        <button
                            v-if="link.url"
                            @click="router.get(link.url, {}, { preserveState: true })"
                            :class="['px-3 py-1 text-xs rounded', link.active ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-400 hover:bg-gray-700']"
                            v-html="link.label"
                        ></button>
                        <span v-else class="px-2 py-1 text-xs text-gray-600" v-html="link.label"></span>
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
