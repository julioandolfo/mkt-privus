<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

// Flash messages
const page = usePage();
const flashMessage = ref<string | null>(null);

onMounted(() => {
    const flash = (page.props as any).flash;
    if (flash?.success) {
        flashMessage.value = flash.success;
        setTimeout(() => { flashMessage.value = null; }, 6000);
    }
});

interface BrandAsset {
    id: number;
    category: string;
    label: string | null;
    file_path: string;
    file_name: string;
    mime_type: string | null;
    file_size: number;
    dimensions: { width: number; height: number } | null;
    is_primary: boolean;
    url: string | null;
}

interface BrandUrl {
    label: string;
    url: string;
    type: string;
}

interface Brand {
    id: number;
    name: string;
    description: string | null;
    website: string | null;
    urls: BrandUrl[] | null;
    segment: string | null;
    target_audience: string | null;
    tone_of_voice: string | null;
    primary_color: string | null;
    secondary_color: string | null;
    accent_color: string | null;
    keywords: string[] | null;
    ai_context: string | null;
    assets: BrandAsset[];
}

const props = defineProps<{
    brand: Brand;
}>();

const form = useForm({
    name: props.brand.name ?? '',
    description: props.brand.description ?? '',
    website: props.brand.website ?? '',
    urls: (props.brand.urls ?? []) as BrandUrl[],
    segment: props.brand.segment ?? '',
    target_audience: props.brand.target_audience ?? '',
    tone_of_voice: props.brand.tone_of_voice ?? 'profissional',
    primary_color: props.brand.primary_color ?? '#6366F1',
    secondary_color: props.brand.secondary_color ?? '#8B5CF6',
    accent_color: props.brand.accent_color ?? '#F59E0B',
    keywords: props.brand.keywords ?? [] as string[],
    ai_context: props.brand.ai_context ?? '',
});

const keywordInput = ref('');
const assets = ref<BrandAsset[]>(props.brand.assets ?? []);
const uploadCategory = ref('logo');
const uploadLabel = ref('');
const uploading = ref(false);
const dragOver = ref(false);

const categoryLabels: Record<string, string> = {
    logo: 'Logotipos',
    icon: 'Ícones',
    watermark: 'Marcas d\'água',
    reference: 'Referências Visuais',
};

const categoryDescriptions: Record<string, string> = {
    logo: 'Logotipos em diferentes formatos e orientações',
    icon: 'Ícones e favicons da marca',
    watermark: 'Marcas d\'água para aplicar em imagens',
    reference: 'Imagens de referência visual para a IA',
};

const assetsByCategory = computed(() => {
    const grouped: Record<string, BrandAsset[]> = {};
    for (const cat of ['logo', 'icon', 'watermark', 'reference']) {
        grouped[cat] = assets.value.filter(a => a.category === cat);
    }
    return grouped;
});

function addKeyword() {
    const keyword = keywordInput.value.trim();
    if (keyword && !form.keywords.includes(keyword)) {
        form.keywords.push(keyword);
        keywordInput.value = '';
    }
}

function removeKeyword(index: number) {
    form.keywords.splice(index, 1);
}

// URLs
function addUrl() {
    form.urls.push({ label: '', url: '', type: 'website' });
}

function removeUrl(index: number) {
    form.urls.splice(index, 1);
}

function submit() {
    form.put(route('brands.update', props.brand.id));
}

// ===== ASSETS =====

function handleDrop(event: DragEvent) {
    dragOver.value = false;
    const files = event.dataTransfer?.files;
    if (files) {
        uploadFiles(files);
    }
}

function handleFileSelect(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files) {
        uploadFiles(input.files);
        input.value = '';
    }
}

async function uploadFiles(files: FileList) {
    uploading.value = true;
    for (const file of Array.from(files)) {
        if (!file.type.startsWith('image/') && file.type !== 'image/svg+xml') continue;

        const formData = new FormData();
        formData.append('file', file);
        formData.append('category', uploadCategory.value);
        formData.append('label', uploadLabel.value || file.name.replace(/\.[^/.]+$/, ''));

        try {
            const response = await axios.post(
                route('brands.assets.upload', props.brand.id),
                formData,
                { headers: { 'Content-Type': 'multipart/form-data' } }
            );
            if (response.data.success) {
                assets.value.push(response.data.asset);
            }
        } catch (error: any) {
            console.error('Erro ao fazer upload:', error);
        }
    }
    uploading.value = false;
    uploadLabel.value = '';
}

async function deleteAsset(asset: BrandAsset) {
    if (!confirm(`Remover "${asset.label || asset.file_name}"?`)) return;

    try {
        await axios.delete(route('brands.assets.delete', [props.brand.id, asset.id]));
        assets.value = assets.value.filter(a => a.id !== asset.id);
    } catch (error) {
        console.error('Erro ao deletar:', error);
    }
}

async function setPrimary(asset: BrandAsset) {
    try {
        await axios.post(route('brands.assets.primary', [props.brand.id, asset.id]));
        // Atualizar estado local
        assets.value.forEach(a => {
            if (a.category === asset.category) {
                a.is_primary = a.id === asset.id;
            }
        });
    } catch (error) {
        console.error('Erro ao definir primário:', error);
    }
}

function formatFileSize(bytes: number): string {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' B';
}

const toneOptions = [
    { value: 'profissional', label: 'Profissional' },
    { value: 'informal', label: 'Informal' },
    { value: 'tecnico', label: 'Técnico' },
    { value: 'descontraido', label: 'Descontraído' },
    { value: 'inspirador', label: 'Inspirador' },
    { value: 'educativo', label: 'Educativo' },
    { value: 'autoritativo', label: 'Autoritativo' },
];

const urlTypeOptions = [
    { value: 'website', label: 'Site Principal' },
    { value: 'ecommerce', label: 'E-commerce / Loja' },
    { value: 'landing_page', label: 'Landing Page' },
    { value: 'blog', label: 'Blog' },
    { value: 'catalog', label: 'Catálogo de Produtos' },
    { value: 'linktree', label: 'Link Tree / Bio' },
    { value: 'other', label: 'Outro' },
];
</script>

<template>
    <Head :title="'Editar ' + brand.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('brands.index')" class="text-gray-400 hover:text-white transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-xl font-semibold text-white">Editar {{ brand.name }}</h1>
            </div>
        </template>

        <div class="max-w-3xl">
            <!-- Flash message (ex: apos criar marca) -->
            <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0" leave-active-class="transition ease-in duration-200" leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="flashMessage" class="mb-6 rounded-xl bg-emerald-600/20 border border-emerald-500/30 p-4 flex items-center gap-3">
                    <span class="text-emerald-400 text-lg">✓</span>
                    <p class="text-sm text-emerald-300 flex-1">{{ flashMessage }}</p>
                    <button @click="flashMessage = null" class="text-emerald-400/60 hover:text-emerald-300 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                    </button>
                </div>
            </Transition>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Informacoes Basicas -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-6">Informações Básicas</h2>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <InputLabel for="name" value="Nome da Marca" class="text-gray-300" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl" required placeholder="Ex: Minha Empresa" />
                            <InputError :message="form.errors.name" class="mt-2" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel for="description" value="Descrição" class="text-gray-300" />
                            <textarea id="description" v-model="form.description" rows="3" class="mt-1 block w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="Breve descrição da marca..." />
                            <InputError :message="form.errors.description" class="mt-2" />
                        </div>
                        <div>
                            <InputLabel for="website" value="Website" class="text-gray-300" />
                            <TextInput id="website" v-model="form.website" type="url" class="mt-1 block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl" placeholder="https://..." />
                            <InputError :message="form.errors.website" class="mt-2" />
                        </div>
                        <div>
                            <InputLabel for="segment" value="Segmento" class="text-gray-300" />
                            <TextInput id="segment" v-model="form.segment" type="text" class="mt-1 block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl" placeholder="Ex: Tecnologia, Saúde, Moda..." />
                            <InputError :message="form.errors.segment" class="mt-2" />
                        </div>
                    </div>
                </div>

                <!-- URLs e Sites -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <div class="flex items-center justify-between mb-2">
                        <h2 class="text-lg font-semibold text-white">URLs e Sites</h2>
                        <button
                            type="button"
                            @click="addUrl"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600/20 px-3 py-1.5 text-sm font-medium text-indigo-400 hover:bg-indigo-600/30 transition"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Adicionar URL
                        </button>
                    </div>
                    <p class="text-sm text-gray-500 mb-5">Sites, lojas e páginas da marca. A IA utilizará essas URLs para criar posts com links de produtos.</p>

                    <div v-if="form.urls.length === 0" class="rounded-xl border-2 border-dashed border-gray-700 p-8 text-center">
                        <svg class="mx-auto h-10 w-10 text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m9.86-1.06l4.5-4.5a4.5 4.5 0 00-6.364-6.364l-1.757 1.757" />
                        </svg>
                        <p class="text-sm text-gray-500">Nenhuma URL adicionada ainda.</p>
                        <p class="text-xs text-gray-600 mt-1">Adicione o site principal, e-commerce ou catálogo de produtos da marca.</p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="(urlEntry, index) in form.urls"
                            :key="index"
                            class="rounded-xl bg-gray-800 border border-gray-700 p-4"
                        >
                            <div class="flex items-start gap-3">
                                <div class="flex-1 grid grid-cols-1 gap-3 sm:grid-cols-12">
                                    <div class="sm:col-span-3">
                                        <select
                                            v-model="urlEntry.type"
                                            class="block w-full rounded-lg bg-gray-700 border-gray-600 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option v-for="opt in urlTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-3">
                                        <input
                                            v-model="urlEntry.label"
                                            type="text"
                                            class="block w-full rounded-lg bg-gray-700 border-gray-600 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            placeholder="Nome / Rótulo"
                                        />
                                    </div>
                                    <div class="sm:col-span-6">
                                        <input
                                            v-model="urlEntry.url"
                                            type="url"
                                            class="block w-full rounded-lg bg-gray-700 border-gray-600 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            placeholder="https://..."
                                        />
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    @click="removeUrl(index)"
                                    class="mt-1 rounded-lg p-1.5 text-gray-500 hover:text-red-400 hover:bg-red-500/10 transition"
                                    title="Remover URL"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                    </svg>
                                </button>
                            </div>
                            <InputError :message="(form.errors as any)[`urls.${index}.url`]" class="mt-2" />
                            <InputError :message="(form.errors as any)[`urls.${index}.label`]" class="mt-1" />
                        </div>
                    </div>
                </div>

                <!-- Identidade -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-6">Identidade e Tom de Voz</h2>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <InputLabel for="target_audience" value="Público-alvo" class="text-gray-300" />
                            <textarea id="target_audience" v-model="form.target_audience" rows="2" class="mt-1 block w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="Descreva o público-alvo da marca..." />
                            <InputError :message="form.errors.target_audience" class="mt-2" />
                        </div>
                        <div>
                            <InputLabel for="tone_of_voice" value="Tom de Voz" class="text-gray-300" />
                            <select id="tone_of_voice" v-model="form.tone_of_voice" class="mt-1 block w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                <option v-for="tone in toneOptions" :key="tone.value" :value="tone.value">{{ tone.label }}</option>
                            </select>
                            <InputError :message="form.errors.tone_of_voice" class="mt-2" />
                        </div>
                        <div class="sm:col-span-2 grid grid-cols-3 gap-4">
                            <div>
                                <InputLabel for="primary_color" value="Cor Primária" class="text-gray-300" />
                                <div class="mt-1 flex items-center gap-2">
                                    <input type="color" id="primary_color" v-model="form.primary_color" class="h-10 w-14 rounded-lg border border-gray-700 bg-gray-800 cursor-pointer" />
                                    <TextInput v-model="form.primary_color" class="block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl text-sm" />
                                </div>
                            </div>
                            <div>
                                <InputLabel for="secondary_color" value="Cor Secundária" class="text-gray-300" />
                                <div class="mt-1 flex items-center gap-2">
                                    <input type="color" id="secondary_color" v-model="form.secondary_color" class="h-10 w-14 rounded-lg border border-gray-700 bg-gray-800 cursor-pointer" />
                                    <TextInput v-model="form.secondary_color" class="block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl text-sm" />
                                </div>
                            </div>
                            <div>
                                <InputLabel for="accent_color" value="Cor de Destaque" class="text-gray-300" />
                                <div class="mt-1 flex items-center gap-2">
                                    <input type="color" id="accent_color" v-model="form.accent_color" class="h-10 w-14 rounded-lg border border-gray-700 bg-gray-800 cursor-pointer" />
                                    <TextInput v-model="form.accent_color" class="block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl text-sm" />
                                </div>
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Palavras-chave" class="text-gray-300" />
                            <div class="mt-1 flex gap-2">
                                <TextInput v-model="keywordInput" @keydown.enter.prevent="addKeyword" class="block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl" placeholder="Digite e pressione Enter..." />
                                <button type="button" @click="addKeyword" class="rounded-xl bg-gray-800 border border-gray-700 px-4 text-gray-300 hover:bg-gray-700 transition">Adicionar</button>
                            </div>
                            <div v-if="form.keywords.length" class="mt-3 flex flex-wrap gap-2">
                                <span v-for="(keyword, index) in form.keywords" :key="index" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600/20 px-3 py-1 text-sm text-indigo-300">
                                    {{ keyword }}
                                    <button type="button" @click="removeKeyword(index)" class="hover:text-red-400 transition">
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                                    </button>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Assets da Marca -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-2">Assets da Marca</h2>
                    <p class="text-sm text-gray-500 mb-6">Logotipos, ícones e imagens de referência para uso em posts e como contexto para a IA.</p>

                    <!-- Upload Area -->
                    <div class="mb-6 rounded-xl border-2 border-dashed transition"
                        :class="dragOver ? 'border-indigo-500 bg-indigo-500/10' : 'border-gray-700 bg-gray-800/50'"
                        @dragover.prevent="dragOver = true"
                        @dragleave="dragOver = false"
                        @drop.prevent="handleDrop"
                    >
                        <div class="p-6 text-center">
                            <svg class="mx-auto h-10 w-10 text-gray-500 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
                            </svg>
                            <p class="text-sm text-gray-400 mb-3">Arraste imagens aqui ou clique para selecionar</p>
                            <div class="flex items-center justify-center gap-3 flex-wrap">
                                <select v-model="uploadCategory" class="rounded-lg bg-gray-700 border-gray-600 text-white text-sm px-3 py-1.5">
                                    <option value="logo">Logotipo</option>
                                    <option value="icon">Ícone</option>
                                    <option value="watermark">Marca d'água</option>
                                    <option value="reference">Referência</option>
                                </select>
                                <input v-model="uploadLabel" type="text" placeholder="Nome (opcional)" class="rounded-lg bg-gray-700 border-gray-600 text-white text-sm px-3 py-1.5 w-40" />
                                <label class="cursor-pointer rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 transition">
                                    {{ uploading ? 'Enviando...' : 'Selecionar' }}
                                    <input type="file" class="hidden" accept="image/*" multiple @change="handleFileSelect" :disabled="uploading" />
                                </label>
                            </div>
                            <p class="text-[11px] text-gray-600 mt-2">JPEG, PNG, GIF, WebP, SVG — máx. 10MB</p>
                        </div>
                    </div>

                    <!-- Assets por Categoria -->
                    <div v-for="(catAssets, category) in assetsByCategory" :key="category" class="mb-5 last:mb-0">
                        <div v-if="catAssets.length > 0" class="mb-3">
                            <h3 class="text-sm font-medium text-gray-300">{{ categoryLabels[category] }}</h3>
                            <p class="text-[11px] text-gray-600">{{ categoryDescriptions[category] }}</p>
                        </div>
                        <div v-if="catAssets.length > 0" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            <div v-for="asset in catAssets" :key="asset.id" class="group relative rounded-xl border overflow-hidden transition"
                                :class="asset.is_primary ? 'border-indigo-500 bg-indigo-500/5' : 'border-gray-700 bg-gray-800'"
                            >
                                <div class="aspect-square bg-gray-800 flex items-center justify-center overflow-hidden">
                                    <img v-if="asset.url" :src="asset.url" :alt="asset.label || asset.file_name" class="max-h-full max-w-full object-contain p-2" />
                                </div>
                                <div class="p-2">
                                    <p class="text-xs text-gray-300 truncate">{{ asset.label || asset.file_name }}</p>
                                    <p class="text-[10px] text-gray-600">
                                        {{ formatFileSize(asset.file_size) }}
                                        <span v-if="asset.dimensions"> &middot; {{ asset.dimensions.width }}x{{ asset.dimensions.height }}</span>
                                    </p>
                                </div>
                                <!-- Badge primario -->
                                <span v-if="asset.is_primary" class="absolute top-1.5 left-1.5 rounded-md bg-indigo-600 px-1.5 py-0.5 text-[9px] font-bold text-white">
                                    Principal
                                </span>
                                <!-- Actions overlay -->
                                <div class="absolute inset-0 flex items-center justify-center gap-2 bg-black/60 opacity-0 group-hover:opacity-100 transition">
                                    <button v-if="!asset.is_primary" type="button" @click="setPrimary(asset)" class="rounded-lg bg-indigo-600 px-2 py-1 text-[10px] font-medium text-white hover:bg-indigo-700 transition" title="Definir como principal">
                                        Principal
                                    </button>
                                    <button type="button" @click="deleteAsset(asset)" class="rounded-lg bg-red-600 px-2 py-1 text-[10px] font-medium text-white hover:bg-red-700 transition" title="Remover">
                                        Remover
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p v-if="assets.length === 0" class="text-center text-sm text-gray-600 py-4">Nenhum asset adicionado ainda.</p>
                </div>

                <!-- Contexto IA -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-2">Contexto para IA</h2>
                    <p class="text-sm text-gray-500 mb-4">Informações adicionais que serão enviadas como contexto quando a IA gerar conteúdo para esta marca.</p>
                    <textarea id="ai_context" v-model="form.ai_context" rows="4" class="block w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="Ex: Nossa empresa atua no mercado desde 2010, focamos em soluções sustentáveis..." />
                    <InputError :message="form.errors.ai_context" class="mt-2" />
                </div>

                <!-- Preview -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Preview</h2>
                    <div class="flex items-center gap-4 rounded-xl bg-gray-800 p-4">
                        <div class="flex h-14 w-14 items-center justify-center rounded-xl text-xl font-bold text-white" :style="{ backgroundColor: form.primary_color }">
                            {{ form.name ? form.name.charAt(0).toUpperCase() : 'M' }}
                        </div>
                        <div>
                            <p class="font-semibold text-white text-lg">{{ form.name || 'Nome da Marca' }}</p>
                            <p class="text-sm text-gray-400">{{ form.segment || 'Segmento' }} &middot; Tom: {{ form.tone_of_voice }}</p>
                        </div>
                        <div class="ml-auto flex gap-2">
                            <div class="h-8 w-8 rounded-lg" :style="{ backgroundColor: form.primary_color }" :title="'Primária: ' + form.primary_color" />
                            <div class="h-8 w-8 rounded-lg" :style="{ backgroundColor: form.secondary_color }" :title="'Secundária: ' + form.secondary_color" />
                            <div class="h-8 w-8 rounded-lg" :style="{ backgroundColor: form.accent_color }" :title="'Destaque: ' + form.accent_color" />
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-4">
                    <Link :href="route('brands.index')" class="rounded-xl px-6 py-2.5 text-sm font-medium text-gray-400 hover:text-white transition">Cancelar</Link>
                    <button type="submit" :disabled="form.processing" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                        {{ form.processing ? 'Salvando...' : 'Salvar Alterações' }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
