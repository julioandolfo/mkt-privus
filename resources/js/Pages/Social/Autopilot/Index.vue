<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';

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

const props = defineProps<{
    stats: { pending: number; publishing: number; published_today: number; failed: number; retryable: number; published_total: number };
    upcoming: ScheduleItem[];
    recent: ScheduleItem[];
    failed: ScheduleItem[];
    config: {
        enabled: boolean; posts_per_week: number; platforms: string[]; post_types: string[];
        generate_images: boolean; auto_hashtags: boolean; hashtag_count: number;
        caption_style: string; include_cta: boolean; include_emojis: boolean;
        tone: string; instructions: string; best_times_source: string; auto_approve: boolean;
    };
    socialAccounts: { id: number; platform: string; username: string }[];
}>();

const page = usePage();
const currentBrand = computed(() => page.props.currentBrand);
const activeTab = ref<'monitor' | 'settings'>('monitor');
const form = ref({ ...props.config });
const saving = ref(false);
const running = ref(false);
const result = ref<{ type: 'success' | 'error'; message: string } | null>(null);

const statusLabels: Record<string, { label: string; class: string }> = {
    pending: { label: 'Pendente', class: 'bg-gray-500/20 text-gray-400 border-gray-500/30' },
    publishing: { label: 'Publicando', class: 'bg-orange-500/20 text-orange-400 border-orange-500/30' },
    published: { label: 'Publicado', class: 'bg-green-500/20 text-green-400 border-green-500/30' },
    failed: { label: 'Falhou', class: 'bg-red-500/20 text-red-400 border-red-500/30' },
};

const clearingFailed = ref(false);

function retrySchedule(id: number) { router.post(route('social.autopilot.retry', id)); }
function getStatusBadge(s: string) { return statusLabels[s] || statusLabels.pending; }

function clearFailed() {
    if (!confirm('Remover todas as publicações com falha? Esta ação não pode ser desfeita.')) return;
    clearingFailed.value = true;
    router.post(route('social.autopilot.clear-failed'), {}, {
        onFinish: () => { clearingFailed.value = false; },
    });
}

const platformOptions = [
    { value: 'instagram', label: 'Instagram' },
    { value: 'facebook', label: 'Facebook' },
    { value: 'tiktok', label: 'TikTok' },
    { value: 'linkedin', label: 'LinkedIn' },
    { value: 'twitter', label: 'Twitter / X' },
];

const postTypeOptions = [
    { value: 'feed', label: 'Feed (imagem)' },
    { value: 'carousel', label: 'Carrossel' },
    { value: 'reel', label: 'Reel / Vídeo' },
    { value: 'story', label: 'Story' },
];

function toggleArrayItem(arr: string[], val: string) {
    const idx = arr.indexOf(val);
    if (idx >= 0) arr.splice(idx, 1);
    else arr.push(val);
}

const connectedPlatforms = computed(() => [...new Set(props.socialAccounts.map(a => a.platform))]);

const summary = computed(() => {
    if (!form.value.enabled) return 'Autopilot desativado';
    const plats = form.value.platforms.length ? form.value.platforms.join(', ') : 'todas plataformas';
    const aprov = form.value.auto_approve ? 'aprovação automática' : 'aguardando sua aprovação';
    return `${form.value.posts_per_week} post(s)/semana → ${plats} → ${aprov}`;
});

async function save() {
    saving.value = true;
    result.value = null;
    try {
        const { data } = await axios.post(route('social.autopilot.save-settings'), form.value);
        result.value = { type: data.success ? 'success' : 'error', message: data.message || data.error };
    } catch (e: any) {
        result.value = { type: 'error', message: e.response?.data?.message || 'Erro ao salvar.' };
    } finally {
        saving.value = false;
    }
}

async function runNow() {
    if (!confirm('Disparar o autopilot agora? Isso vai gerar sugestões e posts do calendário.')) return;
    running.value = true;
    result.value = null;
    try {
        const { data } = await axios.post(route('social.autopilot.run'));
        result.value = { type: data.success ? 'success' : 'error', message: data.message || data.error };
    } catch (e: any) {
        result.value = { type: 'error', message: e.response?.data?.message || 'Erro ao disparar.' };
    } finally {
        running.value = false;
    }
}
</script>

<template>
    <Head title="Social - Autopilot" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold text-white">Autopilot</h1>
                    <span :class="form.enabled
                        ? 'bg-green-500/20 border-green-500/30 text-green-400'
                        : 'bg-gray-500/20 border-gray-500/30 text-gray-400'"
                        class="rounded-lg border px-2 py-0.5 text-xs font-medium">
                        {{ form.enabled ? 'Ativo' : 'Inativo' }}
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <Link :href="route('social.posts.index')" class="rounded-xl px-3 py-2 text-xs text-gray-400 hover:text-white border border-gray-700 transition">Posts</Link>
                    <Link :href="route('social.calendar.index')" class="rounded-xl px-3 py-2 text-xs text-gray-400 hover:text-white border border-gray-700 transition">Calendário</Link>
                </div>
            </div>
        </template>

        <div v-if="!currentBrand" class="rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
            <h3 class="text-lg font-medium text-gray-300">Nenhuma marca selecionada</h3>
            <p class="mt-2 text-sm text-gray-500">Selecione uma marca para configurar o Autopilot.</p>
        </div>

        <template v-else>
            <!-- Tabs -->
            <div class="flex gap-1 mb-6 bg-gray-900 rounded-xl p-1 border border-gray-800 max-w-sm">
                <button @click="activeTab = 'settings'"
                    :class="activeTab === 'settings' ? 'bg-gray-700 text-white' : 'text-gray-500 hover:text-gray-300'"
                    class="flex-1 rounded-lg px-4 py-2 text-sm font-medium transition">
                    Configurações
                </button>
                <button @click="activeTab = 'monitor'"
                    :class="activeTab === 'monitor' ? 'bg-gray-700 text-white' : 'text-gray-500 hover:text-gray-300'"
                    class="flex-1 rounded-lg px-4 py-2 text-sm font-medium transition">
                    Monitor
                </button>
            </div>

            <!-- TAB: SETTINGS -->
            <div v-if="activeTab === 'settings'" class="max-w-2xl space-y-5">
                <!-- Status banner -->
                <div :class="form.enabled
                    ? 'bg-emerald-900/20 border-emerald-500/30 text-emerald-300'
                    : 'bg-gray-900 border-gray-700 text-gray-400'"
                    class="rounded-2xl border p-4 flex items-start gap-3">
                    <div :class="form.enabled ? 'bg-emerald-500' : 'bg-gray-600'" class="w-2 h-2 rounded-full mt-1.5 shrink-0"></div>
                    <div>
                        <p class="text-sm font-medium">{{ form.enabled ? 'Autopilot Ativo' : 'Autopilot Inativo' }}</p>
                        <p class="text-xs mt-0.5 opacity-70">{{ summary }}</p>
                    </div>
                </div>

                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6 space-y-5">
                    <h2 class="text-base font-semibold text-white">Configurações Gerais</h2>

                    <!-- Toggle -->
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-white">Ativar autopilot social</p>
                            <p class="text-xs text-gray-500 mt-0.5">O sistema gera conteúdo e publica automaticamente</p>
                        </div>
                        <button @click="form.enabled = !form.enabled" type="button"
                            :class="form.enabled ? 'bg-indigo-600' : 'bg-gray-700'"
                            class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors duration-200 focus:outline-none">
                            <span :class="form.enabled ? 'translate-x-5' : 'translate-x-0.5'"
                                class="inline-block h-5 w-5 mt-0.5 rounded-full bg-white shadow transform transition-transform duration-200" />
                        </button>
                    </div>

                    <div class="border-t border-gray-800"></div>

                    <!-- Posts por semana -->
                    <div>
                        <label class="text-sm text-gray-400 mb-2 block">Posts por semana: <span class="text-white font-medium">{{ form.posts_per_week }}</span></label>
                        <input v-model.number="form.posts_per_week" type="range" min="1" max="21" step="1" class="w-full accent-indigo-500" />
                        <div class="flex justify-between text-[10px] text-gray-600 mt-1"><span>1</span><span>7</span><span>14</span><span>21</span></div>
                    </div>

                    <!-- Plataformas -->
                    <div>
                        <label class="text-sm text-gray-400 mb-2 block">Plataformas</label>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="p in platformOptions" :key="p.value" type="button"
                                @click="toggleArrayItem(form.platforms, p.value)"
                                :class="form.platforms.includes(p.value) ? 'bg-indigo-600/20 border-indigo-500/50 text-indigo-300' : 'bg-gray-800 border-gray-700 text-gray-500'"
                                class="rounded-lg border px-3 py-1.5 text-xs font-medium transition">
                                {{ p.label }}
                                <span v-if="connectedPlatforms.includes(p.value)" class="ml-1 text-green-400">●</span>
                            </button>
                        </div>
                        <p class="text-[10px] text-gray-600 mt-1">Vazio = todas plataformas conectadas. <span class="text-green-400">●</span> = conta conectada.</p>
                    </div>

                    <!-- Tipos de post -->
                    <div>
                        <label class="text-sm text-gray-400 mb-2 block">Tipos de post</label>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="t in postTypeOptions" :key="t.value" type="button"
                                @click="toggleArrayItem(form.post_types, t.value)"
                                :class="form.post_types.includes(t.value) ? 'bg-indigo-600/20 border-indigo-500/50 text-indigo-300' : 'bg-gray-800 border-gray-700 text-gray-500'"
                                class="rounded-lg border px-3 py-1.5 text-xs font-medium transition">
                                {{ t.label }}
                            </button>
                        </div>
                    </div>

                    <div class="border-t border-gray-800"></div>

                    <!-- Modo de aprovação -->
                    <div class="rounded-xl bg-gray-800/50 border border-gray-700 p-4 space-y-3">
                        <p class="text-sm font-medium text-white">Modo de aprovação</p>
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="radio" :value="false" v-model="form.auto_approve" class="mt-0.5 accent-indigo-500" />
                            <div>
                                <p class="text-sm text-gray-200">Gerar sugestões e aguardar aprovação</p>
                                <p class="text-xs text-gray-500">A IA gera conteúdo. Você revisa e aprova antes de publicar.</p>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="radio" :value="true" v-model="form.auto_approve" class="mt-0.5 accent-indigo-500" />
                            <div>
                                <p class="text-sm text-gray-200">Aprovação e agendamento automático</p>
                                <p class="text-xs text-gray-500">Sugestões são aprovadas e agendadas automaticamente nos melhores horários.</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Conteúdo -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6 space-y-5">
                    <h2 class="text-base font-semibold text-white">Conteúdo e Estilo</h2>

                    <!-- Tom de voz -->
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Tom de voz</label>
                        <select v-model="form.tone"
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Padrão da marca</option>
                            <option value="profissional">Profissional</option>
                            <option value="informal">Informal</option>
                            <option value="educativo">Educativo</option>
                            <option value="persuasivo">Persuasivo</option>
                            <option value="divertido">Divertido</option>
                            <option value="inspirador">Inspirador</option>
                        </select>
                    </div>

                    <!-- Estilo da legenda -->
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Tamanho da legenda</label>
                        <select v-model="form.caption_style"
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="short">Curta (1-2 linhas)</option>
                            <option value="medium">Média (3-5 linhas)</option>
                            <option value="long">Longa (6+ linhas)</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <!-- Gerar imagens -->
                        <div class="flex items-center justify-between rounded-xl bg-gray-800/50 p-3">
                            <span class="text-xs text-gray-300">Gerar imagens com IA</span>
                            <button @click="form.generate_images = !form.generate_images" type="button"
                                :class="form.generate_images ? 'bg-indigo-600' : 'bg-gray-700'"
                                class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full transition-colors duration-200">
                                <span :class="form.generate_images ? 'translate-x-4' : 'translate-x-0.5'"
                                    class="inline-block h-4 w-4 mt-0.5 rounded-full bg-white shadow transform transition-transform duration-200" />
                            </button>
                        </div>

                        <!-- Hashtags -->
                        <div class="flex items-center justify-between rounded-xl bg-gray-800/50 p-3">
                            <span class="text-xs text-gray-300">Auto hashtags</span>
                            <button @click="form.auto_hashtags = !form.auto_hashtags" type="button"
                                :class="form.auto_hashtags ? 'bg-indigo-600' : 'bg-gray-700'"
                                class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full transition-colors duration-200">
                                <span :class="form.auto_hashtags ? 'translate-x-4' : 'translate-x-0.5'"
                                    class="inline-block h-4 w-4 mt-0.5 rounded-full bg-white shadow transform transition-transform duration-200" />
                            </button>
                        </div>

                        <!-- Emojis -->
                        <div class="flex items-center justify-between rounded-xl bg-gray-800/50 p-3">
                            <span class="text-xs text-gray-300">Incluir emojis</span>
                            <button @click="form.include_emojis = !form.include_emojis" type="button"
                                :class="form.include_emojis ? 'bg-indigo-600' : 'bg-gray-700'"
                                class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full transition-colors duration-200">
                                <span :class="form.include_emojis ? 'translate-x-4' : 'translate-x-0.5'"
                                    class="inline-block h-4 w-4 mt-0.5 rounded-full bg-white shadow transform transition-transform duration-200" />
                            </button>
                        </div>

                        <!-- CTA -->
                        <div class="flex items-center justify-between rounded-xl bg-gray-800/50 p-3">
                            <span class="text-xs text-gray-300">Incluir CTA</span>
                            <button @click="form.include_cta = !form.include_cta" type="button"
                                :class="form.include_cta ? 'bg-indigo-600' : 'bg-gray-700'"
                                class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full transition-colors duration-200">
                                <span :class="form.include_cta ? 'translate-x-4' : 'translate-x-0.5'"
                                    class="inline-block h-4 w-4 mt-0.5 rounded-full bg-white shadow transform transition-transform duration-200" />
                            </button>
                        </div>
                    </div>

                    <div v-if="form.auto_hashtags">
                        <label class="text-sm text-gray-400 mb-1 block">Quantidade de hashtags: <span class="text-white">{{ form.hashtag_count }}</span></label>
                        <input v-model.number="form.hashtag_count" type="range" min="0" max="30" step="1" class="w-full accent-indigo-500" />
                        <div class="flex justify-between text-[10px] text-gray-600 mt-1"><span>0</span><span>15</span><span>30</span></div>
                    </div>

                    <!-- Instruções -->
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Instruções permanentes para a IA</label>
                        <textarea v-model="form.instructions" rows="3"
                            placeholder="Ex: Sempre mencionar o link da bio. Focar em engajamento. Usar linguagem jovem."
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <p class="text-[10px] text-gray-600 mt-1">Aplicadas em toda geração automática de conteúdo social.</p>
                    </div>

                    <!-- Melhores horários -->
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Horários de publicação</label>
                        <select v-model="form.best_times_source"
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="auto">Automático (IA analisa engajamento)</option>
                            <option value="data">Baseado nos dados do perfil</option>
                            <option value="default">Horários padrão (9h, 12h, 18h)</option>
                        </select>
                    </div>
                </div>

                <!-- Resultado -->
                <div v-if="result" class="px-3 py-2 rounded-xl text-sm"
                    :class="result.type === 'success' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20'">
                    {{ result.message }}
                </div>

                <!-- Botões -->
                <div class="flex gap-3">
                    <button @click="save" :disabled="saving"
                        class="flex-1 rounded-xl bg-indigo-600 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                        {{ saving ? 'Salvando...' : 'Salvar Configurações' }}
                    </button>
                    <button @click="runNow" :disabled="running"
                        class="rounded-xl border border-gray-600 px-5 py-2.5 text-sm text-gray-300 hover:text-white hover:border-gray-500 disabled:opacity-50 transition">
                        {{ running ? 'Disparando...' : '▶ Executar Agora' }}
                    </button>
                </div>

                <!-- Info -->
                <div class="rounded-xl bg-indigo-950/30 border border-indigo-500/20 p-4">
                    <p class="text-xs text-indigo-300 font-medium mb-2">Como funciona o Autopilot Social:</p>
                    <ul class="text-[11px] text-indigo-400/70 space-y-1.5">
                        <li>• <strong>Calendário mensal</strong>: dia 25 de cada mês, a IA gera pautas para o mês seguinte</li>
                        <li>• <strong>Geração diária</strong>: às 6h, gera posts dos próximos 2 dias do calendário</li>
                        <li>• <strong>Sugestões inteligentes</strong>: às 7h, cria sugestões baseadas na marca e tendências</li>
                        <li>• <strong>Publicação</strong>: posts agendados são publicados automaticamente no horário definido</li>
                        <li>• <strong>Re-tentativas</strong>: falhas são re-tentadas até 3x com intervalos crescentes</li>
                    </ul>
                </div>
            </div>

            <!-- TAB: MONITOR -->
            <template v-if="activeTab === 'monitor'">
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
                    <!-- Próximos Agendamentos -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800">
                        <div class="px-5 py-4 border-b border-gray-800">
                            <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                                <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
                                Próximos Agendamentos
                            </h2>
                        </div>
                        <div v-if="upcoming.length" class="divide-y divide-gray-800">
                            <div v-for="item in upcoming" :key="item.id" class="px-5 py-3 flex items-center gap-3">
                                <span class="w-2 h-2 rounded-full shrink-0" :style="{ backgroundColor: item.platform_color }" />
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-gray-300 truncate">{{ item.post_title }}</p>
                                    <p class="text-xs text-gray-500">{{ item.platform_label }}<span v-if="item.account_username"> · @{{ item.account_username }}</span> · {{ item.scheduled_at }}</p>
                                </div>
                                <span :class="['rounded-md border px-2 py-0.5 text-[10px] font-medium', getStatusBadge(item.status).class]">{{ getStatusBadge(item.status).label }}</span>
                            </div>
                        </div>
                        <div v-else class="px-5 py-8 text-center text-sm text-gray-500">Nenhum agendamento pendente</div>
                    </div>

                    <!-- Posts com Falha -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800">
                        <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                                <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10" /><line x1="15" y1="9" x2="9" y2="15" /><line x1="9" y1="9" x2="15" y2="15" /></svg>
                                Publicações com Falha
                            </h2>
                            <button v-if="failed.length" @click="clearFailed" :disabled="clearingFailed"
                                class="rounded-lg px-2.5 py-1 text-[11px] font-medium text-red-400 hover:text-red-300 border border-red-800/50 hover:border-red-700 transition disabled:opacity-50">
                                {{ clearingFailed ? 'Limpando...' : 'Limpar todas' }}
                            </button>
                        </div>
                        <div v-if="failed.length" class="divide-y divide-gray-800">
                            <div v-for="item in failed" :key="item.id" class="px-5 py-3">
                                <div class="flex items-center gap-3 mb-1">
                                    <span class="w-2 h-2 rounded-full shrink-0" :style="{ backgroundColor: item.platform_color }" />
                                    <p class="text-sm text-gray-300 truncate flex-1">{{ item.post_title }}</p>
                                    <span class="text-[10px] text-gray-500">{{ item.attempts }}/{{ item.max_attempts }}</span>
                                </div>
                                <div class="flex items-center justify-between ml-5">
                                    <p class="text-xs text-red-400 truncate max-w-[70%]" :title="item.error_message || ''">{{ item.error_message || 'Erro desconhecido' }}</p>
                                    <button v-if="item.can_retry" @click="retrySchedule(item.id)"
                                        class="rounded-md bg-red-500/10 border border-red-500/30 px-2.5 py-1 text-[11px] font-medium text-red-400 hover:bg-red-500/20 transition shrink-0">
                                        Re-tentar
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-else class="px-5 py-8 text-center text-sm text-gray-500">Nenhuma falha registrada</div>
                    </div>
                </div>

                <!-- Publicados Recentemente -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 mt-6">
                    <div class="px-5 py-4 border-b border-gray-800">
                        <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                            <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" /></svg>
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
                                        <th class="px-5 py-3 font-medium">Link</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-800">
                                    <tr v-for="item in recent" :key="item.id" class="hover:bg-gray-800/50 transition">
                                        <td class="px-5 py-3 text-gray-300 max-w-[200px] truncate">
                                            <Link :href="route('social.posts.edit', item.post_id)" class="hover:text-indigo-400 transition">{{ item.post_title }}</Link>
                                        </td>
                                        <td class="px-5 py-3">
                                            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-medium text-white" :style="{ backgroundColor: item.platform_color }">{{ item.platform_label }}</span>
                                        </td>
                                        <td class="px-5 py-3 text-gray-500">{{ item.account_username ? '@' + item.account_username : '-' }}</td>
                                        <td class="px-5 py-3 text-gray-400">{{ item.published_at }}</td>
                                        <td class="px-5 py-3">
                                            <a v-if="item.platform_post_url" :href="item.platform_post_url" target="_blank" class="text-indigo-400 hover:text-indigo-300 text-xs">Abrir</a>
                                            <span v-else class="text-gray-600 text-xs">-</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div v-else class="px-5 py-8 text-center text-sm text-gray-500">Nenhuma publicação recente</div>
                </div>
            </template>
        </template>
    </AuthenticatedLayout>
</template>
