<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const autopilotGuideSteps = [
    { title: 'Agende posts', description: 'Crie posts em Social > Posts e defina data/hora de publicação. Eles aparecerão aqui como "Pendentes".' },
    { title: 'Publicação automática', description: 'O sistema verifica a cada minuto se existem posts prontos para publicar e executa automaticamente.' },
    { title: 'Re-tentativas inteligentes', description: 'Falhas são re-tentadas automaticamente até 3 vezes com intervalos crescentes (1min, 5min, 15min).' },
    { title: 'Monitore aqui', description: 'Este painel mostra em tempo real: pendentes, publicando, publicados, falhas e re-tentáveis.' },
];

const autopilotGuideTips = [
    'Tokens de acesso às redes sociais são renovados automaticamente a cada hora quando necessário.',
    'Posts com falha podem ser re-tentados manualmente clicando em "Re-tentar" na seção de falhas.',
    'O Autopilot funciona independentemente - não é necessário manter o navegador aberto.',
    'Conecte contas reais em Social > Contas para que a publicação real funcione nas plataformas.',
];
import { computed } from 'vue';

interface ScheduleItem {
    id: number;
    post_id: number;
    post_title: string;
    platform: string;
    platform_label: string;
    platform_color: string;
    status: string;
    attempts: number;
    max_attempts: number;
    scheduled_at: string | null;
    published_at: string | null;
    last_attempted_at: string | null;
    error_message: string | null;
    platform_post_url: string | null;
    can_retry: boolean;
    account_username: string | null;
}

interface Props {
    stats: {
        pending: number;
        publishing: number;
        published_today: number;
        failed: number;
        retryable: number;
        published_total: number;
    };
    upcoming: ScheduleItem[];
    recent: ScheduleItem[];
    failed: ScheduleItem[];
}

const props = defineProps<Props>();
const page = usePage();
const currentBrand = computed(() => page.props.currentBrand);

const statusLabels: Record<string, { label: string; class: string }> = {
    pending: { label: 'Pendente', class: 'bg-gray-500/20 text-gray-400 border-gray-500/30' },
    publishing: { label: 'Publicando', class: 'bg-orange-500/20 text-orange-400 border-orange-500/30' },
    published: { label: 'Publicado', class: 'bg-green-500/20 text-green-400 border-green-500/30' },
    failed: { label: 'Falhou', class: 'bg-red-500/20 text-red-400 border-red-500/30' },
};

function retrySchedule(scheduleId: number) {
    router.post(route('social.autopilot.retry', scheduleId));
}

function getStatusBadge(status: string) {
    return statusLabels[status] || statusLabels.pending;
}
</script>

<template>
    <Head title="Social - Autopilot" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold text-white">Autopilot</h1>
                    <span class="rounded-lg bg-green-500/20 border border-green-500/30 px-2 py-0.5 text-xs font-medium text-green-400">
                        Ativo
                    </span>
                </div>
                <div class="flex items-center gap-3">
                    <Link :href="route('social.posts.index')" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        Posts
                    </Link>
                    <Link :href="route('social.calendar.index')" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        Calendário
                    </Link>
                </div>
            </div>
        </template>

        <!-- Sem marca -->
        <div v-if="!currentBrand" class="rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
            <h3 class="text-lg font-medium text-gray-300">Nenhuma marca selecionada</h3>
            <p class="mt-2 text-sm text-gray-500">Selecione uma marca para monitorar o Autopilot.</p>
        </div>

        <template v-else>
            <!-- Stats Cards -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6 mb-6">
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-gray-300">{{ stats.pending }}</p>
                    <p class="text-xs text-gray-500 mt-1">Pendentes</p>
                </div>
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-orange-400">{{ stats.publishing }}</p>
                    <p class="text-xs text-gray-500 mt-1">Publicando</p>
                </div>
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-green-400">{{ stats.published_today }}</p>
                    <p class="text-xs text-gray-500 mt-1">Publicados hoje</p>
                </div>
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-red-400">{{ stats.failed }}</p>
                    <p class="text-xs text-gray-500 mt-1">Com falha</p>
                </div>
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-yellow-400">{{ stats.retryable }}</p>
                    <p class="text-xs text-gray-500 mt-1">Re-tentáveis</p>
                </div>
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-indigo-400">{{ stats.published_total }}</p>
                    <p class="text-xs text-gray-500 mt-1">Total publicados</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Proximos Agendamentos -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800">
                    <div class="px-5 py-4 border-b border-gray-800">
                        <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                            <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />
                            </svg>
                            Próximos Agendamentos
                        </h2>
                    </div>
                    <div v-if="upcoming.length" class="divide-y divide-gray-800">
                        <div v-for="item in upcoming" :key="item.id" class="px-5 py-3 flex items-center gap-3">
                            <span
                                class="w-2 h-2 rounded-full shrink-0"
                                :style="{ backgroundColor: item.platform_color }"
                            />
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-300 truncate">{{ item.post_title }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ item.platform_label }}
                                    <span v-if="item.account_username"> &middot; @{{ item.account_username }}</span>
                                    &middot; {{ item.scheduled_at }}
                                </p>
                            </div>
                            <span :class="['rounded-md border px-2 py-0.5 text-[10px] font-medium', getStatusBadge(item.status).class]">
                                {{ getStatusBadge(item.status).label }}
                            </span>
                        </div>
                    </div>
                    <div v-else class="px-5 py-8 text-center text-sm text-gray-500">
                        Nenhum agendamento pendente
                    </div>
                </div>

                <!-- Posts com Falha -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800">
                    <div class="px-5 py-4 border-b border-gray-800">
                        <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <circle cx="12" cy="12" r="10" /><line x1="15" y1="9" x2="9" y2="15" /><line x1="9" y1="9" x2="15" y2="15" />
                            </svg>
                            Publicações com Falha
                        </h2>
                    </div>
                    <div v-if="failed.length" class="divide-y divide-gray-800">
                        <div v-for="item in failed" :key="item.id" class="px-5 py-3">
                            <div class="flex items-center gap-3 mb-1">
                                <span
                                    class="w-2 h-2 rounded-full shrink-0"
                                    :style="{ backgroundColor: item.platform_color }"
                                />
                                <p class="text-sm text-gray-300 truncate flex-1">{{ item.post_title }}</p>
                                <span class="text-[10px] text-gray-500">
                                    {{ item.attempts }}/{{ item.max_attempts }} tentativas
                                </span>
                            </div>
                            <div class="flex items-center justify-between ml-5">
                                <p class="text-xs text-red-400 truncate max-w-[70%]" :title="item.error_message || ''">
                                    {{ item.error_message || 'Erro desconhecido' }}
                                </p>
                                <button
                                    v-if="item.can_retry"
                                    @click="retrySchedule(item.id)"
                                    class="rounded-md bg-red-500/10 border border-red-500/30 px-2.5 py-1 text-[11px] font-medium text-red-400 hover:bg-red-500/20 transition shrink-0"
                                >
                                    Re-tentar
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-else class="px-5 py-8 text-center text-sm text-gray-500">
                        Nenhuma falha registrada
                    </div>
                </div>
            </div>

            <!-- Publicados Recentemente -->
            <div class="rounded-2xl bg-gray-900 border border-gray-800 mt-6">
                <div class="px-5 py-4 border-b border-gray-800">
                    <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" />
                        </svg>
                        Publicados Recentemente
                    </h2>
                </div>
                <div v-if="recent.length">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-800 text-left text-xs text-gray-500 uppercase">
                                    <th class="px-5 py-3 font-medium">Post</th>
                                    <th class="px-5 py-3 font-medium">Plataforma</th>
                                    <th class="px-5 py-3 font-medium">Conta</th>
                                    <th class="px-5 py-3 font-medium">Publicado em</th>
                                    <th class="px-5 py-3 font-medium">Tentativas</th>
                                    <th class="px-5 py-3 font-medium">Link</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-800">
                                <tr v-for="item in recent" :key="item.id" class="hover:bg-gray-800/50 transition">
                                    <td class="px-5 py-3 text-gray-300 max-w-[200px] truncate">
                                        <Link :href="route('social.posts.edit', item.post_id)" class="hover:text-indigo-400 transition">
                                            {{ item.post_title }}
                                        </Link>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span
                                            class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-medium text-white"
                                            :style="{ backgroundColor: item.platform_color }"
                                        >
                                            {{ item.platform_label }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-gray-500">
                                        {{ item.account_username ? '@' + item.account_username : '-' }}
                                    </td>
                                    <td class="px-5 py-3 text-gray-400">{{ item.published_at }}</td>
                                    <td class="px-5 py-3 text-gray-500">{{ item.attempts }}</td>
                                    <td class="px-5 py-3">
                                        <a
                                            v-if="item.platform_post_url"
                                            :href="item.platform_post_url"
                                            target="_blank"
                                            class="text-indigo-400 hover:text-indigo-300 text-xs transition"
                                        >
                                            Abrir
                                        </a>
                                        <span v-else class="text-gray-600 text-xs">-</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div v-else class="px-5 py-8 text-center text-sm text-gray-500">
                    Nenhuma publicação recente
                </div>
            </div>

            <!-- Guia detalhado -->
            <GuideBox
                title="Como funciona o Autopilot"
                description="O Autopilot é o motor de publicação automática do MKT Privus. Ele gerencia todo o ciclo de vida dos posts agendados."
                :steps="autopilotGuideSteps"
                :tips="autopilotGuideTips"
                color="emerald"
                storage-key="autopilot-guide"
                class="mt-6"
            />
        </template>
    </AuthenticatedLayout>
</template>
