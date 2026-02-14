<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

interface Article {
    id: number;
    title: string;
    slug: string;
    excerpt: string | null;
    cover_image_path: string | null;
    status: string;
    status_label: string;
    status_color: string;
    category: string | null;
    connection_name: string | null;
    connection_platform: string | null;
    wp_post_url: string | null;
    word_count: number;
    reading_time: number;
    seo_score: number;
    published_at: string | null;
    scheduled_publish_at: string | null;
    created_at: string;
    user_name: string | null;
    can_approve: boolean;
    can_publish: boolean;
    has_wordpress: boolean;
}

const props = defineProps<{
    articles: { data: Article[]; links: any[]; meta?: any };
    stats: { total: number; published: number; pending: number; draft: number };
    categories: { id: number; name: string }[];
    connections: { id: number; name: string; platform: string; platform_label: string; site_url: string }[];
    filters: { status?: string; category?: string; connection?: string; search?: string };
}>();

const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const categoryFilter = ref(props.filters.category || '');
const connectionFilter = ref(props.filters.connection || '');

function applyFilters() {
    router.get(route('blog.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        category: categoryFilter.value || undefined,
        connection: connectionFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}

function clearFilters() {
    search.value = '';
    statusFilter.value = '';
    categoryFilter.value = '';
    connectionFilter.value = '';
    router.get(route('blog.index'));
}

const statusColors: Record<string, string> = {
    gray: 'bg-gray-500/10 text-gray-400 border-gray-500/30',
    yellow: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/30',
    blue: 'bg-blue-500/10 text-blue-400 border-blue-500/30',
    indigo: 'bg-indigo-500/10 text-indigo-400 border-indigo-500/30',
    green: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
    red: 'bg-red-500/10 text-red-400 border-red-500/30',
    purple: 'bg-purple-500/10 text-purple-400 border-purple-500/30',
};

function seoScoreColor(score: number): string {
    if (score >= 80) return 'text-emerald-400';
    if (score >= 50) return 'text-yellow-400';
    return 'text-red-400';
}

const processingId = ref<number | null>(null);

function deleteArticle(id: number) {
    if (confirm('Tem certeza que deseja excluir este artigo?')) {
        router.delete(route('blog.destroy', id));
    }
}

function approveArticle(id: number) {
    if (!confirm('Aprovar este artigo para publicação?')) return;
    processingId.value = id;
    router.post(route('blog.approve', id), {}, {
        preserveScroll: true,
        onFinish: () => { processingId.value = null; },
    });
}

async function publishArticle(id: number) {
    if (!confirm('Publicar este artigo no WordPress agora?')) return;
    processingId.value = id;
    try {
        const resp = await axios.post(route('blog.publish', id));
        if (resp.data.success) {
            router.reload({ preserveScroll: true });
        } else {
            alert(resp.data.error || 'Erro ao publicar.');
        }
    } catch (e: any) {
        alert(e.response?.data?.error || 'Erro de conexão ao publicar.');
    } finally {
        processingId.value = null;
    }
}
</script>

<template>
    <Head title="Blog - Artigos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-white">Blog</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Gerencie artigos e publique no WordPress</p>
                </div>
                <Link :href="route('blog.create')" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 transition">
                    + Novo Artigo
                </Link>
            </div>
        </template>

        <!-- Stats -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4 text-center">
                <p class="text-2xl font-bold text-white">{{ stats.total }}</p>
                <p class="text-xs text-gray-500">Total</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4 text-center">
                <p class="text-2xl font-bold text-emerald-400">{{ stats.published }}</p>
                <p class="text-xs text-gray-500">Publicados</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4 text-center">
                <p class="text-2xl font-bold text-yellow-400">{{ stats.pending }}</p>
                <p class="text-xs text-gray-500">Aguardando Revisão</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4 text-center">
                <p class="text-2xl font-bold text-gray-400">{{ stats.draft }}</p>
                <p class="text-xs text-gray-500">Rascunhos</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap gap-2 mb-5">
            <input v-model="search" type="text" placeholder="Buscar artigos..." @keyup.enter="applyFilters"
                class="rounded-xl bg-gray-900 border border-gray-700 text-sm text-white px-3 py-2 w-48 focus:border-indigo-500 focus:ring-indigo-500 placeholder-gray-600" />
            <select v-model="statusFilter" @change="applyFilters"
                class="rounded-xl bg-gray-900 border border-gray-700 text-sm text-white px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Todos status</option>
                <option value="draft">Rascunho</option>
                <option value="pending_review">Aguardando Revisão</option>
                <option value="approved">Aprovado</option>
                <option value="published">Publicado</option>
                <option value="failed">Falha</option>
                <option value="scheduled">Agendado</option>
            </select>
            <select v-if="categories.length > 0" v-model="categoryFilter" @change="applyFilters"
                class="rounded-xl bg-gray-900 border border-gray-700 text-sm text-white px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Todas categorias</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <select v-if="connections.length > 1" v-model="connectionFilter" @change="applyFilters"
                class="rounded-xl bg-gray-900 border border-gray-700 text-sm text-white px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Todos destinos</option>
                <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.name }} ({{ c.platform_label }})</option>
            </select>
            <button v-if="search || statusFilter || categoryFilter || connectionFilter" @click="clearFilters"
                class="rounded-xl border border-gray-700 px-3 py-2 text-xs text-gray-400 hover:text-white transition">
                Limpar
            </button>
        </div>

        <!-- Articles List -->
        <div v-if="articles.data.length > 0" class="space-y-3">
            <div v-for="article in articles.data" :key="article.id"
                class="rounded-2xl bg-gray-900 border border-gray-800 p-4 hover:border-gray-700 transition group">
                <div class="flex gap-4">
                    <!-- Cover image -->
                    <div v-if="article.cover_image_path" class="shrink-0">
                        <img :src="'/storage/' + article.cover_image_path" :alt="article.title"
                            class="w-28 h-20 rounded-xl object-cover" />
                    </div>
                    <div v-else class="shrink-0 w-28 h-20 rounded-xl bg-gray-800 flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                        </svg>
                    </div>

                    <!-- Content -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <Link :href="route('blog.edit', article.id)" class="text-white font-medium text-sm hover:text-indigo-400 transition truncate block">
                                    {{ article.title }}
                                </Link>
                                <p v-if="article.excerpt" class="text-xs text-gray-500 mt-0.5 line-clamp-1">{{ article.excerpt }}</p>
                            </div>
                            <span :class="['shrink-0 rounded-full border px-2.5 py-0.5 text-[10px] font-medium', statusColors[article.status_color] || statusColors.gray]">
                                {{ article.status_label }}
                            </span>
                        </div>

                        <div class="flex items-center gap-3 mt-2 text-[10px] text-gray-500">
                            <span v-if="article.category" class="bg-gray-800 rounded px-1.5 py-0.5">{{ article.category }}</span>
                            <span v-if="article.connection_name" class="bg-gray-800 rounded px-1.5 py-0.5">{{ article.connection_name }}</span>
                            <span>{{ article.word_count }} palavras</span>
                            <span>{{ article.reading_time }} min leitura</span>
                            <span :class="seoScoreColor(article.seo_score)">SEO: {{ article.seo_score }}%</span>
                            <span v-if="article.published_at" class="text-emerald-500">Pub: {{ article.published_at }}</span>
                            <span v-else-if="article.scheduled_publish_at" class="text-purple-400">Agendado: {{ article.scheduled_publish_at }}</span>
                            <span>{{ article.created_at }}</span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-1.5 shrink-0 opacity-0 group-hover:opacity-100 transition">
                        <!-- Aprovar -->
                        <button v-if="article.can_approve" @click="approveArticle(article.id)"
                            :disabled="processingId === article.id"
                            class="rounded-lg px-2.5 py-1 text-[11px] font-medium text-blue-400 hover:bg-blue-500/10 border border-blue-500/30 transition disabled:opacity-50">
                            {{ processingId === article.id ? '...' : 'Aprovar' }}
                        </button>
                        <!-- Publicar -->
                        <button v-if="article.can_publish" @click="publishArticle(article.id)"
                            :disabled="processingId === article.id"
                            class="rounded-lg px-2.5 py-1 text-[11px] font-medium text-emerald-400 hover:bg-emerald-500/10 border border-emerald-500/30 transition disabled:opacity-50">
                            {{ processingId === article.id ? 'Publicando...' : 'Publicar' }}
                        </button>
                        <!-- Editar -->
                        <Link :href="route('blog.edit', article.id)"
                            class="rounded-lg px-2.5 py-1 text-[11px] font-medium text-gray-400 hover:bg-gray-700/50 border border-gray-700 transition">
                            Editar
                        </Link>
                        <!-- Ver no site -->
                        <a v-if="article.wp_post_url" :href="article.wp_post_url" target="_blank"
                            class="rounded-lg px-2.5 py-1 text-[11px] font-medium text-emerald-400 hover:bg-emerald-500/10 border border-emerald-500/30 transition">
                            Ver no site
                        </a>
                        <!-- Excluir -->
                        <button @click="deleteArticle(article.id)"
                            class="rounded-lg px-2.5 py-1 text-[11px] font-medium text-red-400 hover:bg-red-500/10 border border-red-500/30 transition">
                            Excluir
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-else class="rounded-2xl bg-gray-900 border border-gray-800 border-dashed p-12 text-center">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-gray-800 flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-300">Nenhum artigo ainda</h3>
            <p class="mt-2 text-sm text-gray-500 max-w-md mx-auto">Crie seu primeiro artigo de blog manualmente ou gere com IA e publique diretamente no WordPress.</p>
            <Link :href="route('blog.create')" class="inline-block mt-4 rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 transition">
                Criar primeiro artigo
            </Link>
        </div>

        <!-- Pagination -->
        <div v-if="articles.links && articles.links.length > 3" class="flex items-center justify-center gap-1 mt-6">
            <template v-for="link in articles.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" v-html="link.label" preserve-scroll
                    :class="['rounded-lg px-3 py-1.5 text-xs transition', link.active ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:bg-gray-800']" />
                <span v-else v-html="link.label" class="rounded-lg px-3 py-1.5 text-xs text-gray-600" />
            </template>
        </div>
    </AuthenticatedLayout>
</template>
