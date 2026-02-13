<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({
    providers: Array,
    lists: Array,
    templates: Array,
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
    if (template.subject) form.subject = template.subject;
}

async function generateWithAI() {
    aiError.value = '';
    aiGenerating.value = true;
    try {
        const { data } = await axios.post(route('email.editor.generate-ai'), {
            prompt: aiPrompt.value || 'Crie um email de marketing profissional com cabeçalho, conteúdo principal, CTA e rodapé.',
            type: 'content',
        });
        if (data.success && data.content) {
            form.html_content = data.content;
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
                <button
                    type="button"
                    :class="[
                        'rounded-lg px-4 py-2 text-sm font-medium transition',
                        currentStep === step.num ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white',
                    ]"
                    @click="currentStep = step.num"
                >
                    {{ step.num }}. {{ step.label }}
                </button>
                <span v-if="i < stepLabels.length - 1" class="text-gray-600">→</span>
            </template>
        </div>

        <form @submit.prevent="submit" class="max-w-3xl space-y-6">
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
            <div v-show="currentStep === 3" class="rounded-xl border border-gray-800 bg-gray-900 p-6 space-y-5">
                <h2 class="text-lg font-semibold text-white">Conteúdo</h2>

                <div v-if="templates?.length">
                    <label class="text-sm font-medium text-gray-300 mb-2 block">Usar template</label>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="t in templates"
                            :key="t.id"
                            type="button"
                            :class="[
                                'rounded-lg border p-3 text-left transition',
                                form.email_template_id === t.id ? 'border-indigo-500 bg-indigo-900/30 text-indigo-400' : 'border-gray-700 bg-gray-800 text-gray-300 hover:bg-gray-700',
                            ]"
                            @click="applyTemplate(t)"
                        >
                            {{ t.name }}
                        </button>
                    </div>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <label class="text-sm font-medium text-gray-300">Conteúdo HTML</label>
                        <button type="button" @click="generateWithAI" :disabled="aiGenerating" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                            {{ aiGenerating ? 'Gerando...' : 'Gerar com IA' }}
                        </button>
                    </div>
                    <textarea
                        v-model="form.html_content"
                        rows="16"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-3 font-mono text-sm text-white"
                        placeholder="Cole o HTML do email ou use o botão 'Gerar com IA'..."
                    ></textarea>
                    <input v-model="aiPrompt" class="mt-2 w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2 text-sm text-gray-400" placeholder="Prompt para IA (ex: email promocional de Black Friday)" />
                    <p v-if="aiError" class="mt-1 text-xs text-red-400">{{ aiError }}</p>
                    <p v-if="form.errors.html_content" class="mt-1 text-xs text-red-400">{{ form.errors.html_content }}</p>
                </div>
            </div>

            <!-- Step 4: Revisão -->
            <div v-show="currentStep === 4" class="rounded-xl border border-gray-800 bg-gray-900 p-6 space-y-5">
                <h2 class="text-lg font-semibold text-white">Revisão</h2>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between border-b border-gray-800 pb-2">
                        <span class="text-gray-500">Nome</span>
                        <span class="text-white">{{ form.name }}</span>
                    </div>
                    <div class="flex justify-between border-b border-gray-800 pb-2">
                        <span class="text-gray-500">Assunto</span>
                        <span class="text-white">{{ form.subject }}</span>
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
                    <div class="flex justify-between pb-2">
                        <span class="text-gray-500">Conteúdo</span>
                        <span class="text-white">{{ form.html_content ? 'Definido' : 'Não definido' }}</span>
                    </div>
                </div>

                <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-emerald-600 py-3 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50">
                    {{ form.processing ? 'Criando...' : 'Criar Campanha' }}
                </button>
            </div>

            <!-- Navigation -->
            <div class="flex justify-between rounded-xl border border-gray-800 bg-gray-900 p-4">
                <button type="button" v-if="currentStep > 1" @click="prevStep" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800 hover:text-white">
                    Anterior
                </button>
                <span v-else></span>
                <button v-if="currentStep < totalSteps" type="button" @click="nextStep" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    Próximo
                </button>
                <span v-else></span>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
