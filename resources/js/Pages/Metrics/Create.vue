<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

interface Category {
    id: number;
    name: string;
    color: string;
}

const props = defineProps<{
    categories: Category[];
    allTags: string[];
    availablePlatforms: Record<string, string>;
}>();

const form = useForm({
    name: '',
    description: '',
    category: '',
    metric_category_id: null as number | null,
    unit: 'number',
    value_type: 'number',
    value_prefix: '',
    value_suffix: '',
    decimal_places: 2,
    direction: 'up',
    color: '#6366F1',
    icon: 'chart-bar',
    tags: [] as string[],
    platform: null as string | null,
    tracking_frequency: 'monthly',
    custom_frequency_days: null as number | null,
    custom_start_date: '',
    custom_end_date: '',
    aggregation: 'last',
    goal_value: null as number | null,
    goal_period: null as string | null,
    goal_start_date: new Date().toISOString().split('T')[0],
    goal_end_date: '',
    new_category_name: '',
    new_category_color: '#6366F1',
});

const showNewCategory = ref(false);
const newTag = ref('');

function submit() {
    form.post(route('metrics.store'));
}

// Tipos de valor expandidos
const valueTypeOptions = [
    { value: 'number', label: 'Numero', prefix: '', suffix: '', decimals: 0, example: '1.234', icon: 'hash' },
    { value: 'decimal', label: 'Decimal', prefix: '', suffix: '', decimals: 2, example: '1.234,56', icon: 'hash' },
    { value: 'currency_brl', label: 'Real (R$)', prefix: 'R$', suffix: '', decimals: 2, example: 'R$ 1.234,56', icon: 'dollar' },
    { value: 'currency_usd', label: 'Dolar (US$)', prefix: 'US$', suffix: '', decimals: 2, example: 'US$ 1,234.56', icon: 'dollar' },
    { value: 'currency_eur', label: 'Euro', prefix: '€', suffix: '', decimals: 2, example: '€ 1.234,56', icon: 'dollar' },
    { value: 'percentage', label: 'Percentual', prefix: '', suffix: '%', decimals: 1, example: '85,3%', icon: 'percent' },
    { value: 'followers', label: 'Seguidores', prefix: '', suffix: '', decimals: 0, example: '10.500', icon: 'users' },
    { value: 'views', label: 'Visualizacoes', prefix: '', suffix: 'views', decimals: 0, example: '45.200 views', icon: 'eye' },
    { value: 'clicks', label: 'Cliques', prefix: '', suffix: 'cliques', decimals: 0, example: '3.450 cliques', icon: 'cursor' },
    { value: 'impressions', label: 'Impressoes', prefix: '', suffix: 'imp.', decimals: 0, example: '120.000 imp.', icon: 'eye' },
    { value: 'engagement_rate', label: 'Taxa Engajamento', prefix: '', suffix: '%', decimals: 2, example: '4,52%', icon: 'heart' },
    { value: 'ctr', label: 'CTR', prefix: '', suffix: '%', decimals: 2, example: '2,35%', icon: 'cursor' },
    { value: 'cpc', label: 'CPC', prefix: 'R$', suffix: '', decimals: 2, example: 'R$ 0,85', icon: 'dollar' },
    { value: 'cpm', label: 'CPM', prefix: 'R$', suffix: '', decimals: 2, example: 'R$ 12,50', icon: 'dollar' },
    { value: 'cpa', label: 'CPA', prefix: 'R$', suffix: '', decimals: 2, example: 'R$ 45,00', icon: 'dollar' },
    { value: 'roas', label: 'ROAS', prefix: '', suffix: 'x', decimals: 2, example: '3,50x', icon: 'trending' },
    { value: 'roi', label: 'ROI', prefix: '', suffix: '%', decimals: 1, example: '250,0%', icon: 'trending' },
    { value: 'conversions', label: 'Conversoes', prefix: '', suffix: '', decimals: 0, example: '234', icon: 'check' },
    { value: 'leads', label: 'Leads', prefix: '', suffix: '', decimals: 0, example: '89', icon: 'users' },
    { value: 'revenue', label: 'Receita', prefix: 'R$', suffix: '', decimals: 2, example: 'R$ 15.000,00', icon: 'dollar' },
    { value: 'time_hours', label: 'Tempo (horas)', prefix: '', suffix: 'h', decimals: 1, example: '24,5h', icon: 'clock' },
    { value: 'score', label: 'Pontuacao', prefix: '', suffix: 'pts', decimals: 0, example: '850 pts', icon: 'star' },
    { value: 'custom', label: 'Personalizado', prefix: '', suffix: '', decimals: 2, example: 'Defina prefixo/sufixo', icon: 'settings' },
];

const directionOptions = [
    { value: 'up', label: 'Quanto maior, melhor', icon: '↑', color: 'text-emerald-400', desc: 'Ex: seguidores, receita, conversoes' },
    { value: 'down', label: 'Quanto menor, melhor', icon: '↓', color: 'text-blue-400', desc: 'Ex: custo, bounce rate, churn' },
    { value: 'neutral', label: 'Neutro', icon: '→', color: 'text-gray-400', desc: 'Ex: temperatura, estoque, indicadores de equilíbrio' },
];

const frequencyOptions = [
    { value: 'daily', label: 'Diario' },
    { value: 'weekly', label: 'Semanal' },
    { value: 'biweekly', label: 'Quinzenal' },
    { value: 'monthly', label: 'Mensal' },
    { value: 'quarterly', label: 'Trimestral' },
    { value: 'yearly', label: 'Anual' },
    { value: 'custom', label: 'Customizado' },
];

const goalPeriodOptions = [
    { value: '', label: 'Sem meta' },
    { value: 'monthly', label: 'Meta mensal' },
    { value: 'quarterly', label: 'Meta trimestral' },
    { value: 'yearly', label: 'Meta anual' },
];

const colorPresets = ['#6366F1', '#8B5CF6', '#EC4899', '#EF4444', '#F59E0B', '#10B981', '#06B6D4', '#3B82F6', '#F97316', '#84CC16'];

function selectValueType(opt: typeof valueTypeOptions[0]) {
    form.value_type = opt.value;
    form.unit = opt.value === 'custom' ? 'custom' : (opt.value.startsWith('currency') ? 'currency' : opt.value.includes('percentage') || opt.value === 'engagement_rate' || opt.value === 'ctr' || opt.value === 'roi' ? 'percentage' : 'number');
    if (opt.value !== 'custom') {
        form.value_prefix = opt.prefix;
        form.value_suffix = opt.suffix;
        form.decimal_places = opt.decimals;
    }
}

function addTag() {
    const tag = newTag.value.trim();
    if (tag && !form.tags.includes(tag)) {
        form.tags.push(tag);
    }
    newTag.value = '';
}

function removeTag(tag: string) {
    form.tags = form.tags.filter(t => t !== tag);
}

function selectExistingTag(tag: string) {
    if (!form.tags.includes(tag)) {
        form.tags.push(tag);
    }
}

function selectCategory(cat: Category) {
    form.metric_category_id = cat.id;
    form.category = cat.name;
    showNewCategory.value = false;
}

const isCustomType = computed(() => form.value_type === 'custom');
const isCustomFrequency = computed(() => form.tracking_frequency === 'custom');
const selectedType = computed(() => valueTypeOptions.find(o => o.value === form.value_type));

// Agrupar tipos por categoria visual
const typeGroups = computed(() => [
    { label: 'Basicos', types: valueTypeOptions.filter(t => ['number', 'decimal', 'percentage', 'score', 'time_hours', 'custom'].includes(t.value)) },
    { label: 'Moedas', types: valueTypeOptions.filter(t => t.value.startsWith('currency_') || t.value === 'revenue') },
    { label: 'Redes Sociais', types: valueTypeOptions.filter(t => ['followers', 'views', 'engagement_rate', 'impressions'].includes(t.value)) },
    { label: 'Marketing / Ads', types: valueTypeOptions.filter(t => ['clicks', 'ctr', 'cpc', 'cpm', 'cpa', 'roas', 'roi', 'conversions', 'leads'].includes(t.value)) },
]);
</script>

<template>
    <Head title="Nova Metrica" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('metrics.index')" class="text-gray-400 hover:text-white transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-xl font-semibold text-white">Nova Metrica</h1>
            </div>
        </template>

        <div class="max-w-3xl">
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Info basica -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-6">Informacoes da Metrica</h2>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Nome da Metrica *</label>
                            <input v-model="form.name" type="text" required class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="Ex: Custo Campanha Black Friday" />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Descricao (opcional)</label>
                            <textarea v-model="form.description" rows="2" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="Descreva o que esta metrica acompanha..." />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <!-- Cor -->
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1">Cor</label>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <button v-for="c in colorPresets" :key="c" type="button" @click="form.color = c" class="w-7 h-7 rounded-lg border-2 transition" :class="form.color === c ? 'border-white scale-110' : 'border-transparent'" :style="{ backgroundColor: c }" />
                                    <input type="color" v-model="form.color" class="h-7 w-7 rounded-lg border border-gray-700 bg-gray-800 cursor-pointer" />
                                </div>
                            </div>

                            <!-- Frequencia -->
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1">Frequencia de registro</label>
                                <select v-model="form.tracking_frequency" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                    <option v-for="opt in frequencyOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>

                            <!-- Campos para frequencia customizada -->
                            <template v-if="isCustomFrequency">
                                <div class="col-span-2 grid grid-cols-3 gap-3 p-4 rounded-xl bg-gray-800/50 border border-gray-700">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-1">A cada (dias)</label>
                                        <input type="number" v-model.number="form.custom_frequency_days" min="1" max="365" placeholder="Ex: 15" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                        <p class="text-xs text-gray-500 mt-1">Intervalo em dias entre registros</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-1">Data inicio</label>
                                        <input type="date" v-model="form.custom_start_date" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-1">Data fim (opcional)</label>
                                        <input type="date" v-model="form.custom_end_date" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Categoria -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Categoria</h2>

                    <div v-if="categories.length > 0 && !showNewCategory" class="space-y-3">
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="cat in categories"
                                :key="cat.id"
                                type="button"
                                @click="selectCategory(cat)"
                                :class="[
                                    'rounded-lg border px-3 py-2 text-sm font-medium transition',
                                    form.metric_category_id === cat.id
                                        ? 'text-white border-current'
                                        : 'text-gray-500 border-gray-700 hover:border-gray-600',
                                ]"
                                :style="form.metric_category_id === cat.id ? { borderColor: cat.color, color: cat.color } : {}"
                            >
                                {{ cat.name }}
                            </button>
                            <button type="button" @click="showNewCategory = true; form.metric_category_id = null" class="rounded-lg border border-dashed border-gray-600 px-3 py-2 text-sm text-gray-500 hover:text-white hover:border-gray-400 transition">
                                + Nova categoria
                            </button>
                        </div>
                    </div>

                    <div v-if="categories.length === 0 || showNewCategory" class="space-y-3">
                        <div class="grid grid-cols-3 gap-3">
                            <div class="col-span-2">
                                <label class="block text-xs text-gray-400 mb-1">Nome da nova categoria</label>
                                <input v-model="form.new_category_name" type="text" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 text-sm" placeholder="Ex: Campanhas" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Cor</label>
                                <div class="flex items-center gap-2">
                                    <input type="color" v-model="form.new_category_color" class="h-10 w-10 rounded-lg border border-gray-700 bg-gray-800 cursor-pointer" />
                                </div>
                            </div>
                        </div>
                        <button v-if="categories.length > 0" type="button" @click="showNewCategory = false" class="text-xs text-gray-500 hover:text-white">
                            Cancelar e usar existente
                        </button>
                    </div>
                </div>

                <!-- Tipo de Valor (expandido) -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-2">Tipo de Valor</h2>
                    <p class="text-sm text-gray-500 mb-4">Escolha como o valor desta metrica sera exibido e formatado.</p>

                    <div class="space-y-4">
                        <div v-for="group in typeGroups" :key="group.label">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">{{ group.label }}</p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                <button
                                    v-for="opt in group.types"
                                    :key="opt.value"
                                    type="button"
                                    @click="selectValueType(opt)"
                                    :class="[
                                        'rounded-xl border p-3 text-left transition',
                                        form.value_type === opt.value
                                            ? 'border-indigo-500 bg-indigo-600/10 text-white'
                                            : 'border-gray-700 bg-gray-800 text-gray-400 hover:border-gray-600',
                                    ]"
                                >
                                    <p class="font-medium text-xs">{{ opt.label }}</p>
                                    <p class="text-[10px] mt-0.5 opacity-50">{{ opt.example }}</p>
                                </button>
                            </div>
                        </div>

                        <!-- Custom prefix/suffix -->
                        <div v-if="isCustomType" class="grid grid-cols-3 gap-3 pt-3 border-t border-gray-800">
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Prefixo</label>
                                <input v-model="form.value_prefix" type="text" placeholder="R$, kg, etc" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Sufixo</label>
                                <input v-model="form.value_suffix" type="text" placeholder="%, pts, etc" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Casas decimais</label>
                                <input v-model.number="form.decimal_places" type="number" min="0" max="6" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Direcao desejada -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Direcao Desejada</h2>

                    <div class="grid grid-cols-3 gap-3">
                        <button
                            v-for="opt in directionOptions"
                            :key="opt.value"
                            type="button"
                            @click="form.direction = opt.value"
                            :class="[
                                'rounded-xl border p-4 text-left transition',
                                form.direction === opt.value
                                    ? 'border-indigo-500 bg-indigo-600/10'
                                    : 'border-gray-700 bg-gray-800 hover:border-gray-600',
                            ]"
                        >
                            <div class="flex items-center gap-2 mb-1">
                                <span :class="['text-lg font-bold', opt.color]">{{ opt.icon }}</span>
                                <span class="text-sm font-medium text-white">{{ opt.label }}</span>
                            </div>
                            <p class="text-[10px] text-gray-500">{{ opt.desc }}</p>
                        </button>
                    </div>
                </div>

                <!-- Plataforma e Tags -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Vinculacao (opcional)</h2>

                    <div class="space-y-4">
                        <!-- Plataforma -->
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Plataforma / Fonte</label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    @click="form.platform = null"
                                    :class="['rounded-lg border px-3 py-1.5 text-xs font-medium transition', !form.platform ? 'border-indigo-500 bg-indigo-600/10 text-indigo-400' : 'border-gray-700 text-gray-500 hover:border-gray-600']"
                                >
                                    Nenhuma
                                </button>
                                <button
                                    v-for="(label, key) in availablePlatforms"
                                    :key="key"
                                    type="button"
                                    @click="form.platform = key as string"
                                    :class="['rounded-lg border px-3 py-1.5 text-xs font-medium transition', form.platform === key ? 'border-indigo-500 bg-indigo-600/10 text-indigo-400' : 'border-gray-700 text-gray-500 hover:border-gray-600']"
                                >
                                    {{ label }}
                                </button>
                            </div>
                        </div>

                        <!-- Tags -->
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Tags</label>
                            <div class="flex items-center gap-2 mb-2">
                                <input v-model="newTag" type="text" @keydown.enter.prevent="addTag" class="flex-1 rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Digite e pressione Enter..." />
                                <button type="button" @click="addTag" class="rounded-lg bg-gray-700 px-3 py-2 text-xs text-gray-300 hover:bg-gray-600 transition">Adicionar</button>
                            </div>
                            <div v-if="form.tags.length > 0" class="flex flex-wrap gap-1.5 mb-2">
                                <span v-for="tag in form.tags" :key="tag" class="inline-flex items-center gap-1 rounded-lg bg-indigo-600/20 border border-indigo-500/30 px-2 py-1 text-xs text-indigo-400">
                                    {{ tag }}
                                    <button type="button" @click="removeTag(tag)" class="hover:text-white">&times;</button>
                                </span>
                            </div>
                            <div v-if="allTags.length > 0" class="flex flex-wrap gap-1">
                                <button v-for="tag in allTags.filter(t => !form.tags.includes(t))" :key="tag" type="button" @click="selectExistingTag(tag)" class="rounded-md bg-gray-800 px-2 py-0.5 text-[10px] text-gray-500 hover:text-white transition">
                                    + {{ tag }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Meta inicial -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-2">Meta Inicial (opcional)</h2>
                    <p class="text-sm text-gray-500 mb-4">Defina uma meta com periodo e datas. Voce podera criar metas adicionais na pagina da metrica.</p>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Periodo da Meta</label>
                            <select v-model="form.goal_period" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                <option v-for="opt in goalPeriodOptions" :key="opt.value" :value="opt.value || null">{{ opt.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Valor da Meta</label>
                            <input v-model.number="form.goal_value" type="number" step="0.01" :disabled="!form.goal_period" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-40" placeholder="Ex: 50000" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Data Inicial</label>
                            <input v-model="form.goal_start_date" type="date" :disabled="!form.goal_period" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-40" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Data Final</label>
                            <input v-model="form.goal_end_date" type="date" :disabled="!form.goal_period" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-40" />
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-4">
                    <Link :href="route('metrics.index')" class="rounded-xl px-6 py-2.5 text-sm font-medium text-gray-400 hover:text-white transition">
                        Cancelar
                    </Link>
                    <button type="submit" :disabled="form.processing" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                        {{ form.processing ? 'Criando...' : 'Criar Metrica' }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
