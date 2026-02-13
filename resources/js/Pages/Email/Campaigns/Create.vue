<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({
    providers: Array,
    lists: Array,
    templates: Array,
    starterTemplates: Array,
});

const currentStep = ref(1);
const totalSteps = 4;

const defaultProviderId = computed(() => {
    const p = props.providers?.find((x) => x.is_default) || props.providers?.[0];
    return p?.id ?? '';
});

const form = useForm({
    name: '',
    subject: '',
    preview_text: '',
    from_name: '',
    from_email: '',
    reply_to: '',
    email_provider_id: defaultProviderId.value || '',
    type: 'regular',
    lists: [],
    exclude_lists: [],
    email_template_id: null,
    html_content: '',
});

// AI generation state
const aiPrompt = ref('');
const aiSubjectPrompt = ref('');
const aiGenerating = ref(false);
const aiSubjectGenerating = ref(false);
const aiError = ref('');

// Template selection
const templateSource = ref('none'); // 'none' | 'custom' | 'starter' | 'ai'
const selectedTemplate = ref(null);
const showHtmlEditor = ref(false);
const previewHtml = ref('');
const showPreviewModal = ref(false);

const estimatedRecipients = computed(() => {
    const includeIds = form.lists;
    const excludeIds = form.exclude_lists;
    let total = 0;
    for (const list of props.lists || []) {
        if (includeIds.includes(list.id) && !excludeIds.includes(list.id)) {
            total += list.contacts_count || 0;
        }
    }
    return total;
});

const stepLabels = [
    { num: 1, label: 'Info Básica' },
    { num: 2, label: 'Destinatários' },
    { num: 3, label: 'Conteúdo' },
    { num: 4, label: 'Revisão' },
];

function nextStep() {
    if (currentStep.value < totalSteps) currentStep.value++;
}

function prevStep() {
    if (currentStep.value > 1) currentStep.value--;
}

function toggleList(listId, isExclude = false) {
    const arr = isExclude ? form.exclude_lists : form.lists;
    const idx = arr.indexOf(listId);
    if (idx >= 0) {
        arr.splice(idx, 1);
    } else {
        arr.push(listId);
    }
}

function applyTemplate(template) {
    form.email_template_id = template.id;
    if (template.html_content) form.html_content = template.html_content;
    if (template.subject && !form.subject) form.subject = template.subject;
    selectedTemplate.value = template;
    templateSource.value = 'custom';
}

function applyStarterTemplate(starter) {
    form.html_content = starter.html_content;
    form.email_template_id = null;
    if (starter.subject && !form.subject) form.subject = starter.subject;
    selectedTemplate.value = starter;
    templateSource.value = 'starter';
}

function clearTemplate() {
    form.email_template_id = null;
    form.html_content = '';
    selectedTemplate.value = null;
    templateSource.value = 'none';
}

function previewTemplate(html) {
    previewHtml.value = html;
    showPreviewModal.value = true;
}

async function generateWithAI() {
    aiError.value = '';
    aiGenerating.value = true;
    try {
        const { data } = await axios.post(route('email.editor.generate-ai'), {
            prompt: aiPrompt.value || 'Crie um email de marketing profissional com cabeçalho, conteúdo principal, CTA e rodapé.',
            type: 'full_template',
        });
        if (data.success && data.content) {
            form.html_content = data.content;
            templateSource.value = 'ai';
            selectedTemplate.value = { name: 'Gerado por IA', id: null };
        } else {
            aiError.value = data.error || 'Falha ao gerar conteúdo.';
        }
    } catch (e) {
        aiError.value = e.response?.data?.error || e.message || 'Erro ao gerar conteúdo.';
    } finally {
        aiGenerating.value = false;
    }
}

async function generateSubjectWithAI() {
    aiSubjectGenerating.value = true;
    try {
        const { data } = await axios.post(route('email.editor.generate-ai'), {
            prompt: aiSubjectPrompt.value || form.name || 'Assunto de email marketing',
            type: 'subject',
        });
        if (data.success && Array.isArray(data.content)) {
            form.subject = data.content[0] || form.subject;
        } else if (typeof data.content === 'string') {
            try {
                const parsed = JSON.parse(data.content.replace(/```json?\s*/g, ''));
                if (Array.isArray(parsed) && parsed[0]) form.subject = parsed[0];
            } catch {
                form.subject = data.content;
            }
        }
    } catch (e) {
        aiError.value = e.response?.data?.error || e.message || 'Erro ao gerar assunto.';
    } finally {
        aiSubjectGenerating.value = false;
    }
}

const categoryLabels = { marketing: 'Marketing', newsletter: 'Newsletter', promotional: 'Promoção', welcome: 'Boas-vindas', transactional: 'Transacional' };

function submit() {
    form.post(route('email.campaigns.store'));
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-2xl font-bold text-white">Nova Campanha</h1>
        </template>

        <!-- Step indicator -->
        <div class="mb-8 flex items-center gap-2">
            <template v-for="(step, i) in stepLabels" :key="step.num">
                <button type="button"
                    :class="['rounded-lg px-4 py-2 text-sm font-medium transition', currentStep === step.num ? 'bg-indigo-600 text-white' : step.num < currentStep ? 'bg-indigo-900/30 text-indigo-400 border border-indigo-500/30' : 'bg-gray-800 text-gray-400 hover:text-white']"
                    @click="currentStep = step.num">
                    {{ step.num }}. {{ step.label }}
                </button>
                <span v-if="i < stepLabels.length - 1" class="text-gray-600">→</span>
            </template>
        </div>

        <form @submit.prevent="submit" class="max-w-4xl space-y-6">
            <!-- Step 1: Info Básica -->
            <div v-show="currentStep === 1" class="rounded-xl border border-gray-800 bg-gray-900 p-6 space-y-5">
                <h2 class="text-lg font-semibold text-white">Informações Básicas</h2>

                <div>
                    <label class="text-sm font-medium text-gray-300">Nome da Campanha *</label>
                    <input v-model="form.name" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white" placeholder="Ex: Newsletter Dezembro" />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-400">{{ form.errors.name }}</p>
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <label class="text-sm font-medium text-gray-300">Assunto *</label>
                        <button type="button" @click="generateSubjectWithAI" :disabled="aiSubjectGenerating" class="text-xs text-indigo-400 hover:text-indigo-300">
                            {{ aiSubjectGenerating ? 'Gerando...' : 'Gerar com IA' }}
                        </button>
                    </div>
                    <input v-model="form.subject" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white" placeholder="Assunto do email" />
                    <input v-model="aiSubjectPrompt" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2 text-sm text-gray-400" placeholder="Prompt para assunto (opcional)" />
                    <p v-if="form.errors.subject" class="mt-1 text-xs text-red-400">{{ form.errors.subject }}</p>
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-300">Preview Text</label>
                    <input v-model="form.preview_text" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white" placeholder="Texto que aparece no preview do cliente" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium text-gray-300">Nome do Remetente</label>
                        <input v-model="form.from_name" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white" placeholder="Ex: Marketing" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-300">Email do Remetente</label>
                        <input v-model="form.from_email" type="email" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white" placeholder="contato@empresa.com" />
                        <p v-if="form.errors.from_email" class="mt-1 text-xs text-red-400">{{ form.errors.from_email }}</p>
                    </div>
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-300">Reply-To</label>
                    <input v-model="form.reply_to" type="email" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white" placeholder="resposta@empresa.com" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium text-gray-300">Provedor de Email *</label>
                        <select v-model="form.email_provider_id" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white">
                            <option value="">Selecione um provedor</option>
                            <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }} ({{ p.type }})</option>
                        </select>
                        <p v-if="form.errors.email_provider_id" class="mt-1 text-xs text-red-400">{{ form.errors.email_provider_id }}</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-300">Tipo</label>
                        <select v-model="form.type" class="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white">
                            <option value="regular">Regular</option>
                            <option value="ab_test">A/B Test</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Step 2: Destinatários -->
            <div v-show="currentStep === 2" class="rounded-xl border border-gray-800 bg-gray-900 p-6 space-y-5">
                <h2 class="text-lg font-semibold text-white">Destinatários</h2>

                <div>
                    <label class="text-sm font-medium text-gray-300 mb-2 block">Listas para incluir *</label>
                    <div class="space-y-2">
                        <label v-for="list in lists" :key="list.id" class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-700 bg-gray-800 p-3 hover:bg-gray-700/50">
                            <span class="flex items-center gap-2">
                                <input type="checkbox" :checked="form.lists.includes(list.id)" @change="toggleList(list.id)" class="rounded border-gray-600 text-indigo-600" />
                                <span class="text-white">{{ list.name }}</span>
                            </span>
                            <span class="text-xs text-gray-500">{{ (list.contacts_count || 0).toLocaleString('pt-BR') }} contatos</span>
                        </label>
                    </div>
                    <p v-if="form.errors.lists" class="mt-1 text-xs text-red-400">{{ form.errors.lists }}</p>
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-300 mb-2 block">Listas para excluir (opcional)</label>
                    <div class="space-y-2">
                        <label v-for="list in lists" :key="'exclude-' + list.id" class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-700 bg-gray-800 p-3 hover:bg-gray-700/50">
                            <span class="flex items-center gap-2">
                                <input type="checkbox" :checked="form.exclude_lists.includes(list.id)" @change="toggleList(list.id, true)" class="rounded border-gray-600 text-indigo-600" />
                                <span class="text-white">{{ list.name }}</span>
                            </span>
                            <span class="text-xs text-gray-500">{{ (list.contacts_count || 0).toLocaleString('pt-BR') }} contatos</span>
                        </label>
                    </div>
                </div>

                <p class="text-sm text-gray-400">Estimativa: <strong class="text-indigo-400">{{ estimatedRecipients.toLocaleString('pt-BR') }}</strong> destinatários</p>
            </div>

            <!-- Step 3: Conteúdo -->
            <div v-show="currentStep === 3" class="space-y-6">
                <!-- Template selecionado (banner) -->
                <div v-if="selectedTemplate" class="rounded-xl border border-indigo-500/30 bg-indigo-900/20 p-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-indigo-600/30 flex items-center justify-center">
                            <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-indigo-300">Template selecionado: {{ selectedTemplate.name }}</p>
                            <p class="text-xs text-gray-500">{{ templateSource === 'starter' ? 'Template pronto' : templateSource === 'custom' ? 'Seu template' : templateSource === 'ai' ? 'Gerado por IA' : '' }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="previewTemplate(form.html_content)" class="px-3 py-1.5 text-xs bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700">Preview</button>
                        <button type="button" @click="clearTemplate" class="px-3 py-1.5 text-xs bg-red-900/30 text-red-400 rounded-lg hover:bg-red-900/50">Trocar</button>
                    </div>
                </div>

                <!-- Seleção de template (quando nenhum selecionado) -->
                <div v-if="!selectedTemplate" class="space-y-6">
                    <!-- Seus Templates -->
                    <div v-if="templates?.length" class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-base font-semibold text-white">Seus Templates</h3>
                            <a :href="route('email.templates.create')" target="_blank" class="text-xs text-indigo-400 hover:text-indigo-300 flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                Criar novo template
                            </a>
                        </div>
                        <div class="grid gap-3 grid-cols-2 lg:grid-cols-3">
                            <button v-for="t in templates" :key="t.id" type="button" @click="applyTemplate(t)"
                                class="group rounded-xl border border-gray-700 bg-gray-800 p-4 text-left transition hover:border-indigo-500/50 hover:bg-gray-700/50">
                                <div class="w-full h-20 rounded-lg bg-gray-700 mb-3 flex items-center justify-center overflow-hidden">
                                    <svg class="w-8 h-8 text-gray-500 group-hover:text-indigo-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                </div>
                                <p class="text-sm font-medium text-white truncate">{{ t.name }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ categoryLabels[t.category] || t.category }}</p>
                            </button>
                        </div>
                    </div>

                    <!-- Templates Prontos -->
                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-base font-semibold text-white">Templates Prontos</h3>
                            <span class="text-xs text-gray-500">Selecione e personalize</span>
                        </div>
                        <div class="grid gap-3 grid-cols-2 lg:grid-cols-3">
                            <button v-for="s in starterTemplates" :key="s.id" type="button"
                                class="group rounded-xl border border-gray-700 bg-gray-800 text-left transition hover:border-indigo-500/50 hover:bg-gray-700/50 overflow-hidden">
                                <!-- Preview visual -->
                                <div class="relative w-full h-32 overflow-hidden bg-gray-700">
                                    <div :style="{ background: s.preview_color || '#6366f1' }" class="absolute inset-0 opacity-10"></div>
                                    <div class="absolute inset-0 flex flex-col items-center justify-center p-3">
                                        <!-- Mini preview icon baseado no tipo -->
                                        <div v-if="s.category === 'newsletter'" class="space-y-1 w-full max-w-[80px]">
                                            <div class="h-3 rounded-sm" :style="{ background: s.preview_color }"></div>
                                            <div class="h-6 rounded-sm bg-gray-600"></div>
                                            <div class="flex gap-1"><div class="h-4 flex-1 rounded-sm bg-gray-600"></div><div class="h-4 flex-1 rounded-sm bg-gray-600"></div></div>
                                            <div class="h-2 rounded-sm bg-gray-600 w-3/4"></div>
                                        </div>
                                        <div v-else-if="s.category === 'promotional'" class="space-y-1 w-full max-w-[80px]">
                                            <div class="h-8 rounded-sm flex items-center justify-center" :style="{ background: s.preview_color }">
                                                <span class="text-white text-[8px] font-bold">50% OFF</span>
                                            </div>
                                            <div class="flex gap-1"><div class="h-5 flex-1 rounded-sm bg-gray-600"></div><div class="h-5 flex-1 rounded-sm bg-gray-600"></div><div class="h-5 flex-1 rounded-sm bg-gray-600"></div></div>
                                            <div class="h-3 rounded-full mx-4" :style="{ background: s.preview_color, opacity: 0.6 }"></div>
                                        </div>
                                        <div v-else-if="s.category === 'welcome'" class="space-y-1 w-full max-w-[80px]">
                                            <div class="h-3 rounded-sm" :style="{ background: s.preview_color }"></div>
                                            <div class="text-center text-lg">👋</div>
                                            <div class="h-2 rounded-sm bg-gray-600"></div>
                                            <div class="space-y-0.5"><div class="h-1.5 rounded-sm bg-gray-600 w-full"></div><div class="h-1.5 rounded-sm bg-gray-600 w-full"></div><div class="h-1.5 rounded-sm bg-gray-600 w-full"></div></div>
                                            <div class="h-3 rounded-full mx-4" :style="{ background: s.preview_color, opacity: 0.6 }"></div>
                                        </div>
                                        <div v-else class="space-y-1 w-full max-w-[80px]">
                                            <div class="h-3 rounded-sm" :style="{ background: s.preview_color }"></div>
                                            <div class="h-8 rounded-sm bg-gray-600"></div>
                                            <div class="h-2 rounded-sm bg-gray-600"></div>
                                            <div class="h-2 rounded-sm bg-gray-600 w-2/3"></div>
                                            <div class="h-3 rounded-full mx-6" :style="{ background: s.preview_color, opacity: 0.6 }"></div>
                                        </div>
                                    </div>
                                    <!-- Botoes hover -->
                                    <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2">
                                        <span @click.stop="applyStarterTemplate(s)" class="px-3 py-1.5 bg-indigo-600 text-white text-xs rounded-lg font-medium">Usar</span>
                                        <span @click.stop="previewTemplate(s.html_content)" class="px-3 py-1.5 bg-gray-700 text-gray-200 text-xs rounded-lg">Preview</span>
                                    </div>
                                </div>
                                <div class="p-3">
                                    <p class="text-sm font-medium text-white">{{ s.name }}</p>
                                    <p class="text-xs text-gray-500 mt-0.5 line-clamp-2">{{ s.description }}</p>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Gerar com IA -->
                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                        <h3 class="text-base font-semibold text-white mb-4">Gerar Conteúdo com IA</h3>
                        <div class="flex gap-3">
                            <textarea v-model="aiPrompt" rows="3" class="flex-1 rounded-lg border border-gray-700 bg-gray-800 px-4 py-3 text-sm text-white" placeholder="Descreva o email que deseja criar. Ex: Email promocional de Black Friday para loja de roupas com 50% de desconto..."></textarea>
                            <div class="flex flex-col gap-2">
                                <button type="button" @click="generateWithAI" :disabled="aiGenerating || !aiPrompt"
                                    class="px-4 py-2.5 bg-indigo-600 text-white rounded-lg text-xs font-medium hover:bg-indigo-500 transition disabled:opacity-50 whitespace-nowrap flex items-center gap-2">
                                    <svg v-if="aiGenerating" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                    {{ aiGenerating ? 'Gerando...' : 'Gerar com IA' }}
                                </button>
                            </div>
                        </div>
                        <p v-if="aiError" class="mt-2 text-xs text-red-400">{{ aiError }}</p>
                    </div>

                    <!-- HTML Manual -->
                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-base font-semibold text-white">HTML Manual</h3>
                            <button type="button" @click="showHtmlEditor = !showHtmlEditor" class="text-xs text-gray-400 hover:text-white">
                                {{ showHtmlEditor ? 'Esconder' : 'Colar HTML direto' }}
                            </button>
                        </div>
                        <div v-if="showHtmlEditor">
                            <textarea v-model="form.html_content" rows="12" class="w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-3 font-mono text-xs text-white" placeholder="Cole o HTML do email aqui..."></textarea>
                            <p v-if="form.errors.html_content" class="mt-1 text-xs text-red-400">{{ form.errors.html_content }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 4: Revisão -->
            <div v-show="currentStep === 4" class="rounded-xl border border-gray-800 bg-gray-900 p-6 space-y-5">
                <h2 class="text-lg font-semibold text-white">Revisão</h2>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between border-b border-gray-800 pb-2">
                        <span class="text-gray-500">Nome</span>
                        <span class="text-white">{{ form.name || '-' }}</span>
                    </div>
                    <div class="flex justify-between border-b border-gray-800 pb-2">
                        <span class="text-gray-500">Assunto</span>
                        <span class="text-white">{{ form.subject || '-' }}</span>
                    </div>
                    <div class="flex justify-between border-b border-gray-800 pb-2">
                        <span class="text-gray-500">Provedor</span>
                        <span class="text-white">{{ providers?.find((p) => p.id == form.email_provider_id)?.name || '-' }}</span>
                    </div>
                    <div class="flex justify-between border-b border-gray-800 pb-2">
                        <span class="text-gray-500">Listas incluídas</span>
                        <span class="text-white">{{ lists?.filter((l) => form.lists.includes(l.id)).map((l) => l.name).join(', ') || '-' }}</span>
                    </div>
                    <div class="flex justify-between border-b border-gray-800 pb-2">
                        <span class="text-gray-500">Destinatários estimados</span>
                        <span class="font-semibold text-indigo-400">{{ estimatedRecipients.toLocaleString('pt-BR') }}</span>
                    </div>
                    <div class="flex justify-between border-b border-gray-800 pb-2">
                        <span class="text-gray-500">Template</span>
                        <span class="text-white">{{ selectedTemplate?.name || 'Manual / Nenhum' }}</span>
                    </div>
                    <div class="flex justify-between pb-2">
                        <span class="text-gray-500">Conteúdo</span>
                        <div class="flex items-center gap-2">
                            <span :class="form.html_content ? 'text-green-400' : 'text-red-400'">{{ form.html_content ? 'Definido' : 'Não definido' }}</span>
                            <button v-if="form.html_content" type="button" @click="previewTemplate(form.html_content)" class="text-xs text-indigo-400 hover:text-indigo-300">Preview</button>
                        </div>
                    </div>
                </div>

                <div v-if="!form.html_content" class="px-4 py-3 rounded-lg bg-yellow-900/30 border border-yellow-700/50 text-yellow-300 text-sm">
                    Atenção: Nenhum conteúdo HTML foi definido. Volte ao passo "Conteúdo" para selecionar um template ou gerar com IA.
                </div>

                <button type="submit" :disabled="form.processing || !form.html_content" class="w-full rounded-xl bg-emerald-600 py-3 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50 transition">
                    {{ form.processing ? 'Criando...' : 'Criar Campanha' }}
                </button>
            </div>

            <!-- Navigation -->
            <div class="flex justify-between rounded-xl border border-gray-800 bg-gray-900 p-4">
                <button type="button" v-if="currentStep > 1" @click="prevStep" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800 hover:text-white transition">
                    Anterior
                </button>
                <span v-else></span>
                <button v-if="currentStep < totalSteps" type="button" @click="nextStep" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition">
                    Próximo
                </button>
                <span v-else></span>
            </div>
        </form>

        <!-- Modal: Preview -->
        <Teleport to="body">
            <div v-if="showPreviewModal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/70" @click.self="showPreviewModal = false">
                <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-3xl max-h-[90vh] flex flex-col">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800 shrink-0">
                        <h3 class="text-lg font-semibold text-white">Preview do Email</h3>
                        <button @click="showPreviewModal = false" class="text-gray-400 hover:text-white text-xl">&times;</button>
                    </div>
                    <div class="flex-1 overflow-auto bg-gray-100 p-4">
                        <div class="max-w-[640px] mx-auto bg-white rounded-lg shadow-sm overflow-hidden">
                            <iframe :srcdoc="previewHtml" class="w-full border-0" style="min-height:600px;height:100%;" sandbox="allow-same-origin"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
