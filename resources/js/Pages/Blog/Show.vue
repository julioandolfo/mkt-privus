<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps<{
    article: {
        id: number; title: string; slug: string; excerpt: string | null; content: string | null;
        cover_image_path: string | null; status: string; status_label: string; status_color: string;
        category: string | null; connection_name: string | null; tags: string[] | null;
        meta_title: string | null; meta_description: string | null; meta_keywords: string | null;
        wp_post_id: number | null; wp_post_url: string | null;
        word_count: number; reading_time: number; seo_score: number;
        published_at: string | null; created_at: string; user_name: string | null;
        ai_model_used: string | null; tokens_used: number | null;
    };
}>();
</script>

<template>
    <Head :title="`Blog - ${article.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3 min-w-0">
                    <Link :href="route('blog.index')" class="text-gray-500 hover:text-white transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                    </Link>
                    <h1 class="text-xl font-semibold text-white truncate">{{ article.title }}</h1>
                </div>
                <div class="flex items-center gap-2">
                    <a v-if="article.wp_post_url" :href="article.wp_post_url" target="_blank"
                        class="rounded-xl bg-emerald-600/20 border border-emerald-500/30 px-4 py-2 text-sm text-emerald-400 hover:bg-emerald-600/30 transition">
                        Ver no site
                    </a>
                    <Link :href="route('blog.edit', article.id)" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 transition">
                        Editar
                    </Link>
                </div>
            </div>
        </template>

        <div class="max-w-4xl mx-auto">
            <!-- Meta info -->
            <div class="flex flex-wrap items-center gap-3 mb-6 text-sm text-gray-500">
                <span :class="['rounded-full border px-2.5 py-0.5 text-[10px] font-medium',
                    article.status === 'published' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30' :
                    article.status === 'draft' ? 'bg-gray-500/10 text-gray-400 border-gray-500/30' :
                    'bg-yellow-500/10 text-yellow-400 border-yellow-500/30']">
                    {{ article.status_label }}
                </span>
                <span v-if="article.category" class="bg-gray-800 rounded px-2 py-0.5 text-xs">{{ article.category }}</span>
                <span>{{ article.word_count }} palavras</span>
                <span>{{ article.reading_time }} min leitura</span>
                <span :class="article.seo_score >= 80 ? 'text-emerald-400' : article.seo_score >= 50 ? 'text-yellow-400' : 'text-red-400'">SEO: {{ article.seo_score }}%</span>
                <span v-if="article.published_at">Publicado: {{ article.published_at }}</span>
                <span>Criado: {{ article.created_at }}</span>
                <span v-if="article.user_name">por {{ article.user_name }}</span>
            </div>

            <!-- Cover -->
            <div v-if="article.cover_image_path" class="mb-6">
                <img :src="'/storage/' + article.cover_image_path" :alt="article.title" class="w-full max-h-80 rounded-2xl object-cover" />
            </div>

            <!-- Excerpt -->
            <div v-if="article.excerpt" class="mb-6 rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-gray-300 italic">{{ article.excerpt }}</p>
            </div>

            <!-- Content -->
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                <div class="prose prose-invert prose-sm max-w-none" v-html="article.content || '<p class=&quot;text-gray-500&quot;>Sem conteúdo.</p>'" />
            </div>

            <!-- Tags -->
            <div v-if="article.tags && article.tags.length > 0" class="flex flex-wrap gap-1.5 mt-4">
                <span v-for="tag in article.tags" :key="tag" class="rounded-lg bg-gray-800 border border-gray-700 px-2.5 py-1 text-xs text-gray-400">
                    {{ tag }}
                </span>
            </div>

            <!-- AI info -->
            <div v-if="article.ai_model_used" class="mt-6 rounded-xl bg-indigo-600/10 border border-indigo-500/20 p-3 text-xs text-indigo-400 flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                Gerado com {{ article.ai_model_used }} ({{ article.tokens_used }} tokens)
            </div>
        </div>
    </AuthenticatedLayout>
</template>
