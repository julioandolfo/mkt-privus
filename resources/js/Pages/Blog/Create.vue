<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';

const props = defineProps<{
    categories: { id: number; name: string; wordpress_connection_id: number | null }[];
    connections: { id: number; name: string; platform: string; platform_label: string; site_url: string }[];
}>();

const form = useForm({
    title: '',
    content: '',
    excerpt: '',
    cover_image_path: '',
    blog_category_id: null as number | null,
    wordpress_connection_id: null as number | null,
    tags: [] as string[],
    meta_title: '',
    meta_description: '',
    meta_keywords: '',
    status: 'draft',
    ai_model_used: '',
    tokens_used: 0,
    ai_metadata: null as any,
});

// Steps
const currentStep = ref(1);
const totalSteps = 4;

// AI Generation
const aiTopic = ref('');
const aiKeywords = ref('');
const aiTone = ref('');
const aiInstructions = ref('');
const aiWordCount = ref(800);
const generating = ref(false);
const generatingCover = ref(false);
const generatingTopics = ref(false);
const topicSuggestions = ref<any[]>([]);
const tagInput = ref('');

// New connection modal
const showConnectionModal = ref(false);
const connectionForm = ref({
    name: '', site_url: '', wp_username: '', wp_app_password: '',
});
const connectionTesting = ref(false);
const connectionSaving = ref(false);
const connectionError = ref('');

async function generateWithAI() {
    if (!aiTopic.value) return;
    generating.value = true;

    try {
        const resp = await axios.post(route('blog.generate'), {
            topic: aiTopic.value,
            keywords: aiKeywords.value || null,
            tone: aiTone.value || null,
            instructions: aiInstructions.value || null,
            word_count: aiWordCount.value,
        });

        if (resp.data.success) {
            form.title = resp.data.title || form.title;
            form.content = resp.data.content || '';
            form.excerpt = resp.data.excerpt || '';
            form.meta_title = resp.data.meta_title || '';
            form.meta_description = resp.data.meta_description || '';
            form.meta_keywords = resp.data.meta_keywords || '';
            form.tags = resp.data.tags || [];
            form.ai_model_used = resp.data.ai_model_used || '';
            form.tokens_used = resp.data.tokens_used || 0;
            currentStep.value = 2;
        } else {
            alert(resp.data.error || 'Erro ao gerar artigo.');
        }
    } catch (e: any) {
        alert(e.response?.data?.error || 'Erro na geração.');
    } finally {
        generating.value = false;
    }
}

async function generateCoverImage() {
    if (!form.title) return;
    generatingCover.value = true;

    try {
        const resp = await axios.post(route('blog.generate-cover'), {
            title: form.title,
            excerpt: form.excerpt || '',
        });

        if (resp.data.success) {
            form.cover_image_path = resp.data.path;
        } else {
            alert(resp.data.error || 'Erro ao gerar imagem.');
        }
    } catch (e: any) {
        alert(e.response?.data?.error || 'Erro na geração de imagem.');
    } finally {
        generatingCover.value = false;
    }
}

async function generateTopicSuggestions() {
    generatingTopics.value = true;
    try {
        const resp = await axios.post(route('blog.generate-topics'), {
            connection_id: form.wordpress_connection_id,
            count: 5,
        });
        if (resp.data.success) {
            topicSuggestions.value = resp.data.topics || [];
        }
    } catch { }
    finally { generatingTopics.value = false; }
}

function selectTopic(topic: any) {
    aiTopic.value = topic.title;
    aiKeywords.value = topic.keywords || '';
    aiWordCount.value = topic.estimated_word_count || 800;
}

function addTag() {
    const tag = tagInput.value.trim();
    if (tag && !form.tags.includes(tag)) {
        form.tags.push(tag);
    }
    tagInput.value = '';
}

function removeTag(index: number) {
    form.tags.splice(index, 1);
}

async function uploadCover(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('image', file);

    try {
        const resp = await axios.post(route('blog.upload-cover'), formData);
        if (resp.data.success) {
            form.cover_image_path = resp.data.path;
        }
    } catch (e: any) {
        alert('Erro no upload: ' + (e.response?.data?.error || e.message));
    }
}

async function saveConnection() {
    connectionSaving.value = true;
    connectionError.value = '';

    try {
        const resp = await axios.post(route('blog.connections.store'), connectionForm.value);
        if (resp.data.success) {
            // Adicionar à lista local
            props.connections.push(resp.data.connection);
            form.wordpress_connection_id = resp.data.connection.id;
            showConnectionModal.value = false;
            connectionForm.value = { name: '', site_url: '', wp_username: '', wp_app_password: '' };
        } else {
            connectionError.value = resp.data.error || 'Erro ao salvar.';
        }
    } catch (e: any) {
        connectionError.value = e.response?.data?.error || 'Erro ao conectar.';
    } finally {
        connectionSaving.value = false;
    }
}

function submit() {
    form.post(route('blog.store'));
}

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
    if (tLen >= 30 && tLen <= 60) score += 10;
    else if (tLen > 0) score += 5;
    const dLen = (form.meta_description || '').length;
    if (dLen >= 120 && dLen <= 160) score += 10;
    else if (dLen > 0) score += 5;
    if (wordCount.value >= 800) score += 10;
    else if (wordCount.value >= 300) score += 5;
    if (form.tags.length > 0) score += 5;
    if (form.blog_category_id) score += 5;
    return Math.min(100, score);
});
</script>

<template>
    <Head title="Blog - Novo Artigo" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-white">Novo Artigo</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Crie manualmente ou gere com IA</p>
                </div>
                <div class="flex items-center gap-2 text-xs text-gray-500">
                    <template v-for="s in totalSteps" :key="s">
                        <div :class="['w-8 h-8 rounded-full flex items-center justify-center font-semibold transition',
                            s === currentStep ? 'bg-indigo-600 text-white' :
                            s < currentStep ? 'bg-emerald-600 text-white' : 'bg-gray-800 text-gray-500']">
                            {{ s }}
                        </div>
                        <div v-if="s < totalSteps" class="w-8 h-0.5" :class="s < currentStep ? 'bg-emerald-600' : 'bg-gray-800'" />
                    </template>
                </div>
            </div>
        </template>

        <!-- Step 1: Tema e Geração com IA -->
        <div v-if="currentStep === 1" class="max-w-3xl mx-auto space-y-6">
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Destino e Tema</h2>

                <!-- Connection -->
                <div class="mb-4">
                    <label class="text-sm text-gray-400 mb-1 block">Destino WordPress</label>
                    <div class="flex gap-2">
                        <select v-model="form.wordpress_connection_id"
                            class="flex-1 rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option :value="null">Nenhum (apenas rascunho local)</option>
                            <option v-for="c in connections" :key="c.id" :value="c.id">
                                {{ c.name }} ({{ c.platform_label }}) — {{ c.site_url }}
                            </option>
                        </select>
                        <button @click="showConnectionModal = true" type="button"
                            class="shrink-0 rounded-xl bg-gray-800 border border-gray-700 px-3 py-2 text-xs text-gray-400 hover:text-white transition">
                            + WordPress
                        </button>
                    </div>
                </div>

                <!-- AI Topic suggestions -->
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-sm text-gray-400">Tema do artigo *</label>
                        <button @click="generateTopicSuggestions" :disabled="generatingTopics" type="button"
                            class="text-[11px] text-indigo-400 hover:text-indigo-300 transition disabled:opacity-50">
                            {{ generatingTopics ? 'Gerando...' : 'Sugerir temas com IA' }}
                        </button>
                    </div>
                    <input v-model="aiTopic" type="text" placeholder="Ex: Como escolher o produto ideal para..."
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>

                <!-- Topic suggestions -->
                <div v-if="topicSuggestions.length > 0" class="mb-4 space-y-2">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Sugestões da IA</p>
                    <button v-for="(topic, i) in topicSuggestions" :key="i" @click="selectTopic(topic)" type="button"
                        class="w-full text-left rounded-xl border border-gray-700 bg-gray-800/50 p-3 hover:border-indigo-500/50 transition">
                        <p class="text-sm font-medium text-white">{{ topic.title }}</p>
                        <p v-if="topic.description" class="text-xs text-gray-500 mt-0.5">{{ topic.description }}</p>
                        <div class="flex items-center gap-2 mt-1">
                            <span v-if="topic.keywords" class="text-[10px] text-indigo-400">{{ topic.keywords }}</span>
                            <span v-if="topic.estimated_word_count" class="text-[10px] text-gray-600">~{{ topic.estimated_word_count }} palavras</span>
                        </div>
                    </button>
                </div>

                <!-- Extra fields -->
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Palavras-chave</label>
                        <input v-model="aiKeywords" type="text" placeholder="seo, marketing digital, vendas"
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Tom de voz</label>
                        <select v-model="aiTone"
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Padrão da marca</option>
                            <option value="profissional">Profissional</option>
                            <option value="informal">Informal</option>
                            <option value="educativo">Educativo</option>
                            <option value="persuasivo">Persuasivo</option>
                            <option value="técnico">Técnico</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="text-sm text-gray-400 mb-1 block">Instruções adicionais</label>
                    <textarea v-model="aiInstructions" rows="2" placeholder="Ex: Mencionar o produto X, incluir estatísticas..."
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>

                <div class="mb-4">
                    <label class="text-sm text-gray-400 mb-1 block">Tamanho desejado: {{ aiWordCount }} palavras</label>
                    <input v-model.number="aiWordCount" type="range" min="300" max="3000" step="100" class="w-full accent-indigo-500" />
                    <div class="flex justify-between text-[10px] text-gray-600"><span>300</span><span>3000</span></div>
                </div>

                <div class="flex items-center gap-3">
                    <button @click="generateWithAI" :disabled="!aiTopic || generating" type="button"
                        class="flex-1 rounded-xl bg-indigo-600 py-3 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                        {{ generating ? 'Gerando artigo com IA...' : 'Gerar Artigo com IA' }}
                    </button>
                    <button @click="currentStep = 2" type="button"
                        class="rounded-xl border border-gray-700 px-6 py-3 text-sm text-gray-400 hover:text-white transition">
                        Escrever manualmente
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 2: Conteúdo -->
        <div v-if="currentStep === 2" class="max-w-4xl mx-auto space-y-4">
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Conteúdo do Artigo</h2>

                <div class="mb-4">
                    <label class="text-sm text-gray-400 mb-1 block">Título *</label>
                    <input v-model="form.title" type="text" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                    <p class="text-[10px] text-gray-600 mt-1">{{ form.title.length }}/60 caracteres (ideal para SEO)</p>
                </div>

                <div class="mb-4">
                    <label class="text-sm text-gray-400 mb-1 block">Resumo (excerpt)</label>
                    <textarea v-model="form.excerpt" rows="2" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>

                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-sm text-gray-400">Conteúdo HTML</label>
                        <span class="text-[10px] text-gray-600">{{ wordCount }} palavras</span>
                    </div>
                    <textarea v-model="form.content" rows="20"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500" />
                </div>

                <!-- Cover image -->
                <div class="mb-4">
                    <label class="text-sm text-gray-400 mb-2 block">Imagem de Capa</label>
                    <div class="flex items-center gap-3">
                        <div v-if="form.cover_image_path" class="shrink-0">
                            <img :src="'/storage/' + form.cover_image_path" class="h-20 rounded-xl object-cover" />
                        </div>
                        <button @click="generateCoverImage" :disabled="generatingCover || !form.title" type="button"
                            class="rounded-xl bg-gray-800 border border-gray-700 px-4 py-2 text-xs text-gray-300 hover:text-white transition disabled:opacity-50">
                            {{ generatingCover ? 'Gerando com DALL-E 3...' : 'Gerar com IA' }}
                        </button>
                        <label class="rounded-xl bg-gray-800 border border-gray-700 px-4 py-2 text-xs text-gray-300 hover:text-white transition cursor-pointer">
                            Upload
                            <input type="file" accept="image/*" @change="uploadCover" class="hidden" />
                        </label>
                        <button v-if="form.cover_image_path" @click="form.cover_image_path = ''" type="button"
                            class="text-xs text-red-400 hover:text-red-300">Remover</button>
                    </div>
                </div>
            </div>

            <div class="flex justify-between">
                <button @click="currentStep = 1" type="button" class="rounded-xl border border-gray-700 px-6 py-2.5 text-sm text-gray-400 hover:text-white transition">Voltar</button>
                <button @click="currentStep = 3" type="button" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 transition">Próximo: SEO</button>
            </div>
        </div>

        <!-- Step 3: SEO -->
        <div v-if="currentStep === 3" class="max-w-3xl mx-auto space-y-4">
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-white">Otimização SEO</h2>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium" :class="seoScore >= 80 ? 'text-emerald-400' : seoScore >= 50 ? 'text-yellow-400' : 'text-red-400'">
                            {{ seoScore }}%
                        </span>
                        <div class="w-20 h-2 bg-gray-800 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all" :class="seoScore >= 80 ? 'bg-emerald-500' : seoScore >= 50 ? 'bg-yellow-500' : 'bg-red-500'" :style="{ width: seoScore + '%' }" />
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Meta Título</label>
                        <input v-model="form.meta_title" type="text" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <p class="text-[10px] mt-1" :class="(form.meta_title?.length || 0) > 60 ? 'text-red-400' : 'text-gray-600'">{{ (form.meta_title || '').length }}/60 caracteres</p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Meta Descrição</label>
                        <textarea v-model="form.meta_description" rows="2" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <p class="text-[10px] mt-1" :class="(form.meta_description?.length || 0) > 160 ? 'text-red-400' : 'text-gray-600'">{{ (form.meta_description || '').length }}/160 caracteres</p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Palavras-chave</label>
                        <input v-model="form.meta_keywords" type="text" placeholder="keyword1, keyword2, keyword3"
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
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
                        <label class="text-sm text-gray-400 mb-1 block">Tags</label>
                        <div class="flex flex-wrap gap-1.5 mb-2">
                            <span v-for="(tag, i) in form.tags" :key="i"
                                class="inline-flex items-center gap-1 rounded-lg bg-indigo-600/20 border border-indigo-500/30 px-2 py-0.5 text-xs text-indigo-400">
                                {{ tag }}
                                <button @click="removeTag(i)" type="button" class="hover:text-white">&times;</button>
                            </span>
                        </div>
                        <div class="flex gap-2">
                            <input v-model="tagInput" type="text" placeholder="Nova tag" @keyup.enter="addTag"
                                class="flex-1 rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <button @click="addTag" type="button" class="rounded-xl bg-gray-800 border border-gray-700 px-3 py-2 text-xs text-gray-400 hover:text-white transition">Adicionar</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-between">
                <button @click="currentStep = 2" type="button" class="rounded-xl border border-gray-700 px-6 py-2.5 text-sm text-gray-400 hover:text-white transition">Voltar</button>
                <button @click="currentStep = 4" type="button" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 transition">Próximo: Revisão</button>
            </div>
        </div>

        <!-- Step 4: Revisão -->
        <div v-if="currentStep === 4" class="max-w-3xl mx-auto space-y-4">
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Revisão Final</h2>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between py-2 border-b border-gray-800">
                        <span class="text-gray-500">Título</span>
                        <span class="text-white font-medium">{{ form.title || '(vazio)' }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-800">
                        <span class="text-gray-500">Palavras</span>
                        <span class="text-white">{{ wordCount }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-800">
                        <span class="text-gray-500">SEO Score</span>
                        <span :class="seoScore >= 80 ? 'text-emerald-400' : seoScore >= 50 ? 'text-yellow-400' : 'text-red-400'" class="font-medium">{{ seoScore }}%</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-800">
                        <span class="text-gray-500">Destino</span>
                        <span class="text-white">{{ connections.find(c => c.id === form.wordpress_connection_id)?.name || 'Apenas local' }}</span>
                    </div>
                    <div v-if="form.cover_image_path" class="py-2 border-b border-gray-800">
                        <p class="text-gray-500 mb-2">Capa</p>
                        <img :src="'/storage/' + form.cover_image_path" class="h-32 rounded-xl object-cover" />
                    </div>
                    <div v-if="form.ai_model_used" class="flex justify-between py-2 border-b border-gray-800">
                        <span class="text-gray-500">Gerado com IA</span>
                        <span class="text-indigo-400">{{ form.ai_model_used }} ({{ form.tokens_used }} tokens)</span>
                    </div>
                </div>

                <div class="mt-6 space-y-3">
                    <div class="flex gap-2">
                        <select v-model="form.status" class="flex-1 rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="draft">Salvar como Rascunho</option>
                            <option value="pending_review">Enviar para Revisão</option>
                            <option value="approved">Marcar como Aprovado</option>
                        </select>
                    </div>
                    <button @click="submit" :disabled="form.processing || !form.title"
                        class="w-full rounded-xl bg-emerald-600 py-3 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50 transition">
                        {{ form.processing ? 'Salvando...' : 'Criar Artigo' }}
                    </button>
                </div>
            </div>

            <div class="flex justify-start">
                <button @click="currentStep = 3" type="button" class="rounded-xl border border-gray-700 px-6 py-2.5 text-sm text-gray-400 hover:text-white transition">Voltar</button>
            </div>
        </div>

        <!-- Modal: Nova conexão WordPress -->
        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="showConnectionModal" class="fixed inset-0 z-[60] flex items-center justify-center">
                    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showConnectionModal = false" />
                    <div class="relative w-full max-w-md rounded-2xl bg-gray-900 border border-gray-700 p-6 shadow-2xl mx-4">
                        <h3 class="text-lg font-semibold text-white mb-4">Adicionar Site WordPress</h3>

                        <div class="space-y-3">
                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Nome da conexão</label>
                                <input v-model="connectionForm.name" type="text" placeholder="Blog Principal"
                                    class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">URL do site</label>
                                <input v-model="connectionForm.site_url" type="url" placeholder="https://meublog.com.br"
                                    class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Usuário WordPress</label>
                                <input v-model="connectionForm.wp_username" type="text" placeholder="admin"
                                    class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Application Password</label>
                                <input v-model="connectionForm.wp_app_password" type="password" placeholder="xxxx xxxx xxxx xxxx"
                                    class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                <p class="text-[10px] text-gray-600 mt-1">WordPress > Usuários > Perfil > Application Passwords</p>
                            </div>

                            <div v-if="connectionError" class="rounded-xl bg-red-900/30 border border-red-700/50 p-3 text-xs text-red-300">{{ connectionError }}</div>
                        </div>

                        <div class="flex justify-end gap-2 mt-5">
                            <button @click="showConnectionModal = false" type="button" class="rounded-xl px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                            <button @click="saveConnection" :disabled="connectionSaving || !connectionForm.site_url || !connectionForm.wp_username" type="button"
                                class="rounded-xl bg-indigo-600 px-6 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                                {{ connectionSaving ? 'Testando e salvando...' : 'Conectar' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AuthenticatedLayout>
</template>
