<script setup>
import { ref, watch } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    campaign: Object,
    costEstimate: Object,
    recentEvents: Array,
});

const page = usePage();
const flash = ref(page.props.flash || {});
watch(() => page.props.flash, (v) => { flash.value = v || {}; });

const statusLabels = {
    draft: 'Rascunho', scheduled: 'Agendada', sending: 'Enviando',
    sent: 'Enviada', paused: 'Pausada', cancelled: 'Cancelada',
};
const statusColors = {
    draft: 'bg-gray-600/20 text-gray-300 border-gray-600',
    scheduled: 'bg-blue-600/20 text-blue-400 border-blue-600',
    sending: 'bg-yellow-600/20 text-yellow-400 border-yellow-600',
    sent: 'bg-green-600/20 text-green-400 border-green-600',
    paused: 'bg-orange-600/20 text-orange-400 border-orange-600',
    cancelled: 'bg-red-600/20 text-red-400 border-red-600',
};

const eventLabels = {
    sent: 'Enviado', delivered: 'Entregue', failed: 'Falha',
    clicked: 'Clique', optout: 'Opt-out',
};
const eventColors = {
    sent: 'text-blue-400', delivered: 'text-green-400', failed: 'text-red-400',
    clicked: 'text-indigo-400', optout: 'text-orange-400',
};

function fmt(n) { return (n || 0).toLocaleString('pt-BR'); }

function sendNow() {
    if (confirm('Iniciar envio da campanha SMS agora?')) {
        router.post(route('sms.campaigns.send', props.campaign.id));
    }
}
function pauseCampaign() {
    router.post(route('sms.campaigns.pause', props.campaign.id));
}
function cancelCampaign() {
    if (confirm('Tem certeza que deseja cancelar esta campanha?')) {
        router.post(route('sms.campaigns.cancel', props.campaign.id));
    }
}
function duplicateCampaign() {
    router.post(route('sms.campaigns.duplicate', props.campaign.id));
}

const scheduleDate = ref('');
function scheduleCampaign() {
    if (scheduleDate.value) {
        router.post(route('sms.campaigns.schedule', props.campaign.id), { scheduled_at: scheduleDate.value });
    }
}

const showScheduleModal = ref(false);
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <Link :href="route('sms.campaigns.index')" class="text-gray-400 hover:text-white transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </Link>
                        <h1 class="text-2xl font-bold text-white">{{ campaign.name }}</h1>
                        <span :class="['px-3 py-1 text-xs rounded-full border', statusColors[campaign.status]]">
                            {{ statusLabels[campaign.status] }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-400 mt-1">Remetente: {{ campaign.sender_name }} · Criada em {{ campaign.created_at }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button v-if="campaign.can_send" @click="sendNow" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-500 transition">Enviar Agora</button>
                    <button v-if="campaign.can_send" @click="showScheduleModal = true" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-500 transition">Agendar</button>
                    <button v-if="campaign.can_pause" @click="pauseCampaign" class="px-4 py-2 bg-yellow-600 text-white rounded-lg text-sm font-medium hover:bg-yellow-500 transition">Pausar</button>
                    <button v-if="campaign.can_cancel" @click="cancelCampaign" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-500 transition">Cancelar</button>
                    <button @click="duplicateCampaign" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg text-sm hover:bg-gray-700 transition">Duplicar</button>
                </div>
            </div>
        </template>

        <!-- Flash -->
        <div v-if="flash?.success" class="mb-4 px-4 py-3 rounded-lg bg-green-900/30 border border-green-700/50 text-green-300 text-sm">{{ flash.success }}</div>
        <div v-if="flash?.error" class="mb-4 px-4 py-3 rounded-lg bg-red-900/30 border border-red-700/50 text-red-300 text-sm">{{ flash.error }}</div>

        <!-- KPIs -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500">Destinatários</p>
                <p class="text-xl font-bold text-white mt-1">{{ fmt(campaign.total_recipients) }}</p>
            </div>
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500">Enviados</p>
                <p class="text-xl font-bold text-blue-400 mt-1">{{ fmt(campaign.total_sent) }}</p>
            </div>
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500">Entregues</p>
                <p class="text-xl font-bold text-green-400 mt-1">{{ fmt(campaign.total_delivered) }}</p>
                <p class="text-xs text-green-600 mt-0.5">{{ campaign.delivery_rate }}%</p>
            </div>
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500">Falhas</p>
                <p class="text-xl font-bold text-red-400 mt-1">{{ fmt(campaign.total_failed) }}</p>
                <p class="text-xs text-red-600 mt-0.5">{{ campaign.failure_rate }}%</p>
            </div>
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500">Cliques</p>
                <p class="text-xl font-bold text-indigo-400 mt-1">{{ fmt(campaign.total_clicked) }}</p>
                <p class="text-xs text-indigo-600 mt-0.5">{{ campaign.click_rate }}%</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Mensagem & Detalhes -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Mensagem -->
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-white mb-3">Mensagem</h3>
                    <div class="max-w-sm mx-auto bg-gray-800 rounded-2xl p-4">
                        <div class="bg-green-600 rounded-xl rounded-bl-sm px-3 py-2 text-white text-sm whitespace-pre-wrap">
                            {{ campaign.body }}
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1 text-right">{{ campaign.sender_name }}</p>
                    </div>
                    <div class="flex items-center justify-center gap-6 mt-4 text-xs text-gray-500">
                        <span>{{ campaign.body?.length }} chars</span>
                        <span>{{ campaign.segments }} segmento(s)</span>
                    </div>
                </div>

                <!-- Eventos Recentes -->
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-white mb-3">Eventos Recentes</h3>
                    <div v-if="!recentEvents?.length" class="text-center py-8 text-gray-500 text-sm">Nenhum evento registrado.</div>
                    <div v-else class="overflow-x-auto max-h-80 overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-gray-900">
                                <tr class="text-gray-500 text-xs uppercase">
                                    <th class="text-left py-2 pr-3">Evento</th>
                                    <th class="text-left py-2 pr-3">Telefone</th>
                                    <th class="text-left py-2 pr-3">Contato</th>
                                    <th class="text-right py-2">Data</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="ev in recentEvents" :key="ev.id" class="border-t border-gray-800">
                                    <td class="py-2 pr-3">
                                        <span :class="['text-xs font-medium', eventColors[ev.event_type] || 'text-gray-400']">
                                            {{ eventLabels[ev.event_type] || ev.event_type }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-3 text-gray-400 font-mono text-xs">{{ ev.phone }}</td>
                                    <td class="py-2 pr-3 text-gray-400 text-xs">{{ ev.contact_name }}</td>
                                    <td class="py-2 text-right text-gray-500 text-xs">{{ ev.occurred_at }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Custo Estimado -->
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-white mb-3">Custo Estimado</h3>
                    <div v-if="costEstimate" class="space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-gray-500">Destinatários</span><span class="text-white">{{ fmt(costEstimate.total_recipients) }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Segs/msg</span><span class="text-white">{{ costEstimate.segments_per_msg }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Total segs</span><span class="text-white">{{ fmt(costEstimate.total_segments) }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Preço/seg</span><span class="text-white">R$ {{ costEstimate.price_per_segment }}</span></div>
                        <div class="flex justify-between pt-2 border-t border-gray-800"><span class="text-gray-400 font-medium">Total</span><span class="text-yellow-400 font-bold text-lg">{{ costEstimate.formatted }}</span></div>
                    </div>
                </div>

                <!-- Detalhes -->
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-white mb-3">Detalhes</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-gray-500">Provedor</span><span class="text-white">{{ campaign.provider?.name || '-' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Template</span><span class="text-white">{{ campaign.template?.name || 'Personalizado' }}</span></div>
                        <div v-if="campaign.scheduled_at" class="flex justify-between"><span class="text-gray-500">Agendado</span><span class="text-white">{{ campaign.scheduled_at }}</span></div>
                        <div v-if="campaign.started_at" class="flex justify-between"><span class="text-gray-500">Iniciado</span><span class="text-white">{{ campaign.started_at }}</span></div>
                        <div v-if="campaign.completed_at" class="flex justify-between"><span class="text-gray-500">Concluído</span><span class="text-white">{{ campaign.completed_at }}</span></div>
                    </div>
                </div>

                <!-- Listas -->
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-white mb-3">Listas</h3>
                    <div class="space-y-1">
                        <div v-for="l in campaign.include_lists" :key="l.id" class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-green-500"></span>
                            <span class="text-sm text-gray-300">{{ l.name }}</span>
                        </div>
                        <div v-for="l in campaign.exclude_lists" :key="'ex-'+l.id" class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-red-500"></span>
                            <span class="text-sm text-gray-400 line-through">{{ l.name }}</span>
                        </div>
                    </div>
                </div>

                <!-- Tags -->
                <div v-if="campaign.tags?.length" class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-white mb-3">Tags</h3>
                    <div class="flex flex-wrap gap-1">
                        <span v-for="tag in campaign.tags" :key="tag" class="px-2 py-0.5 text-xs bg-gray-800 text-gray-400 rounded-full">{{ tag }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schedule Modal -->
        <Teleport to="body">
            <div v-if="showScheduleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="showScheduleModal = false">
                <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6 w-full max-w-md">
                    <h3 class="text-lg font-bold text-white mb-4">Agendar Campanha</h3>
                    <div>
                        <label class="text-sm text-gray-400">Data e Hora</label>
                        <input v-model="scheduleDate" type="datetime-local" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                    </div>
                    <div class="flex justify-end gap-3 mt-6">
                        <button @click="showScheduleModal = false" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                        <button @click="scheduleCampaign(); showScheduleModal = false;" :disabled="!scheduleDate" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-500 disabled:opacity-50 transition">Agendar</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
