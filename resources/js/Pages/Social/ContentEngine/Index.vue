<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, nextTick } from 'vue';
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

const props = defineProps<{
    stats: { pending: number; approved_today: number; converted_total: number; rejected_total: number; rules_active: number };
    suggestions: Suggestion[];
    recentApproved: Suggestion[];
    highlightId: number | null;
    highlightedSuggestion: Suggestion | null;
}>();

const page = usePage();
const currentBrand = computed(() => page.props.currentBrand);

const selectedSuggestion = ref<Suggestion | null>(props.highlightedSuggestion || null);
const showDetail = ref(!!props.highlightedSuggestion);
const rejectingId = ref<number | null>(null);
const rejectReason = ref('');
const generating = ref(false);
const selectedIds = ref<number[]>([]);

const platformLabels: Record<string, string> = {
    instagram: 'Instagram', facebook: 'Facebook', linkedin: 'LinkedIn',
    tiktok: 'TikTok', youtube: 'YouTube', pinterest: 'Pinterest',
};

const platformColors: Record<string, string> = {
    instagram: '#E4405F', facebook: '#1877F2', linkedin: '#0A66C2',
    tiktok: '#000000', youtube: '#FF0000', pinterest: '#BD081C',
};

const postTypeIcons: Record<string, string> = {
    feed: 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
    carousel: 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
    story: 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z',
    reel: 'M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    video: 'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z',
};

function openDetail(s: Suggestion) {
    selectedSuggestion.value = s;
    showDetail.value = true;
}

function closeDetail() {
    showDetail.value = false;
    selectedSuggestion.value = null;
}

function approveSuggestion(id: number) {
    router.post(route('social.content-engine.suggestions.approve', id), {}, {
        onSuccess: () => closeDetail(),
    });
}

function startReject(id: number) {
    rejectingId.value = id;
    rejectReason.value = '';
}

function confirmReject() {
    if (rejectingId.value) {
        router.post(route('social.content-engine.suggestions.reject', rejectingId.value), {
            reason: rejectReason.value,
        }, { onSuccess: () => { rejectingId.value = null; closeDetail(); } });
    }
}

function toggleSelect(id: number, e?: Event) {
    e?.stopPropagation();
    const idx = selectedIds.value.indexOf(id);
    if (idx >= 0) selectedIds.value.splice(idx, 1);
    else selectedIds.value.push(id);
}

function bulkApprove() {
    if (selectedIds.value.length === 0) return;
    router.post(route('social.content-engine.suggestions.bulk-approve'), { ids: selectedIds.value });
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

const allSuggestions = computed(() => {
    const list = [...props.suggestions];
    if (props.highlightedSuggestion && !list.find(s => s.id === props.highlightedSuggestion!.id)) {
        list.unshift(props.highlightedSuggestion);
    }
    return list;
});

onMounted(() => {
    if (props.highlightId && props.highlightedSuggestion) {
        nextTick(() => {
            const el = document.getElementById('card-' + props.highlightId);
            el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }
});
</script>

<template>
    <Head title="Social - Sugestões IA" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold text-white">Sugestões IA</h1>
                    <span class="rounded-lg bg-purple-500/20 border border-purple-500/30 px-2 py-0.5 text-xs font-medium text-purple-400">
                        {{ stats.pending }} pendente{{ stats.pending !== 1 ? 's' : '' }}
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <Link :href="route('social.content-engine.rules')" class="rounded-xl px-3 py-2 text-xs text-gray-400 hover:text-white border border-gray-700 transition">Pautas</Link>
                    <Link :href="route('social.posts.index')" class="rounded-xl px-3 py-2 text-xs text-gray-400 hover:text-white border border-gray-700 transition">Posts</Link>
                    <Link :href="route('social.calendar.index')" class="rounded-xl px-3 py-2 text-xs text-gray-400 hover:text-white border border-gray-700 transition">Calendário</Link>
                    <button @click="generateSmart" :disabled="generating || !currentBrand"
                        class="rounded-xl bg-purple-600 px-4 py-2 text-xs font-semibold text-white hover:bg-purple-700 transition disabled:opacity-50 flex items-center gap-1.5">
                        <svg v-if="generating" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
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
            <!-- Stats compactos -->
            <div class="flex items-center gap-6 mb-6 px-1">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-yellow-400"></span>
                    <span class="text-xs text-gray-400"><span class="text-white font-semibold">{{ stats.pending }}</span> pendentes</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-green-400"></span>
                    <span class="text-xs text-gray-400"><span class="text-white font-semibold">{{ stats.converted_total }}</span> convertidos</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-red-400"></span>
                    <span class="text-xs text-gray-400"><span class="text-white font-semibold">{{ stats.rejected_total }}</span> rejeitados</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-purple-400"></span>
                    <span class="text-xs text-gray-400"><span class="text-white font-semibold">{{ stats.rules_active }}</span> pautas</span>
                </div>
            </div>

            <!-- Bulk Actions -->
            <div v-if="selectedIds.length > 0" class="mb-4 flex items-center gap-3 rounded-xl bg-indigo-950/50 border border-indigo-500/30 p-3">
                <span class="text-sm text-indigo-300">{{ selectedIds.length }} selecionada(s)</span>
                <button @click="bulkApprove" class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-700 transition">Aprovar selecionadas</button>
                <button @click="selectedIds = []" class="text-xs text-gray-400 hover:text-white transition">Limpar</button>
            </div>

            <!-- Grade de sugestões -->
            <div v-if="allSuggestions.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <div v-for="s in allSuggestions" :key="s.id" :id="'card-' + s.id"
                    @click="openDetail(s)"
                    :class="[
                        'group relative rounded-2xl border overflow-hidden cursor-pointer transition-all duration-200 hover:scale-[1.02] hover:shadow-lg hover:shadow-purple-500/10',
                        highlightId === s.id ? 'border-indigo-500/50 ring-2 ring-indigo-500/20' : 'border-gray-800 hover:border-gray-700',
                        selectedIds.includes(s.id) ? 'ring-2 ring-green-500/30 border-green-500/40' : '',
                    ]">
                    <!-- Imagem / Placeholder -->
                    <div class="relative aspect-square bg-gray-900 overflow-hidden">
                        <img v-if="s.has_generated_image && s.generated_image_url"
                            :src="s.generated_image_url" :alt="s.title || ''"
                            class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105" />
                        <div v-else class="w-full h-full flex items-center justify-center bg-gradient-to-br from-gray-900 to-gray-800">
                            <svg class="w-12 h-12 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                                <path :d="postTypeIcons[s.post_type] || postTypeIcons.feed" />
                            </svg>
                        </div>

                        <!-- Overlay gradient -->
                        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-200"></div>

                        <!-- Checkbox -->
                        <div class="absolute top-2.5 left-2.5 z-10" @click.stop>
                            <button @click="toggleSelect(s.id, $event)"
                                :class="[
                                    'w-5 h-5 rounded-md border flex items-center justify-center transition-all',
                                    selectedIds.includes(s.id)
                                        ? 'bg-green-500 border-green-400'
                                        : 'bg-black/40 border-white/30 opacity-0 group-hover:opacity-100 backdrop-blur-sm',
                                ]">
                                <svg v-if="selectedIds.includes(s.id)" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><polyline points="20 6 9 17 4 12" /></svg>
                            </button>
                        </div>

                        <!-- Badges top-right -->
                        <div class="absolute top-2.5 right-2.5 flex items-center gap-1.5">
                            <span v-if="s.has_generated_image" class="rounded-md bg-purple-600/90 px-1.5 py-0.5 text-[9px] font-bold text-white backdrop-blur-sm">IA</span>
                            <span v-if="s.is_from_rule" class="rounded-md bg-amber-600/90 px-1.5 py-0.5 text-[9px] font-bold text-white backdrop-blur-sm">PAUTA</span>
                        </div>

                        <!-- Plataformas bottom-left -->
                        <div class="absolute bottom-2.5 left-2.5 flex items-center gap-1">
                            <span v-for="p in s.platforms" :key="p"
                                class="w-5 h-5 rounded-full flex items-center justify-center text-[8px] font-bold text-white backdrop-blur-sm"
                                :style="{ backgroundColor: (platformColors[p] || '#666') + 'CC' }">
                                {{ (platformLabels[p] || p).charAt(0) }}
                            </span>
                        </div>

                        <!-- Ações hover -->
                        <div class="absolute bottom-2.5 right-2.5 flex items-center gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                            <button @click.stop="approveSuggestion(s.id)"
                                class="w-8 h-8 rounded-full bg-green-500/90 flex items-center justify-center hover:bg-green-500 transition backdrop-blur-sm" title="Aprovar">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><polyline points="20 6 9 17 4 12" /></svg>
                            </button>
                            <button @click.stop="startReject(s.id)"
                                class="w-8 h-8 rounded-full bg-red-500/90 flex items-center justify-center hover:bg-red-500 transition backdrop-blur-sm" title="Rejeitar">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Info -->
                    <div class="p-3 bg-gray-900">
                        <p v-if="s.title" class="text-xs font-semibold text-white truncate mb-0.5">{{ s.title }}</p>
                        <p class="text-[11px] text-gray-500 line-clamp-2 leading-relaxed">{{ s.caption_preview }}</p>
                        <div class="flex items-center justify-between mt-2">
                            <span class="text-[10px] text-gray-600">{{ s.created_at }}</span>
                            <span v-if="s.rule_name" class="text-[10px] text-purple-400 truncate max-w-[50%]">{{ s.rule_name }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Vazio -->
            <div v-else class="rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
                <svg class="w-12 h-12 text-gray-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                    <path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                </svg>
                <h3 class="text-sm font-medium text-gray-400">Nenhuma sugestão pendente</h3>
                <p class="text-xs text-gray-600 mt-1">Clique em "Gerar Sugestões" ou configure pautas automáticas.</p>
            </div>

            <!-- Recentes Aprovados -->
            <div v-if="recentApproved.length" class="mt-6">
                <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3 px-1">Aprovados recentemente</h2>
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-2">
                    <div v-for="s in recentApproved" :key="s.id"
                        class="rounded-xl border border-gray-800 overflow-hidden group cursor-pointer hover:border-gray-700 transition"
                        @click="openDetail(s)">
                        <div class="relative aspect-square bg-gray-900">
                            <img v-if="s.has_generated_image && s.generated_image_url"
                                :src="s.generated_image_url" :alt="s.title || ''"
                                class="w-full h-full object-cover opacity-60 group-hover:opacity-80 transition" />
                            <div v-else class="w-full h-full flex items-center justify-center">
                                <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" /></svg>
                            </div>
                            <span class="absolute bottom-1 left-1 rounded bg-green-500/80 px-1 py-0.5 text-[8px] font-bold text-white">{{ s.status_label }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Guia -->
            <GuideBox
                title="Como funciona as Sugestões IA"
                description="O Content Engine gera sugestões de posts baseadas em pautas e inteligência artificial. Revise, aprove ou rejeite."
                :steps="[
                    { title: 'Gere sugestões', description: 'Clique em Gerar Sugestões ou configure pautas automáticas para geração recorrente.' },
                    { title: 'Revise o conteúdo', description: 'Clique em um card para ver legenda, hashtags e imagem gerada. Edite se necessário.' },
                    { title: 'Aprove ou rejeite', description: 'Aprovações criam posts reais. Rejeições com motivo ajudam a IA a melhorar.' },
                ]"
                :tips="[
                    'Use os checkboxes para aprovar várias sugestões de uma vez.',
                    'Sugestões com imagem gerada por IA são marcadas com badge roxo.',
                    'Pautas geram sugestões automaticamente conforme a frequência definida.',
                ]"
                color="purple"
                storage-key="suggestions-guide"
                class="mt-6"
            />
        </template>

        <!-- Modal de Detalhe -->
        <Teleport to="body">
            <div v-if="showDetail && selectedSuggestion" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm" @click.self="closeDetail">
                <div class="bg-gray-900 border border-gray-700 rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto mx-4">
                    <!-- Imagem grande -->
                    <div v-if="selectedSuggestion.has_generated_image && selectedSuggestion.generated_image_url" class="relative">
                        <img :src="selectedSuggestion.generated_image_url" :alt="selectedSuggestion.title || ''"
                            class="w-full max-h-[400px] object-cover" />
                        <span class="absolute top-3 left-3 rounded-lg bg-purple-600/90 px-2 py-0.5 text-[10px] font-bold text-white backdrop-blur-sm">Imagem IA</span>
                        <button @click="closeDetail" class="absolute top-3 right-3 w-8 h-8 rounded-full bg-black/50 flex items-center justify-center text-white hover:bg-black/70 transition backdrop-blur-sm">&times;</button>
                    </div>
                    <div v-else class="flex items-center justify-between px-6 pt-5">
                        <div></div>
                        <button @click="closeDetail" class="text-gray-500 hover:text-white text-xl">&times;</button>
                    </div>

                    <div class="p-6">
                        <!-- Header -->
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-2">
                                    <span v-if="selectedSuggestion.is_from_rule" class="rounded-md bg-purple-500/20 border border-purple-500/30 px-2 py-0.5 text-[10px] font-medium text-purple-400">
                                        {{ selectedSuggestion.rule_name }}
                                    </span>
                                    <span v-else class="rounded-md bg-blue-500/20 border border-blue-500/30 px-2 py-0.5 text-[10px] font-medium text-blue-400">
                                        IA Automática
                                    </span>
                                    <span v-for="p in selectedSuggestion.platforms" :key="p"
                                        class="rounded-md px-2 py-0.5 text-[10px] font-medium text-white"
                                        :style="{ backgroundColor: platformColors[p] || '#666' }">
                                        {{ platformLabels[p] || p }}
                                    </span>
                                </div>
                                <h3 v-if="selectedSuggestion.title" class="text-lg font-semibold text-white">{{ selectedSuggestion.title }}</h3>
                            </div>
                            <span class="text-[10px] text-gray-600 shrink-0 ml-4">{{ selectedSuggestion.created_at }}</span>
                        </div>

                        <!-- Legenda -->
                        <div class="rounded-xl bg-gray-800/50 border border-gray-800 p-4 mb-4">
                            <p class="text-sm text-gray-300 whitespace-pre-line leading-relaxed">{{ selectedSuggestion.caption }}</p>
                        </div>

                        <!-- Hashtags -->
                        <div v-if="selectedSuggestion.hashtags?.length" class="flex flex-wrap gap-1.5 mb-4">
                            <span v-for="tag in selectedSuggestion.hashtags" :key="tag"
                                class="rounded-md bg-indigo-500/10 border border-indigo-500/20 px-2 py-0.5 text-[11px] text-indigo-400">
                                {{ tag }}
                            </span>
                        </div>

                        <!-- Meta -->
                        <div class="flex items-center gap-4 text-[10px] text-gray-600 mb-5">
                            <span v-if="selectedSuggestion.post_type">Tipo: {{ selectedSuggestion.post_type }}</span>
                            <span v-if="selectedSuggestion.ai_model_used">Modelo: {{ selectedSuggestion.ai_model_used }}</span>
                            <span v-if="selectedSuggestion.tokens_used">{{ selectedSuggestion.tokens_used }} tokens</span>
                        </div>

                        <!-- Ações -->
                        <div v-if="selectedSuggestion.status === 'pending'" class="flex items-center gap-3 pt-4 border-t border-gray-800">
                            <button @click="approveSuggestion(selectedSuggestion.id)"
                                class="flex-1 rounded-xl bg-green-600 py-2.5 text-sm font-semibold text-white hover:bg-green-700 transition flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><polyline points="20 6 9 17 4 12" /></svg>
                                Aprovar e criar post
                            </button>
                            <button @click="startReject(selectedSuggestion.id)"
                                class="rounded-xl border border-red-500/30 bg-red-500/10 px-5 py-2.5 text-sm font-medium text-red-400 hover:bg-red-500/20 transition">
                                Rejeitar
                            </button>
                        </div>
                        <div v-else-if="selectedSuggestion.metadata?.converted_to_post_id" class="pt-4 border-t border-gray-800">
                            <Link :href="route('social.posts.edit', selectedSuggestion.metadata.converted_to_post_id)"
                                class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                Ver Post Criado
                            </Link>
                        </div>
                        <div v-else-if="selectedSuggestion.status === 'rejected'" class="pt-4 border-t border-gray-800">
                            <p class="text-xs text-red-400">Rejeitada{{ selectedSuggestion.rejection_reason ? ': ' + selectedSuggestion.rejection_reason : '' }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal Rejeitar -->
        <Teleport to="body">
            <div v-if="rejectingId" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/70" @click.self="rejectingId = null">
                <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 w-full max-w-md mx-4">
                    <h3 class="text-lg font-semibold text-white mb-4">Rejeitar Sugestão</h3>
                    <textarea v-model="rejectReason" rows="3" placeholder="Motivo (opcional)..."
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-red-500 focus:ring-red-500" />
                    <div class="flex justify-end gap-3 mt-4">
                        <button @click="rejectingId = null" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                        <button @click="confirmReject" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 transition">Confirmar</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
