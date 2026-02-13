<script setup>
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({
    campaign: Object,
    recentEvents: Array,
    hourlyStats: Object,
});

const page = usePage();
const flash = page.props.flash || {};

const sendTestModal = ref(false);
const scheduleModal = ref(false);
const testEmail = ref('');
const scheduleDate = ref('');
const scheduleTime = ref('');
const sendTestLoading = ref(false);
const sendTestError = ref('');
const scheduleLoading = ref(false);

const statusConfig = {
    draft: { label: 'Rascunho', class: 'bg-gray-700/50 text-gray-400 border-gray-600' },
    scheduled: { label: 'Agendado', class: 'bg-blue-900/40 text-blue-400 border-blue-600/50' },
    sending: { label: 'Enviando', class: 'bg-amber-900/40 text-amber-400 border-amber-600/50 animate-pulse' },
    sent: { label: 'Enviado', class: 'bg-emerald-900/40 text-emerald-400 border-emerald-600/50' },
    paused: { label: 'Pausado', class: 'bg-orange-900/40 text-orange-400 border-orange-600/50' },
    cancelled: { label: 'Cancelado', class: 'bg-red-900/40 text-red-400 border-red-600/50' },
    failed: { label: 'Falhou', class: 'bg-red-900/40 text-red-400 border-red-600/50' },
};

const eventTypeConfig = {
    sent: { class: 'bg-blue-900/40 text-blue-400 border-blue-600/50' },
    delivered: { class: 'bg-emerald-900/40 text-emerald-400 border-emerald-600/50' },
    opened: { class: 'bg-indigo-900/40 text-indigo-400 border-indigo-600/50' },
    clicked: { class: 'bg-emerald-900/40 text-emerald-400 border-emerald-600/50' },
    bounced: { class: 'bg-red-900/40 text-red-400 border-red-600/50' },
    failed: { class: 'bg-red-900/40 text-red-400 border-red-600/50' },
    unsubscribed: { class: 'bg-orange-900/40 text-orange-400 border-orange-600/50' },
};

function getStatusBadge(status) {
    return statusConfig[status] || statusConfig.draft;
}

function getEventBadge(type) {
    return eventTypeConfig[type] || { class: 'bg-gray-700/50 text-gray-400 border-gray-600' };
}

function formatNumber(n) {
    if (n == null) return '0';
    return new Intl.NumberFormat('pt-BR').format(n);
}

function sendCampaign() {
    router.post(route('email.campaigns.send', props.campaign.id));
}

function pauseCampaign() {
    router.post(route('email.campaigns.pause', props.campaign.id));
}

function cancelCampaign() {
    if (confirm('Cancelar o envio desta campanha?')) {
        router.post(route('email.campaigns.cancel', props.campaign.id));
    }
}

function duplicateCampaign() {
    router.post(route('email.campaigns.duplicate', props.campaign.id));
}

async function submitSendTest() {
    sendTestError.value = '';
    sendTestLoading.value = true;
    try {
        const { data } = await axios.post(route('email.campaigns.send-test', props.campaign.id), {
            test_email: testEmail.value,
        });
        if (data.success !== false) {
            sendTestModal.value = false;
            testEmail.value = '';
        } else {
            sendTestError.value = data.error || 'Falha ao enviar teste.';
        }
    } catch (e) {
        sendTestError.value = e.response?.data?.error || e.message || 'Erro ao enviar teste.';
    } finally {
        sendTestLoading.value = false;
    }
}

function submitSchedule() {
    scheduleLoading.value = true;
    const dt = scheduleDate.value && scheduleTime.value ? `${scheduleDate.value} ${scheduleTime.value}` : scheduleDate.value;
    if (!dt) {
        scheduleLoading.value = false;
        return;
    }
    router.post(route('email.campaigns.schedule', props.campaign.id), { scheduled_at: dt }, {
        onFinish: () => { scheduleLoading.value = false; scheduleModal.value = false; scheduleDate.value = ''; scheduleTime.value = ''; },
    });
}

const c = props.campaign || {};
const totalRecipients = c.total_recipients ?? 0;
const totalSent = c.total_sent ?? 0;
const totalDelivered = c.total_delivered ?? 0;
const totalOpened = c.total_opened ?? 0;
const totalClicked = c.total_clicked ?? 0;
const totalBounced = c.total_bounced ?? 0;
const totalUnsubscribed = c.total_unsubscribed ?? 0;

const progressMax = Math.max(totalRecipients, totalSent, 1);
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a :href="route('email.campaigns.index')" class="rounded-lg p-2 text-gray-400 hover:bg-gray-800 hover:text-white transition">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-white">{{ c.name }}</h1>
                        <p class="text-sm text-gray-500">{{ c.subject }}</p>
                    </div>
                    <span
                        :class="[
                            'inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium',
                            getStatusBadge(c.status).class,
                        ]"
                    >
                        {{ getStatusBadge(c.status).label }}
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button v-if="c.can_send" @click="sendCampaign" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500">
                        Enviar
                    </button>
                    <button v-if="c.can_pause" @click="pauseCampaign" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-500">
                        Pausar
                    </button>
                    <button v-if="c.can_cancel" @click="cancelCampaign" class="rounded-lg bg-red-600/80 px-4 py-2 text-sm font-medium text-white hover:bg-red-500/80">
                        Cancelar
                    </button>
                    <a v-if="c.can_edit" :href="route('email.campaigns.edit', c.id)" class="rounded-lg border border-gray-600 px-4 py-2 text-sm text-gray-300 hover:bg-gray-800">
                        Editar
                    </a>
                    <button @click="duplicateCampaign" class="rounded-lg border border-gray-600 px-4 py-2 text-sm text-gray-300 hover:bg-gray-800">
                        Duplicar
                    </button>
                    <button @click="sendTestModal = true" class="rounded-lg border border-gray-600 px-4 py-2 text-sm text-gray-300 hover:bg-gray-800">
                        Enviar Teste
                    </button>
                    <button v-if="c.can_send" @click="scheduleModal = true" class="rounded-lg border border-indigo-600 px-4 py-2 text-sm text-indigo-400 hover:bg-indigo-900/30">
                        Agendar
                    </button>
                </div>
            </div>
        </template>

        <div v-if="flash?.success" class="mb-6 rounded-lg border border-emerald-700/50 bg-emerald-900/30 px-4 py-3 text-sm text-emerald-300">
            {{ flash.success }}
        </div>
        <div v-if="flash?.error" class="mb-6 rounded-lg border border-red-700/50 bg-red-900/30 px-4 py-3 text-sm text-red-300">
            {{ flash.error }}
        </div>

        <!-- KPI Cards -->
        <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                <p class="text-xs text-gray-500">Destinatários</p>
                <p class="text-xl font-bold text-white">{{ formatNumber(totalRecipients) }}</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                <p class="text-xs text-gray-500">Enviados</p>
                <p class="text-xl font-bold text-white">{{ formatNumber(totalSent) }}</p>
                <p v-if="totalRecipients" class="text-xs text-gray-400">{{ ((totalSent / totalRecipients) * 100).toFixed(1) }}%</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                <p class="text-xs text-gray-500">Entregues</p>
                <p class="text-xl font-bold text-white">{{ formatNumber(totalDelivered) }}</p>
                <p v-if="totalSent" class="text-xs text-gray-400">{{ c.delivery_rate ?? ((totalDelivered / totalSent) * 100).toFixed(1) }}%</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                <p class="text-xs text-gray-500">Aberturas</p>
                <p class="text-xl font-bold text-indigo-400">{{ formatNumber(totalOpened) }}</p>
                <p class="text-xs text-gray-400">{{ c.open_rate ?? '-' }}%</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                <p class="text-xs text-gray-500">Cliques</p>
                <p class="text-xl font-bold text-emerald-400">{{ formatNumber(totalClicked) }}</p>
                <p class="text-xs text-gray-400">{{ c.click_rate ?? '-' }}%</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                <p class="text-xs text-gray-500">Bounce</p>
                <p class="text-xl font-bold" :class="(c.bounce_rate || 0) > 5 ? 'text-red-400' : 'text-white'">{{ formatNumber(totalBounced) }}</p>
                <p class="text-xs text-gray-400">{{ c.bounce_rate ?? '-' }}%</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                <p class="text-xs text-gray-500">Descadastros</p>
                <p class="text-xl font-bold text-orange-400">{{ formatNumber(totalUnsubscribed) }}</p>
                <p class="text-xs text-gray-400">{{ c.unsubscribe_rate ?? '-' }}%</p>
            </div>
        </div>

        <!-- Progress funnel -->
        <div class="mb-8 rounded-xl border border-gray-800 bg-gray-900 p-6">
            <h3 class="mb-4 text-sm font-semibold text-gray-300">Funil de Entrega</h3>
            <div class="space-y-3">
                <div>
                    <div class="mb-1 flex justify-between text-xs">
                        <span class="text-gray-500">Enviados</span>
                        <span class="text-gray-400">{{ totalSent }} / {{ totalRecipients }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-gray-800">
                        <div class="h-full rounded-full bg-indigo-600" :style="{ width: (totalSent / progressMax * 100) + '%' }"></div>
                    </div>
                </div>
                <div>
                    <div class="mb-1 flex justify-between text-xs">
                        <span class="text-gray-500">Entregues</span>
                        <span class="text-gray-400">{{ totalDelivered }} / {{ totalSent }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-gray-800">
                        <div class="h-full rounded-full bg-blue-500" :style="{ width: (totalSent ? (totalDelivered / totalSent * 100) : 0) + '%' }"></div>
                    </div>
                </div>
                <div>
                    <div class="mb-1 flex justify-between text-xs">
                        <span class="text-gray-500">Aberturas</span>
                        <span class="text-gray-400">{{ totalOpened }} / {{ totalDelivered }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-gray-800">
                        <div class="h-full rounded-full bg-indigo-400" :style="{ width: (totalDelivered ? (totalOpened / totalDelivered * 100) : 0) + '%' }"></div>
                    </div>
                </div>
                <div>
                    <div class="mb-1 flex justify-between text-xs">
                        <span class="text-gray-500">Cliques</span>
                        <span class="text-gray-400">{{ totalClicked }} / {{ totalOpened }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-gray-800">
                        <div class="h-full rounded-full bg-emerald-500" :style="{ width: (totalOpened ? (totalClicked / totalOpened * 100) : 0) + '%' }"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Recent events -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h3 class="mb-4 text-sm font-semibold text-gray-300">Eventos Recentes</h3>
                <div v-if="!recentEvents?.length" class="py-8 text-center text-gray-500">
                    Nenhum evento registrado.
                </div>
                <div v-else class="max-h-80 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-gray-900">
                            <tr class="border-b border-gray-800 text-left text-xs text-gray-500">
                                <th class="py-2 px-2">Tipo</th>
                                <th class="py-2 px-2">Contato</th>
                                <th class="py-2 px-2 text-right">Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in recentEvents" :key="e.id" class="border-b border-gray-800/50">
                                <td class="py-2 px-2">
                                    <span
                                        :class="[
                                            'inline-flex rounded border px-2 py-0.5 text-xs font-medium',
                                            getEventBadge(e.event_type).class,
                                        ]"
                                    >
                                        {{ e.event_type }}
                                    </span>
                                </td>
                                <td class="py-2 px-2 text-gray-300">{{ e.contact?.email ?? '-' }}</td>
                                <td class="py-2 px-2 text-right text-xs text-gray-500">{{ e.occurred_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- HTML Preview -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h3 class="mb-4 text-sm font-semibold text-gray-300">Preview do Email</h3>
                <div v-if="!c.html_content" class="flex aspect-video items-center justify-center rounded-lg border border-dashed border-gray-700 bg-gray-800 text-gray-500">
                    Sem conteúdo para preview
                </div>
                <iframe
                    v-else
                    :srcdoc="c.html_content"
                    class="h-[400px] w-full rounded-lg border border-gray-700 bg-white"
                    sandbox="allow-same-origin"
                    title="Preview do email"
                ></iframe>
            </div>
        </div>

        <!-- Send Test Modal -->
        <div v-if="sendTestModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="sendTestModal = false">
            <div class="w-full max-w-md rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h3 class="mb-4 text-lg font-semibold text-white">Enviar Teste</h3>
                <div class="space-y-4">
                    <div>
                        <label class="text-sm font-medium text-gray-300">Email</label>
                        <input v-model="testEmail" type="email" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white" placeholder="seu@email.com" />
                    </div>
                    <p v-if="sendTestError" class="text-sm text-red-400">{{ sendTestError }}</p>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button @click="sendTestModal = false" class="rounded-lg border border-gray-600 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800">
                        Cancelar
                    </button>
                    <button @click="submitSendTest" :disabled="sendTestLoading || !testEmail" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                        {{ sendTestLoading ? 'Enviando...' : 'Enviar' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Schedule Modal -->
        <div v-if="scheduleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="scheduleModal = false">
            <div class="w-full max-w-md rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h3 class="mb-4 text-lg font-semibold text-white">Agendar Envio</h3>
                <div class="space-y-4">
                    <div>
                        <label class="text-sm font-medium text-gray-300">Data</label>
                        <input v-model="scheduleDate" type="date" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-300">Horário</label>
                        <input v-model="scheduleTime" type="time" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button @click="scheduleModal = false" class="rounded-lg border border-gray-600 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800">
                        Cancelar
                    </button>
                    <button @click="submitSchedule" :disabled="scheduleLoading || !scheduleDate" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                        {{ scheduleLoading ? 'Agendando...' : 'Agendar' }}
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
