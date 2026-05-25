<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const postsGuideSteps = [
    { title: 'Crie manualmente ou com IA', description: 'Clique em "+ Novo Post" para criar. Use o botão "Gerar com IA" para que a inteligência artificial crie legenda e hashtags automaticamente.' },
    { title: 'Selecione plataformas', description: 'Cada post pode ser publicado em múltiplas redes simultaneamente (Instagram, Facebook, LinkedIn, TikTok, YouTube, Pinterest).' },
    { title: 'Agende a publicação', description: 'Defina data e hora para publicação automática. Sem data, o post fica como rascunho para revisão futura.' },
    { title: 'Métricas de engajamento', description: 'Posts publicados mostram curtidas, comentários, compartilhamentos e alcance coletados automaticamente das APIs.' },
];

const postsGuideTips = [
    'Use os filtros de status e plataforma para encontrar posts rapidamente.',
    'Clique em "Atualizar Métricas" para sincronizar dados de engajamento.',
    'Os Top Posts mostram suas publicações com melhor desempenho.',
    'O Autopilot monitora e publica posts agendados automaticamente.',
];

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

interface PostMetrics {
    likes: number;
    comments: number;
    shares: number;
    saves: number;
    reach: number;
    impressions: number;
    video_views: number;
    engagement_rate: number;
    total_engagement: number;
    synced_at: string | null;
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
    failed_platforms?: { platform: string; platform_label: string; error: string | null }[];
    scheduled_at: string | null;
    published_at: string | null;
    created_at: string;
    user_name: string | null;
    media: PostMedia[];
    metrics: PostMetrics | null;
    is_shared?: boolean;
    brand_count?: number;
    brand_names?: string[];
}

interface TopPost {
    id: number;
    title: string | null;
    caption: string;
    type: string | null;
    platforms: string[];
    published_at: string | null;
    platform: string;
    likes: number;
    comments: number;
    shares: number;
    saves: number;
    reach: number;
    engagement_rate: number;
    total_engagement: number;
    media_url: string | null;
    media_type: string | null;
    platform_post_url: string | null;
}

interface EngagementStats {
    total_likes: number;
    total_comments: number;
    total_shares: number;
    total_saves: number;
    total_reach: number;
    total_impressions: number;
    total_engagement: number;
    avg_engagement_rate: number;
    posts_with_metrics: number;
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
    engagementStats: EngagementStats | null;
    topPosts: TopPost[];
    platforms: Array<{ value: string; label: string; color: string }>;
    statuses: Array<{ value: string; label: string; color: string }>;
}

const props = defineProps<Props>();
const page = usePage();
const currentBrand = computed(() => page.props.currentBrand);

const publishingId = ref<number | null>(null);
const republishingId = ref<number | null>(null);
const republishingFailedId = ref<number | null>(null);
const cancellingId = ref<number | null>(null);
const syncingMetrics = ref(false);
const viewMode = ref<'grid' | 'table'>('grid');

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
        const res = await axios.post(route('social.posts.publish-now', post.id));
        const msg = res.data?.message;
        if (msg) alert(msg);
        router.reload({ preserveScroll: true });
    } catch (err: any) {
        const msg = err?.response?.data?.message || 'Erro ao publicar.';
        alert(typeof msg === 'object' ? Object.values(msg).join('\n') : msg);
        router.reload({ preserveScroll: true });
    } finally {
        publishingId.value = null;
    }
}

async function cancelPublish(post: Post) {
    if (!confirm(`Cancelar a publicação de "${post.title || 'este post'}"? O post voltará para rascunho.`)) return;
    cancellingId.value = post.id;
    try {
        await axios.post(route('social.posts.cancel-publish', post.id));
        router.reload({ preserveScroll: true });
    } catch (err: any) {
        const msg = err?.response?.data?.message || 'Erro ao cancelar.';
        alert(typeof msg === 'object' ? Object.values(msg).join('\n') : msg);
    } finally {
        cancellingId.value = null;
    }
}

async function republish(post: Post) {
    if (!confirm(`Republicar "${post.title || 'este post'}" nas plataformas: ${post.platforms.join(', ')}?\n\nIsso vai criar uma nova publicação nas redes sociais.`)) return;
    republishingId.value = post.id;
    try {
        const res = await axios.post(route('social.posts.republish', post.id));
        const msg = res.data?.message;
        if (msg) alert(msg);
        router.reload({ preserveScroll: true });
    } catch (err: any) {
        const msg = err?.response?.data?.message || 'Erro ao republicar.';
        alert(typeof msg === 'object' ? Object.values(msg).join('\n') : msg);
        router.reload({ preserveScroll: true });
    } finally {
        republishingId.value = null;
    }
}

async function republishFailed(post: Post) {
    const labels = (post.failed_platforms || []).map(f => f.platform_label).join(', ');
    if (!confirm(`Reenviar apenas as redes que falharam (${labels})?\n\nAs redes que já publicaram não serão afetadas.`)) return;
    republishingFailedId.value = post.id;
    try {
        const res = await axios.post(route('social.posts.republish-failed', post.id));
        const msg = res.data?.message;
        if (msg) alert(msg);
        router.reload({ preserveScroll: true });
    } catch (err: any) {
        const msg = err?.response?.data?.message || 'Erro ao reenviar.';
        alert(typeof msg === 'object' ? Object.values(msg).join('\n') : msg);
        router.reload({ preserveScroll: true });
    } finally {
        republishingFailedId.value = null;
    }
}

async function syncMetrics() {
    syncingMetrics.value = true;
    try {
        const res = await axios.post(route('social.posts.sync-metrics'));
        router.reload({ preserveScroll: true });
    } catch (err: any) {
        alert(err?.response?.data?.message || 'Erro ao sincronizar métricas.');
    } finally {
        syncingMetrics.value = false;
    }
}

function truncate(text: string, length: number): string {
    if (!text) return '';
    return text.length > length ? text.substring(0, length) + '...' : text;
}

function getPlatformLabel(value: string): string {
    return props.platforms.find(p => p.value === value)?.label || value;
}

function fmtNumber(n: number): string {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return n.toString();
}
</script>

<template>
    <Head title="Social - Posts" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-white">Posts</h1>
                <div class="flex items-center gap-3">
                    <Link :href="route('social.calendar.index')" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        Calendário
                    </Link>
                    <Link :href="route('social.content-engine.index')" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        Content Engine
                    </Link>
                    <Link :href="route('social.autopilot.index')" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        Autopilot
                    </Link>
                    <Link :href="route('social.accounts.index')" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        <span class="flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><line x1="8.59" y1="13.51" x2="15.42" y2="17.49" /><line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
                            </svg>
                            Contas
                        </span>
                    </Link>
                    <Link :href="route('social.posts.create')" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition">
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

            <!-- Stats Cards + Engagement Overview -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-8 mb-6">
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
                <!-- Engagement Stats -->
                <div v-if="engagementStats" class="rounded-2xl bg-gradient-to-br from-pink-500/10 to-rose-500/5 border border-pink-500/20 p-4">
                    <p class="text-2xl font-bold text-pink-400">{{ fmtNumber(engagementStats.total_likes) }}</p>
                    <p class="text-xs text-pink-300/60 mt-1">Curtidas Total</p>
                </div>
                <div v-if="engagementStats" class="rounded-2xl bg-gradient-to-br from-blue-500/10 to-cyan-500/5 border border-blue-500/20 p-4">
                    <p class="text-2xl font-bold text-blue-400">{{ fmtNumber(engagementStats.total_comments) }}</p>
                    <p class="text-xs text-blue-300/60 mt-1">Comentários</p>
                </div>
                <div v-if="engagementStats" class="rounded-2xl bg-gradient-to-br from-emerald-500/10 to-green-500/5 border border-emerald-500/20 p-4">
                    <p class="text-2xl font-bold text-emerald-400">{{ fmtNumber(engagementStats.total_reach) }}</p>
                    <p class="text-xs text-emerald-300/60 mt-1">Alcance Total</p>
                </div>
                <div v-if="engagementStats" class="rounded-2xl bg-gradient-to-br from-amber-500/10 to-yellow-500/5 border border-amber-500/20 p-4">
                    <p class="text-2xl font-bold text-amber-400">{{ engagementStats.avg_engagement_rate }}%</p>
                    <p class="text-xs text-amber-300/60 mt-1">Eng. Rate Médio</p>
                </div>
            </div>

            <!-- Top Posts Section -->
            <div v-if="topPosts.length" class="mb-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        Top Posts por Engajamento
                    </h2>
                    <button
                        @click="syncMetrics"
                        :disabled="syncingMetrics"
                        class="flex items-center gap-2 rounded-xl px-3 py-1.5 text-xs font-medium text-gray-400 hover:text-white border border-gray-700 hover:border-gray-600 transition disabled:opacity-50"
                    >
                        <svg :class="['w-3.5 h-3.5', syncingMetrics ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"/>
                        </svg>
                        {{ syncingMetrics ? 'Sincronizando...' : 'Atualizar Métricas' }}
                    </button>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <div
                        v-for="(tp, idx) in topPosts"
                        :key="tp.id + '-' + tp.platform"
                        class="group relative rounded-2xl bg-gray-900 border border-gray-800 overflow-hidden hover:border-gray-700 transition cursor-pointer"
                        @click="$event => { const found = posts.data.find(p => p.id === tp.id); if (found) openPreview(found); }"
                    >
                        <!-- Rank badge -->
                        <div class="absolute top-2 left-2 z-10 w-6 h-6 rounded-full bg-amber-500/90 flex items-center justify-center text-xs font-bold text-white shadow">
                            {{ idx + 1 }}
                        </div>
                        <!-- Image -->
                        <div class="relative h-28 bg-gray-800">
                            <img v-if="tp.media_url && tp.media_type !== 'video'" :src="tp.media_url" class="w-full h-full object-cover" />
                            <video v-else-if="tp.media_url && tp.media_type === 'video'" :src="tp.media_url" class="w-full h-full object-cover" preload="metadata" muted />
                            <div v-else class="w-full h-full flex items-center justify-center">
                                <svg class="w-8 h-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            </div>
                            <!-- Platform -->
                            <span class="absolute bottom-1.5 right-1.5 rounded px-1.5 py-0.5 text-[9px] font-bold text-white" :style="{ backgroundColor: platformColors[tp.platform] || '#6B7280' }">
                                {{ getPlatformLabel(tp.platform) }}
                            </span>
                        </div>
                        <!-- Metrics -->
                        <div class="p-3">
                            <p class="text-[11px] text-gray-400 truncate mb-2">{{ tp.title || tp.caption || 'Post' }}</p>
                            <div class="grid grid-cols-2 gap-x-3 gap-y-1">
                                <div class="flex items-center gap-1.5">
                                    <svg class="w-3 h-3 text-pink-400 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                    <span class="text-[11px] text-gray-300 font-medium">{{ fmtNumber(tp.likes) }}</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <svg class="w-3 h-3 text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                    <span class="text-[11px] text-gray-300 font-medium">{{ fmtNumber(tp.comments) }}</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <svg class="w-3 h-3 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                    <span class="text-[11px] text-gray-300 font-medium">{{ fmtNumber(tp.shares) }}</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <svg class="w-3 h-3 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                                    <span class="text-[11px] text-gray-300 font-medium">{{ fmtNumber(tp.saves) }}</span>
                                </div>
                            </div>
                            <div class="mt-2 pt-2 border-t border-gray-800 flex items-center justify-between">
                                <span class="text-[10px] text-gray-500">Alcance: {{ fmtNumber(tp.reach) }}</span>
                                <span class="text-[10px] font-semibold" :class="tp.engagement_rate >= 5 ? 'text-green-400' : tp.engagement_rate >= 2 ? 'text-amber-400' : 'text-gray-400'">
                                    {{ tp.engagement_rate }}% eng
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Botão sync se não tem top posts -->
            <div v-else-if="stats.published > 0" class="mb-6 flex justify-end">
                <button
                    @click="syncMetrics"
                    :disabled="syncingMetrics"
                    class="flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white border border-gray-700 hover:border-gray-600 transition disabled:opacity-50"
                >
                    <svg :class="['w-4 h-4', syncingMetrics ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"/>
                    </svg>
                    {{ syncingMetrics ? 'Sincronizando métricas...' : 'Sincronizar Métricas dos Posts' }}
                </button>
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
                        <select v-model="filterStatus" @change="applyFilters" class="rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Todos</option>
                            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">Plataforma</label>
                        <select v-model="filterPlatform" @change="applyFilters" class="rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Todas</option>
                            <option v-for="p in platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
                        </select>
                    </div>
                    <!-- View toggle -->
                    <div class="flex items-center gap-1 bg-gray-800 rounded-xl p-0.5">
                        <button @click="viewMode = 'grid'" :class="['p-2 rounded-lg transition', viewMode === 'grid' ? 'bg-gray-700 text-white' : 'text-gray-500 hover:text-white']">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                        </button>
                        <button @click="viewMode = 'table'" :class="['p-2 rounded-lg transition', viewMode === 'table' ? 'bg-gray-700 text-white' : 'text-gray-500 hover:text-white']">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                        </button>
                    </div>
                    <button @click="clearFilters" class="rounded-xl px-4 py-2 text-sm text-gray-400 hover:text-white hover:bg-gray-800 transition">
                        Limpar
                    </button>
                </div>
            </div>

            <!-- ===== GRID VIEW ===== -->
            <div v-if="posts.data.length && viewMode === 'grid'" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="post in posts.data"
                    :key="post.id"
                    class="rounded-2xl bg-gray-900 border border-gray-800 overflow-hidden hover:border-gray-700 transition group"
                >
                    <!-- Media Preview -->
                    <div class="relative h-40 bg-gray-800">
                        <template v-if="post.media.length && post.media[0].file_path">
                            <template v-if="post.media[0].type === 'video'">
                                <video :src="post.media[0].file_path" class="w-full h-full object-cover" preload="metadata" muted playsinline />
                                <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                                    <div class="w-10 h-10 rounded-full bg-black/50 flex items-center justify-center">
                                        <svg class="w-5 h-5 text-white ml-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    </div>
                                </div>
                            </template>
                            <img v-else :src="post.media[0].file_path" :alt="post.media[0].alt_text || 'Preview'" class="w-full h-full object-cover" />
                        </template>
                        <div v-else class="flex items-center justify-center h-full">
                            <svg class="w-10 h-10 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2" /><circle cx="8.5" cy="8.5" r="1.5" /><polyline points="21 15 16 10 5 21" /></svg>
                        </div>
                        <span v-if="post.media.length > 1" class="absolute top-2 right-2 rounded-lg bg-black/60 px-2 py-1 text-xs text-white">+{{ post.media.length - 1 }}</span>
                        <span :class="['absolute top-2 left-2 rounded-lg border px-2 py-1 text-xs font-medium', statusColorClasses[post.status_color] || statusColorClasses.gray]">{{ post.status_label }}</span>
                        <span v-if="post.is_shared && post.brand_count"
                            class="absolute top-2 right-2 rounded-lg bg-purple-600/80 border border-purple-400/40 px-2 py-1 text-[10px] font-medium text-white flex items-center gap-1"
                            :title="post.brand_names?.join(', ') ?? ''">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M13.828 10.172a4 4 0 0 0-5.656 0l-4 4a4 4 0 1 0 5.656 5.656l1.102-1.101m-.758-4.899a4 4 0 0 0 5.656 0l4-4a4 4 0 0 0-5.656-5.656l-1.1 1.1"/></svg>
                            {{ post.brand_count }} marcas
                        </span>
                        <span v-if="post.type_label" class="absolute bottom-2 left-2 rounded-lg bg-black/60 px-2 py-1 text-xs text-gray-300">{{ post.type_label }}</span>
                    </div>

                    <!-- Content -->
                    <div class="p-4">
                        <h3 v-if="post.title" class="text-sm font-semibold text-white mb-1 truncate">{{ post.title }}</h3>
                        <p class="text-sm text-gray-400 line-clamp-2 mb-3">{{ truncate(post.caption, 120) }}</p>

                        <!-- Platforms -->
                        <div class="flex items-center gap-1.5 mb-3">
                            <span v-for="platform in post.platforms" :key="platform" class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-medium text-white" :style="{ backgroundColor: platformColors[platform] || '#6B7280' }">
                                {{ getPlatformLabel(platform) }}
                            </span>
                        </div>

                        <!-- Alerta de falha por rede (publicação parcial) -->
                        <div v-if="post.failed_platforms && post.failed_platforms.length" class="mb-3 rounded-lg border border-red-500/30 bg-red-500/10 px-2.5 py-2">
                            <div class="flex items-start gap-1.5">
                                <svg class="w-3.5 h-3.5 text-red-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[11px] font-semibold text-red-300">
                                        Não publicado em {{ post.failed_platforms.map(f => f.platform_label).join(', ') }}
                                    </p>
                                    <p v-for="f in post.failed_platforms" :key="f.platform" class="text-[10px] text-red-400/80 truncate" :title="f.error || ''">
                                        {{ f.platform_label }}: {{ f.error || 'Erro desconhecido' }}
                                    </p>
                                    <button @click="republishFailed(post)" :disabled="republishingFailedId === post.id"
                                        class="mt-1.5 inline-flex items-center gap-1 rounded-md border border-red-500/40 bg-red-500/10 px-2 py-1 text-[10px] font-medium text-red-200 hover:bg-red-500/20 transition disabled:opacity-50">
                                        <svg v-if="republishingFailedId === post.id" class="w-3 h-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                                        <svg v-else class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
                                        {{ republishingFailedId === post.id ? 'Reenviando...' : 'Reenviar só as falhas' }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Metrics Bar (for published posts) -->
                        <div v-if="post.metrics" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-2.5 mb-3">
                            <div class="grid grid-cols-4 gap-2 text-center">
                                <div>
                                    <div class="flex items-center justify-center gap-1">
                                        <svg class="w-3 h-3 text-pink-400" fill="currentColor" viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                        <span class="text-xs font-semibold text-gray-200">{{ fmtNumber(post.metrics.likes) }}</span>
                                    </div>
                                    <p class="text-[9px] text-gray-500 mt-0.5">Curtidas</p>
                                </div>
                                <div>
                                    <div class="flex items-center justify-center gap-1">
                                        <svg class="w-3 h-3 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                        <span class="text-xs font-semibold text-gray-200">{{ fmtNumber(post.metrics.comments) }}</span>
                                    </div>
                                    <p class="text-[9px] text-gray-500 mt-0.5">Comentários</p>
                                </div>
                                <div>
                                    <div class="flex items-center justify-center gap-1">
                                        <svg class="w-3 h-3 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                        <span class="text-xs font-semibold text-gray-200">{{ fmtNumber(post.metrics.shares) }}</span>
                                    </div>
                                    <p class="text-[9px] text-gray-500 mt-0.5">Shares</p>
                                </div>
                                <div>
                                    <div class="flex items-center justify-center gap-1">
                                        <svg class="w-3 h-3 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                                        <span class="text-xs font-semibold text-gray-200">{{ fmtNumber(post.metrics.saves) }}</span>
                                    </div>
                                    <p class="text-[9px] text-gray-500 mt-0.5">Saves</p>
                                </div>
                            </div>
                            <div class="mt-2 pt-2 border-t border-gray-700/50 flex items-center justify-between">
                                <span class="text-[10px] text-gray-500">Alcance: {{ fmtNumber(post.metrics.reach) }}</span>
                                <span class="text-[10px] font-bold" :class="post.metrics.engagement_rate >= 5 ? 'text-green-400' : post.metrics.engagement_rate >= 2 ? 'text-amber-400' : 'text-gray-400'">
                                    {{ post.metrics.engagement_rate }}% engajamento
                                </span>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="flex items-center justify-between pt-3 border-t border-gray-800">
                            <div class="text-xs text-gray-500">
                                <span v-if="post.scheduled_at">Agendado: {{ post.scheduled_at }}</span>
                                <span v-else>Criado: {{ post.created_at }}</span>
                            </div>
                            <!-- Actions -->
                            <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition">
                                <button @click="openPreview(post)" class="p-1.5 rounded-lg text-gray-500 hover:text-purple-400 hover:bg-gray-800 transition" title="Visualizar">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                                <button v-if="['draft', 'pending_review', 'approved', 'scheduled', 'failed'].includes(post.status)" @click="publishNow(post)" :disabled="publishingId === post.id" class="p-1.5 rounded-lg text-gray-500 hover:text-green-400 hover:bg-gray-800 transition disabled:opacity-50" title="Publicar Agora">
                                    <svg v-if="publishingId === post.id" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" /></svg>
                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10" /><polygon points="10 8 16 12 10 16 10 8" fill="currentColor" stroke="none" /></svg>
                                </button>
                                <button v-if="post.status === 'publishing'" @click="cancelPublish(post)" :disabled="cancellingId === post.id" class="p-1.5 rounded-lg text-gray-500 hover:text-red-400 hover:bg-gray-800 transition disabled:opacity-50" title="Cancelar Publicação">
                                    <svg v-if="cancellingId === post.id" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" /></svg>
                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10" /><path stroke-linecap="round" d="M15 9l-6 6M9 9l6 6" /></svg>
                                </button>
                                <button v-if="['published', 'failed'].includes(post.status)" @click="republish(post)" :disabled="republishingId === post.id" class="p-1.5 rounded-lg text-gray-500 hover:text-blue-400 hover:bg-gray-800 transition disabled:opacity-50" title="Republicar">
                                    <svg v-if="republishingId === post.id" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" /></svg>
                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
                                </button>
                                <Link :href="route('social.posts.edit', post.id)" class="p-1.5 rounded-lg text-gray-500 hover:text-indigo-400 hover:bg-gray-800 transition" title="Editar">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" /></svg>
                                </Link>
                                <button @click="duplicatePost(post.id)" class="p-1.5 rounded-lg text-gray-500 hover:text-blue-400 hover:bg-gray-800 transition" title="Duplicar">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>
                                </button>
                                <button @click="deletePost(post.id)" class="p-1.5 rounded-lg text-gray-500 hover:text-red-400 hover:bg-gray-800 transition" title="Excluir">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== TABLE VIEW ===== -->
            <div v-else-if="posts.data.length && viewMode === 'table'" class="rounded-2xl bg-gray-900 border border-gray-800 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-800">
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Post</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plataformas</th>
                                <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                    <svg class="w-3.5 h-3.5 inline text-pink-400" fill="currentColor" viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                </th>
                                <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                    <svg class="w-3.5 h-3.5 inline text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                </th>
                                <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                    <svg class="w-3.5 h-3.5 inline text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                </th>
                                <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase">Alcance</th>
                                <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase">Eng%</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Data</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800/50">
                            <tr v-for="post in posts.data" :key="post.id" class="hover:bg-gray-800/30 transition">
                                <!-- Post info -->
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-gray-800 flex-shrink-0 overflow-hidden">
                                            <img v-if="post.media.length && post.media[0].file_path && post.media[0].type !== 'video'" :src="post.media[0].file_path" class="w-full h-full object-cover" />
                                            <video v-else-if="post.media.length && post.media[0].file_path && post.media[0].type === 'video'" :src="post.media[0].file_path" class="w-full h-full object-cover" preload="metadata" muted />
                                            <div v-else class="w-full h-full flex items-center justify-center">
                                                <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-white truncate max-w-[200px]">{{ post.title || truncate(post.caption, 40) }}</p>
                                            <p v-if="post.type_label" class="text-[10px] text-gray-500">{{ post.type_label }}</p>
                                        </div>
                                    </div>
                                </td>
                                <!-- Status -->
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-1.5">
                                        <span :class="['rounded-lg border px-2 py-1 text-[10px] font-medium', statusColorClasses[post.status_color] || statusColorClasses.gray]">
                                            {{ post.status_label }}
                                        </span>
                                        <span v-if="post.failed_platforms && post.failed_platforms.length"
                                            class="inline-flex items-center gap-0.5 rounded border border-red-500/30 bg-red-500/10 px-1.5 py-0.5 text-[9px] font-medium text-red-300"
                                            :title="post.failed_platforms.map(f => `${f.platform_label}: ${f.error || 'Erro desconhecido'}`).join('\n')">
                                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                            {{ post.failed_platforms.length }}
                                        </span>
                                    </div>
                                </td>
                                <!-- Platforms -->
                                <td class="px-3 py-3">
                                    <div class="flex gap-1">
                                        <span v-for="p in post.platforms" :key="p" class="w-2 h-2 rounded-full" :style="{ backgroundColor: platformColors[p] || '#6B7280' }" :title="getPlatformLabel(p)" />
                                    </div>
                                </td>
                                <!-- Likes -->
                                <td class="px-3 py-3 text-center">
                                    <span class="text-sm font-medium" :class="post.metrics ? 'text-gray-200' : 'text-gray-600'">
                                        {{ post.metrics ? fmtNumber(post.metrics.likes) : '-' }}
                                    </span>
                                </td>
                                <!-- Comments -->
                                <td class="px-3 py-3 text-center">
                                    <span class="text-sm font-medium" :class="post.metrics ? 'text-gray-200' : 'text-gray-600'">
                                        {{ post.metrics ? fmtNumber(post.metrics.comments) : '-' }}
                                    </span>
                                </td>
                                <!-- Shares -->
                                <td class="px-3 py-3 text-center">
                                    <span class="text-sm font-medium" :class="post.metrics ? 'text-gray-200' : 'text-gray-600'">
                                        {{ post.metrics ? fmtNumber(post.metrics.shares) : '-' }}
                                    </span>
                                </td>
                                <!-- Reach -->
                                <td class="px-3 py-3 text-center">
                                    <span class="text-sm font-medium" :class="post.metrics ? 'text-gray-200' : 'text-gray-600'">
                                        {{ post.metrics ? fmtNumber(post.metrics.reach) : '-' }}
                                    </span>
                                </td>
                                <!-- Engagement Rate -->
                                <td class="px-3 py-3 text-center">
                                    <span v-if="post.metrics" class="text-sm font-bold" :class="post.metrics.engagement_rate >= 5 ? 'text-green-400' : post.metrics.engagement_rate >= 2 ? 'text-amber-400' : 'text-gray-400'">
                                        {{ post.metrics.engagement_rate }}%
                                    </span>
                                    <span v-else class="text-sm text-gray-600">-</span>
                                </td>
                                <!-- Date -->
                                <td class="px-3 py-3">
                                    <span class="text-xs text-gray-400">
                                        {{ post.published_at || post.scheduled_at || post.created_at }}
                                    </span>
                                </td>
                                <!-- Actions -->
                                <td class="px-3 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <button @click="openPreview(post)" class="p-1 rounded text-gray-500 hover:text-purple-400 transition" title="Visualizar">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                        <Link :href="route('social.posts.edit', post.id)" class="p-1 rounded text-gray-500 hover:text-indigo-400 transition" title="Editar">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </Link>
                                        <button @click="deletePost(post.id)" class="p-1 rounded text-gray-500 hover:text-red-400 transition" title="Excluir">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else-if="!posts.data.length" class="rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2" /><circle cx="8.5" cy="8.5" r="1.5" /><polyline points="21 15 16 10 5 21" /></svg>
                <h3 class="mt-4 text-lg font-medium text-gray-300">Nenhum post encontrado</h3>
                <p class="mt-2 text-sm text-gray-500">Crie seu primeiro post com ajuda da IA.</p>
                <Link :href="route('social.posts.create')" class="mt-6 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
                    Criar Primeiro Post
                </Link>
            </div>

            <!-- Paginação -->
            <div v-if="posts.last_page > 1" class="flex items-center justify-center gap-2 mt-6">
                <template v-for="link in posts.links" :key="link.label">
                    <Link v-if="link.url" :href="link.url" :class="['rounded-lg px-3 py-1.5 text-sm transition', link.active ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800']" v-html="link.label" />
                    <span v-else class="px-3 py-1.5 text-sm text-gray-600" v-html="link.label" />
                </template>
            </div>
        </template>
    </AuthenticatedLayout>

    <!-- Modal de Preview do Post -->
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="previewPost" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="closePreview">
                <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="closePreview" />
                <div class="relative z-10 w-full max-w-sm bg-white rounded-2xl overflow-hidden shadow-2xl">
                    <!-- Header -->
                    <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-100">
                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-purple-500 via-pink-500 to-orange-400 flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                            {{ (previewPost.title || previewPost.caption || 'P').charAt(0).toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-900 truncate">{{ previewPost.title || 'Post' }}</p>
                            <div class="flex items-center gap-1 flex-wrap">
                                <span v-for="platform in previewPost.platforms" :key="platform" class="text-[10px] font-medium px-1.5 py-0.5 rounded text-white" :style="{ backgroundColor: platformColors[platform] || '#6B7280' }">
                                    {{ getPlatformLabel(platform) }}
                                </span>
                            </div>
                        </div>
                        <span :class="['text-xs px-2 py-1 rounded-full font-medium border', statusColorClasses[previewPost.status_color] || statusColorClasses.gray]">{{ previewPost.status_label }}</span>
                        <button @click="closePreview" class="p-1 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition flex-shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>

                    <!-- Mídia -->
                    <div class="relative bg-black aspect-square">
                        <template v-if="previewPost.media.length">
                            <template v-if="previewPost.media[previewMediaIndex]?.type === 'video'">
                                <video :key="previewMediaIndex" :src="previewPost.media[previewMediaIndex].file_path || ''" class="w-full h-full object-contain" controls autoplay muted />
                            </template>
                            <template v-else>
                                <img :key="previewMediaIndex" :src="previewPost.media[previewMediaIndex]?.file_path || ''" :alt="previewPost.media[previewMediaIndex]?.alt_text || 'Post'" class="w-full h-full object-contain" />
                            </template>
                            <template v-if="previewPost.media.length > 1">
                                <button @click="prevMedia" class="absolute left-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/50 text-white flex items-center justify-center hover:bg-black/70 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                                </button>
                                <button @click="nextMedia" class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/50 text-white flex items-center justify-center hover:bg-black/70 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                                <div class="absolute bottom-3 left-0 right-0 flex justify-center gap-1.5">
                                    <button v-for="(_, i) in previewPost.media" :key="i" @click="previewMediaIndex = i" :class="['w-1.5 h-1.5 rounded-full transition', i === previewMediaIndex ? 'bg-white' : 'bg-white/40']" />
                                </div>
                            </template>
                        </template>
                        <div v-else class="w-full h-full flex items-center justify-center bg-gradient-to-br from-gray-800 to-gray-900">
                            <div class="text-center text-gray-400">
                                <svg class="w-12 h-12 mx-auto mb-2 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                <p class="text-xs opacity-50">Sem imagem</p>
                            </div>
                        </div>
                    </div>

                    <!-- Metrics in preview (if available) -->
                    <div v-if="previewPost.metrics" class="px-4 pt-3 pb-1">
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-4">
                                <span class="flex items-center gap-1 font-semibold text-gray-800">
                                    <svg class="w-4 h-4 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                    {{ fmtNumber(previewPost.metrics.likes) }}
                                </span>
                                <span class="flex items-center gap-1 font-semibold text-gray-800">
                                    <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                    {{ fmtNumber(previewPost.metrics.comments) }}
                                </span>
                                <span class="flex items-center gap-1 font-semibold text-gray-800">
                                    <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                    {{ fmtNumber(previewPost.metrics.shares) }}
                                </span>
                            </div>
                            <span class="flex items-center gap-1 font-semibold text-gray-800">
                                <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                                {{ fmtNumber(previewPost.metrics.saves) }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between mt-1.5 text-[11px] text-gray-500">
                            <span>Alcance: {{ fmtNumber(previewPost.metrics.reach) }}</span>
                            <span class="font-semibold" :class="previewPost.metrics.engagement_rate >= 5 ? 'text-green-600' : previewPost.metrics.engagement_rate >= 2 ? 'text-amber-600' : 'text-gray-500'">
                                {{ previewPost.metrics.engagement_rate }}% engajamento
                            </span>
                        </div>
                    </div>

                    <!-- Actions (no metrics) -->
                    <div v-else class="px-4 pt-3 pb-1 flex items-center gap-4">
                        <svg class="w-6 h-6 text-gray-700 cursor-pointer hover:text-red-500 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                        <svg class="w-6 h-6 text-gray-700 cursor-pointer hover:text-gray-900 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <svg class="w-6 h-6 text-gray-700 cursor-pointer hover:text-gray-900 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        <svg class="w-6 h-6 text-gray-700 cursor-pointer hover:text-gray-900 transition ml-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                    </div>

                    <!-- Caption -->
                    <div class="px-4 pb-4">
                        <p class="text-sm text-gray-900 leading-relaxed mt-1">
                            <span class="font-semibold mr-1">{{ previewPost.title || 'post' }}</span>
                            {{ previewPost.caption }}
                        </p>
                        <p v-if="previewPost.hashtags?.length" class="text-sm text-blue-500 mt-1">
                            {{ previewPost.hashtags.map(h => h.startsWith('#') ? h : `#${h}`).join(' ') }}
                        </p>
                        <p class="text-[11px] text-gray-400 mt-2 uppercase tracking-wide">
                            <span v-if="previewPost.published_at">Publicado em {{ previewPost.published_at }}</span>
                            <span v-else-if="previewPost.scheduled_at">Agendado: {{ previewPost.scheduled_at }}</span>
                            <span v-else>Criado: {{ previewPost.created_at }}</span>
                        </p>
                    </div>

                    <!-- Footer ações -->
                    <div class="flex border-t border-gray-100">
                        <Link :href="route('social.posts.edit', previewPost.id)" class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-gray-600 hover:bg-gray-50 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            Editar
                        </Link>
                        <button v-if="['draft', 'pending_review', 'approved', 'scheduled', 'failed'].includes(previewPost.status)" @click="closePreview(); publishNow(previewPost)" class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-green-600 hover:bg-green-50 transition border-l border-gray-100">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8" fill="currentColor" stroke="none"/></svg>
                            Publicar
                        </button>
                        <button v-else-if="previewPost.status === 'publishing'" @click="closePreview(); cancelPublish(previewPost)" class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-red-600 hover:bg-red-50 transition border-l border-gray-100">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" d="M15 9l-6 6M9 9l6 6"/></svg>
                            Cancelar
                        </button>
                        <button v-else-if="['published', 'failed'].includes(previewPost.status)" @click="closePreview(); republish(previewPost)" class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-blue-600 hover:bg-blue-50 transition border-l border-gray-100">
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
