<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps<{
    article: {
        id: number; title: string; slug: string; excerpt: string; content: string;
        cover_image_path: string | null; status: string; status_label: string;
        blog_category_id: number | null; wordpress_connection_id: number | null;
        tags: string[]; meta_title: string | null; meta_description: string | null; meta_keywords: string | null;
        wp_post_id: number | null; wp_post_url: string | null;
        scheduled_publish_at: string | null; word_count: number; seo_score: number;
        can_publish: boolean; can_edit: boolean;
    };
    categories: { id: number; name: string; wordpress_connection_id: number | null }[];
    connections: { id: number; name: string; platform: string; platform_label: string; site_url: string }[];
}>();

const form = useForm({
    title: props.article.title,
    content: props.article.content || '',
    excerpt: props.article.excerpt || '',
    cover_image_path: props.article.cover_image_path || '',
    blog_category_id: props.article.blog_category_id,
    wordpress_connection_id: props.article.wordpress_connection_id,
    tags: props.article.tags || [],
    meta_title: props.article.meta_title || '',
    meta_description: props.article.meta_description || '',
    meta_keywords: props.article.meta_keywords || '',
    status: props.article.status,
    scheduled_publish_at: props.article.scheduled_publish_at || '',
});

const activeTab = ref<'content' | 'seo' | 'settings'>('content');
const publishing = ref(false);
const publishResult = ref<{ success: boolean; message: string; url?: string } | null>(null);
const generatingCover = ref(false);
const generatingSeo = ref(false);
const tagInput = ref('');

const wordCount = computed(() => {
    if (!form.content) return 0;
    return form.content.replace(/<[^>]*>/g, ' ').split(/\s+/).filter(Boolean).length;
});

const seoScore = computed(() => {
    let score = 0;
    if (form.meta_title) score += 15;
    if (form.meta_description) score += 15;
    if (form.meta_keywords) score += 10;
    if (form.excerpt) score += 10;
    if (form.cover_image_path) score += 10;
    const tLen = form.title.length;
    if (tLen >= 30 && tLen <= 60) score += 10; else if (tLen > 0) score += 5;
    const dLen = (form.meta_description || '').length;
    if (dLen >= 120 && dLen <= 160) score += 10; else if (dLen > 0) score += 5;
    if (wordCount.value >= 800) score += 10; else if (wordCount.value >= 300) score += 5;
    if (form.tags.length > 0) score += 5;
    if (form.blog_category_id) score += 5;
    return Math.min(100, score);
});

function save() {
    form.put(route('blog.update', props.article.id), { preserveScroll: true });
}

async function publishToWP() {
    if (!confirm('Publicar este artigo no WordPress?')) return;
    publishing.value = true;
    publishResult.value = null;

    try {
        const resp = await axios.post(route('blog.publish', props.article.id));
        if (resp.data.success) {
            publishResult.value = { success: true, message: 'Artigo publicado!', url: resp.data.wp_post_url };
            // Reload para atualizar status
            window.location.reload();
        } else {
            publishResult.value = { success: false, message: resp.data.error || 'Falha ao publicar.' };
        }
    } catch (e: any) {
        publishResult.value = { success: false, message: e.response?.data?.error || 'Erro de conexão.' };
    } finally {
        publishing.value = false;
    }
}

async function generateCoverImage() {
    generatingCover.value = true;
    try {
        const resp = await axios.post(route('blog.generate-cover'), { title: form.title, excerpt: form.excerpt });
        if (resp.data.success) form.cover_image_path = resp.data.path;
    } catch { } finally { generatingCover.value = false; }
}

async function generateSeoData() {
    generatingSeo.value = true;
    try {
        const resp = await axios.post(route('blog.generate-seo', props.article.id));
        if (resp.data.success) {
            form.meta_title = resp.data.meta_title || form.meta_title;
            form.meta_description = resp.data.meta_description || form.meta_description;
            form.meta_keywords = resp.data.meta_keywords || form.meta_keywords;
        }
    } catch { } finally { generatingSeo.value = false; }
}

function addTag() {
    const tag = tagInput.value.trim();
    if (tag && !form.tags.includes(tag)) form.tags.push(tag);
    tagInput.value = '';
}

function removeTag(i: number) { form.tags.splice(i, 1); }

async function uploadCover(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('image', file);
    try {
        const resp = await axios.post(route('blog.upload-cover'), fd);
        if (resp.data.success) form.cover_image_path = resp.data.path;
    } catch { }
}
</script>

<template>
    <Head :title="`Blog - ${article.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-3">
                        <Link :href="route('blog.index')" class="text-gray-500 hover:text-white transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                        </Link>
                        <h1 class="text-xl font-semibold text-white truncate">{{ article.title }}</h1>
                        <span :class="['shrink-0 rounded-full border px-2.5 py-0.5 text-[10px] font-medium',
                            article.status === 'published' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30' :
                            article.status === 'draft' ? 'bg-gray-500/10 text-gray-400 border-gray-500/30' :
                            'bg-yellow-500/10 text-yellow-400 border-yellow-500/30']">
                            {{ article.status_label }}
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs" :class="seoScore >= 80 ? 'text-emerald-400' : seoScore >= 50 ? 'text-yellow-400' : 'text-red-400'">
                        SEO {{ seoScore }}%
                    </span>
                    <button v-if="article.can_publish" @click="publishToWP" :disabled="publishing"
                        class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50 transition">
                        {{ publishing ? 'Publicando...' : 'Publicar no WordPress' }}
                    </button>
                    <button @click="save" :disabled="form.processing"
                        class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                        {{ form.processing ? 'Salvando...' : 'Salvar' }}
                    </button>
                </div>
            </div>
        </template>

        <!-- Publish result -->
        <div v-if="publishResult" :class="['rounded-xl border p-3 mb-4 text-sm',
            publishResult.success ? 'bg-emerald-900/30 border-emerald-700/50 text-emerald-300' : 'bg-red-900/30 border-red-700/50 text-red-300']">
            {{ publishResult.message }}
            <a v-if="publishResult.url" :href="publishResult.url" target="_blank" class="underline ml-1">Ver no site</a>
        </div>

        <!-- WP URL -->
        <div v-if="article.wp_post_url && !publishResult" class="rounded-xl bg-emerald-900/20 border border-emerald-700/30 p-3 mb-4 flex items-center justify-between">
            <div class="text-sm text-emerald-300">
                Publicado em:
                <a :href="article.wp_post_url" target="_blank" class="underline font-medium">{{ article.wp_post_url }}</a>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 mb-4 border-b border-gray-800">
            <button v-for="tab in [{ key: 'content', label: 'Conteúdo' }, { key: 'seo', label: 'SEO' }, { key: 'settings', label: 'Configurações' }]"
                :key="tab.key" @click="activeTab = tab.key as any"
                :class="['px-4 py-2.5 text-sm font-medium transition border-b-2 -mb-px',
                    activeTab === tab.key ? 'border-indigo-500 text-white' : 'border-transparent text-gray-500 hover:text-gray-300']">
                {{ tab.label }}
            </button>
        </div>

        <!-- Tab: Content -->
        <div v-if="activeTab === 'content'" class="space-y-4">
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                <div class="mb-4">
                    <label class="text-sm text-gray-400 mb-1 block">Título</label>
                    <input v-model="form.title" type="text" :disabled="!article.can_edit"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-60" />
                </div>

                <div class="mb-4">
                    <label class="text-sm text-gray-400 mb-1 block">Resumo</label>
                    <textarea v-model="form.excerpt" rows="2" :disabled="!article.can_edit"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-60" />
                </div>

                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-sm text-gray-400">Conteúdo</label>
                        <span class="text-[10px] text-gray-600">{{ wordCount }} palavras | ~{{ Math.max(1, Math.ceil(wordCount / 200)) }} min</span>
                    </div>
                    <textarea v-model="form.content" rows="25" :disabled="!article.can_edit"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-60" />
                </div>

                <div>
                    <label class="text-sm text-gray-400 mb-2 block">Imagem de Capa</label>
                    <div class="flex items-center gap-3">
                        <img v-if="form.cover_image_path" :src="'/storage/' + form.cover_image_path" class="h-20 rounded-xl object-cover" />
                        <button v-if="article.can_edit" @click="generateCoverImage" :disabled="generatingCover" type="button"
                            class="rounded-xl bg-gray-800 border border-gray-700 px-3 py-2 text-xs text-gray-300 hover:text-white transition disabled:opacity-50">
                            {{ generatingCover ? 'Gerando...' : 'Gerar com IA' }}
                        </button>
                        <label v-if="article.can_edit" class="rounded-xl bg-gray-800 border border-gray-700 px-3 py-2 text-xs text-gray-300 cursor-pointer hover:text-white transition">
                            Upload <input type="file" accept="image/*" @change="uploadCover" class="hidden" />
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: SEO -->
        <div v-if="activeTab === 'seo'" class="space-y-4">
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-white">Meta Tags SEO</h3>
                    <button @click="generateSeoData" :disabled="generatingSeo" type="button"
                        class="text-[11px] text-indigo-400 hover:text-indigo-300 transition disabled:opacity-50">
                        {{ generatingSeo ? 'Gerando...' : 'Gerar SEO com IA' }}
                    </button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Meta Título</label>
                        <input v-model="form.meta_title" type="text" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <p class="text-[10px] mt-1" :class="(form.meta_title?.length || 0) > 60 ? 'text-red-400' : 'text-gray-600'">{{ (form.meta_title || '').length }}/60</p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Meta Descrição</label>
                        <textarea v-model="form.meta_description" rows="2" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <p class="text-[10px] mt-1" :class="(form.meta_description?.length || 0) > 160 ? 'text-red-400' : 'text-gray-600'">{{ (form.meta_description || '').length }}/160</p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Keywords</label>
                        <input v-model="form.meta_keywords" type="text" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Tags</label>
                        <div class="flex flex-wrap gap-1.5 mb-2">
                            <span v-for="(tag, i) in form.tags" :key="i" class="inline-flex items-center gap-1 rounded-lg bg-indigo-600/20 border border-indigo-500/30 px-2 py-0.5 text-xs text-indigo-400">
                                {{ tag }} <button @click="removeTag(i)" type="button" class="hover:text-white">&times;</button>
                            </span>
                        </div>
                        <div class="flex gap-2">
                            <input v-model="tagInput" type="text" placeholder="Nova tag" @keyup.enter="addTag" class="flex-1 rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <button @click="addTag" type="button" class="rounded-xl bg-gray-800 border border-gray-700 px-3 py-2 text-xs text-gray-400 hover:text-white transition">+</button>
                        </div>
                    </div>
                </div>

                <!-- SEO Preview -->
                <div class="mt-6 p-4 rounded-xl bg-white/5 border border-gray-700">
                    <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-2">Preview Google</p>
                    <p class="text-blue-400 text-sm font-medium truncate">{{ form.meta_title || form.title || 'Título do artigo' }}</p>
                    <p class="text-emerald-400 text-xs truncate">{{ connections.find(c => c.id === form.wordpress_connection_id)?.site_url || 'https://seusite.com.br' }}/{{ article.slug }}</p>
                    <p class="text-gray-400 text-xs mt-0.5 line-clamp-2">{{ form.meta_description || form.excerpt || 'Descrição do artigo aparecerá aqui...' }}</p>
                </div>
            </div>
        </div>

        <!-- Tab: Settings -->
        <div v-if="activeTab === 'settings'" class="space-y-4">
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5 space-y-4">
                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Status</label>
                    <select v-model="form.status" :disabled="!article.can_edit"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-60">
                        <option value="draft">Rascunho</option>
                        <option value="pending_review">Aguardando Revisão</option>
                        <option value="approved">Aprovado</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Destino WordPress</label>
                    <select v-model="form.wordpress_connection_id"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option :value="null">Nenhum (apenas local)</option>
                        <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.name }} ({{ c.platform_label }})</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Categoria</label>
                    <select v-model="form.blog_category_id"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option :value="null">Sem categoria</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Agendar publicação</label>
                    <input v-model="form.scheduled_publish_at" type="datetime-local"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
