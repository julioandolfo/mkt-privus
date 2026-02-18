<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

interface PostMedia {
    id: number;
    type: string;
    file_path: string | null;
    file_name: string;
    alt_text: string | null;
    order: number;
}

interface PostData {
    id: number;
    title: string | null;
    caption: string;
    hashtags: string[];
    type: string | null;
    status: string;
    platforms: string[];
    scheduled_at: string | null;
    ai_model_used: string | null;
    ai_prompt: string | null;
    media: PostMedia[];
}

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

const props = defineProps<{
    post: PostData;
    platforms: Platform[];
    postTypes: PostTypeOption[];
    accounts: Array<{ id: number; platform: string; platform_label: string; platform_color: string; username: string; display_name: string }>;
    aiModels: AIModelOption[];
}>();

const form = useForm({
    title: props.post.title || '',
    caption: props.post.caption || '',
    hashtags: props.post.hashtags || [],
    type: props.post.type || 'feed',
    platforms: props.post.platforms || [],
    scheduled_at: props.post.scheduled_at || '',
    status: props.post.status || 'draft',
    media: [] as File[],
    remove_media: [] as number[],
});

// Existing media from DB
const existingMedia = ref<PostMedia[]>([...props.post.media]);

// New upload preview
const mediaInput = ref<HTMLInputElement | null>(null);
const mediaPreviews = ref<Array<{ file: File; url: string; type: string }>>([]);
const dragOver = ref(false);

// AI
const aiModalOpen = ref(false);
const aiTopic = ref('');
const aiTone = ref('');
const aiInstructions = ref('');
const aiModel = ref(props.aiModels[0]?.value || 'gpt-4o-mini');
const aiGenerating = ref(false);
const aiError = ref('');

// Hashtags
const hashtagInput = ref('');

// Char limits
const charLimits: Record<string, number> = {
    instagram: 2200, facebook: 63206, linkedin: 3000,
    tiktok: 2200, youtube: 5000, pinterest: 500,
};

const currentCharLimit = computed(() => {
    if (!form.platforms.length) return 2200;
    return Math.min(...form.platforms.map(p => charLimits[p] || 2200));
});

const captionLength = computed(() => form.caption.length);
const captionOverLimit = computed(() => captionLength.value > currentCharLimit.value);
const captionPercentage = computed(() => Math.min((captionLength.value / currentCharLimit.value) * 100, 100));

function togglePlatform(val: string) {
    const i = form.platforms.indexOf(val);
    i === -1 ? form.platforms.push(val) : form.platforms.splice(i, 1);
}

// Media
function triggerFileInput() { mediaInput.value?.click(); }

function onFileSelect(e: Event) {
    const t = e.target as HTMLInputElement;
    if (t.files) addFiles(Array.from(t.files));
}

function onDrop(e: DragEvent) {
    dragOver.value = false;
    if (e.dataTransfer?.files) addFiles(Array.from(e.dataTransfer.files));
}

function addFiles(files: File[]) {
    const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/quicktime', 'video/x-msvideo'];
    for (const file of files) {
        if (!validTypes.includes(file.type) || file.size > 50 * 1024 * 1024) continue;
        if (existingMedia.value.length + mediaPreviews.value.length >= 10) break;
        const url = URL.createObjectURL(file);
        mediaPreviews.value.push({ file, url, type: file.type.startsWith('video/') ? 'video' : 'image' });
        form.media.push(file);
    }
}

function removeNewMedia(index: number) {
    URL.revokeObjectURL(mediaPreviews.value[index].url);
    mediaPreviews.value.splice(index, 1);
    form.media.splice(index, 1);
}

function removeExistingMedia(index: number) {
    const media = existingMedia.value[index];
    form.remove_media.push(media.id);
    existingMedia.value.splice(index, 1);
}

// Hashtags
function addHashtag() {
    let tag = hashtagInput.value.trim();
    if (!tag) return;
    if (!tag.startsWith('#')) tag = '#' + tag;
    tag = tag.replace(/\s+/g, '');
    if (!form.hashtags.includes(tag)) form.hashtags.push(tag);
    hashtagInput.value = '';
}

function removeHashtag(i: number) { form.hashtags.splice(i, 1); }

// AI Generation
async function generateWithAI() {
    if (!aiTopic.value.trim()) { aiError.value = 'Informe o tema do post.'; return; }
    if (!form.platforms.length) { aiError.value = 'Selecione ao menos uma plataforma.'; return; }

    aiGenerating.value = true;
    aiError.value = '';

    try {
        const resp = await axios.post(route('social.generate'), {
            topic: aiTopic.value,
            platform: form.platforms[0],
            type: form.type,
            tone: aiTone.value || undefined,
            instructions: aiInstructions.value || undefined,
            model: aiModel.value,
        });

        form.caption = resp.data.caption;
        form.hashtags = resp.data.hashtags || [];
        aiModalOpen.value = false;
    } catch (e: any) {
        aiError.value = e.response?.data?.error || 'Erro ao gerar conteúdo.';
    } finally {
        aiGenerating.value = false;
    }
}

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

// Submit
function submit() {
    form.clearErrors();
    const formData = new FormData();
    formData.append('_method', 'PUT'); // Laravel method spoofing
    formData.append('title', form.title);
    formData.append('caption', form.caption);
    formData.append('type', form.type || 'feed');
    formData.append('status', form.status);
    if (form.scheduled_at) formData.append('scheduled_at', toLocalISO(form.scheduled_at));

    form.platforms.forEach((p, i) => formData.append(`platforms[${i}]`, p));
    form.hashtags.forEach((h, i) => formData.append(`hashtags[${i}]`, h));
    form.media.forEach((file, i) => formData.append(`media[${i}]`, file));
    form.remove_media.forEach((id, i) => formData.append(`remove_media[${i}]`, String(id)));

    router.post(route('social.posts.update', props.post.id), formData, {
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

const toneOptions = [
    { value: '', label: 'Automático' },
    { value: 'profissional', label: 'Profissional' },
    { value: 'informal', label: 'Informal / Descontraído' },
    { value: 'inspirador', label: 'Inspirador' },
    { value: 'educativo', label: 'Educativo' },
    { value: 'vendas', label: 'Vendas / Persuasivo' },
    { value: 'humor', label: 'Humorístico' },
    { value: 'tecnico', label: 'Técnico' },
];

const statusOptions = [
    { value: 'draft', label: 'Rascunho' },
    { value: 'scheduled', label: 'Agendado' },
    { value: 'approved', label: 'Aprovado' },
];
</script>

<template>
    <Head :title="'Editar Post - ' + (post.title || 'Sem título')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('social.posts.index')" class="text-gray-400 hover:text-white transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-xl font-semibold text-white">Editar Post</h1>
            </div>
        </template>

        <div class="max-w-4xl">
            <form @submit.prevent="submit" class="space-y-6">

                <!-- Plataformas -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Plataformas</h2>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-6">
                        <button
                            v-for="platform in platforms"
                            :key="platform.value"
                            type="button"
                            @click="togglePlatform(platform.value)"
                            :class="[
                                'flex flex-col items-center gap-2 rounded-xl border-2 p-4 transition-all',
                                form.platforms.includes(platform.value)
                                    ? 'border-current bg-current/10'
                                    : 'border-gray-700 hover:border-gray-600 bg-gray-800/50',
                            ]"
                            :style="form.platforms.includes(platform.value) ? { borderColor: platform.color, color: platform.color } : {}"
                        >
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor" :style="{ color: form.platforms.includes(platform.value) ? platform.color : '#9CA3AF' }">
                                <template v-if="platform.value === 'instagram'">
                                    <path d="M12 2c2.717 0 3.056.01 4.122.06 1.065.05 1.79.217 2.428.465.66.254 1.216.598 1.772 1.153a4.908 4.908 0 0 1 1.153 1.772c.247.637.415 1.363.465 2.428.047 1.066.06 1.405.06 4.122 0 2.717-.01 3.056-.06 4.122-.05 1.065-.218 1.79-.465 2.428a4.883 4.883 0 0 1-1.153 1.772 4.915 4.915 0 0 1-1.772 1.153c-.637.247-1.363.415-2.428.465-1.066.047-1.405.06-4.122.06-2.717 0-3.056-.01-4.122-.06-1.065-.05-1.79-.218-2.428-.465a4.89 4.89 0 0 1-1.772-1.153 4.904 4.904 0 0 1-1.153-1.772c-.248-.637-.415-1.363-.465-2.428C2.013 15.056 2 14.717 2 12c0-2.717.01-3.056.06-4.122.05-1.066.217-1.79.465-2.428a4.88 4.88 0 0 1 1.153-1.772A4.897 4.897 0 0 1 5.45 2.525c.638-.248 1.362-.415 2.428-.465C8.944 2.013 9.283 2 12 2zm0 5a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm6.5-.25a1.25 1.25 0 0 0-2.5 0 1.25 1.25 0 0 0 2.5 0zM12 9a3 3 0 1 1 0 6 3 3 0 0 1 0-6z"/>
                                </template>
                                <template v-else-if="platform.value === 'facebook'">
                                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                </template>
                                <template v-else-if="platform.value === 'linkedin'">
                                    <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                                </template>
                                <template v-else-if="platform.value === 'tiktok'">
                                    <path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>
                                </template>
                                <template v-else-if="platform.value === 'youtube'">
                                    <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                </template>
                                <template v-else-if="platform.value === 'pinterest'">
                                    <path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 0 1 .083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.988C24.007 5.367 18.641.001 12.017.001z"/>
                                </template>
                            </svg>
                            <span :class="['text-xs font-medium', form.platforms.includes(platform.value) ? '' : 'text-gray-400']">
                                {{ platform.label }}
                            </span>
                        </button>
                    </div>
                    <InputError :message="form.errors.platforms" class="mt-2" />
                </div>

                <!-- Tipo de Post -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Tipo de Post</h2>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-6">
                        <button
                            v-for="pt in postTypes"
                            :key="pt.value"
                            type="button"
                            @click="form.type = pt.value"
                            :class="[
                                'flex flex-col items-center gap-2 rounded-xl border-2 p-3 transition-all text-sm',
                                form.type === pt.value ? 'border-indigo-500 bg-indigo-500/10 text-indigo-400' : 'border-gray-700 hover:border-gray-600 text-gray-400',
                            ]"
                        >
                            <span class="font-medium">{{ pt.label }}</span>
                            <span class="text-[10px] text-gray-500">{{ pt.dimensions.width }}x{{ pt.dimensions.height }}</span>
                        </button>
                    </div>
                </div>

                <!-- Midia -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Mídia</h2>

                    <!-- Existing media -->
                    <div v-if="existingMedia.length" class="grid grid-cols-2 gap-3 sm:grid-cols-4 md:grid-cols-5 mb-4">
                        <div v-for="(media, index) in existingMedia" :key="'existing-'+media.id" class="relative group rounded-xl overflow-hidden bg-gray-800 aspect-square">
                            <img v-if="media.type === 'image' && media.file_path" :src="media.file_path" class="w-full h-full object-cover" />
                            <div v-else class="flex items-center justify-center h-full text-gray-500">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            </div>
                            <button type="button" @click="removeExistingMedia(index)" class="absolute top-1 right-1 p-1 rounded-lg bg-red-500/80 text-white opacity-0 group-hover:opacity-100 transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                            </button>
                        </div>
                    </div>

                    <!-- New uploads preview -->
                    <div v-if="mediaPreviews.length" class="grid grid-cols-2 gap-3 sm:grid-cols-4 md:grid-cols-5 mb-4">
                        <div v-for="(preview, index) in mediaPreviews" :key="'new-'+index" class="relative group rounded-xl overflow-hidden bg-gray-800 aspect-square">
                            <img v-if="preview.type === 'image'" :src="preview.url" class="w-full h-full object-cover" />
                            <video v-else :src="preview.url" class="w-full h-full object-cover" muted />
                            <button type="button" @click="removeNewMedia(index)" class="absolute top-1 right-1 p-1 rounded-lg bg-red-500/80 text-white opacity-0 group-hover:opacity-100 transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                            </button>
                            <span class="absolute top-1 left-1 rounded bg-green-500/80 px-1.5 py-0.5 text-[10px] text-white">Novo</span>
                        </div>
                    </div>

                    <!-- Drop zone -->
                    <div
                        @dragover.prevent="dragOver = true"
                        @dragleave="dragOver = false"
                        @drop.prevent="onDrop"
                        @click="triggerFileInput"
                        :class="[
                            'flex flex-col items-center justify-center rounded-xl border-2 border-dashed p-6 cursor-pointer transition-all',
                            dragOver ? 'border-indigo-500 bg-indigo-500/10' : 'border-gray-700 hover:border-gray-600 hover:bg-gray-800/50',
                        ]"
                    >
                        <svg class="w-8 h-8 text-gray-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                        </svg>
                        <p class="text-sm text-gray-400">Adicionar mais arquivos</p>
                        <input ref="mediaInput" type="file" multiple accept="image/jpeg,image/png,image/gif,image/webp,video/mp4" class="hidden" @change="onFileSelect" />
                    </div>
                </div>

                <!-- Legenda + Hashtags -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-white">Legenda</h2>
                        <button
                            type="button"
                            @click="aiModalOpen = true; aiError = ''"
                            class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 px-4 py-2 text-sm font-medium text-white hover:from-purple-700 hover:to-indigo-700 transition-all"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                            </svg>
                            Gerar com IA
                        </button>
                    </div>

                    <div class="mb-4">
                        <label class="text-sm text-gray-400 mb-1 block">Título (opcional)</label>
                        <input v-model="form.title" type="text" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Título interno..." />
                    </div>

                    <textarea
                        v-model="form.caption"
                        rows="6"
                        :class="['w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:ring-indigo-500 resize-y', captionOverLimit ? 'border-red-500 focus:border-red-500' : 'focus:border-indigo-500']"
                        placeholder="Legenda do post..."
                    />
                    <div class="flex items-center justify-between mt-1">
                        <InputError :message="form.errors.caption" />
                        <span :class="['text-xs', captionOverLimit ? 'text-red-400' : 'text-gray-500']">{{ captionLength }} / {{ currentCharLimit }}</span>
                    </div>
                    <div class="w-full h-1 bg-gray-800 rounded-full mt-1 overflow-hidden">
                        <div :class="['h-full rounded-full transition-all', captionOverLimit ? 'bg-red-500' : 'bg-indigo-500']" :style="{ width: captionPercentage + '%' }" />
                    </div>

                    <div class="mt-6">
                        <label class="text-sm text-gray-400 mb-2 block">Hashtags</label>
                        <div class="flex gap-2 mb-2">
                            <input v-model="hashtagInput" @keydown.enter.prevent="addHashtag" type="text" class="flex-1 rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="#exemplo" />
                            <button type="button" @click="addHashtag" class="rounded-xl bg-gray-800 border border-gray-700 px-4 text-gray-300 hover:bg-gray-700 text-sm transition">Adicionar</button>
                        </div>
                        <div v-if="form.hashtags.length" class="flex flex-wrap gap-2">
                            <span v-for="(tag, i) in form.hashtags" :key="i" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600/20 px-3 py-1 text-sm text-indigo-300">
                                {{ tag }}
                                <button type="button" @click="removeHashtag(i)" class="hover:text-red-400 transition">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                                </button>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Status + Agendamento -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Status e Agendamento</h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Status</label>
                            <select v-model="form.status" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option v-for="s in statusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Data e Hora</label>
                            <input v-model="form.scheduled_at" type="datetime-local" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-4 pb-8">
                    <Link :href="route('social.posts.index')" class="rounded-xl px-6 py-2.5 text-sm font-medium text-gray-400 hover:text-white transition">Cancelar</Link>
                    <button
                        type="submit"
                        :disabled="form.processing || !form.caption || !form.platforms.length"
                        class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50"
                    >
                        {{ form.processing ? 'Salvando...' : 'Salvar Alterações' }}
                    </button>
                </div>
            </form>
        </div>

        <!-- Modal IA -->
        <Teleport to="body">
            <div v-if="aiModalOpen" class="fixed inset-0 z-[60] flex items-center justify-center">
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="aiModalOpen = false" />
                <div class="relative w-full max-w-lg rounded-2xl bg-gray-900 border border-gray-700 p-6 shadow-2xl mx-4">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-semibold text-white">Gerar com IA</h3>
                        <button @click="aiModalOpen = false" class="text-gray-500 hover:text-white transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                        </button>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Tema *</label>
                            <input v-model="aiTopic" type="text" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Ex: Lançamento do novo produto..." />
                        </div>
                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Tom de Voz</label>
                            <select v-model="aiTone" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option v-for="t in toneOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Modelo</label>
                            <select v-model="aiModel" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option v-for="m in aiModels" :key="m.value" :value="m.value">{{ m.label }} ({{ m.provider }})</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Instruções extras</label>
                            <textarea v-model="aiInstructions" rows="2" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Instruções adicionais..." />
                        </div>
                        <div v-if="aiError" class="rounded-xl bg-red-500/10 border border-red-500/30 p-3 text-sm text-red-400">{{ aiError }}</div>
                        <button @click="generateWithAI" :disabled="aiGenerating" class="w-full rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 py-3 text-sm font-semibold text-white hover:from-purple-700 hover:to-indigo-700 transition disabled:opacity-50 flex items-center justify-center gap-2">
                            <svg v-if="aiGenerating" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            {{ aiGenerating ? 'Gerando...' : 'Gerar Legenda + Hashtags' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
