<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';

interface Autopilot {
    id: number;
    name: string;
    keywords: string[];
    enabled: boolean;
    posts_per_week: number;
    connection_id: number | null;
    category_id: number | null;
    require_approval: boolean;
    auto_approve: boolean;
    tone: string;
    instructions: string;
    cover_width: number;
    cover_height: number;
    last_run_at: string | null;
}

interface Connection { id: number; name: string; platform_label: string; site_url: string }
interface Category { id: number; name: string }

const props = defineProps<{
    autopilots: Autopilot[];
    connections: Connection[];
    categories: Category[];
}>();

const list = ref<Autopilot[]>([...props.autopilots]);

// editingId: null = formulário fechado | 0 = criando novo | N = editando autopilot N
const editingId = ref<number | null>(null);

type Draft = Omit<Autopilot, 'id' | 'last_run_at'> & { keywordsInput: string };

function emptyDraft(): Draft {
    return {
        name: '',
        keywords: [],
        keywordsInput: '',
        enabled: true,
        posts_per_week: 2,
        connection_id: null,
        category_id: null,
        require_approval: true,
        auto_approve: false,
        tone: '',
        instructions: '',
        cover_width: 1750,
        cover_height: 650,
    };
}

const form = ref<Draft>(emptyDraft());
const saving = ref(false);
const runningId = ref<number | null>(null);
const result = ref<{ type: 'success' | 'error'; message: string } | null>(null);
const availableCategories = ref<Category[]>([...props.categories]);
const loadingCategories = ref(false);
const syncingCategories = ref(false);
const initializingForm = ref(false);

function openNew() {
    initializingForm.value = true;
    form.value = emptyDraft();
    availableCategories.value = [...props.categories];
    editingId.value = 0;
    result.value = null;
    initializingForm.value = false;
}

function openEdit(a: Autopilot) {
    initializingForm.value = true;
    form.value = {
        name: a.name,
        keywords: [...(a.keywords ?? [])],
        keywordsInput: '',
        enabled: a.enabled,
        posts_per_week: a.posts_per_week,
        connection_id: a.connection_id,
        category_id: a.category_id,
        require_approval: a.require_approval,
        auto_approve: a.auto_approve,
        tone: a.tone ?? '',
        instructions: a.instructions ?? '',
        cover_width: a.cover_width,
        cover_height: a.cover_height,
    };
    editingId.value = a.id;
    result.value = null;

    if (a.connection_id) {
        fetchCategoriesForConnection(a.connection_id).finally(() => { initializingForm.value = false; });
    } else {
        availableCategories.value = [...props.categories];
        initializingForm.value = false;
    }
}

function closeForm() {
    editingId.value = null;
    form.value = emptyDraft();
}

function addKeyword() {
    const k = (form.value.keywordsInput ?? '').trim();
    if (!k) return;
    if (form.value.keywords.includes(k)) {
        form.value.keywordsInput = '';
        return;
    }
    form.value.keywords = [...form.value.keywords, k];
    form.value.keywordsInput = '';
}

function removeKeyword(idx: number) {
    form.value.keywords = form.value.keywords.filter((_, i) => i !== idx);
}

watch(() => form.value.connection_id, async (newId, oldId) => {
    if (initializingForm.value) return;
    if (newId === oldId) return;
    form.value.category_id = null;
    if (!newId) {
        availableCategories.value = [...props.categories];
        return;
    }
    await fetchCategoriesForConnection(newId);
});

async function fetchCategoriesForConnection(connectionId: number) {
    loadingCategories.value = true;
    try {
        const { data } = await axios.get(route('blog.connections.categories', connectionId));
        if (data.success && data.categories?.length) {
            availableCategories.value = data.categories.map((c: any) => ({
                id: c.id ?? c.wp_id,
                name: c.name,
            }));
        } else {
            availableCategories.value = [];
        }
    } catch {
        availableCategories.value = [];
    } finally {
        loadingCategories.value = false;
    }
}

async function syncWpCategories() {
    if (!form.value.connection_id) return;
    syncingCategories.value = true;
    try {
        await axios.post(route('blog.categories.sync'), { connection_id: form.value.connection_id });
        await fetchCategoriesForConnection(form.value.connection_id);
    } catch {
        // silencioso
    } finally {
        syncingCategories.value = false;
    }
}

async function save() {
    if (!form.value.name.trim()) {
        result.value = { type: 'error', message: 'Dê um nome ao autopilot.' };
        return;
    }
    saving.value = true;
    result.value = null;
    try {
        const { keywordsInput, ...payload } = form.value;
        const isNew = editingId.value === 0;
        const url = isNew
            ? route('blog.autopilot.store')
            : route('blog.autopilot.update', editingId.value!);
        const method = isNew ? 'post' : 'put';
        const { data } = await axios.request({ method, url, data: payload });

        if (data.success && data.autopilot) {
            if (isNew) {
                list.value.push(data.autopilot);
            } else {
                const idx = list.value.findIndex(a => a.id === data.autopilot.id);
                if (idx >= 0) list.value[idx] = data.autopilot;
            }
            result.value = { type: 'success', message: 'Salvo com sucesso.' };
            closeForm();
        } else {
            result.value = { type: 'error', message: data.error || 'Erro ao salvar.' };
        }
    } catch (e: any) {
        const errors = e.response?.data?.errors;
        const msg = errors
            ? Object.values(errors).flat().join(' ')
            : (e.response?.data?.message || 'Erro ao salvar.');
        result.value = { type: 'error', message: msg };
    } finally {
        saving.value = false;
    }
}

async function destroyAutopilot(a: Autopilot) {
    if (!confirm(`Excluir o autopilot "${a.name}"? Essa ação não pode ser desfeita.`)) return;
    try {
        await axios.delete(route('blog.autopilot.destroy', a.id));
        list.value = list.value.filter(x => x.id !== a.id);
        if (editingId.value === a.id) closeForm();
        result.value = { type: 'success', message: `Autopilot "${a.name}" excluído.` };
    } catch (e: any) {
        result.value = { type: 'error', message: e.response?.data?.message || 'Erro ao excluir.' };
    }
}

async function runOne(a: Autopilot) {
    if (!confirm(`Disparar o autopilot "${a.name}" agora? Isso vai gerar pautas para a próxima semana.`)) return;
    runningId.value = a.id;
    result.value = null;
    try {
        const { data } = await axios.post(route('blog.autopilot.run', a.id));
        result.value = { type: data.success ? 'success' : 'error', message: data.message || data.error };
    } catch (e: any) {
        result.value = { type: 'error', message: e.response?.data?.message || 'Erro ao disparar.' };
    } finally {
        runningId.value = null;
    }
}

async function toggleEnabled(a: Autopilot) {
    try {
        const payload = { ...a, keywords: a.keywords ?? [], enabled: !a.enabled };
        // Removemos campos não esperados pelo backend
        delete (payload as any).last_run_at;
        const { data } = await axios.put(route('blog.autopilot.update', a.id), payload);
        if (data.success && data.autopilot) {
            const idx = list.value.findIndex(x => x.id === a.id);
            if (idx >= 0) list.value[idx] = data.autopilot;
        }
    } catch (e: any) {
        result.value = { type: 'error', message: e.response?.data?.message || 'Erro ao alterar status.' };
    }
}

function connectionName(id: number | null): string {
    if (!id) return 'Sem destino WordPress';
    return props.connections.find(c => c.id === id)?.name ?? `Conexão #${id}`;
}

function categoryName(id: number | null): string {
    if (!id) return 'Sem categoria fixa';
    return props.categories.find(c => c.id === id)?.name ?? `Categoria #${id}`;
}
</script>

<template>
    <Head title="Blog - Autopilot" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-white">Autopilots de Blog</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Crie pilotos com palavras-chave diferentes para gerar pautas em frentes distintas</p>
                </div>
                <div class="flex items-center gap-2">
                    <Link :href="route('blog.index')" class="rounded-xl border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:text-white transition">← Blog</Link>
                    <button v-if="editingId === null" @click="openNew" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 transition">+ Novo Autopilot</button>
                </div>
            </div>
        </template>

        <div class="max-w-3xl mx-auto space-y-5">

            <!-- Resultado global -->
            <div v-if="result" class="px-3 py-2 rounded-xl text-sm"
                :class="result.type === 'success' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20'">
                {{ result.message }}
            </div>

            <!-- Lista de autopilots -->
            <div v-if="list.length" class="space-y-3">
                <div v-for="a in list" :key="a.id"
                    class="rounded-2xl bg-gray-900 border border-gray-800 p-5"
                    :class="editingId === a.id ? 'border-indigo-500/50' : ''">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <h3 class="text-base font-semibold text-white truncate">{{ a.name }}</h3>
                                <span :class="a.enabled ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' : 'bg-gray-700/30 text-gray-400 border-gray-700'"
                                    class="rounded-md border px-1.5 py-0.5 text-[10px] font-medium">
                                    {{ a.enabled ? 'Ativo' : 'Inativo' }}
                                </span>
                            </div>
                            <div v-if="a.keywords?.length" class="flex flex-wrap gap-1.5 mb-2">
                                <span v-for="k in a.keywords" :key="k" class="rounded-md bg-indigo-500/15 border border-indigo-500/30 px-2 py-0.5 text-[10px] text-indigo-300">{{ k }}</span>
                            </div>
                            <p v-else class="text-[11px] text-gray-500 italic mb-2">Sem palavras-chave (usa apenas as da marca)</p>

                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-[11px] text-gray-500">
                                <span><span class="text-gray-400">{{ a.posts_per_week }}</span> artigos/semana</span>
                                <span>{{ connectionName(a.connection_id) }}</span>
                                <span>{{ categoryName(a.category_id) }}</span>
                                <span>{{ a.require_approval ? 'Requer aprovação' : 'Publicação automática' }}</span>
                                <span class="col-span-2">Última execução: <span class="text-gray-400">{{ a.last_run_at ?? 'nunca' }}</span></span>
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-1.5 shrink-0">
                            <button @click="toggleEnabled(a)" type="button"
                                :class="a.enabled ? 'bg-indigo-600' : 'bg-gray-700'"
                                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors duration-200" :title="a.enabled ? 'Desativar' : 'Ativar'">
                                <span :class="a.enabled ? 'translate-x-5' : 'translate-x-0.5'"
                                    class="inline-block h-5 w-5 mt-0.5 rounded-full bg-white shadow transform transition-transform duration-200" />
                            </button>
                            <div class="flex items-center gap-1">
                                <button @click="runOne(a)" :disabled="runningId === a.id" class="rounded-lg border border-gray-700 px-2 py-1 text-[11px] text-gray-400 hover:text-white hover:border-gray-500 disabled:opacity-50 transition" title="Executar agora">
                                    {{ runningId === a.id ? '...' : '▶ Rodar' }}
                                </button>
                                <button @click="editingId === a.id ? closeForm() : openEdit(a)" class="rounded-lg border border-gray-700 px-2 py-1 text-[11px] text-gray-400 hover:text-white hover:border-gray-500 transition">
                                    {{ editingId === a.id ? 'Fechar' : 'Editar' }}
                                </button>
                                <button @click="destroyAutopilot(a)" class="rounded-lg border border-red-500/30 px-2 py-1 text-[11px] text-red-400 hover:text-red-300 hover:border-red-500/50 transition">Excluir</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else-if="editingId === null" class="rounded-2xl bg-gray-900 border border-gray-800 p-8 text-center">
                <p class="text-sm text-gray-400">Nenhum autopilot configurado ainda.</p>
                <button @click="openNew" class="mt-3 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 transition">+ Criar o primeiro autopilot</button>
            </div>

            <!-- Form (criar/editar) -->
            <div v-if="editingId !== null" class="rounded-2xl bg-gray-900 border border-indigo-500/30 p-6 space-y-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-white">{{ editingId === 0 ? 'Novo autopilot' : 'Editar autopilot' }}</h2>
                    <button @click="closeForm" class="text-gray-500 hover:text-white transition" title="Fechar">✕</button>
                </div>

                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Nome do piloto</label>
                    <input v-model="form.name" type="text" maxlength="120" placeholder="Ex.: Brindes personalizados" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>

                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Palavras-chave deste piloto</label>
                    <div class="flex flex-wrap gap-1.5 mb-2 min-h-[28px]">
                        <span v-for="(k, i) in form.keywords" :key="k + i" class="inline-flex items-center gap-1 rounded-md bg-indigo-500/15 border border-indigo-500/30 px-2 py-0.5 text-[11px] text-indigo-300">
                            {{ k }}
                            <button @click="removeKeyword(i)" type="button" class="text-indigo-400 hover:text-red-400">✕</button>
                        </span>
                    </div>
                    <div class="flex gap-2">
                        <input v-model="form.keywordsInput" @keydown.enter.prevent="addKeyword" type="text" placeholder="Digite e pressione Enter (ex.: garrafas personalizadas)" class="flex-1 rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <button @click="addKeyword" type="button" class="rounded-xl border border-gray-600 px-3 py-1.5 text-sm text-gray-300 hover:text-white hover:border-gray-500 transition">+ Adicionar</button>
                    </div>
                    <p class="text-[10px] text-gray-600 mt-1">As pautas geradas por este piloto vão focar nessas palavras-chave (somadas às palavras-chave gerais da marca).</p>
                </div>

                <div class="border-t border-gray-800"></div>

                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-white">Ativar este piloto</p>
                        <p class="text-xs text-gray-500 mt-0.5">Só pilotos ativos rodam na geração automática semanal</p>
                    </div>
                    <button @click="form.enabled = !form.enabled" type="button"
                        :class="form.enabled ? 'bg-indigo-600' : 'bg-gray-700'"
                        class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors duration-200">
                        <span :class="form.enabled ? 'translate-x-5' : 'translate-x-0.5'" class="inline-block h-5 w-5 mt-0.5 rounded-full bg-white shadow transform transition-transform duration-200" />
                    </button>
                </div>

                <div>
                    <label class="text-sm text-gray-400 mb-2 block">Artigos por semana: <span class="text-white font-medium">{{ form.posts_per_week }}</span></label>
                    <input v-model.number="form.posts_per_week" type="range" min="1" max="7" step="1" class="w-full accent-indigo-500" />
                    <div class="flex justify-between text-[10px] text-gray-600 mt-1"><span>1</span><span>7</span></div>
                </div>

                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Destino WordPress</label>
                    <select v-model="form.connection_id" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option :value="null">Nenhum (apenas rascunho local)</option>
                        <option v-for="c in connections" :key="c.id" :value="c.id">
                            {{ c.name }} ({{ c.platform_label }}) — {{ c.site_url }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Categoria padrão</label>
                    <div class="flex items-center gap-2">
                        <select v-model="form.category_id" :disabled="loadingCategories" class="flex-1 rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-50">
                            <option :value="null">{{ loadingCategories ? 'Carregando...' : 'Sem categoria (IA sugere)' }}</option>
                            <option v-for="c in availableCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <button v-if="form.connection_id" @click="syncWpCategories" :disabled="syncingCategories" type="button"
                            class="shrink-0 rounded-xl border border-gray-700 px-3 py-2 text-xs text-gray-400 hover:text-white hover:border-gray-500 disabled:opacity-50 transition" title="Sincronizar categorias do WordPress">
                            <svg :class="syncingCategories ? 'animate-spin' : ''" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="rounded-xl bg-gray-800/50 border border-gray-700 p-4 space-y-3">
                    <p class="text-sm font-medium text-white">Modo de publicação</p>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="radio" :value="true" v-model="form.require_approval" class="mt-0.5 accent-indigo-500" />
                        <div>
                            <p class="text-sm text-gray-200">Gerar pautas e aguardar aprovação</p>
                            <p class="text-xs text-gray-500">Você revisa as pautas no Calendário antes de gerar os artigos.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="radio" :value="false" v-model="form.require_approval" class="mt-0.5 accent-indigo-500" />
                        <div>
                            <p class="text-sm text-gray-200">Geração totalmente automática</p>
                            <p class="text-xs text-gray-500">A IA gera pautas <strong>e</strong> artigos completos automaticamente.</p>
                        </div>
                    </label>
                </div>

                <div class="rounded-xl bg-gray-800/50 border border-gray-700 p-4">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" v-model="form.auto_approve" class="mt-0.5 rounded border-gray-600 text-indigo-600 focus:ring-indigo-500" />
                        <div>
                            <p class="text-sm font-medium text-white">Auto-aprovar artigos completos</p>
                            <p class="text-xs text-gray-500">Artigos com todos os elementos (texto + capa + SEO) são aprovados automaticamente. Incompletos ficam pendentes.</p>
                        </div>
                    </label>
                </div>

                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Tom de voz</label>
                    <select v-model="form.tone" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Padrão da marca</option>
                        <option value="profissional">Profissional</option>
                        <option value="informal">Informal</option>
                        <option value="educativo">Educativo</option>
                        <option value="persuasivo">Persuasivo</option>
                        <option value="técnico">Técnico</option>
                    </select>
                </div>

                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Instruções permanentes para a IA</label>
                    <textarea v-model="form.instructions" rows="3" maxlength="2000" placeholder="Ex.: Sempre mencionar os benefícios do produto X. Focar em SEO." class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>

                <div>
                    <label class="text-sm text-gray-400 mb-2 block">Dimensões da imagem de capa (px)</label>
                    <div class="flex items-center gap-2">
                        <input v-model.number="form.cover_width" type="number" min="100" max="4000" placeholder="Largura" class="w-24 rounded-xl bg-gray-800 border-gray-700 text-white text-sm text-center focus:border-indigo-500 focus:ring-indigo-500" />
                        <span class="text-gray-500 text-sm">×</span>
                        <input v-model.number="form.cover_height" type="number" min="100" max="4000" placeholder="Altura" class="w-24 rounded-xl bg-gray-800 border-gray-700 text-white text-sm text-center focus:border-indigo-500 focus:ring-indigo-500" />
                        <span class="text-xs text-gray-600">px (padrão: 1750 × 650)</span>
                    </div>
                </div>

                <div class="flex gap-3 pt-3 border-t border-gray-800">
                    <button @click="save" :disabled="saving" class="flex-1 rounded-xl bg-indigo-600 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                        {{ saving ? 'Salvando...' : (editingId === 0 ? 'Criar autopilot' : 'Salvar alterações') }}
                    </button>
                    <button @click="closeForm" type="button" class="rounded-xl border border-gray-600 px-5 py-2.5 text-sm text-gray-300 hover:text-white hover:border-gray-500 transition">Cancelar</button>
                </div>
            </div>

            <!-- Info -->
            <div class="rounded-xl bg-indigo-950/30 border border-indigo-500/20 p-4">
                <p class="text-xs text-indigo-300 font-medium mb-2">Como funciona:</p>
                <ul class="text-[11px] text-indigo-400/70 space-y-1.5">
                    <li>• <strong>Toda segunda-feira às 7h</strong>: cada piloto ativo gera pautas para os próximos 7 dias.</li>
                    <li>• <strong>Palavras-chave por piloto</strong>: cada piloto foca em um tema (ex.: "brindes personalizados" num piloto, "garrafas personalizadas" em outro).</li>
                    <li>• <strong>Combinação com a marca</strong>: as palavras-chave do piloto entram <em>somadas</em> às palavras-chave gerais da marca.</li>
                    <li>• <strong>Sem repetição</strong>: a IA confere artigos já publicados antes de sugerir novas pautas.</li>
                    <li>• <strong>Rodar avulso</strong>: o botão "▶ Rodar" dispara só aquele piloto, sem esperar a segunda-feira.</li>
                </ul>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <Link :href="route('blog.calendar')" class="rounded-xl bg-gray-900 border border-gray-800 p-3 text-center hover:border-gray-600 transition">
                    <p class="text-sm font-medium text-white">Calendário</p>
                    <p class="text-[11px] text-gray-500 mt-0.5">Ver pautas geradas</p>
                </Link>
                <Link :href="route('blog.index')" class="rounded-xl bg-gray-900 border border-gray-800 p-3 text-center hover:border-gray-600 transition">
                    <p class="text-sm font-medium text-white">Artigos</p>
                    <p class="text-[11px] text-gray-500 mt-0.5">Revisar e aprovar</p>
                </Link>
                <Link :href="route('blog.categories')" class="rounded-xl bg-gray-900 border border-gray-800 p-3 text-center hover:border-gray-600 transition">
                    <p class="text-sm font-medium text-white">Categorias</p>
                    <p class="text-[11px] text-gray-500 mt-0.5">Gerenciar conexões</p>
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
