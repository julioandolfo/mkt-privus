<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const engineGuideSteps = [
    { title: 'Configure pautas (temas recorrentes)', description: 'Vá em "Pautas" e crie temas como "Dica semanal", "Bastidores", "Promoção mensal". Defina frequência e plataformas.' },
    { title: 'Geração automática programada', description: 'Pautas ativas geram sugestões automaticamente conforme a frequência definida (diário, semanal, etc.).' },
    { title: 'Gere sugestões inteligentes sob demanda', description: 'Clique em "Gerar Sugestões" para que a IA crie posts variados baseados no contexto e histórico da marca.' },
    { title: 'Revise e aprove', description: 'Sugestões pendentes aparecem aqui. Aprove para converter em posts, rejeite com motivo, ou edite antes de aprovar.' },
    { title: 'Aprovação em lote', description: 'Use os checkboxes para selecionar múltiplas sugestões e aprová-las de uma vez.' },
];

const engineGuideTips = [
    'Sugestões aprovadas são convertidas automaticamente em posts com status "Pendente de revisão".',
    'A IA analisa posts recentes para evitar repetições e sugerir temas complementares.',
    'Cada sugestão mostra a origem: "Pauta" (gerada pela regra configurada) ou "IA Automática" (geração inteligente).',
    'Ao rejeitar, informe o motivo para ajudar a IA a aprender suas preferências futuras.',
];
import { ref, computed } from 'vue';
import axios from 'axios';

interface Suggestion {
    id: number;
    title: string | null;
    caption: string;
    caption_preview: string;
    hashtags: string[];
    platforms: string[];
    post_type: string;
    status: string;
    status_label: string;
    status_color: string;
    ai_model_used: string | null;
    tokens_used: number;
    rule_name: string | null;
    rule_category: string | null;
    is_from_rule: boolean;
    rejection_reason: string | null;
    has_generated_image: boolean;
    generated_image_url: string | null;
    created_at: string;
    metadata: any;
}

interface Props {
    stats: {
        pending: number;
        approved_today: number;
        converted_total: number;
        rejected_total: number;
        rules_active: number;
    };
    suggestions: Suggestion[];
    recentApproved: Suggestion[];
}

const props = defineProps<Props>();
const page = usePage();
const currentBrand = computed(() => page.props.currentBrand);

const expandedId = ref<number | null>(null);
const rejectingId = ref<number | null>(null);
const rejectReason = ref('');
const generating = ref(false);
const selectedIds = ref<number[]>([]);

const platformLabels: Record<string, string> = {
    instagram: 'Instagram',
    facebook: 'Facebook',
    linkedin: 'LinkedIn',
    tiktok: 'TikTok',
    youtube: 'YouTube',
    pinterest: 'Pinterest',
};

function toggleExpand(id: number) {
    expandedId.value = expandedId.value === id ? null : id;
}

function approveSuggestion(id: number) {
    router.post(route('social.content-engine.suggestions.approve', id));
}

function startReject(id: number) {
    rejectingId.value = id;
    rejectReason.value = '';
}

function confirmReject() {
    if (rejectingId.value) {
        router.post(route('social.content-engine.suggestions.reject', rejectingId.value), {
            reason: rejectReason.value,
        });
        rejectingId.value = null;
    }
}

function toggleSelect(id: number) {
    const idx = selectedIds.value.indexOf(id);
    if (idx >= 0) selectedIds.value.splice(idx, 1);
    else selectedIds.value.push(id);
}

function bulkApprove() {
    if (selectedIds.value.length === 0) return;
    router.post(route('social.content-engine.suggestions.bulk-approve'), {
        ids: selectedIds.value,
    });
    selectedIds.value = [];
}

async function generateSmart() {
    generating.value = true;
    try {
        await axios.post(route('social.content-engine.suggestions.generate-smart'));
        router.reload();
    } catch (error) {
        console.error('Erro ao gerar:', error);
    } finally {
        generating.value = false;
    }
}
</script>

<template>
    <Head title="Social - Content Engine" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold text-white">Content Engine</h1>
                    <span class="rounded-lg bg-purple-500/20 border border-purple-500/30 px-2 py-0.5 text-xs font-medium text-purple-400">
                        IA
                    </span>
                </div>
                <div class="flex items-center gap-3">
                    <Link :href="route('social.content-engine.rules')" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        Pautas
                    </Link>
                    <Link :href="route('social.posts.index')" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        Posts
                    </Link>
                    <button
                        @click="generateSmart"
                        :disabled="generating || !currentBrand"
                        class="rounded-xl bg-purple-600 px-4 py-2 text-sm font-semibold text-white hover:bg-purple-700 transition disabled:opacity-50"
                    >
                        {{ generating ? 'Gerando...' : 'Gerar Sugestões' }}
                    </button>
                </div>
            </div>
        </template>

        <div v-if="!currentBrand" class="rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
            <h3 class="text-lg font-medium text-gray-300">Nenhuma marca selecionada</h3>
            <p class="mt-2 text-sm text-gray-500">Selecione uma marca para usar o Content Engine.</p>
        </div>

        <template v-else>
            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 mb-6">
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-yellow-400">{{ stats.pending }}</p>
                    <p class="text-xs text-gray-500 mt-1">Pendentes</p>
                </div>
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-blue-400">{{ stats.approved_today }}</p>
                    <p class="text-xs text-gray-500 mt-1">Aprovados hoje</p>
                </div>
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-green-400">{{ stats.converted_total }}</p>
                    <p class="text-xs text-gray-500 mt-1">Convertidos</p>
                </div>
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-red-400">{{ stats.rejected_total }}</p>
                    <p class="text-xs text-gray-500 mt-1">Rejeitados</p>
                </div>
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-purple-400">{{ stats.rules_active }}</p>
                    <p class="text-xs text-gray-500 mt-1">Pautas ativas</p>
                </div>
            </div>

            <!-- Bulk Actions -->
            <div v-if="selectedIds.length > 0" class="mb-4 flex items-center gap-3 rounded-xl bg-indigo-950/50 border border-indigo-500/30 p-3">
                <span class="text-sm text-indigo-300">{{ selectedIds.length }} selecionada(s)</span>
                <button @click="bulkApprove" class="rounded-lg bg-green-600 px-3 py-1 text-sm font-medium text-white hover:bg-green-700 transition">
                    Aprovar selecionadas
                </button>
                <button @click="selectedIds = []" class="text-sm text-gray-400 hover:text-white transition">Limpar</button>
            </div>

            <!-- Sugestoes Pendentes -->
            <div class="rounded-2xl bg-gray-900 border border-gray-800 mb-6">
                <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                        </svg>
                        Sugestões Pendentes
                    </h2>
                </div>

                <div v-if="suggestions.length" class="divide-y divide-gray-800">
                    <div v-for="s in suggestions" :key="s.id" class="px-5 py-4">
                        <div class="flex items-start gap-3">
                            <input type="checkbox" :checked="selectedIds.includes(s.id)" @change="toggleSelect(s.id)" class="mt-1 rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <span v-if="s.is_from_rule" class="rounded-md bg-purple-500/20 border border-purple-500/30 px-1.5 py-0.5 text-[10px] font-medium text-purple-400">
                                        {{ s.rule_name }}
                                    </span>
                                    <span v-else class="rounded-md bg-blue-500/20 border border-blue-500/30 px-1.5 py-0.5 text-[10px] font-medium text-blue-400">
                                        IA Automática
                                    </span>
                                    <span v-for="p in s.platforms" :key="p" class="rounded-md bg-gray-700 px-1.5 py-0.5 text-[10px] text-gray-400">
                                        {{ platformLabels[p] || p }}
                                    </span>
                                    <span class="text-[10px] text-gray-600 ml-auto">{{ s.created_at }}</span>
                                </div>

                                <p v-if="s.title" class="text-sm font-medium text-gray-200 mb-1">{{ s.title }}</p>

                                <!-- Caption preview / expand -->
                                <div v-if="expandedId !== s.id" class="flex items-start gap-2 cursor-pointer" @click="toggleExpand(s.id)">
                                    <!-- Thumbnail da imagem gerada -->
                                    <img v-if="s.has_generated_image && s.generated_image_url" :src="s.generated_image_url" :alt="s.title || ''" class="w-10 h-10 rounded-lg object-cover border border-gray-700 shrink-0" />
                                    <p class="text-sm text-gray-400">
                                        {{ s.caption_preview }}
                                        <span v-if="s.has_generated_image" class="inline-flex items-center ml-1 text-purple-400 text-[10px]">
                                            <svg class="w-3 h-3 mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                            IA
                                        </span>
                                    </p>
                                </div>
                                <div v-else>
                                    <!-- Imagem gerada pela IA -->
                                    <div v-if="s.has_generated_image && s.generated_image_url" class="mb-3">
                                        <div class="relative inline-block rounded-xl overflow-hidden border border-gray-700">
                                            <img :src="s.generated_image_url" :alt="s.title || 'Imagem gerada'" class="max-h-48 w-auto rounded-xl object-cover" />
                                            <span class="absolute top-2 left-2 rounded-md bg-purple-600/90 px-1.5 py-0.5 text-[10px] font-medium text-white backdrop-blur-sm">
                                                DALL-E 3
                                            </span>
                                        </div>
                                    </div>
                                    <p class="text-sm text-gray-300 whitespace-pre-line mb-2 cursor-pointer" @click="toggleExpand(s.id)">{{ s.caption }}</p>
                                    <div v-if="s.hashtags.length" class="flex flex-wrap gap-1.5 mb-2">
                                        <span v-for="tag in s.hashtags" :key="tag" class="text-[11px] text-indigo-400">{{ tag }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <button @click="approveSuggestion(s.id)" class="rounded-lg bg-green-600/20 border border-green-500/30 px-3 py-1.5 text-xs font-medium text-green-400 hover:bg-green-600/30 transition">
                                    Aprovar
                                </button>
                                <button @click="startReject(s.id)" class="rounded-lg bg-red-600/10 border border-red-500/30 px-3 py-1.5 text-xs font-medium text-red-400 hover:bg-red-600/20 transition">
                                    Rejeitar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-8 text-center text-sm text-gray-500">
                    Nenhuma sugestão pendente. Clique em "Gerar Sugestões" ou configure pautas automáticas.
                </div>
            </div>

            <!-- Modal Rejeitar -->
            <div v-if="rejectingId" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70" @click.self="rejectingId = null">
                <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 w-full max-w-md">
                    <h3 class="text-lg font-semibold text-white mb-4">Rejeitar Sugestão</h3>
                    <textarea v-model="rejectReason" rows="3" placeholder="Motivo (opcional)..." class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-red-500 focus:ring-red-500" />
                    <div class="flex justify-end gap-3 mt-4">
                        <button @click="rejectingId = null" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                        <button @click="confirmReject" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 transition">Confirmar</button>
                    </div>
                </div>
            </div>

            <!-- Recentes Aprovados -->
            <div v-if="recentApproved.length" class="rounded-2xl bg-gray-900 border border-gray-800">
                <div class="px-5 py-4 border-b border-gray-800">
                    <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" />
                        </svg>
                        Recentes Aprovados
                    </h2>
                </div>
                <div class="divide-y divide-gray-800">
                    <div v-for="s in recentApproved" :key="s.id" class="px-5 py-3 flex items-center gap-3">
                        <span class="rounded-md bg-green-500/20 border border-green-500/30 px-1.5 py-0.5 text-[10px] font-medium text-green-400">
                            {{ s.status_label }}
                        </span>
                        <p class="text-sm text-gray-300 truncate flex-1">{{ s.title || s.caption_preview }}</p>
                        <span class="text-[10px] text-gray-600">{{ s.created_at }}</span>
                    </div>
                </div>
            </div>

            <!-- Guia detalhado -->
            <GuideBox
                title="Como funciona o Content Engine"
                description="O Content Engine é o motor de geração automática de conteúdo. Ele cria sugestões de posts baseadas em pautas configuráveis e inteligência artificial."
                :steps="engineGuideSteps"
                :tips="engineGuideTips"
                color="purple"
                storage-key="content-engine-guide"
                class="mt-6"
            />
        </template>
    </AuthenticatedLayout>
</template>
