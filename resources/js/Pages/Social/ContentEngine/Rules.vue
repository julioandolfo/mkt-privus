<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';

const rulesGuideSteps = [
    { title: 'Crie uma pauta', description: 'Clique em "+ Nova Pauta" e defina um tema recorrente, como "Dica da semana" ou "Bastidores do dia".' },
    { title: 'Configure a frequência', description: 'Escolha com que frequência o conteúdo será gerado: diário, dias úteis, semanal, quinzenal ou mensal.' },
    { title: 'Defina as plataformas', description: 'Selecione para quais redes sociais as sugestões serão criadas. A IA adapta o formato e linguagem para cada uma.' },
    { title: 'Personalize com instruções', description: 'Adicione instruções extras (opcional) para guiar a IA, como "focar em dicas práticas" ou "incluir call-to-action".' },
    { title: 'Ative ou desative', description: 'Pautas ativas geram conteúdo automaticamente. Desative temporariamente sem perder a configuração.' },
];

const rulesGuideTips = [
    'Cada pauta gera sugestões que ficam pendentes para aprovação no painel do Content Engine.',
    'O tom de voz da pauta pode sobrescrever o tom padrão da marca para sugestões específicas.',
    'Use o botão "Gerar agora" para criar uma sugestão imediata a partir de qualquer pauta.',
    'A frequência determina quando a próxima geração automática acontecerá.',
    'Categorias ajudam a organizar pautas: Dica, Novidade, Bastidores, Promoção, Educativo, etc.',
];
import { ref, computed } from 'vue';
import axios from 'axios';

interface Rule {
    id: number;
    name: string;
    description: string | null;
    category: string;
    category_label: string;
    platforms: string[];
    post_type: string;
    tone_override: string | null;
    instructions: string | null;
    frequency: string;
    frequency_label: string;
    preferred_times: string[] | null;
    is_active: boolean;
    last_generated_at: string | null;
    next_generation_at: string | null;
    suggestions_count: number;
}

const props = defineProps<{
    rules: Rule[];
}>();

const page = usePage();
const currentBrand = computed(() => page.props.currentBrand);
const showForm = ref(false);
const editingRule = ref<Rule | null>(null);
const generatingId = ref<number | null>(null);

const form = useForm({
    name: '',
    description: '',
    category: 'dica',
    platforms: ['instagram'] as string[],
    post_type: 'feed',
    tone_override: '',
    instructions: '',
    frequency: 'weekly',
    preferred_times: [] as string[],
});

const categoryOptions = [
    { value: 'dica', label: 'Dica' },
    { value: 'novidade', label: 'Novidade' },
    { value: 'bastidores', label: 'Bastidores' },
    { value: 'promocao', label: 'Promoção' },
    { value: 'educativo', label: 'Educativo' },
    { value: 'inspiracional', label: 'Inspiracional' },
    { value: 'engajamento', label: 'Engajamento' },
    { value: 'produto', label: 'Produto' },
];

const frequencyOptions = [
    { value: 'daily', label: 'Diário' },
    { value: 'weekday', label: 'Dias úteis' },
    { value: 'weekly', label: 'Semanal' },
    { value: 'biweekly', label: 'Quinzenal' },
    { value: 'monthly', label: 'Mensal' },
];

const platformOptions = [
    { value: 'instagram', label: 'Instagram' },
    { value: 'facebook', label: 'Facebook' },
    { value: 'linkedin', label: 'LinkedIn' },
    { value: 'tiktok', label: 'TikTok' },
    { value: 'youtube', label: 'YouTube' },
    { value: 'pinterest', label: 'Pinterest' },
];

const postTypeOptions = [
    { value: 'feed', label: 'Post Feed' },
    { value: 'carousel', label: 'Carrossel' },
    { value: 'story', label: 'Story' },
    { value: 'reel', label: 'Reel / TikTok' },
    { value: 'video', label: 'Vídeo' },
    { value: 'pin', label: 'Pin' },
];

function openCreate() {
    editingRule.value = null;
    form.reset();
    form.platforms = ['instagram'];
    showForm.value = true;
}

function openEdit(rule: Rule) {
    editingRule.value = rule;
    form.name = rule.name;
    form.description = rule.description ?? '';
    form.category = rule.category;
    form.platforms = [...rule.platforms];
    form.post_type = rule.post_type;
    form.tone_override = rule.tone_override ?? '';
    form.instructions = rule.instructions ?? '';
    form.frequency = rule.frequency;
    form.preferred_times = rule.preferred_times ?? [];
    showForm.value = true;
}

function submitForm() {
    if (editingRule.value) {
        form.put(route('social.content-engine.rules.update', editingRule.value.id), {
            onSuccess: () => { showForm.value = false; },
        });
    } else {
        form.post(route('social.content-engine.rules.store'), {
            onSuccess: () => { showForm.value = false; form.reset(); },
        });
    }
}

function deleteRule(rule: Rule) {
    if (!confirm(`Remover pauta "${rule.name}"?`)) return;
    router.delete(route('social.content-engine.rules.destroy', rule.id));
}

function toggleRule(rule: Rule) {
    router.post(route('social.content-engine.rules.toggle', rule.id));
}

function togglePlatform(value: string) {
    const idx = form.platforms.indexOf(value);
    if (idx >= 0) {
        if (form.platforms.length > 1) form.platforms.splice(idx, 1);
    } else {
        form.platforms.push(value);
    }
}

async function generateNow(rule: Rule) {
    generatingId.value = rule.id;
    try {
        await axios.post(route('social.content-engine.rules.generate', rule.id));
        router.reload();
    } catch (error) {
        console.error('Erro ao gerar:', error);
    } finally {
        generatingId.value = null;
    }
}
</script>

<template>
    <Head title="Social - Pautas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('social.content-engine.index')" class="text-gray-400 hover:text-white transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                        </svg>
                    </Link>
                    <h1 class="text-xl font-semibold text-white">Pautas de Conteúdo</h1>
                </div>
                <button v-if="currentBrand" @click="openCreate" class="rounded-xl bg-purple-600 px-4 py-2 text-sm font-semibold text-white hover:bg-purple-700 transition">
                    + Nova Pauta
                </button>
            </div>
        </template>

        <div v-if="!currentBrand" class="rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
            <h3 class="text-lg font-medium text-gray-300">Nenhuma marca selecionada</h3>
        </div>

        <template v-else>
            <GuideBox
                title="Como configurar Pautas de Conteúdo"
                description="Pautas são temas recorrentes que o sistema usa para gerar conteúdo automaticamente com IA."
                :steps="rulesGuideSteps"
                :tips="rulesGuideTips"
                color="purple"
                storage-key="rules-guide"
                class="mb-6"
            />

            <!-- Lista de Pautas -->
            <div v-if="rules.length" class="space-y-4">
                <div v-for="rule in rules" :key="rule.id" class="rounded-2xl bg-gray-900 border transition"
                    :class="rule.is_active ? 'border-gray-800' : 'border-gray-800/50 opacity-60'"
                >
                    <div class="px-5 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <h3 class="text-sm font-semibold text-white">{{ rule.name }}</h3>
                                    <span class="rounded-md bg-purple-500/20 border border-purple-500/30 px-1.5 py-0.5 text-[10px] font-medium text-purple-400">
                                        {{ rule.category_label }}
                                    </span>
                                    <span class="rounded-md bg-gray-700 px-1.5 py-0.5 text-[10px] text-gray-400">
                                        {{ rule.frequency_label }}
                                    </span>
                                    <span v-if="!rule.is_active" class="rounded-md bg-red-500/20 border border-red-500/30 px-1.5 py-0.5 text-[10px] font-medium text-red-400">
                                        Inativa
                                    </span>
                                </div>
                                <p v-if="rule.description" class="text-xs text-gray-500 mb-2">{{ rule.description }}</p>
                                <div class="flex items-center gap-3 text-[11px] text-gray-500">
                                    <span>Plataformas: {{ rule.platforms.join(', ') }}</span>
                                    <span>&middot;</span>
                                    <span>{{ rule.suggestions_count }} sugestões geradas</span>
                                    <span v-if="rule.last_generated_at">&middot; Última: {{ rule.last_generated_at }}</span>
                                    <span v-if="rule.next_generation_at">&middot; Próxima: {{ rule.next_generation_at }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <button @click="generateNow(rule)" :disabled="generatingId === rule.id"
                                    class="rounded-lg bg-purple-600/20 border border-purple-500/30 px-2.5 py-1 text-[11px] font-medium text-purple-400 hover:bg-purple-600/30 transition disabled:opacity-50">
                                    {{ generatingId === rule.id ? 'Gerando...' : 'Gerar agora' }}
                                </button>
                                <button @click="toggleRule(rule)" class="rounded-lg px-2.5 py-1 text-[11px] font-medium transition"
                                    :class="rule.is_active ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-green-600/20 border border-green-500/30 text-green-400 hover:bg-green-600/30'">
                                    {{ rule.is_active ? 'Desativar' : 'Ativar' }}
                                </button>
                                <button @click="openEdit(rule)" class="rounded-lg bg-gray-700 px-2.5 py-1 text-[11px] font-medium text-gray-300 hover:bg-gray-600 transition">
                                    Editar
                                </button>
                                <button @click="deleteRule(rule)" class="rounded-lg bg-red-600/10 border border-red-500/30 px-2.5 py-1 text-[11px] font-medium text-red-400 hover:bg-red-600/20 transition">
                                    Excluir
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                <h3 class="text-lg font-medium text-gray-300">Nenhuma pauta criada</h3>
                <p class="mt-2 text-sm text-gray-500">Crie pautas para gerar conteúdo automaticamente.</p>
                <button @click="openCreate" class="mt-4 rounded-xl bg-purple-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-purple-700 transition">
                    + Nova Pauta
                </button>
            </div>

            <!-- Modal Form -->
            <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 overflow-y-auto py-8" @click.self="showForm = false">
                <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 w-full max-w-lg mx-4">
                    <h3 class="text-lg font-semibold text-white mb-5">{{ editingRule ? 'Editar Pauta' : 'Nova Pauta' }}</h3>

                    <form @submit.prevent="submitForm" class="space-y-4">
                        <div>
                            <label class="text-sm font-medium text-gray-300">Nome</label>
                            <input v-model="form.name" type="text" required class="mt-1 w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-purple-500 focus:ring-purple-500" placeholder="Ex: Dica do dia" />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-300">Descrição</label>
                            <textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-purple-500 focus:ring-purple-500" placeholder="O que esta pauta deve gerar..." />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-sm font-medium text-gray-300">Categoria</label>
                                <select v-model="form.category" class="mt-1 w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-purple-500 focus:ring-purple-500">
                                    <option v-for="opt in categoryOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-medium text-gray-300">Frequência</label>
                                <select v-model="form.frequency" class="mt-1 w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-purple-500 focus:ring-purple-500">
                                    <option v-for="opt in frequencyOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-300">Plataformas</label>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button v-for="p in platformOptions" :key="p.value" type="button" @click="togglePlatform(p.value)"
                                    class="rounded-lg px-3 py-1.5 text-xs font-medium border transition"
                                    :class="form.platforms.includes(p.value) ? 'bg-purple-600/20 border-purple-500/50 text-purple-300' : 'bg-gray-800 border-gray-700 text-gray-500 hover:text-gray-300'"
                                >{{ p.label }}</button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-sm font-medium text-gray-300">Tipo de Post</label>
                                <select v-model="form.post_type" class="mt-1 w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-purple-500 focus:ring-purple-500">
                                    <option v-for="opt in postTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-medium text-gray-300">Tom (sobrescrever)</label>
                                <input v-model="form.tone_override" type="text" class="mt-1 w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-purple-500 focus:ring-purple-500" placeholder="Deixe vazio para usar o da marca" />
                            </div>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-300">Instruções para a IA</label>
                            <textarea v-model="form.instructions" rows="3" class="mt-1 w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-purple-500 focus:ring-purple-500" placeholder="Instruções específicas..." />
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showForm = false" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                            <button type="submit" :disabled="form.processing" class="rounded-xl bg-purple-600 px-6 py-2 text-sm font-semibold text-white hover:bg-purple-700 transition disabled:opacity-50">
                                {{ form.processing ? 'Salvando...' : (editingRule ? 'Salvar' : 'Criar Pauta') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </template>
    </AuthenticatedLayout>
</template>
