<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface Category {
    id: number;
    name: string;
    color: string;
}

const props = defineProps<{
    metric: Record<string, any>;
    categories: Category[];
    allTags: string[];
    availablePlatforms: Record<string, string>;
}>();

const form = useForm({
    name: props.metric.name || '',
    description: props.metric.description || '',
    category: props.metric.category || '',
    metric_category_id: props.metric.metric_category_id || null,
    unit: props.metric.unit || 'number',
    value_type: props.metric.value_type || 'number',
    value_prefix: props.metric.value_prefix || '',
    value_suffix: props.metric.value_suffix || '',
    decimal_places: props.metric.decimal_places ?? 2,
    direction: props.metric.direction || 'up',
    color: props.metric.color || '#6366F1',
    icon: props.metric.icon || 'chart-bar',
    tags: props.metric.tags || [],
    platform: props.metric.platform || null,
    tracking_frequency: props.metric.tracking_frequency || 'monthly',
    custom_frequency_days: props.metric.custom_frequency_days || null,
    custom_start_date: props.metric.custom_start_date || '',
    custom_end_date: props.metric.custom_end_date || '',
    aggregation: props.metric.aggregation || 'last',
    goal_value: props.metric.goal_value || null,
    goal_period: props.metric.goal_period || null,
});

const newTag = ref('');

function submit() {
    form.put(route('metrics.update', props.metric.id));
}

const valueTypeOptions = [
    { value: 'number', label: 'Numero', prefix: '', suffix: '', decimals: 0 },
    { value: 'decimal', label: 'Decimal', prefix: '', suffix: '', decimals: 2 },
    { value: 'currency_brl', label: 'Real (R$)', prefix: 'R$', suffix: '', decimals: 2 },
    { value: 'currency_usd', label: 'Dolar (US$)', prefix: 'US$', suffix: '', decimals: 2 },
    { value: 'currency_eur', label: 'Euro', prefix: '€', suffix: '', decimals: 2 },
    { value: 'percentage', label: 'Percentual', prefix: '', suffix: '%', decimals: 1 },
    { value: 'followers', label: 'Seguidores', prefix: '', suffix: '', decimals: 0 },
    { value: 'views', label: 'Visualizacoes', prefix: '', suffix: 'views', decimals: 0 },
    { value: 'clicks', label: 'Cliques', prefix: '', suffix: 'cliques', decimals: 0 },
    { value: 'impressions', label: 'Impressoes', prefix: '', suffix: 'imp.', decimals: 0 },
    { value: 'engagement_rate', label: 'Taxa Engajamento', prefix: '', suffix: '%', decimals: 2 },
    { value: 'ctr', label: 'CTR', prefix: '', suffix: '%', decimals: 2 },
    { value: 'cpc', label: 'CPC', prefix: 'R$', suffix: '', decimals: 2 },
    { value: 'cpm', label: 'CPM', prefix: 'R$', suffix: '', decimals: 2 },
    { value: 'cpa', label: 'CPA', prefix: 'R$', suffix: '', decimals: 2 },
    { value: 'roas', label: 'ROAS', prefix: '', suffix: 'x', decimals: 2 },
    { value: 'roi', label: 'ROI', prefix: '', suffix: '%', decimals: 1 },
    { value: 'conversions', label: 'Conversoes', prefix: '', suffix: '', decimals: 0 },
    { value: 'leads', label: 'Leads', prefix: '', suffix: '', decimals: 0 },
    { value: 'revenue', label: 'Receita', prefix: 'R$', suffix: '', decimals: 2 },
    { value: 'time_hours', label: 'Tempo (horas)', prefix: '', suffix: 'h', decimals: 1 },
    { value: 'score', label: 'Pontuacao', prefix: '', suffix: 'pts', decimals: 0 },
    { value: 'custom', label: 'Personalizado', prefix: '', suffix: '', decimals: 2 },
];

const directionOptions = [
    { value: 'up', label: 'Quanto maior, melhor', icon: '↑', color: 'text-emerald-400' },
    { value: 'down', label: 'Quanto menor, melhor', icon: '↓', color: 'text-blue-400' },
    { value: 'neutral', label: 'Neutro', icon: '→', color: 'text-gray-400' },
];

const frequencyOptions = [
    { value: 'daily', label: 'Diario' }, { value: 'weekly', label: 'Semanal' },
    { value: 'biweekly', label: 'Quinzenal' }, { value: 'monthly', label: 'Mensal' },
    { value: 'quarterly', label: 'Trimestral' }, { value: 'yearly', label: 'Anual' },
];

const colorPresets = ['#6366F1', '#8B5CF6', '#EC4899', '#EF4444', '#F59E0B', '#10B981', '#06B6D4', '#3B82F6', '#F97316', '#84CC16'];

function selectValueType(opt: typeof valueTypeOptions[0]) {
    form.value_type = opt.value;
    if (opt.value !== 'custom') {
        form.value_prefix = opt.prefix;
        form.value_suffix = opt.suffix;
        form.decimal_places = opt.decimals;
    }
}

function addTag() {
    const tag = newTag.value.trim();
    if (tag && !form.tags.includes(tag)) form.tags.push(tag);
    newTag.value = '';
}

function removeTag(tag: string) {
    form.tags = form.tags.filter((t: string) => t !== tag);
}

const isCustomType = computed(() => form.value_type === 'custom');
const isCustomFrequency = computed(() => form.tracking_frequency === 'custom');
</script>

<template>
    <Head :title="`Editar: ${metric.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('metrics.show', metric.id)" class="text-gray-400 hover:text-white transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                </Link>
                <h1 class="text-xl font-semibold text-white">Editar Metrica</h1>
            </div>
        </template>

        <div class="max-w-3xl">
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Info basica -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-6">Informacoes</h2>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Nome *</label>
                            <input v-model="form.name" type="text" required class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Descricao</label>
                            <textarea v-model="form.description" rows="2" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1">Cor</label>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <button v-for="c in colorPresets" :key="c" type="button" @click="form.color = c" class="w-7 h-7 rounded-lg border-2 transition" :class="form.color === c ? 'border-white scale-110' : 'border-transparent'" :style="{ backgroundColor: c }" />
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1">Frequencia</label>
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
                <div v-if="categories.length > 0" class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Categoria</h2>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="cat in categories" :key="cat.id" type="button" @click="form.metric_category_id = cat.id; form.category = cat.name"
                            :class="['rounded-lg border px-3 py-2 text-sm font-medium transition', form.metric_category_id === cat.id ? 'text-white border-current' : 'text-gray-500 border-gray-700 hover:border-gray-600']"
                            :style="form.metric_category_id === cat.id ? { borderColor: cat.color, color: cat.color } : {}">
                            {{ cat.name }}
                        </button>
                        <button type="button" @click="form.metric_category_id = null; form.category = ''" class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-500 hover:text-white transition" :class="{ 'border-indigo-500 text-indigo-400': !form.metric_category_id }">
                            Nenhuma
                        </button>
                    </div>
                </div>

                <!-- Tipo -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Tipo de Valor</h2>
                    <div class="grid grid-cols-3 sm:grid-cols-4 gap-2">
                        <button v-for="opt in valueTypeOptions" :key="opt.value" type="button" @click="selectValueType(opt)"
                            :class="['rounded-xl border p-2.5 text-left transition', form.value_type === opt.value ? 'border-indigo-500 bg-indigo-600/10 text-white' : 'border-gray-700 bg-gray-800 text-gray-400 hover:border-gray-600']">
                            <p class="font-medium text-[11px]">{{ opt.label }}</p>
                        </button>
                    </div>
                    <div v-if="isCustomType" class="grid grid-cols-3 gap-3 mt-3 pt-3 border-t border-gray-800">
                        <div><label class="block text-xs text-gray-400 mb-1">Prefixo</label><input v-model="form.value_prefix" type="text" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" /></div>
                        <div><label class="block text-xs text-gray-400 mb-1">Sufixo</label><input v-model="form.value_suffix" type="text" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" /></div>
                        <div><label class="block text-xs text-gray-400 mb-1">Decimais</label><input v-model.number="form.decimal_places" type="number" min="0" max="6" class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" /></div>
                    </div>
                </div>

                <!-- Direcao -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Direcao</h2>
                    <div class="grid grid-cols-3 gap-3">
                        <button v-for="opt in directionOptions" :key="opt.value" type="button" @click="form.direction = opt.value"
                            :class="['rounded-xl border p-3 text-center transition', form.direction === opt.value ? 'border-indigo-500 bg-indigo-600/10' : 'border-gray-700 bg-gray-800 hover:border-gray-600']">
                            <span :class="['text-lg font-bold', opt.color]">{{ opt.icon }}</span>
                            <p class="text-xs text-gray-400 mt-1">{{ opt.label }}</p>
                        </button>
                    </div>
                </div>

                <!-- Plataforma e Tags -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Vinculacao</h2>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Plataforma</label>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" @click="form.platform = null" :class="['rounded-lg border px-3 py-1.5 text-xs font-medium transition', !form.platform ? 'border-indigo-500 text-indigo-400' : 'border-gray-700 text-gray-500']">Nenhuma</button>
                                <button v-for="(label, key) in availablePlatforms" :key="key" type="button" @click="form.platform = key as string"
                                    :class="['rounded-lg border px-3 py-1.5 text-xs font-medium transition', form.platform === key ? 'border-indigo-500 text-indigo-400' : 'border-gray-700 text-gray-500']">
                                    {{ label }}
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Tags</label>
                            <div class="flex items-center gap-2 mb-2">
                                <input v-model="newTag" type="text" @keydown.enter.prevent="addTag" class="flex-1 rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Nova tag..." />
                                <button type="button" @click="addTag" class="rounded-lg bg-gray-700 px-3 py-2 text-xs text-gray-300 hover:bg-gray-600 transition">Add</button>
                            </div>
                            <div v-if="form.tags.length > 0" class="flex flex-wrap gap-1.5">
                                <span v-for="tag in form.tags" :key="tag" class="inline-flex items-center gap-1 rounded-lg bg-indigo-600/20 border border-indigo-500/30 px-2 py-1 text-xs text-indigo-400">
                                    {{ tag }}<button type="button" @click="removeTag(tag)" class="hover:text-white">&times;</button>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-4">
                    <Link :href="route('metrics.show', metric.id)" class="rounded-xl px-6 py-2.5 text-sm font-medium text-gray-400 hover:text-white transition">Cancelar</Link>
                    <button type="submit" :disabled="form.processing" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                        {{ form.processing ? 'Salvando...' : 'Salvar Alteracoes' }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
