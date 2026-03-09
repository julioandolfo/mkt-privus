<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';

// Types
interface Brand {
    id: number;
    name: string;
    segment: string | null;
    website: string | null;
}

interface VoiceTone {
    tone: string;
    personality: string;
    communication_style: string;
    formality_level: string;
}

interface Messaging {
    value_proposition: string;
    key_messages: string[];
}

interface TargetAudience {
    demographics: string;
    pain_points: string[];
}

interface BrandDna {
    voice_tone: VoiceTone;
    messaging: Messaging;
    target_audience_analysis: TargetAudience;
    content_strategy_hints?: {
        best_platforms: string[];
        content_pillars: string[];
    };
    _meta?: {
        analyzed_at: string;
    };
}

interface Post {
    angle: string;
    hook: string;
    cta: string;
    visual_description?: string;
}

interface Campaign {
    id: string;
    db_id?: number;
    name: string;
    concept: string;
    objective: string;
    platforms: string[];
    format: string;
    duration?: string;
    kpis?: string[];
    posts: Post[];
    effort_level?: string;
    suggested_times?: Record<string, string[]>;
    _generated_at?: string;
    created_at?: string;
    status?: string;
    status_label?: string;
    status_color?: string;
    rejection_reason?: string | null;
    can_delete?: boolean;
    can_reject?: boolean;
    can_convert?: boolean;
}

interface BrandConfig {
    posts_per_campaign: number;
    campaigns_per_generation: number;
    generate_images: boolean;
    auto_hashtags: boolean;
    caption_style: 'short' | 'medium' | 'long';
    preferred_platforms: string[];
    content_tone_override: string | null;
    include_cta: boolean;
    include_emojis: boolean;
    hashtag_count: number;
    post_types: string[];
    best_times_source: string;
}

interface Props {
    brand: Brand | null;
    brandDna: BrandDna | null;
    hasAnalysis: boolean;
    campaignHistory: Campaign[];
    brandConfig: BrandConfig;
    presetCommands: Record<string, { command: string; description: string }>;
}

const props = defineProps<Props>();
const page = usePage();
const currentBrand = computed(() => page.props.currentBrand);

// Estado
const activeTab = ref<'campaigns' | 'history' | 'editor' | 'dna' | 'config'>('campaigns');
const analyzing = ref(false);
const analysisStatus = ref('');
const generatingCampaigns = ref(false);
const campaigns = ref<Campaign[]>([]);
const selectedCampaign = ref<Campaign | null>(null);
const campaignTheme = ref('');
const userIdea = ref('');
const generatingFromIdea = ref(false);
const loadingHistory = ref(false);
const historyFilter = ref('all');
const showRejectionModal = ref(false);
const rejectionReason = ref('');
const campaignToReject = ref<Campaign | null>(null);

// Editor Natural
const contentToEdit = ref('');
const editCommand = ref('');
const editingResult = ref<{
    edited_content: string;
    changes_made: string[];
    suggestions: string[];
    original_length: number;
    new_length: number;
} | null>(null);
const applyingEdit = ref(false);
const selectedPlatform = ref('instagram');

// Configurações
const configForm = ref<BrandConfig>({ ...props.brandConfig });
const savingConfig = ref(false);

// Plataformas
const platformLabels: Record<string, string> = {
    instagram: 'Instagram',
    facebook: 'Facebook',
    linkedin: 'LinkedIn',
    tiktok: 'TikTok',
    youtube: 'YouTube',
    pinterest: 'Pinterest',
    twitter: 'X/Twitter',
};

// Guia
const guideSteps = [
    { title: 'Análise automática do Brand DNA', description: 'O sistema analisa o site da marca para extrair tom de voz, personalidade, mensagens-chave e estratégia de conteúdo automaticamente.' },
    { title: 'Geração de campanhas com IA', description: 'Gere 5-10 ideias de campanha completas com conceito, objetivo, plataformas e variações de posts. Personalize por tema ou deixe a IA criar.' },
    { title: 'Editor por linguagem natural', description: 'Refine conteúdo com comandos simples em português como "deixe mais descontraído" ou "adicione urgência".' },
    { title: 'Crie posts diretamente', description: 'Converta campanhas aprovadas em posts reais com legendas, hashtags e imagens geradas automaticamente.' },
];

const guideTips = [
    'O Brand DNA é atualizado automaticamente a cada análise e fica salvo no cache por 24 horas.',
    'Campanhas geradas são únicas e baseadas no DNA específico da sua marca.',
    'Use o editor natural para ajustar tom, comprimento, emojis e outros elementos do conteúdo.',
    'Cada campanha inclui 3 variações de posts com ângulos diferentes para teste A/B.',
];

// Métodos
async function analyzeBrand() {
    analyzing.value = true;
    analysisStatus.value = 'Analisando site da marca...';

    try {
        const response = await axios.post(route('social.content-engine.analyze-brand'));
        analysisStatus.value = 'Análise concluída! Atualizando...';

        // Aguarda um momento para mostrar a mensagem de sucesso
        setTimeout(() => {
            router.reload();
        }, 500);
    } catch (error: any) {
        console.error('Erro na análise:', error);
        analysisStatus.value = `Erro: ${error.response?.data?.message || error.message || 'Falha na análise'}`;
    } finally {
        setTimeout(() => {
            analyzing.value = false;
            analysisStatus.value = '';
        }, 3000);
    }
}

async function generateCampaigns() {
    generatingCampaigns.value = true;
    try {
            const response = await axios.post(route('social.content-engine.generate-campaigns'), {
            theme: campaignTheme.value,
            count: 5,
        });
        campaigns.value = response.data.campaigns;
    } catch (error) {
        console.error('Erro ao gerar campanhas:', error);
    } finally {
        generatingCampaigns.value = false;
    }
}

async function generateFromUserIdea() {
    if (!userIdea.value.trim()) return;
    generatingFromIdea.value = true;
    try {
            const response = await axios.post(route('social.content-engine.generate-from-idea'), {
            idea: userIdea.value,
            post_count: 3,
        });
        campaigns.value = [response.data.campaign];
    } catch (error) {
        console.error('Erro ao gerar da ideia:', error);
    } finally {
        generatingFromIdea.value = false;
    }
}

function selectCampaign(campaign: Campaign) {
    selectedCampaign.value = campaign;
}

function closeCampaignDetail() {
    selectedCampaign.value = null;
}

async function createPostsFromCampaign(campaign: Campaign, selectedPosts?: number[]) {
    try {
            const response = await axios.post(route('social.content-engine.create-posts'), {
            campaign: campaign,
            selected_posts: selectedPosts,
            generate_images: true,
        });
        if (response.data.success) {
            alert(`${response.data.count} posts criados com sucesso!`);
            router.visit(route('social.posts.index'));
        }
    } catch (error) {
        console.error('Erro ao criar posts:', error);
    }
}

async function applyNaturalEdit() {
    if (!contentToEdit.value || !editCommand.value) return;
    applyingEdit.value = true;
    try {
            const response = await axios.post(route('social.content-engine.natural-edit'), {
            content: contentToEdit.value,
            command: editCommand.value,
            platform: selectedPlatform.value,
        });
        editingResult.value = response.data.result;
    } catch (error) {
        console.error('Erro na edição:', error);
    } finally {
        applyingEdit.value = false;
    }
}

async function applyPreset(presetKey: string) {
    if (!contentToEdit.value) return;
    applyingEdit.value = true;
    try {
            const response = await axios.post(route('social.content-engine.apply-preset'), {
            content: contentToEdit.value,
            preset_key: presetKey,
        });
        editingResult.value = response.data.result;
        contentToEdit.value = response.data.result.edited_content;
    } catch (error) {
        console.error('Erro no preset:', error);
    } finally {
        applyingEdit.value = false;
    }
}

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function getObjectiveColor(objective: string): string {
    const colors: Record<string, string> = {
        awareness: 'bg-blue-500/20 text-blue-400 border-blue-500/30',
        engajamento: 'bg-green-500/20 text-green-400 border-green-500/30',
        conversão: 'bg-purple-500/20 text-purple-400 border-purple-500/30',
        fidelização: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    };
    return colors[objective] || 'bg-gray-500/20 text-gray-400 border-gray-500/30';
}

function getObjectiveLabel(objective: string): string {
    const labels: Record<string, string> = {
        awareness: 'Awareness',
        engajamento: 'Engajamento',
        conversão: 'Conversão',
        fidelização: 'Fidelização',
    };
    return labels[objective] || objective;
}

function getEffortColor(level?: string): string {
    const colors: Record<string, string> = {
        low: 'text-green-400',
        medium: 'text-yellow-400',
        high: 'text-red-400',
    };
    return colors[level || 'low'] || 'text-gray-400';
}

function getEffortLabel(level?: string): string {
    const labels: Record<string, string> = {
        low: 'Baixo',
        medium: 'Médio',
        high: 'Alto',
    };
    return labels[level || 'low'] || level || 'Baixo';
}

function getStatusColor(status?: string): string {
    const colors: Record<string, string> = {
        active: 'bg-green-500/20 text-green-400 border-green-500/30',
        rejected: 'bg-red-500/20 text-red-400 border-red-500/30',
        converted: 'bg-blue-500/20 text-blue-400 border-blue-500/30',
        deleted: 'bg-gray-500/20 text-gray-400 border-gray-500/30',
    };
    return colors[status || 'active'] || 'bg-gray-500/20 text-gray-400 border-gray-500/30';
}

function getStatusLabel(status?: string): string {
    const labels: Record<string, string> = {
        active: 'Ativa',
        rejected: 'Rejeitada',
        converted: 'Convertida',
        deleted: 'Excluída',
    };
    return labels[status || 'active'] || status || 'Desconhecido';
}

// Histórico de Campanhas
async function loadCampaignHistory() {
    loadingHistory.value = true;
    try {
        const response = await axios.get(route('social.content-engine.campaigns-history'), {
            params: { status: historyFilter.value, limit: 20 }
        });
        if (response.data.success) {
            // Atualiza o histórico (o controller já passa na prop, mas podemos recarregar)
            router.reload({ only: ['campaignHistory'] });
        }
    } catch (error) {
        console.error('Erro ao carregar histórico:', error);
    } finally {
        loadingHistory.value = false;
    }
}

async function deleteCampaign(campaign: Campaign) {
    if (!confirm(`Tem certeza que deseja excluir a campanha "${campaign.name}"?`)) {
        return;
    }

    if (!campaign.db_id) {
        // Campanha ainda não salva no banco (está apenas no cache)
        campaigns.value = campaigns.value.filter(c => c.id !== campaign.id);
        return;
    }

    try {
        await axios.delete(route('social.content-engine.campaigns.destroy', campaign.db_id));
        router.reload({ only: ['campaignHistory'] });
    } catch (error) {
        console.error('Erro ao excluir campanha:', error);
        alert('Erro ao excluir campanha');
    }
}

function startRejectCampaign(campaign: Campaign) {
    campaignToReject.value = campaign;
    rejectionReason.value = '';
    showRejectionModal.value = true;
}

async function confirmRejectCampaign() {
    if (!campaignToReject.value?.db_id) return;

    try {
        await axios.post(route('social.content-engine.campaigns.reject', campaignToReject.value.db_id), {
            reason: rejectionReason.value
        });
        showRejectionModal.value = false;
        campaignToReject.value = null;
        router.reload({ only: ['campaignHistory'] });
    } catch (error) {
        console.error('Erro ao rejeitar campanha:', error);
        alert('Erro ao rejeitar campanha');
    }
}

async function restoreCampaign(campaign: Campaign) {
    if (!campaign.db_id) return;

    try {
        await axios.post(route('social.content-engine.campaigns.restore', campaign.db_id));
        router.reload({ only: ['campaignHistory'] });
    } catch (error) {
        console.error('Erro ao restaurar campanha:', error);
        alert('Erro ao restaurar campanha');
    }
}

// Configurações
async function saveBrandConfig() {
    savingConfig.value = true;
    try {
        await axios.put(route('social.content-engine.brand-config.update'), configForm.value);
        router.reload({ only: ['brandConfig'] });
        alert('Configurações salvas com sucesso!');
    } catch (error) {
        console.error('Erro ao salvar configurações:', error);
        alert('Erro ao salvar configurações');
    } finally {
        savingConfig.value = false;
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
                    <span class="text-xs text-gray-500">Powered by AI</span>
                </div>
                <div class="flex items-center gap-3">
                    <Link :href="route('social.content-engine.rules')" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        Pautas
                    </Link>
                    <Link :href="route('social.posts.index')" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        Posts
                    </Link>
                </div>
            </div>
        </template>

        <div v-if="!currentBrand" class="rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
            <h3 class="text-lg font-medium text-gray-300">Nenhuma marca selecionada</h3>
            <p class="mt-2 text-sm text-gray-500">Selecione uma marca para usar o Content Engine.</p>
        </div>

        <template v-else>
            <!-- Brand DNA Card -->
            <div v-if="hasAnalysis && brandDna" class="rounded-2xl bg-gradient-to-br from-gray-900 to-gray-800 border border-gray-700 p-6 mb-6">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-lg font-semibold text-white">Brand DNA - {{ brand?.name }}</h2>
                                <p class="text-xs text-gray-400">Análise de marca via IA</p>
                            </div>
                        </div>
                    </div>
                    <button @click="analyzeBrand" :disabled="analyzing" class="rounded-lg bg-gray-700 hover:bg-gray-600 px-3 py-1.5 text-xs font-medium text-gray-300 transition disabled:opacity-50">
                        {{ analyzing ? 'Analisando...' : 'Reanalisar' }}
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
                    <div class="p-4 bg-gray-800/50 rounded-xl border border-gray-700/50">
                        <h4 class="text-xs font-medium text-gray-500 uppercase mb-2">Tom de Voz</h4>
                        <p class="text-sm text-white font-medium">{{ brandDna.voice_tone?.tone }}</p>
                        <p class="text-xs text-gray-400 mt-1">{{ brandDna.voice_tone?.formality_level }}</p>
                    </div>
                    <div class="p-4 bg-gray-800/50 rounded-xl border border-gray-700/50">
                        <h4 class="text-xs font-medium text-gray-500 uppercase mb-2">Personalidade</h4>
                        <p class="text-sm text-white font-medium">{{ brandDna.voice_tone?.personality }}</p>
                        <p class="text-xs text-gray-400 mt-1">{{ brandDna.voice_tone?.communication_style }}</p>
                    </div>
                    <div class="p-4 bg-gray-800/50 rounded-xl border border-gray-700/50">
                        <h4 class="text-xs font-medium text-gray-500 uppercase mb-2">Proposta de Valor</h4>
                        <p class="text-sm text-white font-medium line-clamp-2">{{ brandDna.messaging?.value_proposition }}</p>
                    </div>
                    <div class="p-4 bg-gray-800/50 rounded-xl border border-gray-700/50">
                        <h4 class="text-xs font-medium text-gray-500 uppercase mb-2">Público-Alvo</h4>
                        <p class="text-sm text-white font-medium line-clamp-2">{{ brandDna.target_audience_analysis?.demographics }}</p>
                    </div>
                </div>

                <!-- Keywords -->
                <div v-if="brandDna.messaging?.key_messages?.length" class="mt-4">
                    <h4 class="text-xs font-medium text-gray-500 uppercase mb-2">Mensagens-Chave</h4>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="msg in brandDna.messaging.key_messages.slice(0, 5)" :key="msg" class="rounded-lg bg-purple-500/10 border border-purple-500/20 px-2 py-1 text-xs text-purple-300">
                            {{ msg }}
                        </span>
                    </div>
                </div>

                <p v-if="brandDna._meta?.analyzed_at" class="text-xs text-gray-500 mt-4">
                    Última análise: {{ formatDate(brandDna._meta.analyzed_at) }}
                </p>
            </div>

            <!-- Sem Brand DNA -->
            <div v-else class="rounded-2xl bg-gray-900 border border-gray-800 p-8 text-center mb-6">
                <div class="w-16 h-16 rounded-2xl bg-gray-800 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-gray-300 mb-2">Brand DNA não analisado</h3>
                <p class="text-sm text-gray-500 mb-4 max-w-md mx-auto">
                    Analise o site da marca para extrair automaticamente tom de voz, personalidade, mensagens-chave e estratégia de conteúdo.
                </p>
                <button @click="analyzeBrand" :disabled="analyzing" class="rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 px-6 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition disabled:opacity-50">
                    {{ analyzing ? 'Analisando...' : 'Analisar Minha Marca' }}
                </button>

                <!-- Status da análise -->
                <div v-if="analyzing || analysisStatus" class="mt-4">
                    <div class="flex items-center justify-center gap-2 text-sm text-purple-400">
                        <svg v-if="analyzing" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>{{ analysisStatus || 'Processando...' }}</span>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex items-center gap-2 mb-6 border-b border-gray-800">
                <button
                    @click="activeTab = 'campaigns'"
                    :class="['px-4 py-3 text-sm font-medium border-b-2 transition', activeTab === 'campaigns' ? 'border-purple-500 text-purple-400' : 'border-transparent text-gray-400 hover:text-gray-300']"
                >
                    Gerador
                </button>
                <button
                    @click="activeTab = 'history'"
                    :class="['px-4 py-3 text-sm font-medium border-b-2 transition', activeTab === 'history' ? 'border-purple-500 text-purple-400' : 'border-transparent text-gray-400 hover:text-gray-300']"
                >
                    Histórico
                </button>
                <button
                    @click="activeTab = 'editor'"
                    :class="['px-4 py-3 text-sm font-medium border-b-2 transition', activeTab === 'editor' ? 'border-purple-500 text-purple-400' : 'border-transparent text-gray-400 hover:text-gray-300']"
                >
                    Editor
                </button>
                <button
                    @click="activeTab = 'dna'"
                    :class="['px-4 py-3 text-sm font-medium border-b-2 transition', activeTab === 'dna' ? 'border-purple-500 text-purple-400' : 'border-transparent text-gray-400 hover:text-gray-300']"
                >
                    Brand DNA
                </button>
                <button
                    @click="activeTab = 'config'"
                    :class="['px-4 py-3 text-sm font-medium border-b-2 transition', activeTab === 'config' ? 'border-purple-500 text-purple-400' : 'border-transparent text-gray-400 hover:text-gray-300']"
                >
                    Configurações
                </button>
            </div>

            <!-- Tab: Gerador de Campanhas -->
            <div v-if="activeTab === 'campaigns'" class="space-y-6">
                <!-- Input para gerar -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h3 class="text-lg font-medium text-white mb-4">Gerar Ideias de Campanha</h3>
                    
                    <!-- Tema opcional -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Tema (opcional)</label>
                        <input
                            v-model="campaignTheme"
                            type="text"
                            placeholder="Ex: Black Friday, Lançamento de Produto, Dia das Mães..."
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-purple-500 focus:ring-purple-500"
                        />
                    </div>

                    <!-- Ou ideia do usuário -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Ou descreva sua ideia</label>
                        <textarea
                            v-model="userIdea"
                            rows="3"
                            placeholder="Tenho uma ideia de campanha sobre..."
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-purple-500 focus:ring-purple-500"
                        ></textarea>
                    </div>

                    <div class="flex gap-3">
                        <button
                            @click="generateCampaigns"
                            :disabled="generatingCampaigns"
                            class="rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 px-6 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition disabled:opacity-50"
                        >
                            {{ generatingCampaigns ? 'Gerando...' : 'Gerar Campanhas' }}
                        </button>
                        <button
                            v-if="userIdea"
                            @click="generateFromUserIdea"
                            :disabled="generatingFromIdea"
                            class="rounded-xl bg-gray-700 px-6 py-2.5 text-sm font-medium text-white hover:bg-gray-600 transition disabled:opacity-50"
                        >
                            {{ generatingFromIdea ? 'Gerando...' : 'Usar Minha Ideia' }}
                        </button>
                    </div>
                </div>

                <!-- Campanhas Geradas -->
                <div v-if="campaigns.length" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div
                        v-for="campaign in campaigns"
                        :key="campaign.id"
                        @click="selectCampaign(campaign)"
                        class="rounded-2xl bg-gray-900 border border-gray-800 p-5 cursor-pointer hover:border-purple-500/50 transition group"
                    >
                        <div class="flex items-start justify-between mb-3">
                            <h4 class="text-lg font-semibold text-white group-hover:text-purple-400 transition">{{ campaign.name }}</h4>
                            <span :class="['rounded-lg border px-2 py-0.5 text-xs font-medium', getObjectiveColor(campaign.objective)]">
                                {{ getObjectiveLabel(campaign.objective) }}
                            </span>
                        </div>
                        
                        <p class="text-sm text-gray-400 mb-4 line-clamp-2">{{ campaign.concept }}</p>
                        
                        <div class="flex flex-wrap gap-2 mb-3">
                            <span v-for="platform in campaign.platforms" :key="platform" class="rounded-md bg-gray-800 px-2 py-1 text-xs text-gray-400">
                                {{ platformLabels[platform] || platform }}
                            </span>
                            <span class="rounded-md bg-gray-800 px-2 py-1 text-xs text-gray-400">
                                {{ campaign.format }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span :class="['text-xs', getEffortColor(campaign.effort_level)]">
                                Esforço: {{ getEffortLabel(campaign.effort_level) }}
                            </span>
                            <span class="text-xs text-purple-400 font-medium">
                                {{ campaign.posts.length }} posts
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Campanhas Recentes -->
                <div v-if="recentCampaigns.length && !campaigns.length" class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h3 class="text-lg font-medium text-white mb-4">Campanhas Recentes</h3>
                    <div class="space-y-3">
                        <div v-for="(group, idx) in recentCampaigns" :key="idx" class="flex items-center justify-between p-3 bg-gray-800/50 rounded-xl">
                            <div>
                                <p class="text-sm font-medium text-white">{{ group.generated_at ? formatDate(group.generated_at) : 'Campanha anterior' }}</p>
                                <p class="text-xs text-gray-500">{{ group.campaigns?.length || 0 }} campanhas geradas</p>
                            </div>
                            <button class="text-xs text-purple-400 hover:text-purple-300">Ver detalhes</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Histórico de Campanhas -->
            <div v-if="activeTab === 'history'" class="space-y-6">
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-medium text-white">Histórico de Campanhas</h3>
                        <select
                            v-model="historyFilter"
                            @change="loadCampaignHistory"
                            class="rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-purple-500 focus:ring-purple-500"
                        >
                            <option value="all">Todas</option>
                            <option value="active">Ativas</option>
                            <option value="rejected">Rejeitadas</option>
                            <option value="converted">Convertidas</option>
                        </select>
                    </div>

                    <div v-if="loadingHistory" class="text-center py-8">
                        <svg class="animate-spin h-8 w-8 text-purple-500 mx-auto" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <p class="text-sm text-gray-400 mt-2">Carregando...</p>
                    </div>

                    <div v-else-if="campaignHistory.length === 0" class="text-center py-8 text-gray-500">
                        <p>Nenhuma campanha no histórico</p>
                        <p class="text-sm mt-1">Gere campanhas na aba "Gerador"</p>
                    </div>

                    <div v-else class="space-y-4">
                        <div
                            v-for="campaign in campaignHistory"
                            :key="campaign.db_id"
                            class="p-4 bg-gray-800/50 rounded-xl border border-gray-700/50 hover:border-gray-600 transition"
                        >
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-2">
                                        <h4 class="text-sm font-semibold text-white">{{ campaign.name }}</h4>
                                        <span :class="['rounded-md border px-2 py-0.5 text-xs font-medium', getStatusColor(campaign.status)]">
                                            {{ getStatusLabel(campaign.status) }}
                                        </span>
                                        <span :class="['rounded-md border px-2 py-0.5 text-xs font-medium', getObjectiveColor(campaign.objective)]">
                                            {{ getObjectiveLabel(campaign.objective) }}
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-400 line-clamp-2 mb-2">{{ campaign.concept }}</p>
                                    <div class="flex items-center gap-4 text-xs text-gray-500">
                                        <span>{{ campaign.created_at }}</span>
                                        <span>{{ campaign.posts?.length || 0 }} posts</span>
                                        <span v-if="campaign.rejection_reason" class="text-red-400">Motivo: {{ campaign.rejection_reason }}</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 ml-4">
                                    <button
                                        v-if="campaign.can_convert"
                                        @click="selectCampaign(campaign)"
                                        class="rounded-lg bg-purple-600/20 border border-purple-500/30 px-3 py-1.5 text-xs font-medium text-purple-400 hover:bg-purple-600/30 transition"
                                    >
                                        Ver / Criar Posts
                                    </button>
                                    <button
                                        v-if="campaign.can_reject"
                                        @click="startRejectCampaign(campaign)"
                                        class="rounded-lg bg-red-600/20 border border-red-500/30 px-3 py-1.5 text-xs font-medium text-red-400 hover:bg-red-600/30 transition"
                                    >
                                        Rejeitar
                                    </button>
                                    <button
                                        v-if="campaign.can_delete"
                                        @click="deleteCampaign(campaign)"
                                        class="rounded-lg bg-gray-700 border border-gray-600 px-3 py-1.5 text-xs font-medium text-gray-400 hover:bg-gray-600 transition"
                                    >
                                        Excluir
                                    </button>
                                    <button
                                        v-if="campaign.status === 'deleted'"
                                        @click="restoreCampaign(campaign)"
                                        class="rounded-lg bg-green-600/20 border border-green-500/30 px-3 py-1.5 text-xs font-medium text-green-400 hover:bg-green-600/30 transition"
                                    >
                                        Restaurar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Editor Natural -->
            <div v-if="activeTab === 'editor'" class="space-y-6">
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h3 class="text-lg font-medium text-white mb-4">Editor por Linguagem Natural</h3>
                    <p class="text-sm text-gray-400 mb-4">Cole seu conteúdo e use comandos em português para refinar. Ex: "deixe mais descontraído", "adicione emojis", "enfatize o CTA"</p>

                    <!-- Preset Commands -->
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-2">Comandos rápidos</label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="(preset, key) in presetCommands"
                                :key="key"
                                @click="applyPreset(key)"
                                class="rounded-lg bg-gray-800 hover:bg-gray-700 border border-gray-700 px-3 py-1.5 text-xs text-gray-300 transition"
                            >
                                {{ preset.description }}
                            </button>
                        </div>
                    </div>

                    <!-- Content Input -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Conteúdo</label>
                        <textarea
                            v-model="contentToEdit"
                            rows="8"
                            placeholder="Cole aqui a legenda do seu post..."
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-purple-500 focus:ring-purple-500"
                        ></textarea>
                    </div>

                    <!-- Platform -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Plataforma</label>
                        <select
                            v-model="selectedPlatform"
                            class="rounded-xl bg-gray-800 border-gray-700 text-white focus:border-purple-500 focus:ring-purple-500"
                        >
                            <option v-for="(label, platform) in platformLabels" :key="platform" :value="platform">{{ label }}</option>
                        </select>
                    </div>

                    <!-- Command Input -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Comando</label>
                        <div class="flex gap-3">
                            <input
                                v-model="editCommand"
                                type="text"
                                placeholder="Ex: deixe mais descontraído, adicione emojis..."
                                class="flex-1 rounded-xl bg-gray-800 border-gray-700 text-white focus:border-purple-500 focus:ring-purple-500"
                                @keyup.enter="applyNaturalEdit"
                            />
                            <button
                                @click="applyNaturalEdit"
                                :disabled="applyingEdit || !contentToEdit || !editCommand"
                                class="rounded-xl bg-purple-600 px-6 py-2 text-sm font-semibold text-white hover:bg-purple-700 transition disabled:opacity-50"
                            >
                                {{ applyingEdit ? 'Aplicando...' : 'Aplicar' }}
                            </button>
                        </div>
                    </div>

                    <!-- Result -->
                    <div v-if="editingResult" class="mt-6 p-5 bg-gradient-to-br from-green-900/30 to-emerald-900/30 border border-green-500/30 rounded-2xl">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-sm font-semibold text-green-400">Resultado</h4>
                            <span class="text-xs text-gray-500">{{ editingResult.new_length }} caracteres (era {{ editingResult.original_length }})</span>
                        </div>
                        <p class="text-sm text-white whitespace-pre-line mb-4">{{ editingResult.edited_content }}</p>
                        
                        <div v-if="editingResult.changes_made?.length" class="mb-3">
                            <h5 class="text-xs font-medium text-gray-500 uppercase mb-2">Alterações feitas</h5>
                            <ul class="space-y-1">
                                <li v-for="change in editingResult.changes_made" :key="change" class="text-xs text-gray-400 flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                    {{ change }}
                                </li>
                            </ul>
                        </div>

                        <div class="flex gap-3 mt-4">
                            <button @click="contentToEdit = editingResult.edited_content; editingResult = null" class="rounded-lg bg-green-600/20 border border-green-500/30 px-3 py-1.5 text-xs font-medium text-green-400 hover:bg-green-600/30 transition">
                                Usar este texto
                            </button>
                            <button @click="editingResult = null" class="text-xs text-gray-500 hover:text-gray-400">Descartar</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Brand DNA -->
            <div v-if="activeTab === 'dna'" class="space-y-6">
                <div v-if="brandDna" class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h3 class="text-lg font-medium text-white mb-6">Análise Completa do Brand DNA</h3>

                    <!-- Voice & Tone -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-purple-400 mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" /></svg>
                            Voz e Tom
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-4 bg-gray-800/50 rounded-xl">
                                <p class="text-xs text-gray-500 mb-1">Tom</p>
                                <p class="text-sm text-white">{{ brandDna.voice_tone?.tone }}</p>
                            </div>
                            <div class="p-4 bg-gray-800/50 rounded-xl">
                                <p class="text-xs text-gray-500 mb-1">Personalidade</p>
                                <p class="text-sm text-white">{{ brandDna.voice_tone?.personality }}</p>
                            </div>
                            <div class="p-4 bg-gray-800/50 rounded-xl">
                                <p class="text-xs text-gray-500 mb-1">Estilo de Comunicação</p>
                                <p class="text-sm text-white">{{ brandDna.voice_tone?.communication_style }}</p>
                            </div>
                            <div class="p-4 bg-gray-800/50 rounded-xl">
                                <p class="text-xs text-gray-500 mb-1">Formalidade</p>
                                <p class="text-sm text-white">{{ brandDna.voice_tone?.formality_level }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Messaging -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-purple-400 mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" /></svg>
                            Mensagens
                        </h4>
                        <div class="p-4 bg-gray-800/50 rounded-xl mb-3">
                            <p class="text-xs text-gray-500 mb-1">Proposta de Valor</p>
                            <p class="text-sm text-white">{{ brandDna.messaging?.value_proposition }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span v-for="msg in brandDna.messaging?.key_messages" :key="msg" class="rounded-lg bg-purple-500/10 border border-purple-500/20 px-3 py-1.5 text-sm text-purple-300">
                                {{ msg }}
                            </span>
                        </div>
                    </div>

                    <!-- Target Audience -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-purple-400 mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                            Público-Alvo
                        </h4>
                        <div class="p-4 bg-gray-800/50 rounded-xl mb-3">
                            <p class="text-xs text-gray-500 mb-1">Demografia</p>
                            <p class="text-sm text-white">{{ brandDna.target_audience_analysis?.demographics }}</p>
                        </div>
                        <div v-if="brandDna.target_audience_analysis?.pain_points?.length" class="space-y-2">
                            <p class="text-xs text-gray-500">Dores do Público</p>
                            <ul class="space-y-1">
                                <li v-for="pain in brandDna.target_audience_analysis.pain_points" :key="pain" class="text-sm text-gray-300 flex items-center gap-2">
                                    <span class="w-1 h-1 rounded-full bg-red-400"></span>
                                    {{ pain }}
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Content Strategy -->
                    <div v-if="brandDna.content_strategy_hints">
                        <h4 class="text-sm font-semibold text-purple-400 mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
                            Estratégia de Conteúdo
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-4 bg-gray-800/50 rounded-xl">
                                <p class="text-xs text-gray-500 mb-2">Melhores Plataformas</p>
                                <div class="flex flex-wrap gap-2">
                                    <span v-for="p in brandDna.content_strategy_hints.best_platforms" :key="p" class="text-sm text-gray-300">{{ platformLabels[p] || p }}</span>
                                </div>
                            </div>
                            <div class="p-4 bg-gray-800/50 rounded-xl">
                                <p class="text-xs text-gray-500 mb-2">Pilares de Conteúdo</p>
                                <div class="flex flex-wrap gap-2">
                                    <span v-for="pillar in brandDna.content_strategy_hints.content_pillars" :key="pillar" class="rounded bg-purple-500/10 px-2 py-1 text-xs text-purple-300">{{ pillar }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Configurações -->
            <div v-if="activeTab === 'config'" class="space-y-6">
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-lg font-medium text-white">Configurações do Content Engine</h3>
                            <p class="text-sm text-gray-400 mt-1">Personalize como as campanhas são geradas para esta marca</p>
                        </div>
                        <button
                            @click="saveBrandConfig"
                            :disabled="savingConfig"
                            class="rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 px-6 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition disabled:opacity-50"
                        >
                            {{ savingConfig ? 'Salvando...' : 'Salvar Configurações' }}
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Quantidade de Posts -->
                        <div class="p-4 bg-gray-800/50 rounded-xl border border-gray-700/50">
                            <label class="block text-sm font-medium text-white mb-2">Posts por Campanha</label>
                            <input
                                v-model.number="configForm.posts_per_campaign"
                                type="number"
                                min="1"
                                max="10"
                                class="w-full rounded-lg bg-gray-700 border-gray-600 text-white"
                            />
                            <p class="text-xs text-gray-500 mt-1">Quantidade de posts gerados em cada campanha (1-10)</p>
                        </div>

                        <!-- Campanhas por Geração -->
                        <div class="p-4 bg-gray-800/50 rounded-xl border border-gray-700/50">
                            <label class="block text-sm font-medium text-white mb-2">Campanhas por Geração</label>
                            <input
                                v-model.number="configForm.campaigns_per_generation"
                                type="number"
                                min="1"
                                max="10"
                                class="w-full rounded-lg bg-gray-700 border-gray-600 text-white"
                            />
                            <p class="text-xs text-gray-500 mt-1">Quantidade de campanhas geradas de uma vez (1-10)</p>
                        </div>

                        <!-- Estilo da Legenda -->
                        <div class="p-4 bg-gray-800/50 rounded-xl border border-gray-700/50">
                            <label class="block text-sm font-medium text-white mb-2">Estilo da Legenda</label>
                            <select
                                v-model="configForm.caption_style"
                                class="w-full rounded-lg bg-gray-700 border-gray-600 text-white"
                            >
                                <option value="short">Curta (até 100 caracteres)</option>
                                <option value="medium">Média (100-300 caracteres)</option>
                                <option value="long">Longa (300+ caracteres)</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Tamanho preferido das legendas</p>
                        </div>

                        <!-- Quantidade de Hashtags -->
                        <div class="p-4 bg-gray-800/50 rounded-xl border border-gray-700/50">
                            <label class="block text-sm font-medium text-white mb-2">Quantidade de Hashtags</label>
                            <input
                                v-model.number="configForm.hashtag_count"
                                type="number"
                                min="3"
                                max="20"
                                class="w-full rounded-lg bg-gray-700 border-gray-600 text-white"
                            />
                            <p class="text-xs text-gray-500 mt-1">Número de hashtags geradas (3-20)</p>
                        </div>

                        <!-- Plataformas Preferidas -->
                        <div class="p-4 bg-gray-800/50 rounded-xl border border-gray-700/50">
                            <label class="block text-sm font-medium text-white mb-2">Plataformas Preferidas</label>
                            <div class="flex flex-wrap gap-2">
                                <label v-for="(label, platform) in platformLabels" :key="platform" class="flex items-center gap-2 bg-gray-700 rounded-lg px-3 py-2 cursor-pointer hover:bg-gray-600">
                                    <input
                                        v-model="configForm.preferred_platforms"
                                        :value="platform"
                                        type="checkbox"
                                        class="rounded border-gray-600 bg-gray-600 text-purple-600 focus:ring-purple-500"
                                    />
                                    <span class="text-sm text-gray-300">{{ label }}</span>
                                </label>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Selecione nenhuma para usar todas</p>
                        </div>

                        <!-- Tipos de Post -->
                        <div class="p-4 bg-gray-800/50 rounded-xl border border-gray-700/50">
                            <label class="block text-sm font-medium text-white mb-2">Tipos de Post Preferidos</label>
                            <div class="flex flex-wrap gap-2">
                                <label class="flex items-center gap-2 bg-gray-700 rounded-lg px-3 py-2 cursor-pointer hover:bg-gray-600">
                                    <input
                                        v-model="configForm.post_types"
                                        value="feed"
                                        type="checkbox"
                                        class="rounded border-gray-600 bg-gray-600 text-purple-600 focus:ring-purple-500"
                                    />
                                    <span class="text-sm text-gray-300">Feed</span>
                                </label>
                                <label class="flex items-center gap-2 bg-gray-700 rounded-lg px-3 py-2 cursor-pointer hover:bg-gray-600">
                                    <input
                                        v-model="configForm.post_types"
                                        value="carousel"
                                        type="checkbox"
                                        class="rounded border-gray-600 bg-gray-600 text-purple-600 focus:ring-purple-500"
                                    />
                                    <span class="text-sm text-gray-300">Carousel</span>
                                </label>
                                <label class="flex items-center gap-2 bg-gray-700 rounded-lg px-3 py-2 cursor-pointer hover:bg-gray-600">
                                    <input
                                        v-model="configForm.post_types"
                                        value="story"
                                        type="checkbox"
                                        class="rounded border-gray-600 bg-gray-600 text-purple-600 focus:ring-purple-500"
                                    />
                                    <span class="text-sm text-gray-300">Story</span>
                                </label>
                                <label class="flex items-center gap-2 bg-gray-700 rounded-lg px-3 py-2 cursor-pointer hover:bg-gray-600">
                                    <input
                                        v-model="configForm.post_types"
                                        value="reel"
                                        type="checkbox"
                                        class="rounded border-gray-600 bg-gray-600 text-purple-600 focus:ring-purple-500"
                                    />
                                    <span class="text-sm text-gray-300">Reel</span>
                                </label>
                                <label class="flex items-center gap-2 bg-gray-700 rounded-lg px-3 py-2 cursor-pointer hover:bg-gray-600">
                                    <input
                                        v-model="configForm.post_types"
                                        value="video"
                                        type="checkbox"
                                        class="rounded border-gray-600 bg-gray-600 text-purple-600 focus:ring-purple-500"
                                    />
                                    <span class="text-sm text-gray-300">Vídeo</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Opções Booleanas -->
                    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <label class="flex items-center gap-3 p-4 bg-gray-800/50 rounded-xl border border-gray-700/50 cursor-pointer hover:bg-gray-800">
                            <input
                                v-model="configForm.generate_images"
                                type="checkbox"
                                class="rounded border-gray-600 bg-gray-600 text-purple-600 focus:ring-purple-500 h-5 w-5"
                            />
                            <div>
                                <p class="text-sm font-medium text-white">Gerar Imagens</p>
                                <p class="text-xs text-gray-500">Criar imagens automaticamente</p>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-4 bg-gray-800/50 rounded-xl border border-gray-700/50 cursor-pointer hover:bg-gray-800">
                            <input
                                v-model="configForm.auto_hashtags"
                                type="checkbox"
                                class="rounded border-gray-600 bg-gray-600 text-purple-600 focus:ring-purple-500 h-5 w-5"
                            />
                            <div>
                                <p class="text-sm font-medium text-white">Auto Hashtags</p>
                                <p class="text-xs text-gray-500">Gerar hashtags automaticamente</p>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-4 bg-gray-800/50 rounded-xl border border-gray-700/50 cursor-pointer hover:bg-gray-800">
                            <input
                                v-model="configForm.include_cta"
                                type="checkbox"
                                class="rounded border-gray-600 bg-gray-600 text-purple-600 focus:ring-purple-500 h-5 w-5"
                            />
                            <div>
                                <p class="text-sm font-medium text-white">Incluir CTA</p>
                                <p class="text-xs text-gray-500">Adicionar call-to-action</p>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-4 bg-gray-800/50 rounded-xl border border-gray-700/50 cursor-pointer hover:bg-gray-800">
                            <input
                                v-model="configForm.include_emojis"
                                type="checkbox"
                                class="rounded border-gray-600 bg-gray-600 text-purple-600 focus:ring-purple-500 h-5 w-5"
                            />
                            <div>
                                <p class="text-sm font-medium text-white">Incluir Emojis</p>
                                <p class="text-xs text-gray-500">Usar emojis nas legendas</p>
                            </div>
                        </label>
                    </div>

                    <!-- Override do Tom de Voz -->
                    <div class="mt-6 p-4 bg-gray-800/50 rounded-xl border border-gray-700/50">
                        <label class="block text-sm font-medium text-white mb-2">Override do Tom de Voz (opcional)</label>
                        <input
                            v-model="configForm.content_tone_override"
                            type="text"
                            placeholder="Deixe em branco para usar o tom da marca"
                            class="w-full rounded-lg bg-gray-700 border-gray-600 text-white"
                        />
                        <p class="text-xs text-gray-500 mt-1">Ex: "mais descontraído", "técnico", "luxuoso"</p>
                    </div>
                </div>
            </div>

            <!-- Modal: Detalhe da Campanha -->
            <div v-if="selectedCampaign" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" @click.self="closeCampaignDetail">
                <div class="bg-gray-900 border border-gray-700 rounded-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
                    <div class="sticky top-0 bg-gray-900 border-b border-gray-800 p-6 flex items-start justify-between">
                        <div>
                            <h3 class="text-xl font-semibold text-white mb-1">{{ selectedCampaign.name }}</h3>
                            <div class="flex items-center gap-2">
                                <span :class="['rounded-md border px-2 py-0.5 text-xs font-medium', getObjectiveColor(selectedCampaign.objective)]">
                                    {{ getObjectiveLabel(selectedCampaign.objective) }}
                                </span>
                                <span class="text-xs text-gray-500">{{ selectedCampaign.format }}</span>
                            </div>
                        </div>
                        <button @click="closeCampaignDetail" class="text-gray-400 hover:text-white">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <div class="p-6 space-y-6">
                        <!-- Conceito -->
                        <div>
                            <h4 class="text-sm font-medium text-gray-400 mb-2">Conceito</h4>
                            <p class="text-sm text-gray-300">{{ selectedCampaign.concept }}</p>
                        </div>

                        <!-- Plataformas e Duração -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <h4 class="text-sm font-medium text-gray-400 mb-2">Plataformas</h4>
                                <div class="flex flex-wrap gap-2">
                                    <span v-for="p in selectedCampaign.platforms" :key="p" class="rounded-lg bg-gray-800 px-3 py-1.5 text-sm text-gray-300">
                                        {{ platformLabels[p] || p }}
                                    </span>
                                </div>
                            </div>
                            <div v-if="selectedCampaign.duration">
                                <h4 class="text-sm font-medium text-gray-400 mb-2">Duração Sugerida</h4>
                                <p class="text-sm text-gray-300">{{ selectedCampaign.duration }}</p>
                            </div>
                        </div>

                        <!-- Posts -->
                        <div>
                            <h4 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-purple-500/20 flex items-center justify-center text-xs text-purple-400">{{ selectedCampaign.posts.length }}</span>
                                Variações de Posts
                            </h4>
                            <div class="space-y-3">
                                <div v-for="(post, idx) in selectedCampaign.posts" :key="idx" class="p-4 bg-gray-800/50 rounded-xl border border-gray-700/50">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-xs font-medium text-purple-400 uppercase">{{ post.angle }}</span>
                                        <span class="text-xs text-gray-500">#{{ idx + 1 }}</span>
                                    </div>
                                    <p class="text-sm font-medium text-white mb-2">{{ post.hook }}</p>
                                    <p class="text-xs text-gray-400 mb-2">{{ post.cta }}</p>
                                    <p v-if="post.visual_description" class="text-xs text-gray-500 italic">{{ post.visual_description }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- KPIs -->
                        <div v-if="selectedCampaign.kpis?.length">
                            <h4 class="text-sm font-medium text-gray-400 mb-2">KPIs Sugeridos</h4>
                            <div class="flex flex-wrap gap-2">
                                <span v-for="kpi in selectedCampaign.kpis" :key="kpi" class="rounded-lg bg-green-500/10 border border-green-500/20 px-3 py-1.5 text-sm text-green-400">
                                    {{ kpi }}
                                </span>
                            </div>
                        </div>

                        <!-- Ações -->
                        <div class="flex gap-3 pt-4 border-t border-gray-800">
                            <button @click="createPostsFromCampaign(selectedCampaign)" class="flex-1 rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 px-6 py-3 text-sm font-semibold text-white hover:opacity-90 transition">
                                Criar Todos os Posts
                            </button>
                            <button @click="closeCampaignDetail" class="rounded-xl bg-gray-700 px-6 py-3 text-sm font-medium text-white hover:bg-gray-600 transition">
                                Fechar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal: Rejeitar Campanha -->
            <div v-if="showRejectionModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" @click.self="showRejectionModal = false">
                <div class="bg-gray-900 border border-gray-700 rounded-2xl w-full max-w-md p-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Rejeitar Campanha</h3>
                    <p class="text-sm text-gray-400 mb-4">{{ campaignToReject?.name }}</p>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Motivo (opcional)</label>
                        <textarea
                            v-model="rejectionReason"
                            rows="3"
                            placeholder="Por que está rejeitando esta campanha?"
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-red-500 focus:ring-red-500"
                        ></textarea>
                    </div>

                    <div class="flex gap-3">
                        <button
                            @click="confirmRejectCampaign"
                            class="flex-1 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700 transition"
                        >
                            Confirmar Rejeição
                        </button>
                        <button
                            @click="showRejectionModal = false"
                            class="rounded-xl bg-gray-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-600 transition"
                        >
                            Cancelar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Guia -->
            <GuideBox
                title="Content Engine - Como funciona"
                description="O Content Engine é um sistema avançado de geração de conteúdo inspirado no Pomeli do Google. Ele combina análise automática de marca, geração inteligente de campanhas e edição por linguagem natural."
                :steps="guideSteps"
                :tips="guideTips"
                color="purple"
                storage-key="content-engine-guide"
                class="mt-6"
            />
        </template>
    </AuthenticatedLayout>
</template>
