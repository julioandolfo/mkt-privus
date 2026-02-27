<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({
    campaign: Object,
    recentEvents: Array,
    hourlyStats: Object,
    scheduleInfo: Object,
});

const page = usePage();
const flash = page.props.flash || {};

const sendTestModal = ref(false);
const scheduleModal = ref(false);
const editScheduleModal = ref(false);
const sendNowModal = ref(false);
const testEmail = ref('');
const scheduleDate = ref('');
const scheduleTime = ref('');
const editScheduleDate = ref('');
const editScheduleTime = ref('');
const sendTestLoading = ref(false);
const sendTestError = ref('');
const sendTestSuccess = ref('');
const scheduleLoading = ref(false);
const editScheduleLoading = ref(false);
const sendNowLoading = ref(false);
const sendNowError = ref('');
const sendNowSuccess = ref('');

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

// Contador regressivo para agendamento
const countdown = ref('');
const countdownInterval = ref(null);

function updateCountdown() {
    if (!props.scheduleInfo || props.scheduleInfo.type !== 'scheduled') {
        countdown.value = '';
        return;
    }

    const scheduledAt = new Date(props.scheduleInfo.scheduled_at);
    const now = new Date();
    const diff = scheduledAt - now;

    if (diff <= 0) {
        countdown.value = 'Aguardando início...';
        return;
    }

    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);

    if (hours > 0) {
        countdown.value = `${hours}h ${minutes}m ${seconds}s`;
    } else if (minutes > 0) {
        countdown.value = `${minutes}m ${seconds}s`;
    } else {
        countdown.value = `${seconds}s`;
    }
}

onMounted(() => {
    if (props.scheduleInfo?.type === 'scheduled') {
        updateCountdown();
        countdownInterval.value = setInterval(updateCountdown, 1000);
    }
});

onUnmounted(() => {
    if (countdownInterval.value) {
        clearInterval(countdownInterval.value);
    }
});

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
    sendTestSuccess.value = '';
    sendTestLoading.value = true;
    try {
        const { data } = await axios.post(route('email.campaigns.send-test', props.campaign.id), {
            test_email: testEmail.value,
        });
        if (data.success !== false) {
            sendTestSuccess.value = `Email de teste enviado com sucesso para ${testEmail.value}!`;
            // Fecha o modal após 2 segundos para o usuário ver a mensagem
            setTimeout(() => {
                sendTestModal.value = false;
                testEmail.value = '';
                sendTestSuccess.value = '';
            }, 2000);
        } else {
            sendTestError.value = data.error || 'Falha ao enviar teste.';
        }
    } catch (e) {
        sendTestError.value = e.response?.data?.error || e.message || 'Erro ao enviar teste.';
    } finally {
        sendTestLoading.value = false;
    }
}

function toLocalISO(dateStr, timeStr) {
    const dt = timeStr ? `${dateStr}T${timeStr}` : dateStr;
    const d = new Date(dt);
    const pad = (n) => String(n).padStart(2, '0');
    const offset = -d.getTimezoneOffset();
    const sign = offset >= 0 ? '+' : '-';
    const hh = pad(Math.floor(Math.abs(offset) / 60));
    const mm = pad(Math.abs(offset) % 60);
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:00${sign}${hh}:${mm}`;
}

function submitSchedule() {
    scheduleLoading.value = true;
    if (!scheduleDate.value) {
        scheduleLoading.value = false;
        return;
    }
    const dt = toLocalISO(scheduleDate.value, scheduleTime.value || '00:00');
    router.post(route('email.campaigns.schedule', props.campaign.id), { scheduled_at: dt }, {
        onFinish: () => { scheduleLoading.value = false; scheduleModal.value = false; scheduleDate.value = ''; scheduleTime.value = ''; },
    });
}

function openEditScheduleModal() {
    // Preenche com o valor atual do agendamento
    if (props.campaign.scheduled_at) {
        const date = new Date(props.campaign.scheduled_at);
        editScheduleDate.value = date.toISOString().split('T')[0];
        editScheduleTime.value = date.toTimeString().slice(0, 5);
    }
    editScheduleModal.value = true;
}

function submitEditSchedule() {
    editScheduleLoading.value = true;
    if (!editScheduleDate.value) {
        editScheduleLoading.value = false;
        return;
    }
    const dt = toLocalISO(editScheduleDate.value, editScheduleTime.value || '00:00');
    router.post(route('email.campaigns.update-schedule', props.campaign.id), { scheduled_at: dt }, {
        onFinish: () => { editScheduleLoading.value = false; editScheduleModal.value = false; editScheduleDate.value = ''; editScheduleTime.value = ''; },
    });
}

function openSendNowModal() {
    sendNowModal.value = true;
    sendNowError.value = '';
    sendNowSuccess.value = '';
}

function confirmSendNow() {
    sendNowLoading.value = true;
    sendNowError.value = '';
    sendNowSuccess.value = '';

    router.post(route('email.campaigns.send-now', props.campaign.id), {}, {
        onSuccess: () => {
            sendNowSuccess.value = 'Campanha iniciada com sucesso! Os envios estão sendo processados.';
            // Recarrega a página após 1.5 segundos para mostrar o status atualizado
            setTimeout(() => {
                router.reload({ only: ['campaign', 'recentEvents'] });
                sendNowModal.value = false;
                sendNowSuccess.value = '';
            }, 1500);
        },
        onError: (errors) => {
            sendNowLoading.value = false;
            sendNowError.value = errors?.error || 'Erro ao iniciar o envio. Tente novamente.';
        },
        onFinish: () => {
            sendNowLoading.value = false;
        },
    });
}

// Verificar se há falhas por quota para reenviar
const hasQuotaFailures = computed(() => {
    if (!props.recentEvents) return false;
    return props.recentEvents.some(e =>
        e.event_type === 'failed' &&
        (e.metadata?.reason === 'quota_exceeded' ||
         e.metadata?.error?.includes('quota') ||
         e.metadata?.error?.includes('Limite'))
    );
});

const retryLoading = ref(false);

function retryFailed() {
    if (!confirm('Deseja reenviar os emails que falharam por limite de quota?')) {
        return;
    }
    retryLoading.value = true;
    router.post(route('email.campaigns.retry-failed', props.campaign.id), {}, {
        onSuccess: () => {
            retryLoading.value = false;
            // Recarrega a página para mostrar o status atualizado
            router.reload({ only: ['campaign', 'recentEvents', 'scheduleInfo'] });
        },
        onError: () => {
            retryLoading.value = false;
        },
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
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <!-- Título e status -->
                <div class="flex items-start gap-3 min-w-0">
                    <a :href="route('email.campaigns.index')" class="rounded-lg p-2 text-gray-400 hover:bg-gray-800 hover:text-white transition flex-shrink-0 mt-1">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                    </a>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <h1 class="text-xl lg:text-2xl font-bold text-white truncate">{{ c.name }}</h1>
                            <span
                                :class="[
                                    'inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium flex-shrink-0',
                                    getStatusBadge(c.status).class,
                                ]"
                            >
                                {{ getStatusBadge(c.status).label }}
                            </span>
                        </div>
                        <p class="text-sm text-gray-500 truncate">{{ c.subject }}</p>
                    </div>
                </div>

                <!-- Botões de ação -->
                <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                    <!-- Status: Draft -->
                    <button v-if="c.can_send && c.status !== 'scheduled'" @click="sendCampaign" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-500 whitespace-nowrap">
                        Enviar
                    </button>

                    <!-- Status: Scheduled -->
                    <template v-if="c.status === 'scheduled'">
                        <button @click="openSendNowModal" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-500 whitespace-nowrap">
                            Enviar Agora
                        </button>
                        <button @click="openEditScheduleModal" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-500 whitespace-nowrap">
                            Alterar
                        </button>
                        <button @click="cancelCampaign" class="rounded-lg bg-red-600/80 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-500/80 whitespace-nowrap">
                            Cancelar
                        </button>
                    </template>

                    <!-- Status: Sending -->
                    <button v-if="c.can_pause" @click="pauseCampaign" class="rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-500 whitespace-nowrap">
                        Pausar
                    </button>

                    <!-- Status: Sending/Scheduled -->
                    <button v-if="c.can_cancel && c.status !== 'scheduled'" @click="cancelCampaign" class="rounded-lg bg-red-600/80 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-500/80 whitespace-nowrap">
                        Cancelar
                    </button>

                    <a v-if="c.can_edit" :href="route('email.campaigns.edit', c.id)" class="rounded-lg border border-gray-600 px-3 py-1.5 text-sm text-gray-300 hover:bg-gray-800 whitespace-nowrap">
                        Editar
                    </a>
                    <button @click="duplicateCampaign" class="rounded-lg border border-gray-600 px-3 py-1.5 text-sm text-gray-300 hover:bg-gray-800 whitespace-nowrap">
                        Duplicar
                    </button>
                    <button @click="sendTestModal = true" class="rounded-lg border border-gray-600 px-3 py-1.5 text-sm text-gray-300 hover:bg-gray-800 whitespace-nowrap">
                        Teste
                    </button>

                    <!-- Reenviar falhas por quota -->
                    <button v-if="hasQuotaFailures" @click="retryFailed" :disabled="retryLoading" class="rounded-lg bg-purple-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-purple-500 whitespace-nowrap disabled:opacity-50">
                        {{ retryLoading ? 'Reprocessando...' : 'Reenviar Falhas' }}
                    </button>

                    <button v-if="c.can_send && c.status !== 'scheduled'" @click="scheduleModal = true" class="rounded-lg border border-indigo-600 px-3 py-1.5 text-sm text-indigo-400 hover:bg-indigo-900/30 whitespace-nowrap">
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

        <!-- Agendamento / Progresso -->
        <div v-if="scheduleInfo" class="mb-8">
            <!-- Status: Agendado -->
            <div v-if="scheduleInfo.type === 'scheduled'" class="rounded-xl border border-blue-700/50 bg-blue-900/20 p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="flex h-14 w-14 items-center justify-center rounded-xl bg-blue-600/30">
                            <svg class="h-7 w-7 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <circle cx="12" cy="12" r="10" />
                                <polyline points="12 6 12 12 16 14" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-white">Campanha Agendada</h3>
                            <p class="text-sm text-blue-300">
                                Envio programado para <span class="font-medium text-white">{{ scheduleInfo.scheduled_at_formatted }}</span>
                            </p>
                            <p v-if="scheduleInfo.is_overdue" class="text-xs text-amber-400 mt-1">
                                ⚠️ Horário do agendamento já passou - será enviado assim que o scheduler executar
                            </p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-blue-400 mb-1">Faltam</p>
                        <p class="text-3xl font-bold text-white font-mono">{{ countdown }}</p>
                    </div>
                </div>
            </div>

            <!-- Status: Enviando -->
            <div v-if="scheduleInfo.type === 'sending'" class="rounded-xl border border-amber-700/50 bg-amber-900/20 p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="flex h-14 w-14 items-center justify-center rounded-xl bg-amber-600/30 animate-pulse">
                            <svg class="h-7 w-7 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-white">Enviando Emails...</h3>
                            <p class="text-sm text-amber-300">
                                {{ scheduleInfo.total_processed }} de {{ scheduleInfo.total_queued }} processados
                            </p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-amber-400 mb-1">Tempo estimado</p>
                        <p class="text-2xl font-bold text-white">{{ scheduleInfo.eta_formatted }}</p>
                        <p class="text-xs text-gray-400">{{ scheduleInfo.send_speed }} emails/min</p>
                    </div>
                </div>

                <!-- Progress bar -->
                <div class="mb-2 flex justify-between text-xs">
                    <span class="text-gray-400">Progresso</span>
                    <span class="text-white font-medium">{{ scheduleInfo.progress_percent }}%</span>
                </div>
                <div class="h-3 rounded-full bg-gray-800 overflow-hidden">
                    <div class="h-full rounded-full bg-amber-500 transition-all duration-500" :style="{ width: scheduleInfo.progress_percent + '%' }"></div>
                </div>
                <div class="mt-2 flex justify-between text-xs text-gray-500">
                    <span>{{ scheduleInfo.remaining }} restantes</span>
                    <span v-if="scheduleInfo.progress_percent >= 99">Quase lá...</span>
                </div>
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

                <!-- Alerta de Sucesso -->
                <div v-if="sendTestSuccess" class="mb-4 rounded-lg border border-emerald-700/50 bg-emerald-900/30 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <svg class="h-5 w-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <p class="text-sm text-emerald-300">{{ sendTestSuccess }}</p>
                    </div>
                </div>

                <!-- Alerta de Erro -->
                <div v-if="sendTestError" class="mb-4 rounded-lg border border-red-700/50 bg-red-900/30 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <svg class="h-5 w-5 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        <p class="text-sm text-red-300">{{ sendTestError }}</p>
                    </div>
                </div>

                <div class="space-y-4" v-if="!sendTestSuccess">
                    <div>
                        <label class="text-sm font-medium text-gray-300">Email</label>
                        <input v-model="testEmail" type="email" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white" placeholder="seu@email.com" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button @click="sendTestModal = false; sendTestError = ''; sendTestSuccess = '';" class="rounded-lg border border-gray-600 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800">
                        {{ sendTestSuccess ? 'Fechar' : 'Cancelar' }}
                    </button>
                    <button v-if="!sendTestSuccess" @click="submitSendTest" :disabled="sendTestLoading || !testEmail" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
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

        <!-- Edit Schedule Modal -->
        <div v-if="editScheduleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="editScheduleModal = false">
            <div class="w-full max-w-md rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h3 class="mb-4 text-lg font-semibold text-white">Alterar Agendamento</h3>
                <p class="mb-4 text-sm text-gray-400">Agendado atualmente para: <span class="text-blue-400 font-medium">{{ c.scheduled_at }}</span></p>
                <div class="space-y-4">
                    <div>
                        <label class="text-sm font-medium text-gray-300">Nova Data</label>
                        <input v-model="editScheduleDate" type="date" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-300">Novo Horário</label>
                        <input v-model="editScheduleTime" type="time" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button @click="editScheduleModal = false" class="rounded-lg border border-gray-600 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800">
                        Cancelar
                    </button>
                    <button @click="submitEditSchedule" :disabled="editScheduleLoading || !editScheduleDate" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500 disabled:opacity-50">
                        {{ editScheduleLoading ? 'Salvando...' : 'Salvar Alteração' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Send Now Confirmation Modal -->
        <div v-if="sendNowModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="sendNowModal = false">
            <div class="w-full max-w-md rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h3 class="mb-2 text-lg font-semibold text-white">Enviar Agora?</h3>

                <!-- Alerta de Sucesso -->
                <div v-if="sendNowSuccess" class="mb-4 rounded-lg border border-emerald-700/50 bg-emerald-900/30 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <svg class="h-5 w-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <p class="text-sm text-emerald-300">{{ sendNowSuccess }}</p>
                    </div>
                </div>

                <!-- Alerta de Erro -->
                <div v-if="sendNowError" class="mb-4 rounded-lg border border-red-700/50 bg-red-900/30 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <svg class="h-5 w-5 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        <p class="text-sm text-red-300">{{ sendNowError }}</p>
                    </div>
                </div>

                <!-- Conteúdo original (escondido quando há sucesso) -->
                <template v-if="!sendNowSuccess">
                    <p class="mb-4 text-sm text-gray-400">
                        Esta campanha está agendada para <span class="text-blue-400 font-medium">{{ c.scheduled_at }}</span>.
                        Deseja enviar imediatamente e cancelar o agendamento?
                    </p>
                    <div class="rounded-lg bg-amber-900/30 border border-amber-700/50 p-3 mb-4">
                        <p class="text-xs text-amber-400">
                            Atenção: Esta ação não pode ser desfeita. Os emails serão enviados imediatamente.
                        </p>
                    </div>
                </template>

                <div class="flex justify-end gap-2">
                    <button @click="sendNowModal = false; sendNowError = ''; sendNowSuccess = '';" class="rounded-lg border border-gray-600 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800">
                        {{ sendNowSuccess ? 'Fechar' : 'Cancelar' }}
                    </button>
                    <button v-if="!sendNowSuccess" @click="confirmSendNow" :disabled="sendNowLoading" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 disabled:opacity-50">
                        {{ sendNowLoading ? 'Iniciando...' : 'Sim, Enviar Agora' }}
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
