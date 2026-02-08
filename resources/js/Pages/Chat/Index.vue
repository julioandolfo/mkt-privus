<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';

const chatGuideSteps = [
    { title: 'Inicie uma conversa', description: 'Clique em "Nova Conversa" e escolha o modelo de IA desejado: GPT-4o (OpenAI), Claude (Anthropic) ou Gemini (Google).' },
    { title: 'Contexto automático', description: 'A marca ativa é enviada automaticamente como contexto para a IA, gerando respostas personalizadas ao seu negócio.' },
    { title: 'Troque de modelo a qualquer momento', description: 'Dentro de uma conversa, use o seletor no topo para mudar entre modelos e comparar respostas.' },
    { title: 'Organize suas conversas', description: 'Fixe conversas importantes com a estrela, edite títulos e exclua as que não precisa mais.' },
];

const chatGuideTips = [
    'Use o chat para brainstorming de ideias, criação de copy, estratégias de marketing e análise de concorrentes.',
    'Cada conversa mantém histórico completo e mostra o consumo de tokens por mensagem.',
    'Para respostas mais criativas, experimente o Claude. Para análises técnicas, o GPT-4o é recomendado.',
];
import { ref } from 'vue';

interface Conversation {
    id: number;
    title: string;
    model: string;
    is_pinned: boolean;
    updated_at: string;
    last_message: string | null;
    brand_id: number | null;
}

interface ModelOption {
    value: string;
    label: string;
    provider: string;
}

const props = defineProps<{
    conversations: Conversation[];
    models: ModelOption[];
}>();

const showNewChat = ref(false);
const form = useForm({
    title: '',
    model: 'gpt-4o',
});

function createConversation() {
    form.post(route('chat.store'), {
        onSuccess: () => {
            showNewChat.value = false;
            form.reset();
        },
    });
}

function deleteConversation(conv: Conversation) {
    if (confirm(`Excluir a conversa "${conv.title}"?`)) {
        router.delete(route('chat.destroy', conv.id));
    }
}

function getProviderColor(model: string): string {
    if (model.includes('gpt')) return 'bg-emerald-500/20 text-emerald-400';
    if (model.includes('claude')) return 'bg-orange-500/20 text-orange-400';
    if (model.includes('gemini')) return 'bg-blue-500/20 text-blue-400';
    return 'bg-gray-500/20 text-gray-400';
}

function getProviderLabel(model: string): string {
    const found = props.models.find(m => m.value === model);
    return found?.label || model;
}
</script>

<template>
    <Head title="Chat IA" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-white">Chat IA</h1>
                <button
                    @click="showNewChat = true"
                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Nova Conversa
                </button>
            </div>
        </template>

        <!-- Modal nova conversa -->
        <div v-if="showNewChat" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showNewChat = false">
            <div class="w-full max-w-md rounded-2xl bg-gray-900 border border-gray-800 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Nova Conversa</h2>
                <form @submit.prevent="createConversation" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Título (opcional)</label>
                        <input
                            v-model="form.title"
                            type="text"
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="Será gerado automaticamente..."
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Modelo de IA</label>
                        <select
                            v-model="form.model"
                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option v-for="m in models" :key="m.value" :value="m.value">
                                {{ m.label }} ({{ m.provider }})
                            </option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="showNewChat = false" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition">
                            Cancelar
                        </button>
                        <button type="submit" :disabled="form.processing" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                            Iniciar Chat
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <GuideBox
            title="Como usar o Chat com IA"
            description="Converse com múltiplos modelos de inteligência artificial, todos com contexto da sua marca ativa."
            :steps="chatGuideSteps"
            :tips="chatGuideTips"
            color="purple"
            storage-key="chat-guide"
            class="mb-6"
        />

        <!-- Lista de conversas -->
        <div v-if="conversations.length === 0" class="flex flex-col items-center justify-center rounded-2xl bg-gray-900 border border-gray-800 p-16 text-center">
            <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-600/20 mb-6">
                <svg class="w-8 h-8 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-white mb-2">Nenhuma conversa</h3>
            <p class="text-gray-400 mb-6 max-w-sm">Inicie uma conversa com IA usando GPT-4o, Claude, Gemini e outros modelos.</p>
            <button @click="showNewChat = true" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                Iniciar primeira conversa
            </button>
        </div>

        <div v-else class="space-y-2">
            <Link
                v-for="conv in conversations"
                :key="conv.id"
                :href="route('chat.show', conv.id)"
                class="group flex items-center gap-4 rounded-2xl bg-gray-900 border border-gray-800 p-4 hover:border-gray-700 transition-all"
            >
                <!-- Icon -->
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gray-800 text-gray-400 group-hover:bg-indigo-600/20 group-hover:text-indigo-400 transition shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                    </svg>
                </div>

                <!-- Content -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-0.5">
                        <h3 class="font-medium text-white truncate">{{ conv.title }}</h3>
                        <svg v-if="conv.is_pinned" class="w-3.5 h-3.5 text-amber-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                        </svg>
                    </div>
                    <p v-if="conv.last_message" class="text-sm text-gray-500 truncate">{{ conv.last_message }}</p>
                </div>

                <!-- Meta -->
                <div class="flex items-center gap-3 shrink-0">
                    <span :class="['inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-medium', getProviderColor(conv.model)]">
                        {{ getProviderLabel(conv.model) }}
                    </span>
                    <span class="text-xs text-gray-500 hidden sm:block">{{ conv.updated_at }}</span>
                    <button
                        @click.prevent="deleteConversation(conv)"
                        class="opacity-0 group-hover:opacity-100 flex items-center justify-center h-8 w-8 rounded-lg text-gray-500 hover:bg-red-500/10 hover:text-red-400 transition"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                        </svg>
                    </button>
                </div>
            </Link>
        </div>
    </AuthenticatedLayout>
</template>
