<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const postsGuideSteps = [
    { title: 'Crie manualmente ou com IA', description: 'Clique em "+ Novo Post" para criar. Use o botão "Gerar com IA" para que a inteligência artificial crie legenda e hashtags automaticamente.' },
    { title: 'Selecione plataformas', description: 'Cada post pode ser publicado em múltiplas redes simultaneamente (Instagram, Facebook, LinkedIn, TikTok, YouTube, Pinterest).' },
    { title: 'Agende a publicação', description: 'Defina data e hora para publicação automática. Sem data, o post fica como rascunho para revisão futura.' },
    { title: 'Acompanhe o status', description: 'Os cards mostram o status atual: Rascunho (cinza), Agendado (azul), Publicando (laranja), Publicado (verde), Falhou (vermelho).' },
];

const postsGuideTips = [
    'Use os filtros de status e plataforma para encontrar posts rapidamente.',
    'Duplique posts existentes para criar variações sem começar do zero.',
    'O Content Engine pode gerar sugestões de posts automaticamente - acesse pelo menu acima.',
    'O Autopilot monitora e publica posts agendados automaticamente a cada minuto.',
];
import { ref, computed } from 'vue';
import axios from 'axios';

// Modal de preview
const previewPost = ref<Post | null>(null);
const previewMediaIndex = ref(0);

function openPreview(post: Post) {
    previewPost.value = post;
    previewMediaIndex.value = 0;
    document.body.style.overflow = 'hidden';
}

function closePreview() {
    previewPost.value = null;
    document.body.style.overflow = '';
}

function prevMedia() {
    if (!previewPost.value) return;
    const len = previewPost.value.media.length;
    previewMediaIndex.value = (previewMediaIndex.value - 1 + len) % len;
}

function nextMedia() {
    if (!previewPost.value) return;
    const len = previewPost.value.media.length;
    previewMediaIndex.value = (previewMediaIndex.value + 1) % len;
}

interface PostMedia {
    id: number;
    type: string;
    file_path: string | null;
    file_name: string;
    alt_text: string | null;
}

interface Post {
    id: number;
    title: string | null;
    caption: string;
    hashtags: string[];
    type: string | null;
    type_label: string | null;
    status: string;
    status_label: string;
    status_color: string;
    platforms: string[];
    scheduled_at: string | null;
    published_at: string | null;
    created_at: string;
    user_name: string | null;
    media: PostMedia[];
}

interface Props {
    posts: {
        data: Post[];
        links: any[];
        current_page: number;
        last_page: number;
    };
    filters: Record<string, string>;
    stats: { drafts: number; scheduled: number; published: number; failed: number };
    platforms: Array<{ value: string; label: string; color: string }>;
    statuses: Array<{ value: string; label: string; color: string }>;
}

const props = defineProps<Props>();
const page = usePage();
const currentBrand = computed(() => page.props.currentBrand);

const publishingId = ref<number | null>(null);
const republishingId = ref<number | null>(null);

const filterStatus = ref(props.filters?.status || '');
const filterPlatform = ref(props.filters?.platform || '');
const filterSearch = ref(props.filters?.search || '');

const platformColors: Record<string, string> = {
    instagram: '#E4405F',
    facebook: '#1877F2',
    linkedin: '#0A66C2',
    tiktok: '#000000',
    youtube: '#FF0000',
    pinterest: '#BD081C',
};

const statusColorClasses: Record<string, string> = {
    gray: 'bg-gray-500/20 text-gray-400 border-gray-500/30',
    yellow: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    blue: 'bg-blue-500/20 text-blue-400 border-blue-500/30',
    indigo: 'bg-indigo-500/20 text-indigo-400 border-indigo-500/30',
    orange: 'bg-orange-500/20 text-orange-400 border-orange-500/30',
    green: 'bg-green-500/20 text-green-400 border-green-500/30',
    red: 'bg-red-500/20 text-red-400 border-red-500/30',
};

function applyFilters() {
    const params: Record<string, string> = {};
    if (filterStatus.value) params.status = filterStatus.value;
    if (filterPlatform.value) params.platform = filterPlatform.value;
    if (filterSearch.value) params.search = filterSearch.value;

    router.get(route('social.posts.index'), params, { preserveState: true });
}

function clearFilters() {
    filterStatus.value = '';
    filterPlatform.value = '';
    filterSearch.value = '';
    router.get(route('social.posts.index'));
}

function deletePost(postId: number) {
    if (confirm('Tem certeza que deseja excluir este post?')) {
        router.delete(route('social.posts.destroy', postId));
    }
}

function duplicatePost(postId: number) {
    router.post(route('social.posts.duplicate', postId));
}

async function publishNow(post: Post) {
    if (!confirm(`Publicar "${post.title || 'este post'}" agora nas plataformas: ${post.platforms.join(', ')}?`)) return;

    publishingId.value = post.id;
    try {
        await axios.post(route('social.posts.publish-now', post.id));
        router.reload({ preserveScroll: true });
    } catch (err: any) {
        const msg = err?.response?.data?.message || err?.response?.data?.errors?.accounts || 'Erro ao publicar.';
        alert(typeof msg === 'object' ? Object.values(msg).join('\n') : msg);
    } finally {
        publishingId.value = null;
    }
}

async function republish(post: Post) {
    if (!confirm(`Republicar "${post.title || 'este post'}" nas plataformas: ${post.platforms.join(', ')}?\n\nIsso vai criar uma nova publicação nas redes sociais.`)) return;

    republishingId.value = post.id;
    try {
        await axios.post(route('social.posts.republish', post.id));
        router.reload({ preserveScroll: true });
    } catch (err: any) {
        const msg = err?.response?.data?.message || 'Erro ao republicar.';
        alert(typeof msg === 'object' ? Object.values(msg).join('\n') : msg);
    } finally {
        republishingId.value = null;
    }
}

function truncate(text: string, length: number): string {
    if (!text) return '';
    return text.length > length ? text.substring(0, length) + '...' : text;
}

function getPlatformLabel(value: string): string {
    return props.platforms.find(p => p.value === value)?.label || value;
}
</script>

<template>
    <Head title="Social - Posts" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-white">Posts</h1>
                <div class="flex items-center gap-3">
                    <Link
                        :href="route('social.calendar.index')"
                        class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition"
                    >
                        Calendário
                    </Link>
                    <Link
                        :href="route('social.content-engine.index')"
                        class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition"
                    >
                        Content Engine
                    </Link>
                    <Link
                        :href="route('social.autopilot.index')"
                        class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition"
                    >
                        Autopilot
                    </Link>
                    <Link
                        :href="route('social.accounts.index')"
                        class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition"
                    >
                        <span class="flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><line x1="8.59" y1="13.51" x2="15.42" y2="17.49" /><line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
                            </svg>
                            Contas
                        </span>
                    </Link>
                    <Link
                        :href="route('social.posts.create')"
                        class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition"
                    >
                        + Novo Post
                    </Link>
                </div>
            </div>
        </template>

        <!-- Aviso sem marca -->
        <div v-if="!currentBrand" class="rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" />
            </svg>
            <h3 class="mt-4 text-lg font-medium text-gray-300">Nenhuma marca selecionada</h3>
            <p class="mt-2 text-sm text-gray-500">Selecione uma marca para gerenciar os posts.</p>
        </div>

        <template v-else>
            <GuideBox
                title="Como gerenciar seus posts"
                description="Aqui você cria, organiza e acompanha todos os posts das redes sociais da marca ativa."
                :steps="postsGuideSteps"
                :tips="postsGuideTips"
                color="indigo"
                storage-key="posts-guide"
                class="mb-6"
            />

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-6">
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-gray-300">{{ stats.drafts }}</p>
                    <p class="text-xs text-gray-500 mt-1">Rascunhos</p>
                </div>
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-indigo-400">{{ stats.scheduled }}</p>
                    <p class="text-xs text-gray-500 mt-1">Agendados</p>
                </div>
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-green-400">{{ stats.published }}</p>
                    <p class="text-xs text-gray-500 mt-1">Publicados</p>
                </div>
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4">
                    <p class="text-2xl font-bold text-red-400">{{ stats.failed }}</p>
                    <p class="text-xs text-gray-500 mt-1">Falharam</p>
                </div>
            </div>

            <!-- Filtros -->
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-4 mb-6">
                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <label class="text-xs text-gray-500 mb-1 block">Buscar</label>
                        <input
                            v-model="filterSearch"
                            type="text"
                            placeholder="Buscar por título ou legenda..."
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            @keydown.enter="applyFilters"
                        />
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">Status</label>
                        <select
                            v-model="filterStatus"
                            @change="applyFilters"
                            class="rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">Todos</option>
                            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">Plataforma</label>
                        <select
                            v-model="filterPlatform"
                            @change="applyFilters"
                            class="rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">Todas</option>
                            <option v-for="p in platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
                        </select>
                    </div>
                    <button
                        @click="clearFilters"
                        class="rounded-xl px-4 py-2 text-sm text-gray-400 hover:text-white hover:bg-gray-800 transition"
                    >
                        Limpar
                    </button>
                </div>
            </div>

            <!-- Grid de Posts -->
            <div v-if="posts.data.length" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="post in posts.data"
                    :key="post.id"
                    class="rounded-2xl bg-gray-900 border border-gray-800 overflow-hidden hover:border-gray-700 transition group"
                >
                    <!-- Media Preview -->
                    <div class="relative h-40 bg-gray-800">
                        <template v-if="post.media.length && post.media[0].file_path">
                            <!-- Vídeo: preload="metadata" carrega o primeiro frame como thumbnail -->
                            <template v-if="post.media[0].type === 'video'">
                                <video
                                    :src="post.media[0].file_path"
                                    class="w-full h-full object-cover"
                                    preload="metadata"
                                    muted
                                    playsinline
                                />
                                <!-- Ícone de play sobre o vídeo -->
                                <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                                    <div class="w-10 h-10 rounded-full bg-black/50 flex items-center justify-center">
                                        <svg class="w-5 h-5 text-white ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M8 5v14l11-7z"/>
                                        </svg>
                                    </div>
                                </div>
                            </template>
                            <!-- Imagem -->
                            <img
                                v-else
                                :src="post.media[0].file_path"
                                :alt="post.media[0].alt_text || 'Preview'"
                                class="w-full h-full object-cover"
                            />
                        </template>
                        <div v-else class="flex items-center justify-center h-full">
                            <svg class="w-10 h-10 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2" /><circle cx="8.5" cy="8.5" r="1.5" /><polyline points="21 15 16 10 5 21" />
                            </svg>
                        </div>

                        <!-- Media count badge -->
                        <span v-if="post.media.length > 1" class="absolute top-2 right-2 rounded-lg bg-black/60 px-2 py-1 text-xs text-white">
                            +{{ post.media.length - 1 }}
                        </span>

                        <!-- Status badge -->
                        <span
                            :class="['absolute top-2 left-2 rounded-lg border px-2 py-1 text-xs font-medium', statusColorClasses[post.status_color] || statusColorClasses.gray]"
                        >
                            {{ post.status_label }}
                        </span>

                        <!-- Type badge -->
                        <span v-if="post.type_label" class="absolute bottom-2 left-2 rounded-lg bg-black/60 px-2 py-1 text-xs text-gray-300">
                            {{ post.type_label }}
                        </span>
                    </div>

                    <!-- Content -->
                    <div class="p-4">
                        <h3 v-if="post.title" class="text-sm font-semibold text-white mb-1 truncate">{{ post.title }}</h3>
                        <p class="text-sm text-gray-400 line-clamp-2 mb-3">{{ truncate(post.caption, 120) }}</p>

                        <!-- Platforms -->
                        <div class="flex items-center gap-1.5 mb-3">
                            <span
                                v-for="platform in post.platforms"
                                :key="platform"
                                class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-medium text-white"
                                :style="{ backgroundColor: platformColors[platform] || '#6B7280' }"
                            >
                                {{ getPlatformLabel(platform) }}
                            </span>
                        </div>

                        <!-- Footer -->
                        <div class="flex items-center justify-between pt-3 border-t border-gray-800">
                            <div class="text-xs text-gray-500">
                                <span v-if="post.scheduled_at">Agendado: {{ post.scheduled_at }}</span>
                                <span v-else>Criado: {{ post.created_at }}</span>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition">
                                <!-- Visualizar -->
                                <button
                                    @click="openPreview(post)"
                                    class="p-1.5 rounded-lg text-gray-500 hover:text-purple-400 hover:bg-gray-800 transition"
                                    title="Visualizar"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                    </svg>
                                </button>

                                <!-- Publicar Agora: draft / pending_review / approved / scheduled / failed -->
                                <button
                                    v-if="['draft', 'pending_review', 'approved', 'scheduled', 'failed'].includes(post.status)"
                                    @click="publishNow(post)"
                                    :disabled="publishingId === post.id"
                                    class="p-1.5 rounded-lg text-gray-500 hover:text-green-400 hover:bg-gray-800 transition disabled:opacity-50"
                                    title="Publicar Agora"
                                >
                                    <svg v-if="publishingId === post.id" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" />
                                    </svg>
                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" /><polygon points="10 8 16 12 10 16 10 8" fill="currentColor" stroke="none" />
                                    </svg>
                                </button>

                                <!-- Republicar: somente para published/failed -->
                                <button
                                    v-if="['published', 'failed'].includes(post.status)"
                                    @click="republish(post)"
                                    :disabled="republishingId === post.id"
                                    class="p-1.5 rounded-lg text-gray-500 hover:text-blue-400 hover:bg-gray-800 transition disabled:opacity-50"
                                    title="Republicar"
                                >
                                    <svg v-if="republishingId === post.id" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" />
                                    </svg>
                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"/>
                                    </svg>
                                </button>
                                <Link
                                    :href="route('social.posts.edit', post.id)"
                                    class="p-1.5 rounded-lg text-gray-500 hover:text-indigo-400 hover:bg-gray-800 transition"
                                    title="Editar"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                    </svg>
                                </Link>
                                <button
                                    @click="duplicatePost(post.id)"
                                    class="p-1.5 rounded-lg text-gray-500 hover:text-blue-400 hover:bg-gray-800 transition"
                                    title="Duplicar"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                                    </svg>
                                </button>
                                <button
                                    @click="deletePost(post.id)"
                                    class="p-1.5 rounded-lg text-gray-500 hover:text-red-400 hover:bg-gray-800 transition"
                                    title="Excluir"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2" /><circle cx="8.5" cy="8.5" r="1.5" /><polyline points="21 15 16 10 5 21" />
                </svg>
                <h3 class="mt-4 text-lg font-medium text-gray-300">Nenhum post encontrado</h3>
                <p class="mt-2 text-sm text-gray-500">Crie seu primeiro post com ajuda da IA.</p>
                <Link
                    :href="route('social.posts.create')"
                    class="mt-6 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Criar Primeiro Post
                </Link>
            </div>

            <!-- Paginacao simples -->
            <div v-if="posts.last_page > 1" class="flex items-center justify-center gap-2 mt-6">
                <template v-for="link in posts.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-sm transition',
                            link.active ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800',
                        ]"
                        v-html="link.label"
                    />
                    <span v-else class="px-3 py-1.5 text-sm text-gray-600" v-html="link.label" />
                </template>
            </div>
        </template>
    </AuthenticatedLayout>

    <!-- Modal de Preview do Post -->
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="previewPost"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                @click.self="closePreview"
            >
                <!-- Backdrop -->
                <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="closePreview" />

                <!-- Modal -->
                <div class="relative z-10 w-full max-w-sm bg-white rounded-2xl overflow-hidden shadow-2xl">

                    <!-- Header estilo Instagram -->
                    <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-100">
                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-purple-500 via-pink-500 to-orange-400 flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                            {{ (previewPost.title || previewPost.caption || 'P').charAt(0).toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-900 truncate">{{ previewPost.title || 'Post' }}</p>
                            <div class="flex items-center gap-1 flex-wrap">
                                <span
                                    v-for="platform in previewPost.platforms"
                                    :key="platform"
                                    class="text-[10px] font-medium px-1.5 py-0.5 rounded text-white"
                                    :style="{ backgroundColor: platformColors[platform] || '#6B7280' }"
                                >
                                    {{ getPlatformLabel(platform) }}
                                </span>
                            </div>
                        </div>
                        <!-- Status badge -->
                        <span :class="['text-xs px-2 py-1 rounded-full font-medium border', statusColorClasses[previewPost.status_color] || statusColorClasses.gray]">
                            {{ previewPost.status_label }}
                        </span>
                        <!-- Fechar -->
                        <button @click="closePreview" class="p-1 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition flex-shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Mídia -->
                    <div class="relative bg-black aspect-square">
                        <!-- Imagem/Vídeo -->
                        <template v-if="previewPost.media.length">
                            <template v-if="previewPost.media[previewMediaIndex]?.type === 'video'">
                                <video
                                    :key="previewMediaIndex"
                                    :src="previewPost.media[previewMediaIndex].file_path || ''"
                                    class="w-full h-full object-contain"
                                    controls
                                    autoplay
                                    muted
                                />
                            </template>
                            <template v-else>
                                <img
                                    :key="previewMediaIndex"
                                    :src="previewPost.media[previewMediaIndex]?.file_path || ''"
                                    :alt="previewPost.media[previewMediaIndex]?.alt_text || 'Post'"
                                    class="w-full h-full object-contain"
                                />
                            </template>

                            <!-- Navegação carrossel -->
                            <template v-if="previewPost.media.length > 1">
                                <button
                                    @click="prevMedia"
                                    class="absolute left-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/50 text-white flex items-center justify-center hover:bg-black/70 transition"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                                </button>
                                <button
                                    @click="nextMedia"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/50 text-white flex items-center justify-center hover:bg-black/70 transition"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                                <!-- Dots indicadores -->
                                <div class="absolute bottom-3 left-0 right-0 flex justify-center gap-1.5">
                                    <button
                                        v-for="(_, i) in previewPost.media"
                                        :key="i"
                                        @click="previewMediaIndex = i"
                                        :class="['w-1.5 h-1.5 rounded-full transition', i === previewMediaIndex ? 'bg-white' : 'bg-white/40']"
                                    />
                                </div>
                            </template>
                        </template>

                        <!-- Sem mídia -->
                        <div v-else class="w-full h-full flex items-center justify-center bg-gradient-to-br from-gray-800 to-gray-900">
                            <div class="text-center text-gray-400">
                                <svg class="w-12 h-12 mx-auto mb-2 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
                                </svg>
                                <p class="text-xs opacity-50">Sem imagem</p>
                            </div>
                        </div>
                    </div>

                    <!-- Ações estilo Instagram -->
                    <div class="px-4 pt-3 pb-1 flex items-center gap-4">
                        <svg class="w-6 h-6 text-gray-700 cursor-pointer hover:text-red-500 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                        <svg class="w-6 h-6 text-gray-700 cursor-pointer hover:text-gray-900 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <svg class="w-6 h-6 text-gray-700 cursor-pointer hover:text-gray-900 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        <svg class="w-6 h-6 text-gray-700 cursor-pointer hover:text-gray-900 transition ml-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                    </div>

                    <!-- Caption -->
                    <div class="px-4 pb-4">
                        <!-- Legenda completa -->
                        <p class="text-sm text-gray-900 leading-relaxed mt-1">
                            <span class="font-semibold mr-1">{{ previewPost.title || 'post' }}</span>
                            {{ previewPost.caption }}
                        </p>

                        <!-- Hashtags -->
                        <p v-if="previewPost.hashtags?.length" class="text-sm text-blue-500 mt-1">
                            {{ previewPost.hashtags.map(h => h.startsWith('#') ? h : `#${h}`).join(' ') }}
                        </p>

                        <!-- Data/hora -->
                        <p class="text-[11px] text-gray-400 mt-2 uppercase tracking-wide">
                            <span v-if="previewPost.published_at">Publicado em {{ previewPost.published_at }}</span>
                            <span v-else-if="previewPost.scheduled_at">Agendado: {{ previewPost.scheduled_at }}</span>
                            <span v-else>Criado: {{ previewPost.created_at }}</span>
                        </p>
                    </div>

                    <!-- Footer ações -->
                    <div class="flex border-t border-gray-100">
                        <Link
                            :href="route('social.posts.edit', previewPost.id)"
                            class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-gray-600 hover:bg-gray-50 transition"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            Editar
                        </Link>
                        <button
                            v-if="['draft', 'pending_review', 'approved', 'scheduled', 'failed'].includes(previewPost.status)"
                            @click="closePreview(); publishNow(previewPost)"
                            class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-green-600 hover:bg-green-50 transition border-l border-gray-100"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8" fill="currentColor" stroke="none"/></svg>
                            Publicar
                        </button>
                        <button
                            v-else-if="['published', 'failed'].includes(previewPost.status)"
                            @click="closePreview(); republish(previewPost)"
                            class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-blue-600 hover:bg-blue-50 transition border-l border-gray-100"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
                            Republicar
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-active .relative,
.modal-leave-active .relative {
    transition: transform 0.2s ease;
}
.modal-enter-from .relative,
.modal-leave-to .relative {
    transform: scale(0.95);
}
</style>
