<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, watch, nextTick } from 'vue';
import axios from 'axios';

interface Block {
    type: string;
    label: string;
    config: Record<string, any>;
    visible: boolean;
    sort_order: number;
}

interface PageData {
    id: number; title: string; slug: string; description: string;
    avatar_path: string | null; theme: Record<string, string>;
    blocks: Block[]; seo_title: string | null; seo_description: string | null;
    seo_image: string | null; custom_css: string | null; is_active: boolean;
    public_url: string;
}

const props = defineProps<{ page: PageData }>();

const title = ref(props.page.title);
const slug = ref(props.page.slug);
const description = ref(props.page.description || '');
const blocks = ref<Block[]>([...(props.page.blocks || [])]);
const theme = ref({ ...props.page.theme });
const seoTitle = ref(props.page.seo_title || '');
const seoDescription = ref(props.page.seo_description || '');
const customCss = ref(props.page.custom_css || '');
const isActive = ref(props.page.is_active);
const saving = ref(false);
const saveResult = ref<string | null>(null);
const activePanel = ref<'blocks' | 'theme' | 'settings'>('blocks');
const editingBlockIndex = ref<number | null>(null);
const dragIndex = ref<number | null>(null);
const dragOverIndex = ref<number | null>(null);

// Blocos disponíveis para adicionar
const blockTypes = [
    { type: 'link', label: 'Link', icon: '🔗', description: 'Botão com link externo' },
    { type: 'header', label: 'Cabeçalho', icon: '📝', description: 'Título e subtítulo' },
    { type: 'social', label: 'Redes Sociais', icon: '📱', description: 'Ícones de redes sociais' },
    { type: 'text', label: 'Texto', icon: '📄', description: 'Parágrafo de texto livre' },
    { type: 'image', label: 'Imagem', icon: '🖼️', description: 'Imagem ou banner' },
    { type: 'video', label: 'Vídeo', icon: '🎬', description: 'Embed YouTube/Vimeo' },
    { type: 'divider', label: 'Divisor', icon: '➖', description: 'Linha separadora' },
    { type: 'email', label: 'E-mail', icon: '✉️', description: 'Botão mailto' },
    { type: 'phone', label: 'Telefone', icon: '📞', description: 'Botão tel:' },
    { type: 'whatsapp', label: 'WhatsApp', icon: '💬', description: 'Link direto WhatsApp' },
    { type: 'map', label: 'Mapa', icon: '📍', description: 'Google Maps embed' },
    { type: 'spotify', label: 'Spotify', icon: '🎵', description: 'Embed Spotify' },
];

const buttonStyles = ['rounded', 'pill', 'square', 'outline', 'shadow', 'gradient'];
const fontFamilies = ['Inter', 'Poppins', 'Roboto', 'Montserrat', 'Open Sans', 'Playfair Display', 'Space Grotesk'];

function addBlock(type: string) {
    const defaults: Record<string, any> = {
        link: { url: 'https://', icon: 'globe', highlight: false },
        header: { title: 'Título', subtitle: '', show_avatar: false },
        social: { networks: [{ platform: 'instagram', url: '' }, { platform: 'facebook', url: '' }] },
        text: { content: 'Texto aqui...' },
        image: { url: '', alt: '', link: '' },
        video: { embed_url: '', platform: 'youtube' },
        divider: { style: 'line' },
        email: { address: '', subject: '' },
        phone: { number: '' },
        whatsapp: { number: '', message: '' },
        map: { embed_url: '' },
        spotify: { embed_url: '' },
    };

    const newBlock: Block = {
        type,
        label: blockTypes.find(b => b.type === type)?.label || type,
        config: defaults[type] || {},
        visible: true,
        sort_order: blocks.value.length,
    };

    blocks.value.push(newBlock);
    editingBlockIndex.value = blocks.value.length - 1;
}

function removeBlock(index: number) {
    blocks.value.splice(index, 1);
    if (editingBlockIndex.value === index) editingBlockIndex.value = null;
    else if (editingBlockIndex.value !== null && editingBlockIndex.value > index) editingBlockIndex.value--;
    reindex();
}

function moveBlock(from: number, to: number) {
    if (to < 0 || to >= blocks.value.length) return;
    const item = blocks.value.splice(from, 1)[0];
    blocks.value.splice(to, 0, item);
    if (editingBlockIndex.value === from) editingBlockIndex.value = to;
    reindex();
}

function reindex() {
    blocks.value.forEach((b, i) => b.sort_order = i);
}

// Drag & drop
function onDragStart(index: number, e: DragEvent) {
    dragIndex.value = index;
    if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
}

function onDragOver(index: number, e: DragEvent) {
    e.preventDefault();
    dragOverIndex.value = index;
}

function onDragDrop(index: number) {
    if (dragIndex.value !== null && dragIndex.value !== index) {
        moveBlock(dragIndex.value, index);
    }
    dragIndex.value = null;
    dragOverIndex.value = null;
}

function onDragEnd() {
    dragIndex.value = null;
    dragOverIndex.value = null;
}

async function save() {
    saving.value = true;
    saveResult.value = null;
    try {
        const resp = await axios.put(route('links.save', props.page.id), {
            title: title.value,
            slug: slug.value,
            description: description.value,
            theme: theme.value,
            blocks: blocks.value,
            seo_title: seoTitle.value || null,
            seo_description: seoDescription.value || null,
            custom_css: customCss.value || null,
            is_active: isActive.value,
        });
        if (resp.data.success) {
            saveResult.value = 'success';
            setTimeout(() => saveResult.value = null, 3000);
        } else {
            saveResult.value = resp.data.error || 'Erro ao salvar.';
        }
    } catch (e: any) {
        saveResult.value = e.response?.data?.error || 'Erro de conexão.';
    } finally {
        saving.value = false;
    }
}

function copyUrl() {
    navigator.clipboard.writeText(props.page.public_url);
    saveResult.value = 'URL copiada!';
    setTimeout(() => saveResult.value = null, 2000);
}

function getBlockIcon(type: string): string {
    return blockTypes.find(b => b.type === type)?.icon || '📦';
}

const buttonStyleClass = computed(() => {
    const s = theme.value.button_style;
    return {
        'rounded': 'rounded-xl',
        'pill': 'rounded-full',
        'square': 'rounded-none',
        'outline': 'rounded-xl border-2 bg-transparent',
        'shadow': 'rounded-xl shadow-lg',
        'gradient': 'rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500',
    }[s] || 'rounded-xl';
});
</script>

<template>
    <Head :title="`Editor - ${page.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('links.index')" class="text-gray-500 hover:text-white transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                    </Link>
                    <h1 class="text-xl font-semibold text-white">Editor de Links</h1>
                </div>
                <div class="flex items-center gap-2">
                    <p v-if="saveResult === 'success'" class="text-xs text-emerald-400">Salvo!</p>
                    <p v-else-if="saveResult && saveResult !== 'URL copiada!'" class="text-xs text-red-400">{{ saveResult }}</p>
                    <p v-else-if="saveResult === 'URL copiada!'" class="text-xs text-indigo-400">{{ saveResult }}</p>
                    <button @click="copyUrl" class="rounded-xl border border-gray-700 px-3 py-2 text-xs text-gray-400 hover:text-white transition">
                        Copiar URL
                    </button>
                    <a :href="page.public_url" target="_blank" class="rounded-xl border border-gray-700 px-3 py-2 text-xs text-gray-400 hover:text-white transition">
                        Preview
                    </a>
                    <button @click="save" :disabled="saving" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                        {{ saving ? 'Salvando...' : 'Salvar' }}
                    </button>
                </div>
            </div>
        </template>

        <div class="flex gap-6 min-h-[calc(100vh-10rem)]">
            <!-- Left panel: Editor -->
            <div class="flex-1 min-w-0 space-y-4">
                <!-- Panel tabs -->
                <div class="flex gap-1 bg-gray-900 rounded-xl p-1">
                    <button v-for="tab in [{ key: 'blocks', label: 'Blocos' }, { key: 'theme', label: 'Tema' }, { key: 'settings', label: 'Config' }]"
                        :key="tab.key" @click="activePanel = tab.key as any"
                        :class="['flex-1 rounded-lg px-3 py-2 text-xs font-medium transition',
                            activePanel === tab.key ? 'bg-gray-800 text-white' : 'text-gray-500 hover:text-gray-300']">
                        {{ tab.label }}
                    </button>
                </div>

                <!-- Blocks panel -->
                <div v-if="activePanel === 'blocks'" class="space-y-3">
                    <!-- Block list -->
                    <div v-for="(block, index) in blocks" :key="index"
                        :class="['rounded-xl bg-gray-900 border transition cursor-grab active:cursor-grabbing',
                            editingBlockIndex === index ? 'border-indigo-500' : 'border-gray-800 hover:border-gray-700',
                            dragOverIndex === index ? 'border-dashed border-indigo-400' : '']"
                        draggable="true"
                        @dragstart="onDragStart(index, $event)" @dragover="onDragOver(index, $event)" @drop="onDragDrop(index)" @dragend="onDragEnd">

                        <!-- Block header -->
                        <div class="flex items-center gap-2 p-3 cursor-pointer" @click="editingBlockIndex = editingBlockIndex === index ? null : index">
                            <span class="text-xs shrink-0 opacity-50">⠿</span>
                            <span class="text-sm shrink-0">{{ getBlockIcon(block.type) }}</span>
                            <span class="text-sm text-white font-medium truncate flex-1">{{ block.label }}</span>
                            <span class="text-[10px] text-gray-600">{{ block.type }}</span>
                            <button @click.stop="block.visible = !block.visible" :class="['text-xs transition', block.visible ? 'text-emerald-400' : 'text-gray-600']" title="Visibilidade">
                                {{ block.visible ? '👁' : '🚫' }}
                            </button>
                            <button @click.stop="moveBlock(index, index - 1)" :disabled="index === 0" class="text-gray-600 hover:text-white transition disabled:opacity-30 text-xs">▲</button>
                            <button @click.stop="moveBlock(index, index + 1)" :disabled="index === blocks.length - 1" class="text-gray-600 hover:text-white transition disabled:opacity-30 text-xs">▼</button>
                            <button @click.stop="removeBlock(index)" class="text-red-500/50 hover:text-red-400 transition text-xs">✕</button>
                        </div>

                        <!-- Block editor (expanded) -->
                        <div v-if="editingBlockIndex === index" class="border-t border-gray-800 p-3 space-y-2">
                            <div>
                                <label class="text-[11px] text-gray-500">Label</label>
                                <input v-model="block.label" type="text" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>

                            <!-- Link block -->
                            <template v-if="block.type === 'link'">
                                <div>
                                    <label class="text-[11px] text-gray-500">URL</label>
                                    <input v-model="block.config.url" type="url" placeholder="https://..." class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                                <div class="flex items-center gap-2">
                                    <label class="text-[11px] text-gray-500 flex items-center gap-1.5">
                                        <input v-model="block.config.highlight" type="checkbox" class="rounded bg-gray-800 border-gray-600 text-indigo-500 focus:ring-indigo-500" />
                                        Destacar
                                    </label>
                                </div>
                            </template>

                            <!-- Header block -->
                            <template v-if="block.type === 'header'">
                                <div>
                                    <label class="text-[11px] text-gray-500">Título</label>
                                    <input v-model="block.config.title" type="text" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                                <div>
                                    <label class="text-[11px] text-gray-500">Subtítulo</label>
                                    <input v-model="block.config.subtitle" type="text" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                            </template>

                            <!-- Text block -->
                            <template v-if="block.type === 'text'">
                                <div>
                                    <label class="text-[11px] text-gray-500">Conteúdo</label>
                                    <textarea v-model="block.config.content" rows="3" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                            </template>

                            <!-- Video block -->
                            <template v-if="block.type === 'video'">
                                <div>
                                    <label class="text-[11px] text-gray-500">URL do Vídeo (YouTube/Vimeo)</label>
                                    <input v-model="block.config.embed_url" type="url" placeholder="https://youtube.com/watch?v=..." class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                            </template>

                            <!-- Email block -->
                            <template v-if="block.type === 'email'">
                                <div>
                                    <label class="text-[11px] text-gray-500">E-mail</label>
                                    <input v-model="block.config.address" type="email" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                            </template>

                            <!-- Phone block -->
                            <template v-if="block.type === 'phone'">
                                <div>
                                    <label class="text-[11px] text-gray-500">Telefone</label>
                                    <input v-model="block.config.number" type="tel" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                            </template>

                            <!-- WhatsApp block -->
                            <template v-if="block.type === 'whatsapp'">
                                <div>
                                    <label class="text-[11px] text-gray-500">Número (com DDI)</label>
                                    <input v-model="block.config.number" type="tel" placeholder="5511999999999" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                                <div>
                                    <label class="text-[11px] text-gray-500">Mensagem padrão</label>
                                    <input v-model="block.config.message" type="text" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                            </template>

                            <!-- Social block -->
                            <template v-if="block.type === 'social'">
                                <div v-for="(net, ni) in (block.config.networks || [])" :key="ni" class="flex gap-2 items-center">
                                    <select v-model="net.platform" class="rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 w-28">
                                        <option value="instagram">Instagram</option>
                                        <option value="facebook">Facebook</option>
                                        <option value="twitter">Twitter/X</option>
                                        <option value="tiktok">TikTok</option>
                                        <option value="youtube">YouTube</option>
                                        <option value="linkedin">LinkedIn</option>
                                        <option value="github">GitHub</option>
                                        <option value="pinterest">Pinterest</option>
                                    </select>
                                    <input v-model="net.url" type="url" placeholder="https://..." class="flex-1 rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                    <button @click="block.config.networks.splice(ni, 1)" class="text-red-400 text-xs hover:text-red-300">✕</button>
                                </div>
                                <button @click="block.config.networks = [...(block.config.networks || []), { platform: 'instagram', url: '' }]" type="button"
                                    class="text-[10px] text-indigo-400 hover:text-indigo-300">+ Rede</button>
                            </template>

                            <!-- Spotify/Map blocks -->
                            <template v-if="block.type === 'spotify' || block.type === 'map'">
                                <div>
                                    <label class="text-[11px] text-gray-500">URL ou Embed</label>
                                    <input v-model="block.config.embed_url" type="url" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                            </template>

                            <!-- Image block -->
                            <template v-if="block.type === 'image'">
                                <div>
                                    <label class="text-[11px] text-gray-500">URL da Imagem</label>
                                    <input v-model="block.config.url" type="url" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                                <div>
                                    <label class="text-[11px] text-gray-500">Link ao clicar</label>
                                    <input v-model="block.config.link" type="url" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Add block -->
                    <div class="rounded-xl bg-gray-900 border border-dashed border-gray-700 p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wider mb-3">Adicionar Bloco</p>
                        <div class="grid grid-cols-3 sm:grid-cols-4 gap-2">
                            <button v-for="bt in blockTypes" :key="bt.type" @click="addBlock(bt.type)" type="button"
                                class="rounded-xl bg-gray-800 border border-gray-700 p-2.5 text-center hover:border-indigo-500/50 hover:bg-gray-800/80 transition">
                                <span class="text-lg block">{{ bt.icon }}</span>
                                <span class="text-[10px] text-gray-400 block mt-0.5">{{ bt.label }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Theme panel -->
                <div v-if="activePanel === 'theme'" class="rounded-xl bg-gray-900 border border-gray-800 p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-white">Personalização Visual</h3>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-[11px] text-gray-500 mb-1 block">Cor de Fundo</label>
                            <div class="flex items-center gap-2">
                                <input v-model="theme.bg_color" type="color" class="w-8 h-8 rounded border border-gray-700 cursor-pointer" />
                                <input v-model="theme.bg_color" type="text" class="flex-1 rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5" />
                            </div>
                        </div>
                        <div>
                            <label class="text-[11px] text-gray-500 mb-1 block">Cor do Texto</label>
                            <div class="flex items-center gap-2">
                                <input v-model="theme.text_color" type="color" class="w-8 h-8 rounded border border-gray-700 cursor-pointer" />
                                <input v-model="theme.text_color" type="text" class="flex-1 rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5" />
                            </div>
                        </div>
                        <div>
                            <label class="text-[11px] text-gray-500 mb-1 block">Cor do Botão</label>
                            <div class="flex items-center gap-2">
                                <input v-model="theme.button_color" type="color" class="w-8 h-8 rounded border border-gray-700 cursor-pointer" />
                                <input v-model="theme.button_color" type="text" class="flex-1 rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5" />
                            </div>
                        </div>
                        <div>
                            <label class="text-[11px] text-gray-500 mb-1 block">Texto do Botão</label>
                            <div class="flex items-center gap-2">
                                <input v-model="theme.button_text_color" type="color" class="w-8 h-8 rounded border border-gray-700 cursor-pointer" />
                                <input v-model="theme.button_text_color" type="text" class="flex-1 rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5" />
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="text-[11px] text-gray-500 mb-1 block">Estilo dos Botões</label>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="style in buttonStyles" :key="style" @click="theme.button_style = style" type="button"
                                :class="['px-3 py-1.5 text-xs border transition', theme.button_style === style ? 'border-indigo-500 text-indigo-400' : 'border-gray-700 text-gray-500',
                                    style === 'rounded' ? 'rounded-xl' : style === 'pill' ? 'rounded-full' : style === 'square' ? 'rounded-none' : 'rounded-xl']">
                                {{ style }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="text-[11px] text-gray-500 mb-1 block">Fonte</label>
                        <select v-model="theme.font_family" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option v-for="f in fontFamilies" :key="f" :value="f">{{ f }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-[11px] text-gray-500 mb-1 block">Gradiente de Fundo</label>
                        <input v-model="theme.bg_gradient" type="text" placeholder="linear-gradient(135deg, #667eea 0%, #764ba2 100%)"
                            class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs px-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                </div>

                <!-- Settings panel -->
                <div v-if="activePanel === 'settings'" class="rounded-xl bg-gray-900 border border-gray-800 p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-white">Configurações</h3>
                    <div>
                        <label class="text-[11px] text-gray-500 mb-1 block">Título da Página</label>
                        <input v-model="title" type="text" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div>
                        <label class="text-[11px] text-gray-500 mb-1 block">Slug (URL)</label>
                        <div class="flex items-center gap-1">
                            <span class="text-xs text-gray-600">/l/</span>
                            <input v-model="slug" type="text" class="flex-1 rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        </div>
                    </div>
                    <div>
                        <label class="text-[11px] text-gray-500 mb-1 block">Descrição</label>
                        <textarea v-model="description" rows="2" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div>
                        <label class="text-[11px] text-gray-500 mb-1 block">SEO Título</label>
                        <input v-model="seoTitle" type="text" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div>
                        <label class="text-[11px] text-gray-500 mb-1 block">SEO Descrição</label>
                        <textarea v-model="seoDescription" rows="2" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div>
                        <label class="text-[11px] text-gray-500 mb-1 block">CSS Customizado</label>
                        <textarea v-model="customCss" rows="4" placeholder=".link-page { }" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-xs font-mono focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div class="flex items-center gap-2">
                        <input v-model="isActive" type="checkbox" class="rounded bg-gray-800 border-gray-600 text-indigo-500 focus:ring-indigo-500" />
                        <label class="text-sm text-gray-400">Página ativa (visível publicamente)</label>
                    </div>
                </div>
            </div>

            <!-- Right panel: Live Preview (phone mockup) -->
            <div class="w-80 shrink-0 hidden lg:block">
                <div class="sticky top-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider mb-2 text-center">Preview</p>
                    <div class="rounded-[2rem] border-4 border-gray-700 bg-black overflow-hidden" style="height: 640px;">
                        <div class="w-full h-full overflow-y-auto scrollbar-thin" :style="{
                            backgroundColor: theme.bg_color,
                            backgroundImage: theme.bg_gradient || 'none',
                            color: theme.text_color,
                            fontFamily: theme.font_family + ', sans-serif',
                        }">
                            <div class="p-5 space-y-3 text-center">
                                <!-- Avatar -->
                                <div v-if="page.avatar_path" class="w-20 h-20 mx-auto rounded-full overflow-hidden border-2" :style="{ borderColor: theme.button_color }">
                                    <img :src="'/storage/' + page.avatar_path" class="w-full h-full object-cover" />
                                </div>

                                <!-- Blocks preview -->
                                <template v-for="(block, i) in blocks" :key="i">
                                    <div v-if="block.visible">
                                        <!-- Header -->
                                        <div v-if="block.type === 'header'" class="py-2">
                                            <p class="text-lg font-bold" :style="{ color: theme.text_color }">{{ block.config.title }}</p>
                                            <p v-if="block.config.subtitle" class="text-sm opacity-70">{{ block.config.subtitle }}</p>
                                        </div>

                                        <!-- Link -->
                                        <div v-else-if="block.type === 'link'"
                                            :class="['w-full py-3 px-4 text-sm font-medium text-center transition cursor-pointer', buttonStyleClass]"
                                            :style="{
                                                backgroundColor: block.config.highlight ? theme.button_color : (theme.button_style === 'outline' ? 'transparent' : theme.button_color),
                                                color: theme.button_text_color,
                                                borderColor: theme.button_color,
                                            }">
                                            {{ block.label }}
                                        </div>

                                        <!-- Text -->
                                        <div v-else-if="block.type === 'text'" class="text-sm opacity-80 py-1">
                                            {{ block.config.content }}
                                        </div>

                                        <!-- Divider -->
                                        <hr v-else-if="block.type === 'divider'" class="border-gray-600 my-2" />

                                        <!-- Social -->
                                        <div v-else-if="block.type === 'social'" class="flex items-center justify-center gap-3 py-2">
                                            <span v-for="(net, ni) in (block.config.networks || [])" :key="ni"
                                                class="w-9 h-9 rounded-full flex items-center justify-center text-sm"
                                                :style="{ backgroundColor: theme.button_color + '33', color: theme.text_color }">
                                                {{ net.platform?.charAt(0).toUpperCase() }}
                                            </span>
                                        </div>

                                        <!-- Image -->
                                        <div v-else-if="block.type === 'image' && block.config.url" class="py-1">
                                            <img :src="block.config.url" class="rounded-xl w-full max-h-40 object-cover" />
                                        </div>

                                        <!-- Email/Phone/WhatsApp -->
                                        <div v-else-if="['email', 'phone', 'whatsapp'].includes(block.type)"
                                            :class="['w-full py-3 px-4 text-sm font-medium text-center', buttonStyleClass]"
                                            :style="{ backgroundColor: theme.button_color, color: theme.button_text_color }">
                                            {{ block.label }}
                                        </div>

                                        <!-- Generic -->
                                        <div v-else class="w-full py-3 px-4 text-xs text-center bg-gray-800/30 rounded-xl">
                                            {{ block.label }} ({{ block.type }})
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
