<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, nextTick, onMounted, watch } from 'vue';
import axios from 'axios';

interface Message {
    id: number;
    role: 'user' | 'assistant' | 'system';
    content: string;
    model?: string;
    input_tokens?: number;
    output_tokens?: number;
    created_at: string;
}

interface Conversation {
    id: number;
    title: string;
    model: string;
    is_pinned: boolean;
    brand_id: number | null;
}

interface ConversationListItem {
    id: number;
    title: string;
    model: string;
    is_pinned: boolean;
    updated_at: string;
}

interface ModelOption {
    value: string;
    label: string;
    provider: string;
}

const props = defineProps<{
    conversation: Conversation;
    messages: Message[];
    conversations: ConversationListItem[];
    models: ModelOption[];
}>();

const localMessages = ref<Message[]>([...props.messages]);
const messageInput = ref('');
const isLoading = ref(false);
const messagesContainer = ref<HTMLElement | null>(null);
const selectedModel = ref(props.conversation.model);
const showSidebar = ref(true);
const editingTitle = ref(false);
const editTitle = ref(props.conversation.title);

// Scroll to bottom
function scrollToBottom() {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    });
}

onMounted(scrollToBottom);
watch(() => localMessages.value.length, scrollToBottom);

// Send message
async function sendMessage() {
    const content = messageInput.value.trim();
    if (!content || isLoading.value) return;

    messageInput.value = '';
    isLoading.value = true;

    // Add user message optimistically
    const tempUserMsg: Message = {
        id: Date.now(),
        role: 'user',
        content,
        created_at: new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }),
    };
    localMessages.value.push(tempUserMsg);
    scrollToBottom();

    // Add placeholder for assistant
    const tempAssistantMsg: Message = {
        id: Date.now() + 1,
        role: 'assistant',
        content: '',
        model: selectedModel.value,
        created_at: '',
    };
    localMessages.value.push(tempAssistantMsg);
    scrollToBottom();

    try {
        const response = await axios.post(route('chat.message', props.conversation.id), {
            content,
            model: selectedModel.value,
        });

        // Replace placeholder with real response
        const lastIndex = localMessages.value.length - 1;
        localMessages.value[lastIndex] = response.data.message;
        scrollToBottom();
    } catch (error: any) {
        // Remove placeholder
        localMessages.value.pop();
        const errorMsg = error.response?.data?.error || 'Erro ao enviar mensagem. Verifique suas chaves de API.';
        localMessages.value.push({
            id: Date.now() + 2,
            role: 'assistant',
            content: `**Erro:** ${errorMsg}`,
            created_at: new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }),
        });
    } finally {
        isLoading.value = false;
    }
}

function saveTitle() {
    router.put(route('chat.update', props.conversation.id), {
        title: editTitle.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { editingTitle.value = false; },
    });
}

function togglePin() {
    router.put(route('chat.update', props.conversation.id), {
        title: props.conversation.title,
        is_pinned: !props.conversation.is_pinned,
    }, { preserveScroll: true });
}

function getProviderColor(model: string): string {
    if (model.includes('gpt')) return 'text-emerald-400';
    if (model.includes('claude')) return 'text-orange-400';
    if (model.includes('gemini')) return 'text-blue-400';
    return 'text-gray-400';
}

function handleKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

function createNewChat() {
    router.post(route('chat.store'), {
        model: selectedModel.value,
    });
}

const currentModelLabel = computed(() => {
    return props.models.find(m => m.value === selectedModel.value)?.label || selectedModel.value;
});
</script>

<template>
    <Head :title="conversation.title" />

    <div class="flex h-screen bg-gray-950">
        <!-- Chat Sidebar -->
        <aside
            :class="[
                'flex flex-col bg-gray-900 border-r border-gray-800 transition-all duration-300 shrink-0',
                showSidebar ? 'w-72' : 'w-0 overflow-hidden',
            ]"
        >
            <!-- Sidebar header -->
            <div class="flex items-center justify-between p-3 border-b border-gray-800">
                <Link :href="route('chat.index')" class="flex items-center gap-2 text-sm text-gray-400 hover:text-white transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                    Voltar
                </Link>
                <button
                    @click="createNewChat"
                    class="flex items-center justify-center h-8 w-8 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition"
                    title="Nova conversa"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                </button>
            </div>

            <!-- Conversation list -->
            <nav class="flex-1 overflow-y-auto p-2 space-y-0.5">
                <Link
                    v-for="conv in conversations"
                    :key="conv.id"
                    :href="route('chat.show', conv.id)"
                    :class="[
                        'flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm transition',
                        conv.id === conversation.id
                            ? 'bg-indigo-600/20 text-indigo-300 border border-indigo-500/30'
                            : 'text-gray-400 hover:text-white hover:bg-gray-800',
                    ]"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                    </svg>
                    <span class="truncate flex-1">{{ conv.title }}</span>
                    <svg v-if="conv.is_pinned" class="w-3 h-3 text-amber-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                    </svg>
                </Link>
            </nav>
        </aside>

        <!-- Main chat area -->
        <div class="flex flex-1 flex-col min-w-0">
            <!-- Chat header -->
            <header class="flex items-center gap-3 border-b border-gray-800 bg-gray-900/80 backdrop-blur-xl px-4 py-3">
                <button
                    @click="showSidebar = !showSidebar"
                    class="flex items-center justify-center h-9 w-9 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <!-- Title (editable) -->
                <div class="flex-1 min-w-0">
                    <div v-if="editingTitle" class="flex items-center gap-2">
                        <input
                            v-model="editTitle"
                            @keydown.enter="saveTitle"
                            @keydown.escape="editingTitle = false"
                            class="rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500 py-1 px-2"
                            autofocus
                        />
                        <button @click="saveTitle" class="text-indigo-400 hover:text-indigo-300 text-sm">Salvar</button>
                    </div>
                    <button v-else @click="editingTitle = true; editTitle = conversation.title" class="text-white font-medium truncate hover:text-indigo-400 transition text-left">
                        {{ conversation.title }}
                    </button>
                </div>

                <!-- Model selector -->
                <select
                    v-model="selectedModel"
                    class="rounded-xl bg-gray-800 border-gray-700 text-sm text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 py-1.5"
                >
                    <option v-for="m in models" :key="m.value" :value="m.value">
                        {{ m.label }}
                    </option>
                </select>

                <!-- Pin button -->
                <button
                    @click="togglePin"
                    :class="['flex items-center justify-center h-9 w-9 rounded-lg transition', conversation.is_pinned ? 'text-amber-400 bg-amber-500/10' : 'text-gray-400 hover:text-white hover:bg-gray-800']"
                    title="Fixar conversa"
                >
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                    </svg>
                </button>
            </header>

            <!-- Messages area -->
            <div ref="messagesContainer" class="flex-1 overflow-y-auto">
                <!-- Empty state -->
                <div v-if="localMessages.length === 0" class="flex flex-col items-center justify-center h-full text-center px-4">
                    <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-600/20 mb-6">
                        <svg class="w-8 h-8 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-white mb-2">Comece a conversar</h3>
                    <p class="text-gray-400 max-w-md mb-2">
                        Usando <span :class="getProviderColor(selectedModel)" class="font-medium">{{ currentModelLabel }}</span>
                    </p>
                    <p class="text-gray-500 text-sm max-w-md mb-6">
                        O contexto da sua marca ativa será incluído automaticamente nas respostas.
                    </p>

                    <!-- Sugestoes de uso -->
                    <div class="max-w-lg w-full">
                        <p class="text-xs text-gray-600 uppercase tracking-wider mb-3">Sugestões de uso</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <button
                                v-for="(suggestion, idx) in [
                                    'Crie 5 ideias de posts para Instagram sobre nosso produto',
                                    'Sugira uma estratégia de conteúdo para o próximo mês',
                                    'Analise nossos concorrentes e sugira diferenciais',
                                    'Crie um calendário editorial semanal para nossas redes',
                                    'Escreva um artigo de blog sobre tendências do setor',
                                    'Gere copy para uma campanha de lançamento de produto',
                                ]"
                                :key="idx"
                                @click="messageInput = suggestion"
                                class="text-left rounded-xl bg-gray-800/50 border border-gray-700/50 p-3 text-sm text-gray-400 hover:text-white hover:bg-gray-800 hover:border-gray-600 transition"
                            >
                                {{ suggestion }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <div v-else class="max-w-4xl mx-auto px-4 py-6 space-y-6">
                    <div
                        v-for="msg in localMessages"
                        :key="msg.id"
                        :class="['flex gap-4', msg.role === 'user' ? 'justify-end' : 'justify-start']"
                    >
                        <!-- Avatar -->
                        <div v-if="msg.role === 'assistant'" class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white text-xs font-bold shrink-0 mt-1">
                            IA
                        </div>

                        <!-- Message bubble -->
                        <div
                            :class="[
                                'rounded-2xl px-4 py-3 max-w-[80%]',
                                msg.role === 'user'
                                    ? 'bg-indigo-600 text-white'
                                    : 'bg-gray-800 text-gray-200 border border-gray-700',
                            ]"
                        >
                            <!-- Loading indicator -->
                            <div v-if="msg.role === 'assistant' && !msg.content && isLoading" class="flex items-center gap-1.5 py-1">
                                <div class="h-2 w-2 rounded-full bg-indigo-400 animate-bounce" style="animation-delay: 0ms" />
                                <div class="h-2 w-2 rounded-full bg-indigo-400 animate-bounce" style="animation-delay: 150ms" />
                                <div class="h-2 w-2 rounded-full bg-indigo-400 animate-bounce" style="animation-delay: 300ms" />
                            </div>

                            <!-- Content -->
                            <div v-else class="whitespace-pre-wrap text-sm leading-relaxed break-words">{{ msg.content }}</div>

                            <!-- Meta -->
                            <div v-if="msg.role === 'assistant' && msg.content && msg.output_tokens" class="flex items-center gap-3 mt-2 pt-2 border-t border-gray-700/50">
                                <span class="text-xs text-gray-500">{{ msg.created_at }}</span>
                                <span :class="['text-xs', getProviderColor(msg.model || '')]">{{ msg.model }}</span>
                                <span class="text-xs text-gray-600">{{ (msg.input_tokens || 0) + (msg.output_tokens || 0) }} tokens</span>
                            </div>
                            <div v-else-if="msg.role === 'user' && msg.created_at" class="text-right mt-1">
                                <span class="text-xs text-indigo-200/60">{{ msg.created_at }}</span>
                            </div>
                        </div>

                        <!-- User avatar -->
                        <div v-if="msg.role === 'user'" class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-700 text-white text-xs font-bold shrink-0 mt-1">
                            {{ $page.props.auth?.user?.name?.charAt(0)?.toUpperCase() }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Input area -->
            <div class="border-t border-gray-800 bg-gray-900/80 backdrop-blur-xl p-4">
                <div class="max-w-4xl mx-auto">
                    <div class="flex items-end gap-3">
                        <div class="flex-1 relative">
                            <textarea
                                v-model="messageInput"
                                @keydown="handleKeydown"
                                :disabled="isLoading"
                                rows="1"
                                class="w-full resize-none rounded-2xl bg-gray-800 border-gray-700 text-white placeholder-gray-500 focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 pr-12 text-sm"
                                :class="{ 'opacity-50': isLoading }"
                                placeholder="Digite sua mensagem... (Enter para enviar, Shift+Enter para nova linha)"
                                style="min-height: 48px; max-height: 200px"
                                @input="(e: Event) => {
                                    const t = e.target as HTMLTextAreaElement;
                                    t.style.height = 'auto';
                                    t.style.height = Math.min(t.scrollHeight, 200) + 'px';
                                }"
                            />
                        </div>
                        <button
                            @click="sendMessage"
                            :disabled="!messageInput.trim() || isLoading"
                            class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-600 text-white hover:bg-indigo-700 transition disabled:opacity-30 disabled:cursor-not-allowed shrink-0"
                        >
                            <svg v-if="!isLoading" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <line x1="22" y1="2" x2="11" y2="13" /><polygon points="22 2 15 22 11 13 2 9 22 2" />
                            </svg>
                            <svg v-else class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                        </button>
                    </div>
                    <p class="text-xs text-gray-600 mt-2 text-center">
                        <span :class="getProviderColor(selectedModel)">{{ currentModelLabel }}</span>
                        &middot; As respostas podem conter erros. Verifique informações importantes.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
