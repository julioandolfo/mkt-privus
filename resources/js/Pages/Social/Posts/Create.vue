<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';

interface Platform {
    value: string;
    label: string;
    color: string;
}

interface PostTypeOption {
    value: string;
    label: string;
    dimensions: { width: number; height: number };
}

interface AIModelOption {
    value: string;
    label: string;
    provider: string;
}

interface AccountOption {
    id: number;
    brand_id: number;
    brand_name: string;
    platform: string;
    platform_label: string;
    platform_color: string;
    username: string;
    display_name: string;
    avatar_url?: string | null;
    source?: string;
}

const props = defineProps<{
    platforms: Platform[];
    postTypes: PostTypeOption[];
    accounts: AccountOption[];
    activeBrandId?: number | null;
    aiModels: AIModelOption[];
}>();

function hasConnectedAccount(platformValue: string): boolean {
    return (props.accounts || []).some(a => a.platform === platformValue);
}

const form = useForm({
    title: '',
    caption: '',
    hashtags: [] as string[],
    type: 'feed',
    platforms: [] as string[],
    account_ids: [] as number[],
    scheduled_at: '',
    media: [] as File[],
});

// Agrupa contas por brand para a UI cross-brand. Brand ativa primeiro.
const accountsByBrand = computed(() => {
    const groups = new Map<number, { brand_id: number; brand_name: string; accounts: AccountOption[] }>();
    for (const acc of props.accounts) {
        if (!groups.has(acc.brand_id)) {
            groups.set(acc.brand_id, { brand_id: acc.brand_id, brand_name: acc.brand_name, accounts: [] });
        }
        groups.get(acc.brand_id)!.accounts.push(acc);
    }
    const list = Array.from(groups.values());
    list.sort((a, b) => {
        if (a.brand_id === props.activeBrandId) return -1;
        if (b.brand_id === props.activeBrandId) return 1;
        return a.brand_name.localeCompare(b.brand_name);
    });
    return list;
});

const selectedBrandsCount = computed(() => {
    const brandIds = new Set<number>();
    for (const id of form.account_ids) {
        const acc = props.accounts.find(a => a.id === id);
        if (acc) brandIds.add(acc.brand_id);
    }
    return brandIds.size;
});

function toggleAccount(accountId: number) {
    const idx = form.account_ids.indexOf(accountId);
    if (idx === -1) {
        form.account_ids.push(accountId);
    } else {
        form.account_ids.splice(idx, 1);
    }
    syncPlatformsFromAccounts();
}

function toggleAllForBrand(brandId: number) {
    const group = accountsByBrand.value.find(g => g.brand_id === brandId);
    if (!group) return;
    const ids = group.accounts.map(a => a.id);
    const allSelected = ids.every(id => form.account_ids.includes(id));
    if (allSelected) {
        form.account_ids = form.account_ids.filter(id => !ids.includes(id));
    } else {
        ids.forEach(id => { if (!form.account_ids.includes(id)) form.account_ids.push(id); });
    }
    syncPlatformsFromAccounts();
}

// Mantém form.platforms como derivado das contas selecionadas (informativo,
// usado pra calcular charLimit e gerar conteúdo de IA com a 1ª plataforma).
function syncPlatformsFromAccounts() {
    const platforms = new Set<string>();
    for (const id of form.account_ids) {
        const acc = props.accounts.find(a => a.id === id);
        if (acc) platforms.add(acc.platform);
    }
    form.platforms = Array.from(platforms);
}

// Upload & preview
const mediaInput = ref<HTMLInputElement | null>(null);
const mediaPreviews = ref<Array<{ file: File; url: string; type: string }>>([]);
const dragOver = ref(false);
const mediaError = ref('');

// AI Generation (legenda)
const aiModalOpen = ref(false);
const aiTopic = ref('');
const aiTone = ref('');
const aiInstructions = ref('');
const aiModel = ref(props.aiModels[0]?.value || 'gpt-4o-mini');
const aiGenerating = ref(false);
const aiError = ref('');

// AI Complete Post (post completo)
const aiCompleteOpen = ref(false);
const aiCompleteTopic = ref('');
const aiCompleteTone = ref('');
const aiCompleteInstructions = ref('');
const aiCompleteModel = ref(props.aiModels.find(m => m.value === 'gpt-4o')?.value || props.aiModels[0]?.value || 'gpt-4o');
const aiCompleteGenerating = ref(false);
const aiCompleteError = ref('');
const aiCompleteStep = ref<'config' | 'generating' | 'result'>('config');
const aiCompleteResult = ref<any>(null);

// Opções de análise
const analyzeHistory = ref(true);
const analyzeWebsite = ref(true);
const analyzeSocial = ref(true);
const generateImage = ref(true);
const imageStyle = ref('');
const imageSize = ref('1024x1024');
const referenceImages = ref<File[]>([]);
const referenceImagePreviews = ref<string[]>([]);
const contextUrls = ref<string[]>(['']);
const referenceInput = ref<HTMLInputElement | null>(null);

function triggerReferenceInput() { referenceInput.value?.click(); }

function onReferenceSelect(event: Event) {
    const target = event.target as HTMLInputElement;
    if (!target.files) return;
    for (const file of Array.from(target.files)) {
        if (!file.type.startsWith('image/') || referenceImages.value.length >= 3) continue;
        referenceImages.value.push(file);
        referenceImagePreviews.value.push(URL.createObjectURL(file));
    }
    target.value = '';
}

function removeReference(index: number) {
    URL.revokeObjectURL(referenceImagePreviews.value[index]);
    referenceImages.value.splice(index, 1);
    referenceImagePreviews.value.splice(index, 1);
}

function addContextUrl() {
    if (contextUrls.value.length < 5) contextUrls.value.push('');
}
function removeContextUrl(index: number) {
    contextUrls.value.splice(index, 1);
    if (contextUrls.value.length === 0) contextUrls.value.push('');
}

const imageStyleOptions = [
    { value: '', label: 'Automático (baseado na marca)' },
    { value: 'flat design, minimalist, vector illustration', label: 'Flat / Minimalista' },
    { value: 'photorealistic, professional photography', label: 'Fotorrealista' },
    { value: '3D render, modern, glossy', label: 'Render 3D' },
    { value: 'watercolor, artistic, soft', label: 'Aquarela / Artístico' },
    { value: 'neon, vibrant, dark background', label: 'Neon / Vibrante' },
    { value: 'vintage, retro, film grain', label: 'Vintage / Retrô' },
    { value: 'geometric, abstract, modern', label: 'Geométrico / Abstrato' },
    { value: 'hand drawn, sketch, creative', label: 'Ilustração Manual' },
];

const imageSizeOptions = [
    { value: '1024x1024', label: 'Quadrado (1:1) - Feed' },
    { value: '1792x1024', label: 'Paisagem (16:9) - YouTube/LinkedIn' },
    { value: '1024x1792', label: 'Retrato (9:16) - Stories/Reels' },
];

// Hashtag input
const hashtagInput = ref('');

// Limites de caracteres por plataforma
const charLimits: Record<string, number> = {
    instagram: 2200,
    facebook: 63206,
    linkedin: 3000,
    tiktok: 2200,
    youtube: 5000,
    pinterest: 500,
};

const currentCharLimit = computed(() => {
    if (!form.platforms.length) return 2200;
    return Math.min(...form.platforms.map(p => charLimits[p] || 2200));
});

const captionLength = computed(() => form.caption.length);
const captionPercentage = computed(() => Math.min((captionLength.value / currentCharLimit.value) * 100, 100));
const captionOverLimit = computed(() => captionLength.value > currentCharLimit.value);

function togglePlatform(platformValue: string) {
    const index = form.platforms.indexOf(platformValue);
    if (index === -1) {
        form.platforms.push(platformValue);
    } else {
        form.platforms.splice(index, 1);
    }
}

// ===== MEDIA =====
function triggerFileInput() {
    mediaInput.value?.click();
}

function onFileSelect(event: Event) {
    const target = event.target as HTMLInputElement;
    if (target.files) {
        addFiles(Array.from(target.files));
    }
}

function onDrop(event: DragEvent) {
    dragOver.value = false;
    if (event.dataTransfer?.files) {
        addFiles(Array.from(event.dataTransfer.files));
    }
}

function addFiles(files: File[]) {
    const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm'];
    const validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'avi', 'webm', 'm4v'];
    const videoExtensions = ['mp4', 'mov', 'avi', 'webm', 'm4v'];
    const maxSize = 100 * 1024 * 1024; // 100MB
    const errors: string[] = [];

    console.log('[media] addFiles chamado com', files.length, 'arquivo(s)', files.map(f => ({
        name: f.name,
        type: f.type || '(vazio)',
        sizeMB: (f.size / 1024 / 1024).toFixed(2),
    })));

    for (const file of files) {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const mimeOk = validTypes.includes(file.type);
        const extOk = validExtensions.includes(ext);

        if (!mimeOk && !extOk) {
            const reason = `Tipo nao suportado: ${file.name} (mime=${file.type || 'vazio'}, ext=${ext || 'nenhuma'})`;
            console.warn('[media] rejeitado:', reason);
            errors.push(`"${file.name}" - formato nao suportado (${file.type || 'sem tipo'})`);
            continue;
        }

        if (file.size > maxSize) {
            const sizeMB = (file.size / 1024 / 1024).toFixed(1);
            console.warn(`[media] rejeitado por tamanho: ${file.name} = ${sizeMB}MB (max 50MB)`);
            errors.push(`"${file.name}" - ${sizeMB}MB excede o limite de 100MB`);
            continue;
        }

        if (mediaPreviews.value.length >= 10) {
            console.warn('[media] limite de 10 arquivos atingido, ignorando restantes');
            errors.push('Limite de 10 arquivos por post atingido');
            break;
        }

        try {
            const url = URL.createObjectURL(file);
            const isVideo = file.type.startsWith('video/') || videoExtensions.includes(ext);
            const type = isVideo ? 'video' : 'image';
            mediaPreviews.value.push({ file, url, type });
            form.media.push(file);
            console.log(`[media] adicionado: ${file.name} como ${type}`);
        } catch (e) {
            console.error('[media] erro ao criar preview:', e);
            errors.push(`"${file.name}" - falha ao gerar preview`);
        }
    }

    mediaError.value = errors.length ? errors.join(' | ') : '';
    console.log('[media] estado atual:', {
        previews: mediaPreviews.value.length,
        formMedia: form.media.length,
        errors: errors.length,
    });
}

function removeMedia(index: number) {
    URL.revokeObjectURL(mediaPreviews.value[index].url);
    mediaPreviews.value.splice(index, 1);
    form.media.splice(index, 1);
}

// ===== HASHTAGS =====
function addHashtag() {
    let tag = hashtagInput.value.trim();
    if (!tag) return;
    if (!tag.startsWith('#')) tag = '#' + tag;
    tag = tag.replace(/\s+/g, '');
    if (!form.hashtags.includes(tag)) {
        form.hashtags.push(tag);
    }
    hashtagInput.value = '';
}

function removeHashtag(index: number) {
    form.hashtags.splice(index, 1);
}

// ===== AI COMPLETE POST =====
function openAIComplete() {
    aiCompleteOpen.value = true;
    aiCompleteError.value = '';
    aiCompleteStep.value = 'config';
    aiCompleteResult.value = null;
}

function closeAIComplete() {
    aiCompleteOpen.value = false;
}

async function generateCompletePost() {
    if (!aiCompleteTopic.value.trim()) {
        aiCompleteError.value = 'Informe o tema/assunto do post.';
        return;
    }
    if (!form.platforms.length) {
        aiCompleteError.value = 'Selecione ao menos uma plataforma antes.';
        return;
    }

    aiCompleteGenerating.value = true;
    aiCompleteError.value = '';
    aiCompleteStep.value = 'generating';

    try {
        const formData = new FormData();
        formData.append('topic', aiCompleteTopic.value);
        formData.append('platform', form.platforms[0]);
        if (form.type) formData.append('type', form.type);
        if (aiCompleteTone.value) formData.append('tone', aiCompleteTone.value);
        if (aiCompleteInstructions.value) formData.append('instructions', aiCompleteInstructions.value);
        formData.append('model', aiCompleteModel.value);
        formData.append('analyze_history', analyzeHistory.value ? '1' : '0');
        formData.append('analyze_website', analyzeWebsite.value ? '1' : '0');
        formData.append('analyze_social', analyzeSocial.value ? '1' : '0');
        formData.append('generate_image', generateImage.value ? '1' : '0');
        if (imageStyle.value) formData.append('image_style', imageStyle.value);
        formData.append('image_size', imageSize.value);
        referenceImages.value.forEach((file, i) => formData.append(`reference_images[${i}]`, file));
        contextUrls.value.filter(u => u.trim()).forEach((url, i) => formData.append(`context_urls[${i}]`, url.trim()));

        const response = await axios.post(route('social.generate-complete'), formData);

        aiCompleteResult.value = response.data;
        aiCompleteStep.value = 'result';
    } catch (e: any) {
        aiCompleteError.value = e.response?.data?.error || 'Erro ao gerar post. Tente novamente.';
        aiCompleteStep.value = 'config';
    } finally {
        aiCompleteGenerating.value = false;
    }
}

async function applyCompleteResult() {
    const result = aiCompleteResult.value;
    if (!result) return;

    if (result.title) form.title = result.title;
    if (result.caption) form.caption = result.caption;
    if (result.hashtags?.length) form.hashtags = result.hashtags;

    // Se imagem foi gerada com sucesso, baixar e adicionar como mídia
    if (result.image?.url && !result.image?.error) {
        try {
            const imgResponse = await fetch(result.image.url);
            const blob = await imgResponse.blob();
            const file = new File([blob], `ai-generated-${Date.now()}.png`, { type: 'image/png' });

            const url = URL.createObjectURL(file);
            mediaPreviews.value.push({ file, url, type: 'image' });
            form.media.push(file);
        } catch (e) {
            console.warn('Falha ao baixar imagem gerada:', e);
        }
    }

    closeAIComplete();
}

// ===== AI GENERATION (legenda apenas) =====
function openAIModal() {
    aiModalOpen.value = true;
    aiError.value = '';
}

function closeAIModal() {
    aiModalOpen.value = false;
}

async function generateWithAI() {
    if (!aiTopic.value.trim()) {
        aiError.value = 'Informe o tema do post.';
        return;
    }

    if (!form.platforms.length) {
        aiError.value = 'Selecione ao menos uma plataforma.';
        return;
    }

    aiGenerating.value = true;
    aiError.value = '';

    try {
        const response = await axios.post(route('social.generate'), {
            topic: aiTopic.value,
            platform: form.platforms[0],
            type: form.type,
            tone: aiTone.value || undefined,
            instructions: aiInstructions.value || undefined,
            model: aiModel.value,
        });

        form.caption = response.data.caption;
        form.hashtags = response.data.hashtags || [];
        closeAIModal();
    } catch (e: any) {
        aiError.value = e.response?.data?.error || 'Erro ao gerar conteúdo. Tente novamente.';
    } finally {
        aiGenerating.value = false;
    }
}

// ===== SUBMIT =====
/** Converte o valor do input datetime-local (sem fuso) para ISO 8601 com offset local. */
function toLocalISO(datetimeLocal: string): string {
    if (!datetimeLocal) return '';
    const d = new Date(datetimeLocal);
    const pad = (n: number) => String(n).padStart(2, '0');
    const offset = -d.getTimezoneOffset();
    const sign = offset >= 0 ? '+' : '-';
    const hh = pad(Math.floor(Math.abs(offset) / 60));
    const mm = pad(Math.abs(offset) % 60);
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:00${sign}${hh}:${mm}`;
}

function submit() {
    form.clearErrors();
    const formData = new FormData();
    formData.append('title', form.title);
    formData.append('caption', form.caption);
    formData.append('type', form.type);
    if (form.scheduled_at) formData.append('scheduled_at', toLocalISO(form.scheduled_at));

    // Arrays precisam ser adicionados item a item no FormData
    form.account_ids.forEach((id, i) => formData.append(`account_ids[${i}]`, String(id)));
    form.platforms.forEach((p, i) => formData.append(`platforms[${i}]`, p));
    form.hashtags.forEach((h, i) => formData.append(`hashtags[${i}]`, h));
    form.media.forEach((file, i) => formData.append(`media[${i}]`, file));

    // Usar router.post com o formData real (não form.post que ignora o formData construído)
    router.post(route('social.posts.store'), formData, {
        onStart: () => { form.processing = true; },
        onFinish: () => { form.processing = false; },
        onError: (errors) => {
            form.processing = false;
            Object.keys(errors).forEach(key => {
                form.setError(key as any, errors[key]);
            });
        },
    });
}

const createPostTips = [
    'Selecione primeiro as plataformas - isso ajusta os limites de caracteres e formatos recomendados.',
    'Use o botao "Gerar com IA" para criar legendas automaticamente com base no tema e tom de voz da marca.',
    'Voce pode escolher diferentes modelos de IA (GPT-4o, Claude, Gemini) e instrucoes extras para personalizar a geracao.',
    'Arraste e solte imagens/videos na area de upload. Maximo 10 arquivos, 50MB cada.',
    'Hashtags sao adicionadas separadamente e formatadas automaticamente com o #.',
    'Agende data/hora para publicacao automatica via Autopilot, ou deixe vazio para salvar como rascunho.',
];

const toneOptions = [
    { value: '', label: 'Automático (baseado na marca)' },
    { value: 'profissional', label: 'Profissional' },
    { value: 'informal', label: 'Informal / Descontraído' },
    { value: 'inspirador', label: 'Inspirador' },
    { value: 'educativo', label: 'Educativo' },
    { value: 'vendas', label: 'Vendas / Persuasivo' },
    { value: 'humor', label: 'Humorístico' },
    { value: 'tecnico', label: 'Técnico' },
];
</script>

<template>
    <Head title="Novo Post" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('social.posts.index')" class="text-gray-400 hover:text-white transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-xl font-semibold text-white">Novo Post</h1>
            </div>
        </template>

        <div class="max-w-4xl">
            <GuideBox
                title="Dicas para criar um post perfeito"
                color="purple"
                storage-key="posts-create-guide"
                class="mb-6"
                :tips="createPostTips"
            />

            <form @submit.prevent="submit" class="space-y-6">

                <!-- Secao 1a: Onde publicar (cross-brand) -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <h2 class="text-lg font-semibold text-white mb-1">Onde publicar</h2>
                            <p class="text-sm text-gray-500">Selecione contas de uma ou mais empresas — o mesmo post vai para todas em paralelo.</p>
                        </div>
                        <div v-if="form.account_ids.length > 0" class="text-right shrink-0">
                            <div class="text-xs text-gray-500">Selecionadas</div>
                            <div class="text-lg font-semibold text-emerald-400">{{ form.account_ids.length }} <span class="text-xs text-gray-500">conta(s)</span></div>
                            <div v-if="selectedBrandsCount > 1" class="text-[10px] text-purple-300">{{ selectedBrandsCount }} empresas</div>
                        </div>
                    </div>

                    <div v-if="!accounts || accounts.length === 0" class="rounded-xl bg-amber-500/10 border border-amber-500/30 p-4 text-sm text-amber-300">
                        Nenhuma conta social conectada nas suas empresas.
                        <Link :href="route('social.accounts.index')" class="underline hover:text-amber-200">Conectar contas</Link>
                    </div>

                    <div v-else class="space-y-4">
                        <div v-for="group in accountsByBrand" :key="group.brand_id" class="rounded-xl border border-gray-800 overflow-hidden">
                            <div class="flex items-center justify-between bg-gray-800/40 px-4 py-2.5 border-b border-gray-800">
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5"/></svg>
                                    <span class="text-sm font-semibold text-white">{{ group.brand_name }}</span>
                                    <span v-if="group.brand_id === activeBrandId" class="text-[10px] rounded-full bg-indigo-500/20 border border-indigo-500/40 px-1.5 py-0.5 text-indigo-300">marca ativa</span>
                                </div>
                                <button type="button" @click="toggleAllForBrand(group.brand_id)"
                                    class="text-[11px] text-indigo-400 hover:text-indigo-300 underline">
                                    Marcar/desmarcar todas
                                </button>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 p-3">
                                <button v-for="account in group.accounts" :key="account.id"
                                    type="button"
                                    @click="toggleAccount(account.id)"
                                    :class="[
                                        'flex items-center gap-2.5 rounded-lg border-2 p-2.5 transition-all text-left',
                                        form.account_ids.includes(account.id)
                                            ? 'border-emerald-500 bg-emerald-500/10 shadow-[0_0_14px_rgba(16,185,129,0.35)]'
                                            : 'border-gray-700 bg-gray-800/30 hover:border-gray-600 hover:bg-gray-800/60',
                                    ]">
                                    <div class="relative shrink-0">
                                        <img v-if="account.avatar_url" :src="account.avatar_url" :alt="account.username"
                                            class="w-9 h-9 rounded-lg object-cover" />
                                        <div v-else class="w-9 h-9 rounded-lg flex items-center justify-center text-white text-xs font-bold"
                                            :style="{ backgroundColor: account.platform_color }">
                                            {{ (account.username || 'A')[0].toUpperCase() }}
                                        </div>
                                        <span class="absolute -bottom-0.5 -right-0.5 w-4 h-4 rounded-full border border-gray-900 flex items-center justify-center"
                                            :style="{ backgroundColor: account.platform_color }">
                                            <span class="text-[7px] font-bold text-white">{{ account.platform_label[0] }}</span>
                                        </span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-xs font-semibold text-white truncate">{{ account.display_name || account.username }}</div>
                                        <div class="text-[10px] text-gray-500 truncate">{{ account.platform_label }} · @{{ account.username }}</div>
                                    </div>
                                    <svg v-if="form.account_ids.includes(account.id)" class="w-4 h-4 text-emerald-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.5 7.55a1 1 0 0 1-1.42.001l-3.5-3.515a1 1 0 1 1 1.42-1.41l2.79 2.802 6.79-6.836a1 1 0 0 1 1.414-.006z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <InputError :message="form.errors.account_ids" class="mt-2" />
                </div>

                <!-- Secao 1b: Indicador de plataformas (derivado das contas, somente leitura) -->
                <div v-if="form.platforms.length > 0" class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-xs text-gray-500 mb-2">Plataformas alvo (derivadas das contas selecionadas):</p>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="p in form.platforms" :key="p"
                            class="text-xs rounded-full px-2 py-0.5 border"
                            :style="{ borderColor: platforms.find(pl => pl.value === p)?.color, color: platforms.find(pl => pl.value === p)?.color }">
                            {{ platforms.find(pl => pl.value === p)?.label || p }}
                        </span>
                    </div>
                </div>

                <!-- Secao 1: Plataformas (LEGADO — escondido) -->
                <div v-if="false" class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-2">Plataformas</h2>
                    <p class="text-sm text-gray-500 mb-1">Selecione onde este post será publicado</p>
                    <p class="text-xs text-emerald-500/80 mb-4 flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 shadow-[0_0_6px_rgba(16,185,129,0.7)]"></span>
                        Contorno verde = conta conectada (publicação automática disponível)
                    </p>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-6">
                        <button
                            v-for="platform in platforms"
                            :key="platform.value"
                            type="button"
                            @click="togglePlatform(platform.value)"
                            :class="[
                                'relative flex flex-col items-center gap-2 rounded-xl border-2 p-4 transition-all',
                                form.platforms.includes(platform.value)
                                    ? 'border-current bg-current/10'
                                    : hasConnectedAccount(platform.value)
                                        ? 'border-emerald-500/60 bg-emerald-500/5 shadow-[0_0_18px_rgba(16,185,129,0.35)] hover:border-emerald-400 hover:shadow-[0_0_24px_rgba(16,185,129,0.5)]'
                                        : 'border-gray-700 hover:border-gray-600 bg-gray-800/50',
                            ]"
                            :style="form.platforms.includes(platform.value) ? { borderColor: platform.color, color: platform.color } : {}"
                            :title="hasConnectedAccount(platform.value) ? `Conta ${platform.label} conectada` : `Sem conta conectada em ${platform.label}`"
                        >
                            <span v-if="hasConnectedAccount(platform.value) && !form.platforms.includes(platform.value)"
                                class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_6px_rgba(16,185,129,0.8)]"></span>
                            <!-- Platform icons -->
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor" :style="{ color: form.platforms.includes(platform.value) ? platform.color : '#9CA3AF' }">
                                <template v-if="platform.value === 'instagram'">
                                    <path d="M12 2c2.717 0 3.056.01 4.122.06 1.065.05 1.79.217 2.428.465.66.254 1.216.598 1.772 1.153a4.908 4.908 0 0 1 1.153 1.772c.247.637.415 1.363.465 2.428.047 1.066.06 1.405.06 4.122 0 2.717-.01 3.056-.06 4.122-.05 1.065-.218 1.79-.465 2.428a4.883 4.883 0 0 1-1.153 1.772 4.915 4.915 0 0 1-1.772 1.153c-.637.247-1.363.415-2.428.465-1.066.047-1.405.06-4.122.06-2.717 0-3.056-.01-4.122-.06-1.065-.05-1.79-.218-2.428-.465a4.89 4.89 0 0 1-1.772-1.153 4.904 4.904 0 0 1-1.153-1.772c-.248-.637-.415-1.363-.465-2.428C2.013 15.056 2 14.717 2 12c0-2.717.01-3.056.06-4.122.05-1.066.217-1.79.465-2.428a4.88 4.88 0 0 1 1.153-1.772A4.897 4.897 0 0 1 5.45 2.525c.638-.248 1.362-.415 2.428-.465C8.944 2.013 9.283 2 12 2zm0 5a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm6.5-.25a1.25 1.25 0 0 0-2.5 0 1.25 1.25 0 0 0 2.5 0zM12 9a3 3 0 1 1 0 6 3 3 0 0 1 0-6z"/>
                                </template>
                                <template v-else-if="platform.value === 'facebook'">
                                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                </template>
                                <template v-else-if="platform.value === 'linkedin' || platform.value === 'linkedin_page'">
                                    <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                                </template>
                                <template v-else-if="platform.value === 'tiktok'">
                                    <path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>
                                </template>
                                <template v-else-if="platform.value === 'youtube'">
                                    <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                </template>
                                <template v-else-if="platform.value === 'google_my_business'">
                                    <path d="M12.24 10.285V14.4h6.806c-.275 1.765-2.056 5.174-6.806 5.174-4.095 0-7.439-3.389-7.439-7.574s3.345-7.574 7.439-7.574c2.33 0 3.891.989 4.785 1.849l3.254-3.138C18.189 1.186 15.479 0 12.24 0c-6.635 0-12 5.365-12 12s5.365 12 12 12c6.926 0 11.52-4.869 11.52-11.726 0-.788-.085-1.39-.189-1.989H12.24z"/>
                                </template>
                            </svg>
                            <span :class="['text-xs font-medium', form.platforms.includes(platform.value) ? '' : 'text-gray-400']">
                                {{ platform.label }}
                                <span v-if="platform.value === 'linkedin_page'" class="block text-[9px] opacity-70">(empresa)</span>
                            </span>
                        </button>
                    </div>
                    <InputError :message="form.errors.platforms" class="mt-2" />
                </div>

                <!-- Secao 2: Tipo de Post -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Tipo de Post</h2>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-6">
                        <button
                            v-for="postType in postTypes"
                            :key="postType.value"
                            type="button"
                            @click="form.type = postType.value"
                            :class="[
                                'flex flex-col items-center gap-2 rounded-xl border-2 p-3 transition-all text-sm',
                                form.type === postType.value
                                    ? 'border-indigo-500 bg-indigo-500/10 text-indigo-400'
                                    : 'border-gray-700 hover:border-gray-600 text-gray-400',
                            ]"
                        >
                            <span class="font-medium">{{ postType.label }}</span>
                            <span class="text-[10px] text-gray-500">{{ postType.dimensions.width }}x{{ postType.dimensions.height }}</span>
                        </button>
                    </div>
                </div>

                <!-- Secao 3: Upload de Midia -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Mídia</h2>

                    <!-- Drop zone -->
                    <div
                        @dragover.prevent="dragOver = true"
                        @dragleave="dragOver = false"
                        @drop.prevent="onDrop"
                        @click="triggerFileInput"
                        :class="[
                            'relative flex flex-col items-center justify-center rounded-xl border-2 border-dashed p-8 cursor-pointer transition-all',
                            dragOver
                                ? 'border-indigo-500 bg-indigo-500/10'
                                : 'border-gray-700 hover:border-gray-600 hover:bg-gray-800/50',
                        ]"
                    >
                        <svg class="w-10 h-10 text-gray-500 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                        </svg>
                        <p class="text-sm text-gray-400">Arraste arquivos aqui ou <span class="text-indigo-400">clique para selecionar</span></p>
                        <p class="text-xs text-gray-600 mt-1">JPG, PNG, GIF, WEBP, MP4, MOV, WEBM, AVI - Máx. 100MB por arquivo</p>
                        <input
                            ref="mediaInput"
                            type="file"
                            multiple
                            accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/x-msvideo,video/webm,.mp4,.mov,.avi,.webm,.m4v"
                            class="hidden"
                            @change="onFileSelect"
                        />
                    </div>

                    <!-- Preview grid -->
                    <div v-if="mediaPreviews.length" class="grid grid-cols-2 gap-3 mt-4 sm:grid-cols-4 md:grid-cols-5">
                        <div
                            v-for="(preview, index) in mediaPreviews"
                            :key="index"
                            class="relative group rounded-xl overflow-hidden bg-gray-800 aspect-square"
                        >
                            <img
                                v-if="preview.type === 'image'"
                                :src="preview.url"
                                class="w-full h-full object-cover"
                            />
                            <video
                                v-else
                                :src="preview.url"
                                class="w-full h-full object-cover"
                                muted
                            />
                            <button
                                type="button"
                                @click="removeMedia(index)"
                                class="absolute top-1 right-1 p-1 rounded-lg bg-red-500/80 text-white opacity-0 group-hover:opacity-100 transition"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                                </svg>
                            </button>
                            <span v-if="preview.type === 'video'" class="absolute bottom-1 left-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-white">
                                Vídeo
                            </span>
                        </div>
                    </div>
                    <div v-if="mediaError" class="mt-3 rounded-xl bg-red-500/10 border border-red-500/30 p-3 text-sm text-red-300 flex items-start gap-2">
                        <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg>
                        <span>{{ mediaError }}</span>
                    </div>
                    <InputError :message="form.errors.media" class="mt-2" />
                </div>

                <!-- Criar Post Completo com IA (destaque) -->
                <button
                    type="button"
                    @click="openAIComplete"
                    class="w-full rounded-2xl border-2 border-dashed border-purple-500/30 bg-gradient-to-r from-purple-900/20 to-indigo-900/20 p-6 flex items-center gap-5 hover:border-purple-500/50 hover:from-purple-900/30 hover:to-indigo-900/30 transition-all group"
                >
                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-purple-600/30 to-indigo-600/30 shrink-0 group-hover:from-purple-600/40 group-hover:to-indigo-600/40 transition">
                        <svg class="w-7 h-7 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2L2 7l10 5 10-5-10-5z" /><path d="M2 17l10 5 10-5" /><path d="M2 12l10 5 10-5" />
                        </svg>
                    </div>
                    <div class="text-left flex-1">
                        <h3 class="text-base font-semibold text-white group-hover:text-purple-200 transition">Criar Post Completo com IA</h3>
                        <p class="text-sm text-gray-400 mt-0.5">Gera título, legenda, hashtags e imagem automaticamente. Analisa o histórico, site e redes da marca.</p>
                    </div>
                    <div class="shrink-0 flex items-center gap-1.5 px-4 py-2 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 text-white text-sm font-medium group-hover:from-purple-500 group-hover:to-indigo-500 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        Criar
                    </div>
                </button>

                <!-- Secao 4: Legenda + Hashtags -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-white">Legenda</h2>
                        <button
                            type="button"
                            @click="openAIModal"
                            class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 px-4 py-2 text-sm font-medium text-white hover:from-purple-700 hover:to-indigo-700 transition-all"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                            </svg>
                            Gerar Legenda com IA
                        </button>
                    </div>

                    <!-- Titulo (opcional) -->
                    <div class="mb-4">
                        <label class="text-sm text-gray-400 mb-1 block">Título (opcional)</label>
                        <input
                            v-model="form.title"
                            type="text"
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="Título interno para organizar..."
                        />
                    </div>

                    <!-- Legenda -->
                    <textarea
                        v-model="form.caption"
                        rows="6"
                        :class="[
                            'w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:ring-indigo-500 resize-y',
                            captionOverLimit ? 'border-red-500 focus:border-red-500' : 'focus:border-indigo-500',
                        ]"
                        placeholder="Escreva a legenda do post ou gere com IA..."
                    />
                    <div class="flex items-center justify-between mt-1">
                        <InputError :message="form.errors.caption" />
                        <span :class="['text-xs', captionOverLimit ? 'text-red-400' : 'text-gray-500']">
                            {{ captionLength }} / {{ currentCharLimit }}
                        </span>
                    </div>
                    <!-- Character limit bar -->
                    <div class="w-full h-1 bg-gray-800 rounded-full mt-1 overflow-hidden">
                        <div
                            :class="['h-full rounded-full transition-all', captionOverLimit ? 'bg-red-500' : 'bg-indigo-500']"
                            :style="{ width: captionPercentage + '%' }"
                        />
                    </div>

                    <!-- Hashtags -->
                    <div class="mt-6">
                        <label class="text-sm text-gray-400 mb-2 block">Hashtags</label>
                        <div class="flex gap-2 mb-2">
                            <input
                                v-model="hashtagInput"
                                @keydown.enter.prevent="addHashtag"
                                type="text"
                                class="flex-1 rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="#exemplo"
                            />
                            <button
                                type="button"
                                @click="addHashtag"
                                class="rounded-xl bg-gray-800 border border-gray-700 px-4 text-gray-300 hover:bg-gray-700 text-sm transition"
                            >
                                Adicionar
                            </button>
                        </div>
                        <div v-if="form.hashtags.length" class="flex flex-wrap gap-2">
                            <span
                                v-for="(tag, index) in form.hashtags"
                                :key="index"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600/20 px-3 py-1 text-sm text-indigo-300"
                            >
                                {{ tag }}
                                <button type="button" @click="removeHashtag(index)" class="hover:text-red-400 transition">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                                </button>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Secao 5: Agendamento -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Agendamento</h2>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:gap-4">
                        <div class="flex-1">
                            <label class="text-sm text-gray-400 mb-1 block">Data e Hora</label>
                            <input
                                v-model="form.scheduled_at"
                                type="datetime-local"
                                class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                        <p class="text-xs text-gray-500 sm:pb-2">
                            Deixe vazio para salvar como rascunho
                        </p>
                    </div>
                    <InputError :message="form.errors.scheduled_at" class="mt-2" />
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-4 pb-8">
                    <Link
                        :href="route('social.posts.index')"
                        class="rounded-xl px-6 py-2.5 text-sm font-medium text-gray-400 hover:text-white transition"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing || !form.caption || !form.platforms.length"
                        class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {{ form.processing ? 'Salvando...' : (form.scheduled_at ? 'Agendar Post' : 'Salvar Rascunho') }}
                    </button>
                </div>
            </form>
        </div>

        <!-- Modal IA -->
        <Teleport to="body">
            <div v-if="aiModalOpen" class="fixed inset-0 z-[60] flex items-center justify-center">
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeAIModal" />
                <div class="relative w-full max-w-lg rounded-2xl bg-gray-900 border border-gray-700 p-6 shadow-2xl mx-4">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                            </svg>
                            Gerar com IA
                        </h3>
                        <button @click="closeAIModal" class="text-gray-500 hover:text-white transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Tema / Assunto *</label>
                            <input
                                v-model="aiTopic"
                                type="text"
                                class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Ex: Lançamento do novo produto, dica de moda..."
                            />
                        </div>

                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Tom de Voz</label>
                            <select
                                v-model="aiTone"
                                class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="tone in toneOptions" :key="tone.value" :value="tone.value">
                                    {{ tone.label }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Modelo de IA</label>
                            <select
                                v-model="aiModel"
                                class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="m in aiModels" :key="m.value" :value="m.value">
                                    {{ m.label }} ({{ m.provider }})
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Instruções extras (opcional)</label>
                            <textarea
                                v-model="aiInstructions"
                                rows="2"
                                class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Ex: Incluir CTA para o site, mencionar desconto de 20%..."
                            />
                        </div>

                        <div v-if="aiError" class="rounded-xl bg-red-500/10 border border-red-500/30 p-3 text-sm text-red-400">
                            {{ aiError }}
                        </div>

                        <button
                            @click="generateWithAI"
                            :disabled="aiGenerating"
                            class="w-full rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 py-3 text-sm font-semibold text-white hover:from-purple-700 hover:to-indigo-700 transition disabled:opacity-50 flex items-center justify-center gap-2"
                        >
                            <svg v-if="aiGenerating" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            {{ aiGenerating ? 'Gerando...' : 'Gerar Legenda + Hashtags' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
        <!-- Modal: Criar Post Completo com IA -->
        <Teleport to="body">
            <div v-if="aiCompleteOpen" class="fixed inset-0 z-[60] flex items-center justify-center">
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeAIComplete" />
                <div class="relative w-full max-w-2xl rounded-2xl bg-gray-900 border border-gray-700 shadow-2xl mx-4 max-h-[90vh] overflow-y-auto">

                    <!-- Header -->
                    <div class="sticky top-0 bg-gray-900 border-b border-gray-800 px-6 py-4 flex items-center justify-between z-10 rounded-t-2xl">
                        <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-600 to-indigo-600 flex items-center justify-center">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                            </div>
                            Criar Post Completo com IA
                        </h3>
                        <button @click="closeAIComplete" class="text-gray-500 hover:text-white transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                        </button>
                    </div>

                    <!-- Step: Config -->
                    <div v-if="aiCompleteStep === 'config'" class="p-6 space-y-5">
                        <!-- Tema -->
                        <div>
                            <label class="text-sm font-medium text-gray-300 mb-1 block">Tema / Assunto do Post *</label>
                            <input v-model="aiCompleteTopic" type="text"
                                class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Ex: Lançamento do novo produto, promoção de verão, dica de moda..." />
                        </div>

                        <!-- Tom e Modelo -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Tom de Voz</label>
                                <select v-model="aiCompleteTone" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option v-for="tone in toneOptions" :key="tone.value" :value="tone.value">{{ tone.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Modelo de IA</label>
                                <select v-model="aiCompleteModel" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option v-for="m in aiModels" :key="m.value" :value="m.value">{{ m.label }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Análise inteligente -->
                        <div>
                            <h4 class="text-sm font-medium text-white mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
                                Análise Inteligente
                            </h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <label class="flex items-center gap-3 rounded-xl border border-gray-700 bg-gray-800/50 p-3 cursor-pointer hover:bg-gray-800 transition"
                                    :class="analyzeHistory && 'border-indigo-500/40 bg-indigo-900/10'">
                                    <input type="checkbox" v-model="analyzeHistory" class="rounded border-gray-600 text-indigo-600 focus:ring-indigo-500" />
                                    <div>
                                        <p class="text-sm text-white">Histórico</p>
                                        <p class="text-[10px] text-gray-500">Posts com mais engajamento</p>
                                    </div>
                                </label>
                                <label class="flex items-center gap-3 rounded-xl border border-gray-700 bg-gray-800/50 p-3 cursor-pointer hover:bg-gray-800 transition"
                                    :class="analyzeWebsite && 'border-indigo-500/40 bg-indigo-900/10'">
                                    <input type="checkbox" v-model="analyzeWebsite" class="rounded border-gray-600 text-indigo-600 focus:ring-indigo-500" />
                                    <div>
                                        <p class="text-sm text-white">Website</p>
                                        <p class="text-[10px] text-gray-500">URLs e links da marca</p>
                                    </div>
                                </label>
                                <label class="flex items-center gap-3 rounded-xl border border-gray-700 bg-gray-800/50 p-3 cursor-pointer hover:bg-gray-800 transition"
                                    :class="analyzeSocial && 'border-indigo-500/40 bg-indigo-900/10'">
                                    <input type="checkbox" v-model="analyzeSocial" class="rounded border-gray-600 text-indigo-600 focus:ring-indigo-500" />
                                    <div>
                                        <p class="text-sm text-white">Redes Sociais</p>
                                        <p class="text-[10px] text-gray-500">Contas conectadas</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Geração de Imagem -->
                        <div>
                            <label class="flex items-center gap-3 mb-3 cursor-pointer">
                                <input type="checkbox" v-model="generateImage" class="rounded border-gray-600 text-indigo-600 focus:ring-indigo-500" />
                                <div>
                                    <span class="text-sm font-medium text-white">Gerar Imagem com IA</span>
                                    <span class="text-[10px] text-gray-500 ml-2">(gpt-image-2 via Responses API — preserva produtos reais)</span>
                                </div>
                            </label>
                            <div v-if="generateImage" class="space-y-3 pl-7">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-xs text-gray-400 mb-1 block">Estilo Visual</label>
                                        <select v-model="imageStyle" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-xs focus:border-indigo-500 focus:ring-indigo-500">
                                            <option v-for="s in imageStyleOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-400 mb-1 block">Proporção</label>
                                        <select v-model="imageSize" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-xs focus:border-indigo-500 focus:ring-indigo-500">
                                            <option v-for="s in imageSizeOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-400 mb-1.5 block">Fotos de Produto / Referência (opcional, max 3)</label>
                                    <p class="text-[10px] text-gray-500 mb-2">Envie fotos reais dos seus produtos — a IA vai preservar logos, textos e detalhes fielmente na imagem gerada.</p>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <div v-for="(preview, i) in referenceImagePreviews" :key="i" class="relative w-16 h-16 rounded-lg overflow-hidden border border-gray-700 group">
                                            <img :src="preview" class="w-full h-full object-cover" />
                                            <button type="button" @click="removeReference(i)" class="absolute inset-0 bg-black/60 flex items-center justify-center opacity-0 group-hover:opacity-100 transition">
                                                <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                                            </button>
                                        </div>
                                        <button v-if="referenceImagePreviews.length < 3" type="button" @click="triggerReferenceInput"
                                            class="w-16 h-16 rounded-lg border-2 border-dashed border-gray-700 flex items-center justify-center text-gray-500 hover:border-indigo-500 hover:text-indigo-400 transition">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        </button>
                                        <input ref="referenceInput" type="file" accept="image/*" multiple class="hidden" @change="onReferenceSelect" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Links de Contexto -->
                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Links de Referência (opcional)</label>
                            <p class="text-[10px] text-gray-500 mb-2">A IA vai acessar e analisar o conteúdo destes links para enriquecer o post.</p>
                            <div class="space-y-2">
                                <div v-for="(url, i) in contextUrls" :key="i" class="flex items-center gap-2">
                                    <input v-model="contextUrls[i]" type="url"
                                        class="flex-1 rounded-xl bg-gray-800 border-gray-700 text-white text-xs focus:border-indigo-500 focus:ring-indigo-500"
                                        placeholder="https://exemplo.com/pagina-do-produto" />
                                    <button v-if="contextUrls.length > 1" type="button" @click="removeContextUrl(i)" class="text-gray-500 hover:text-red-400 transition">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                                    </button>
                                </div>
                                <button v-if="contextUrls.length < 5" type="button" @click="addContextUrl"
                                    class="text-xs text-indigo-400 hover:text-indigo-300 transition flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    Adicionar outro link
                                </button>
                            </div>
                        </div>

                        <!-- Instruções extras -->
                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Orientações extras (opcional)</label>
                            <textarea v-model="aiCompleteInstructions" rows="3"
                                class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Ex: Focar em promoção de 20%, incluir CTA para o site, mencionar frete grátis..." />
                        </div>

                        <!-- Erro -->
                        <div v-if="aiCompleteError" class="rounded-xl bg-red-500/10 border border-red-500/30 p-3 text-sm text-red-400">
                            {{ aiCompleteError }}
                        </div>

                        <!-- Botão -->
                        <button @click="generateCompletePost" :disabled="aiCompleteGenerating || !aiCompleteTopic.trim()"
                            class="w-full rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 py-3 text-sm font-semibold text-white hover:from-purple-700 hover:to-indigo-700 transition disabled:opacity-50 flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                            Criar Post Completo
                        </button>
                    </div>

                    <!-- Step: Generating -->
                    <div v-if="aiCompleteStep === 'generating'" class="p-12 flex flex-col items-center justify-center text-center">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-purple-600/30 to-indigo-600/30 flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-indigo-400 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                        </div>
                        <h4 class="text-lg font-semibold text-white mb-2">Criando seu post...</h4>
                        <p class="text-sm text-gray-400">A IA está analisando a marca e gerando conteúdo otimizado.</p>
                        <div class="mt-4 space-y-2 text-xs text-gray-500">
                            <p v-if="analyzeHistory">Analisando histórico de engajamento...</p>
                            <p v-if="analyzeWebsite">Analisando sites e links da marca...</p>
                            <p v-if="analyzeSocial">Analisando redes sociais conectadas...</p>
                            <p>Gerando título, legenda e hashtags...</p>
                            <p v-if="generateImage">Gerando imagem com gpt-image-2...</p>
                        </div>
                    </div>

                    <!-- Step: Result -->
                    <div v-if="aiCompleteStep === 'result' && aiCompleteResult" class="p-6 space-y-5">
                        <div class="rounded-xl bg-green-900/20 border border-green-700/30 p-3 flex items-center gap-2">
                            <svg class="w-5 h-5 text-green-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <p class="text-sm text-green-300">Post gerado com sucesso! Revise e ajuste antes de aplicar.</p>
                        </div>

                        <!-- Título -->
                        <div>
                            <label class="text-xs font-medium text-gray-400 mb-1 block">Título</label>
                            <input v-model="aiCompleteResult.title" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm" />
                        </div>

                        <!-- Legenda -->
                        <div>
                            <label class="text-xs font-medium text-gray-400 mb-1 block">Legenda</label>
                            <textarea v-model="aiCompleteResult.caption" rows="6" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm" />
                        </div>

                        <!-- Hashtags -->
                        <div>
                            <label class="text-xs font-medium text-gray-400 mb-1 block">Hashtags</label>
                            <div class="flex flex-wrap gap-1.5">
                                <span v-for="(tag, i) in aiCompleteResult.hashtags" :key="i" class="px-2.5 py-1 rounded-lg bg-indigo-600/20 text-indigo-300 text-xs">
                                    {{ tag }}
                                </span>
                            </div>
                        </div>

                        <!-- Imagem gerada -->
                        <div v-if="aiCompleteResult.image">
                            <label class="text-xs font-medium text-gray-400 mb-1 block">Imagem Gerada</label>
                            <div v-if="aiCompleteResult.image.url && !aiCompleteResult.image.error" class="rounded-xl overflow-hidden border border-gray-700">
                                <img :src="aiCompleteResult.image.url" class="w-full max-h-80 object-contain bg-gray-800" alt="Imagem gerada por IA" />
                            </div>
                            <div v-else-if="aiCompleteResult.image.error" class="rounded-xl bg-yellow-900/20 border border-yellow-700/30 p-3">
                                <p class="text-xs text-yellow-400">Imagem não pôde ser gerada: {{ aiCompleteResult.image.error }}</p>
                                <p v-if="aiCompleteResult.image.image_prompt" class="text-[10px] text-gray-500 mt-1">Prompt sugerido: "{{ aiCompleteResult.image.image_prompt }}"</p>
                            </div>
                        </div>

                        <!-- Ações -->
                        <div class="flex items-center gap-3 pt-2">
                            <button @click="aiCompleteStep = 'config'" class="flex-1 rounded-xl border border-gray-700 py-2.5 text-sm text-gray-400 hover:bg-gray-800 hover:text-white transition">
                                Gerar Novamente
                            </button>
                            <button @click="applyCompleteResult"
                                class="flex-1 rounded-xl bg-gradient-to-r from-green-600 to-emerald-600 py-2.5 text-sm font-semibold text-white hover:from-green-700 hover:to-emerald-700 transition flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                Aplicar ao Post
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
