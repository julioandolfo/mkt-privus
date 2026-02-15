<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps<{
    categories: { id: number; name: string; slug: string; description: string | null; articles_count: number; wp_category_id: number | null; wordpress_connection_id: number | null }[];
    connections: { id: number; name: string; platform: string; platform_label: string; site_url: string }[];
}>();

const showForm = ref(false);
const editingId = ref<number | null>(null);
const syncing = ref(false);
const syncResult = ref<{ type: 'success' | 'error'; message: string } | null>(null);
const selectedSyncConnection = ref<number | null>(null);

const form = useForm({
    name: '',
    description: '',
    wordpress_connection_id: null as number | null,
});

// Stats
const totalCategories = computed(() => props.categories.length);
const linkedToWp = computed(() => props.categories.filter(c => c.wp_category_id).length);
const localOnly = computed(() => props.categories.filter(c => !c.wp_category_id).length);

function openCreate() {
    editingId.value = null;
    form.reset();
    showForm.value = true;
}

function openEdit(cat: typeof props.categories[0]) {
    editingId.value = cat.id;
    form.name = cat.name;
    form.description = cat.description || '';
    form.wordpress_connection_id = cat.wordpress_connection_id;
    showForm.value = true;
}

function submitForm() {
    if (editingId.value) {
        form.put(route('blog.categories.update', editingId.value), {
            onSuccess: () => { showForm.value = false; form.reset(); editingId.value = null; },
        });
    } else {
        form.post(route('blog.categories.store'), {
            onSuccess: () => { showForm.value = false; form.reset(); },
        });
    }
}

function deleteCategory(id: number) {
    if (confirm('Excluir esta categoria?')) {
        router.delete(route('blog.categories.destroy', id));
    }
}

async function syncFromWordPress() {
    const connectionId = selectedSyncConnection.value || (props.connections.length === 1 ? props.connections[0].id : null);
    if (!connectionId) {
        syncResult.value = { type: 'error', message: 'Selecione uma conexão WordPress para sincronizar.' };
        return;
    }

    syncing.value = true;
    syncResult.value = null;

    try {
        const { data } = await axios.post(route('blog.categories.sync'), { connection_id: connectionId });
        if (data.success) {
            syncResult.value = { type: 'success', message: data.message || `${data.synced} categorias sincronizadas.` };
            router.reload({ only: ['categories'] });
        } else {
            syncResult.value = { type: 'error', message: data.error || 'Erro ao sincronizar.' };
        }
    } catch (e: any) {
        syncResult.value = { type: 'error', message: e.response?.data?.error || 'Erro ao sincronizar categorias.' };
    } finally {
        syncing.value = false;
    }
}

function getConnectionName(connectionId: number | null): string {
    if (!connectionId) return '';
    const c = props.connections.find(c => c.id === connectionId);
    return c ? c.name : '';
}
</script>

<template>
    <Head title="Blog - Categorias" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-white">Categorias do Blog</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Organize seus artigos por categorias — sincronize com WordPress</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="openCreate" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 transition">
                        + Nova Categoria
                    </button>
                </div>
            </div>
        </template>

        <!-- Stats Cards -->
        <div class="grid grid-cols-3 gap-3 mb-6">
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-3 text-center">
                <p class="text-lg font-bold text-white">{{ totalCategories }}</p>
                <p class="text-[11px] text-gray-500">Total</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-3 text-center">
                <p class="text-lg font-bold text-green-400">{{ linkedToWp }}</p>
                <p class="text-[11px] text-gray-500">Vinculadas ao WP</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-3 text-center">
                <p class="text-lg font-bold text-yellow-400">{{ localOnly }}</p>
                <p class="text-[11px] text-gray-500">Apenas Local</p>
            </div>
        </div>

        <!-- Sync from WordPress -->
        <div v-if="connections.length > 0" class="rounded-2xl bg-gray-900 border border-gray-800 p-4 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center">
                        <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-white">Sincronizar do WordPress</p>
                        <p class="text-[11px] text-gray-500">Importar categorias existentes do seu site WordPress/WooCommerce</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <select v-if="connections.length > 1" v-model="selectedSyncConnection"
                        class="rounded-lg bg-gray-800 border-gray-700 text-white text-xs py-1.5 px-2 focus:border-indigo-500 focus:ring-indigo-500">
                        <option :value="null">Selecione...</option>
                        <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.name }} ({{ c.platform_label }})</option>
                    </select>
                    <button @click="syncFromWordPress" :disabled="syncing"
                        class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500 disabled:opacity-50 transition flex items-center gap-1.5">
                        <svg v-if="syncing" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        {{ syncing ? 'Sincronizando...' : 'Sincronizar Categorias' }}
                    </button>
                </div>
            </div>

            <!-- Sync result -->
            <div v-if="syncResult" class="mt-3 px-3 py-2 rounded-lg text-xs"
                :class="syncResult.type === 'success' ? 'bg-green-500/10 text-green-400 border border-green-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20'">
                {{ syncResult.message }}
            </div>
        </div>

        <!-- Form -->
        <div v-if="showForm" class="rounded-2xl bg-gray-900 border border-gray-800 p-5 mb-6">
            <h3 class="text-sm font-semibold text-white mb-3">{{ editingId ? 'Editar' : 'Nova' }} Categoria</h3>
            <form @submit.prevent="submitForm" class="space-y-3">
                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Nome *</label>
                    <input v-model="form.name" type="text" required
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Descrição</label>
                    <input v-model="form.description" type="text"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
                <div v-if="!editingId && connections.length > 0">
                    <label class="text-sm text-gray-400 mb-1 block">Vincular a site WordPress</label>
                    <select v-model="form.wordpress_connection_id"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option :value="null">Nenhum (apenas local)</option>
                        <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.name }} ({{ c.platform_label }})</option>
                    </select>
                    <p class="text-[10px] text-gray-600 mt-1">Se vincular, ao publicar artigos a categoria será criada automaticamente no WordPress.</p>
                </div>
                <div class="flex gap-2">
                    <button type="submit" :disabled="form.processing" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                        {{ form.processing ? 'Salvando...' : 'Salvar' }}
                    </button>
                    <button @click="showForm = false" type="button" class="rounded-xl px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                </div>
            </form>
        </div>

        <!-- List -->
        <div v-if="categories.length > 0" class="space-y-2">
            <div v-for="cat in categories" :key="cat.id"
                class="rounded-xl bg-gray-900 border border-gray-800 p-4 flex items-center justify-between hover:border-gray-700 transition">
                <div class="flex items-center gap-3">
                    <!-- WP status indicator -->
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                        :class="cat.wp_category_id ? 'bg-green-500/10' : 'bg-gray-800'">
                        <svg v-if="cat.wp_category_id" class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <svg v-else class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-white">{{ cat.name }}</p>
                        <div class="flex items-center gap-2 mt-0.5 text-[10px] text-gray-500">
                            <span>{{ cat.articles_count }} artigo(s)</span>
                            <span v-if="cat.description" class="truncate max-w-[200px]">{{ cat.description }}</span>
                            <span v-if="cat.wp_category_id" class="inline-flex items-center gap-0.5 text-green-400 bg-green-500/10 px-1.5 py-0.5 rounded">
                                <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                WP #{{ cat.wp_category_id }}
                            </span>
                            <span v-else class="inline-flex items-center text-yellow-400 bg-yellow-500/10 px-1.5 py-0.5 rounded">
                                Apenas local
                            </span>
                            <span v-if="cat.wordpress_connection_id" class="text-gray-600">
                                via {{ getConnectionName(cat.wordpress_connection_id) }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-1.5">
                    <button @click="openEdit(cat)" class="rounded-lg px-2.5 py-1 text-[11px] text-gray-400 hover:bg-gray-700/50 border border-gray-700 transition">Editar</button>
                    <button @click="deleteCategory(cat.id)" class="rounded-lg px-2.5 py-1 text-[11px] text-red-400 hover:bg-red-500/10 border border-red-500/30 transition">Excluir</button>
                </div>
            </div>
        </div>

        <div v-else class="rounded-2xl bg-gray-900 border border-gray-800 border-dashed p-10 text-center">
            <svg class="w-10 h-10 mx-auto text-gray-700 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
            </svg>
            <p class="text-gray-400">Nenhuma categoria criada ainda.</p>
            <p class="text-xs text-gray-600 mt-1">Crie categorias manualmente ou sincronize do WordPress.</p>
            <div class="flex items-center justify-center gap-3 mt-4">
                <button @click="openCreate" class="text-sm text-indigo-400 hover:text-indigo-300 transition">+ Criar categoria</button>
                <span v-if="connections.length > 0" class="text-gray-700">|</span>
                <button v-if="connections.length > 0" @click="syncFromWordPress" class="text-sm text-blue-400 hover:text-blue-300 transition">↻ Sincronizar do WP</button>
            </div>
        </div>

        <!-- Info box -->
        <div class="mt-6 rounded-xl bg-indigo-950/30 border border-indigo-500/20 p-4">
            <p class="text-xs text-indigo-300 font-medium mb-1">Como funciona a integração de categorias:</p>
            <ul class="text-[11px] text-indigo-400/70 space-y-1">
                <li>• <strong>Sincronizar do WP</strong>: Puxa todas as categorias existentes do WordPress e cria localmente com vínculo.</li>
                <li>• <strong>Criação local</strong>: Categorias criadas aqui são automaticamente criadas no WordPress ao publicar um artigo.</li>
                <li>• <strong>Auto-match</strong>: Ao publicar, o sistema busca categorias com mesmo nome/slug no WP antes de criar uma nova.</li>
                <li>• <strong>Ao conectar WordPress</strong>: As categorias são sincronizadas automaticamente na primeira conexão.</li>
            </ul>
        </div>
    </AuthenticatedLayout>
</template>
