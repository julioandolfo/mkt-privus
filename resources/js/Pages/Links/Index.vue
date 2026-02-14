<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Page {
    id: number;
    title: string;
    slug: string;
    description: string | null;
    avatar_path: string | null;
    public_url: string;
    is_active: boolean;
    block_count: number;
    total_views: number;
    total_clicks: number;
    theme: Record<string, string> | null;
    created_at: string;
    user_name: string | null;
}

const props = defineProps<{
    pages: { data: Page[]; links: any[]; meta?: any };
}>();

const showCreateModal = ref(false);
const form = useForm({ title: '', description: '' });

function create() {
    form.post(route('links.store'), {
        onSuccess: () => { showCreateModal.value = false; form.reset(); },
    });
}

function deletePage(id: number) {
    if (confirm('Excluir esta página de links?')) {
        router.delete(route('links.destroy', id));
    }
}

function duplicatePage(id: number) {
    router.post(route('links.duplicate', id));
}

function copyUrl(url: string) {
    navigator.clipboard.writeText(url);
}

function formatNumber(n: number): string {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return String(n);
}
</script>

<template>
    <Head title="Link Pages" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-white">Link Pages</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Crie páginas de links personalizadas (bio link)</p>
                </div>
                <button @click="showCreateModal = true" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 transition">
                    + Nova Página
                </button>
            </div>
        </template>

        <!-- Grid de páginas -->
        <div v-if="pages.data.length > 0" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div v-for="page in pages.data" :key="page.id"
                class="rounded-2xl bg-gray-900 border border-gray-800 overflow-hidden hover:border-gray-700 transition group">

                <!-- Preview header -->
                <div class="h-24 relative" :style="{ backgroundColor: page.theme?.bg_color || '#0f172a' }">
                    <div class="absolute inset-0 bg-gradient-to-b from-transparent to-gray-900/80" />
                    <div class="absolute bottom-3 left-4 right-4 flex items-end gap-3">
                        <div v-if="page.avatar_path" class="w-10 h-10 rounded-full border-2 border-gray-700 overflow-hidden shrink-0">
                            <img :src="'/storage/' + page.avatar_path" class="w-full h-full object-cover" />
                        </div>
                        <div v-else class="w-10 h-10 rounded-full bg-gray-800 border-2 border-gray-700 flex items-center justify-center shrink-0">
                            <span class="text-gray-500 text-xs font-bold">{{ page.title.charAt(0) }}</span>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-white truncate">{{ page.title }}</p>
                            <p class="text-[10px] text-gray-400 truncate">/l/{{ page.slug }}</p>
                        </div>
                    </div>
                    <!-- Active badge -->
                    <div class="absolute top-2 right-2">
                        <span :class="['rounded-full px-2 py-0.5 text-[9px] font-medium',
                            page.is_active ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-600/20 text-gray-500']">
                            {{ page.is_active ? 'Ativa' : 'Inativa' }}
                        </span>
                    </div>
                </div>

                <!-- Stats -->
                <div class="p-4">
                    <div class="grid grid-cols-3 gap-2 mb-3">
                        <div class="text-center bg-gray-800/50 rounded-lg py-2">
                            <p class="text-sm font-bold text-white">{{ formatNumber(page.total_views) }}</p>
                            <p class="text-[9px] text-gray-500">Views</p>
                        </div>
                        <div class="text-center bg-gray-800/50 rounded-lg py-2">
                            <p class="text-sm font-bold text-white">{{ formatNumber(page.total_clicks) }}</p>
                            <p class="text-[9px] text-gray-500">Cliques</p>
                        </div>
                        <div class="text-center bg-gray-800/50 rounded-lg py-2">
                            <p class="text-sm font-bold text-white">{{ page.block_count }}</p>
                            <p class="text-[9px] text-gray-500">Blocos</p>
                        </div>
                    </div>

                    <p v-if="page.description" class="text-xs text-gray-500 mb-3 line-clamp-2">{{ page.description }}</p>

                    <!-- Actions -->
                    <div class="flex items-center gap-1.5">
                        <Link :href="route('links.editor', page.id)" class="flex-1 rounded-lg bg-indigo-600/20 border border-indigo-500/30 px-3 py-1.5 text-[11px] font-medium text-indigo-400 hover:bg-indigo-600/30 transition text-center">
                            Editar
                        </Link>
                        <Link :href="route('links.analytics', page.id)" class="flex-1 rounded-lg bg-gray-700/50 border border-gray-600/50 px-3 py-1.5 text-[11px] font-medium text-gray-300 hover:bg-gray-700 transition text-center">
                            Analytics
                        </Link>
                        <button @click="copyUrl(page.public_url)" class="rounded-lg bg-gray-700/50 border border-gray-600/50 px-2 py-1.5 text-[11px] text-gray-400 hover:text-white transition" title="Copiar URL">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" /></svg>
                        </button>
                        <button @click="duplicatePage(page.id)" class="rounded-lg bg-gray-700/50 border border-gray-600/50 px-2 py-1.5 text-[11px] text-gray-400 hover:text-white transition" title="Duplicar">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                        </button>
                        <button @click="deletePage(page.id)" class="rounded-lg px-2 py-1.5 text-[11px] text-red-400 hover:bg-red-500/10 border border-red-500/30 transition" title="Excluir">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        </button>
                    </div>

                    <p class="text-[10px] text-gray-600 mt-2">Criado em {{ page.created_at }} {{ page.user_name ? `por ${page.user_name}` : '' }}</p>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-else class="rounded-2xl bg-gray-900 border border-gray-800 border-dashed p-12 text-center">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-gray-800 flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m9.364-2.12l4.5-4.5a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-300">Nenhuma página de links</h3>
            <p class="mt-2 text-sm text-gray-500 max-w-md mx-auto">Crie páginas de bio link personalizadas para sua marca com editor visual avançado.</p>
            <button @click="showCreateModal = true" class="inline-block mt-4 rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 transition">
                Criar primeira página
            </button>
        </div>

        <!-- Pagination -->
        <div v-if="pages.links && pages.links.length > 3" class="flex items-center justify-center gap-1 mt-6">
            <template v-for="link in pages.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" v-html="link.label" preserve-scroll
                    :class="['rounded-lg px-3 py-1.5 text-xs transition', link.active ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:bg-gray-800']" />
                <span v-else v-html="link.label" class="rounded-lg px-3 py-1.5 text-xs text-gray-600" />
            </template>
        </div>

        <!-- Create Modal -->
        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="showCreateModal" class="fixed inset-0 z-[60] flex items-center justify-center">
                    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showCreateModal = false" />
                    <div class="relative w-full max-w-md rounded-2xl bg-gray-900 border border-gray-700 p-6 shadow-2xl mx-4">
                        <h3 class="text-lg font-semibold text-white mb-4">Nova Página de Links</h3>
                        <form @submit.prevent="create" class="space-y-4">
                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Título da página *</label>
                                <input v-model="form.title" type="text" required placeholder="Meus Links" autofocus
                                    class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Descrição</label>
                                <input v-model="form.description" type="text" placeholder="Links da minha marca"
                                    class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div class="flex justify-end gap-2 pt-2">
                                <button @click="showCreateModal = false" type="button" class="rounded-xl px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                                <button type="submit" :disabled="form.processing || !form.title" class="rounded-xl bg-indigo-600 px-6 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                                    {{ form.processing ? 'Criando...' : 'Criar Página' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AuthenticatedLayout>
</template>
