<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface MetricEntry {
    id: number;
    value: number;
    date: string;
    date_formatted: string;
    notes: string | null;
    source: string;
}

interface MetricDetail {
    id: number;
    name: string;
    description: string | null;
    category: string | null;
    unit: string;
    value_type: string;
    value_prefix: string | null;
    value_suffix: string | null;
    decimal_places: number;
    direction: string;
    color: string;
    platform: string | null;
    tags: string[];
    tracking_frequency: string;
    goal_value: number | null;
    goal_period: string | null;
    goal_progress: number | null;
}

interface Goal {
    id: number;
    name: string;
    target_value: number;
    target_formatted: string;
    period: string;
    start_date: string;
    end_date: string;
    baseline_value: number | null;
    comparison_type: string;
    notes: string | null;
    is_active: boolean;
    achieved: boolean;
    progress: number;
    days_remaining: number;
    time_elapsed: number;
    is_expired: boolean;
}

interface Comparison {
    current_avg: number;
    previous_avg: number;
    current_sum: number;
    previous_sum: number;
    current_count: number;
    previous_count: number;
    current_min: number;
    current_max: number;
    variation: number | null;
    variation_positive: boolean;
}

interface Stats {
    total_entries: number;
    first_date: string | null;
    last_date: string | null;
    all_time_min: number | null;
    all_time_max: number | null;
    all_time_avg: number | null;
    all_time_sum: number | null;
    all_time_min_formatted: string;
    all_time_max_formatted: string;
    all_time_avg_formatted: string;
    trend: { direction: string; percentage: number; positive: boolean } | null;
    streak_days: number;
}

const props = defineProps<{
    metric: MetricDetail;
    entries: MetricEntry[];
    comparison: Comparison;
    goals: Goal[];
    stats: Stats;
    period: string;
}>();

const showAddGoal = ref(false);

const entryForm = useForm({
    value: null as number | null,
    date: new Date().toISOString().split('T')[0],
    notes: '',
});

const goalForm = useForm({
    name: '',
    target_value: null as number | null,
    period: 'monthly',
    start_date: new Date().toISOString().split('T')[0],
    end_date: '',
    baseline_value: null as number | null,
    comparison_type: 'absolute',
    notes: '',
});

function addEntry() {
    entryForm.post(route('metrics.entries.store', props.metric.id), {
        preserveScroll: true,
        onSuccess: () => entryForm.reset(),
    });
}

function removeEntry(entry: MetricEntry) {
    if (confirm('Remover esta entrada?')) {
        router.delete(route('metrics.entries.destroy', [props.metric.id, entry.id]), { preserveScroll: true });
    }
}

function changePeriod(p: string) {
    router.get(route('metrics.show', props.metric.id), { period: p }, { preserveState: true, preserveScroll: true });
}

function addGoal() {
    goalForm.post(route('metrics.goals.store', props.metric.id), {
        preserveScroll: true,
        onSuccess: () => {
            goalForm.reset();
            showAddGoal.value = false;
        },
    });
}

function deleteGoal(goal: Goal) {
    if (confirm(`Remover a meta "${goal.name}"?`)) {
        router.delete(route('metrics.goals.destroy', goal.id), { preserveScroll: true });
    }
}

function formatValue(value: number): string {
    const decimals = props.metric.decimal_places ?? 2;
    const prefix = props.metric.value_prefix || '';
    const suffix = props.metric.value_suffix || '';

    if (prefix || suffix) {
        return `${prefix} ${value.toLocaleString('pt-BR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })} ${suffix}`.trim();
    }

    switch (props.metric.unit) {
        case 'currency': return 'R$ ' + value.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
        case 'percentage': return value.toLocaleString('pt-BR', { minimumFractionDigits: 1 }) + '%';
        default: return value.toLocaleString('pt-BR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }
}

const latestValue = computed(() => {
    if (props.entries.length === 0) return '--';
    return formatValue(props.entries[props.entries.length - 1].value);
});

const periodOptions = [
    { value: '1week', label: '1 sem' },
    { value: '2weeks', label: '2 sem' },
    { value: '1month', label: '1 mes' },
    { value: '3months', label: '3 meses' },
    { value: '6months', label: '6 meses' },
    { value: '1year', label: '1 ano' },
    { value: 'all', label: 'Tudo' },
];

const goalPeriodOptions = [
    { value: 'weekly', label: 'Semanal' },
    { value: 'monthly', label: 'Mensal' },
    { value: 'quarterly', label: 'Trimestral' },
    { value: 'semester', label: 'Semestral' },
    { value: 'yearly', label: 'Anual' },
    { value: 'custom', label: 'Personalizado' },
];

const directionLabel = computed(() => {
    return props.metric.direction === 'up' ? '↑ Quanto maior, melhor' : props.metric.direction === 'down' ? '↓ Quanto menor, melhor' : '→ Neutro';
});

// SVG Chart
const chartPoints = computed(() => {
    if (props.entries.length < 2) return '';
    const values = props.entries.map(e => e.value);
    const minVal = Math.min(...values);
    const maxVal = Math.max(...values);
    const range = maxVal - minVal || 1;
    const w = 800, h = 200, p = 10;
    return props.entries.map((e, i) => {
        const x = p + (i / (props.entries.length - 1)) * (w - 2 * p);
        const y = h - p - ((e.value - minVal) / range) * (h - 2 * p);
        return `${x},${y}`;
    }).join(' ');
});

const chartAreaPoints = computed(() => {
    if (!chartPoints.value) return '';
    return `10,190 ${chartPoints.value} 790,190`;
});

const goalLineY = computed(() => {
    if (!props.metric.goal_value || props.entries.length < 2) return null;
    const values = props.entries.map(e => e.value);
    const minVal = Math.min(...values, props.metric.goal_value);
    const maxVal = Math.max(...values, props.metric.goal_value);
    const range = maxVal - minVal || 1;
    return 190 - ((props.metric.goal_value - minVal) / range) * 180;
});

const activeGoals = computed(() => props.goals.filter(g => g.is_active && !g.is_expired));
const completedGoals = computed(() => props.goals.filter(g => g.achieved));
</script>

<template>
    <Head :title="metric.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('metrics.index')" class="text-gray-400 hover:text-white transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                    </Link>
                    <div>
                        <h1 class="text-xl font-semibold text-white">{{ metric.name }}</h1>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span v-if="metric.category" class="text-xs text-gray-500">{{ metric.category }}</span>
                            <span v-if="metric.platform" class="text-xs text-purple-400">{{ metric.platform }}</span>
                            <span class="text-[10px] text-gray-600">{{ directionLabel }}</span>
                        </div>
                    </div>
                </div>
                <Link :href="route('metrics.edit', metric.id)" class="rounded-xl bg-gray-800 border border-gray-700 px-4 py-2 text-sm text-gray-300 hover:text-white hover:bg-gray-700 transition">
                    Editar
                </Link>
            </div>
        </template>

        <!-- Stats overview -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-6">
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-xs text-gray-400 mb-1">Valor Atual</p>
                <p class="text-xl font-bold" :style="{ color: metric.color }">{{ latestValue }}</p>
                <p v-if="comparison.variation !== null" :class="['text-xs mt-0.5', comparison.variation_positive ? 'text-emerald-400' : 'text-red-400']">
                    {{ comparison.variation >= 0 ? '+' : '' }}{{ comparison.variation }}%
                </p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-xs text-gray-400 mb-1">Media</p>
                <p class="text-xl font-bold text-white">{{ formatValue(comparison.current_avg) }}</p>
                <p class="text-xs text-gray-600 mt-0.5">{{ comparison.current_count }} registros</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-xs text-gray-400 mb-1">Min / Max</p>
                <p class="text-sm font-medium text-white mt-1">{{ formatValue(comparison.current_min) }}</p>
                <p class="text-sm font-medium text-white">{{ formatValue(comparison.current_max) }}</p>
            </div>
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
                <p class="text-xs text-gray-400 mb-1">Tendencia Geral</p>
                <div v-if="stats.trend">
                    <p class="text-xl font-bold" :class="stats.trend.positive ? 'text-emerald-400' : 'text-red-400'">
                        {{ stats.trend.percentage > 0 ? '+' : '' }}{{ stats.trend.percentage }}%
                    </p>
                    <p class="text-[10px] text-gray-600">{{ stats.total_entries }} registros totais</p>
                </div>
                <p v-else class="text-xl font-bold text-gray-600">--</p>
            </div>
        </div>

        <!-- Chart -->
        <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-white">Evolucao</h2>
                <div class="flex items-center gap-0.5 bg-gray-800 rounded-xl p-0.5">
                    <button v-for="opt in periodOptions" :key="opt.value" @click="changePeriod(opt.value)" :class="['rounded-lg px-2.5 py-1.5 text-[11px] font-medium transition', period === opt.value ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white']">
                        {{ opt.label }}
                    </button>
                </div>
            </div>

            <div v-if="entries.length < 2" class="flex items-center justify-center h-48 text-gray-500 text-sm">
                Adicione pelo menos 2 registros para visualizar o grafico.
            </div>
            <div v-else class="relative">
                <svg viewBox="0 0 800 200" class="w-full h-48" preserveAspectRatio="none">
                    <polygon :points="chartAreaPoints" :fill="metric.color" fill-opacity="0.08" />
                    <polyline :points="chartPoints" fill="none" :stroke="metric.color" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                    <line v-if="goalLineY !== null" x1="10" :y1="goalLineY" x2="790" :y2="goalLineY" stroke="#F59E0B" stroke-width="1" stroke-dasharray="8,4" opacity="0.6" />
                    <circle v-for="(entry, i) in entries" :key="entry.id"
                        :cx="10 + (i / (entries.length - 1)) * 780"
                        :cy="190 - ((entry.value - Math.min(...entries.map(e => e.value))) / (Math.max(...entries.map(e => e.value)) - Math.min(...entries.map(e => e.value)) || 1)) * 180"
                        r="3" :fill="metric.color"
                    />
                </svg>
                <div class="flex justify-between text-[10px] text-gray-600 mt-1 px-2">
                    <span>{{ entries[0]?.date_formatted }}</span>
                    <span v-if="goalLineY !== null" class="text-amber-500">Meta: {{ formatValue(metric.goal_value!) }}</span>
                    <span>{{ entries[entries.length - 1]?.date_formatted }}</span>
                </div>
            </div>
        </div>

        <!-- Goals section -->
        <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-white">Metas</h2>
                <button @click="showAddGoal = !showAddGoal" class="rounded-lg bg-indigo-600/20 border border-indigo-500/30 px-3 py-1.5 text-xs font-medium text-indigo-400 hover:bg-indigo-600/30 transition">
                    {{ showAddGoal ? 'Cancelar' : '+ Nova Meta' }}
                </button>
            </div>

            <!-- Add goal form -->
            <div v-if="showAddGoal" class="rounded-xl bg-gray-800/50 border border-gray-700 p-4 mb-4">
                <form @submit.prevent="addGoal" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2">
                            <label class="block text-xs text-gray-400 mb-1">Nome da meta</label>
                            <input v-model="goalForm.name" type="text" required class="w-full rounded-lg bg-gray-900 border-gray-600 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Ex: Atingir 10k seguidores" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Valor alvo</label>
                            <input v-model.number="goalForm.target_value" type="number" step="0.01" required class="w-full rounded-lg bg-gray-900 border-gray-600 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Periodo</label>
                            <select v-model="goalForm.period" class="w-full rounded-lg bg-gray-900 border-gray-600 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option v-for="opt in goalPeriodOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Data inicio</label>
                            <input v-model="goalForm.start_date" type="date" required class="w-full rounded-lg bg-gray-900 border-gray-600 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Data fim</label>
                            <input v-model="goalForm.end_date" type="date" required class="w-full rounded-lg bg-gray-900 border-gray-600 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Valor base (opcional)</label>
                            <input v-model.number="goalForm.baseline_value" type="number" step="0.01" class="w-full rounded-lg bg-gray-900 border-gray-600 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Valor de partida" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Tipo de comparacao</label>
                            <select v-model="goalForm.comparison_type" class="w-full rounded-lg bg-gray-900 border-gray-600 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="absolute">Absoluto (valor alvo direto)</option>
                                <option value="percentage">Percentual (% de crescimento)</option>
                                <option value="cumulative">Acumulado (soma no periodo)</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" :disabled="goalForm.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                            {{ goalForm.processing ? 'Criando...' : 'Criar Meta' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Active goals -->
            <div v-if="activeGoals.length > 0" class="space-y-3">
                <div v-for="goal in activeGoals" :key="goal.id" class="rounded-xl bg-gray-800/50 border border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="text-sm font-medium text-white">{{ goal.name }}</p>
                            <p class="text-[10px] text-gray-500">{{ goal.start_date }} - {{ goal.end_date }} | Alvo: {{ goal.target_formatted }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span v-if="goal.achieved" class="rounded-full bg-emerald-500/20 border border-emerald-500/30 px-2 py-0.5 text-[10px] text-emerald-400 font-medium">Atingida!</span>
                            <button @click="deleteGoal(goal)" class="text-gray-600 hover:text-red-400 transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                            </button>
                        </div>
                    </div>
                    <!-- Progress -->
                    <div class="flex items-center gap-3">
                        <div class="flex-1">
                            <div class="h-2 rounded-full bg-gray-700 overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-500" :style="{ width: Math.min(100, goal.progress) + '%', backgroundColor: metric.color }" />
                            </div>
                        </div>
                        <span class="text-xs font-medium text-gray-400 w-12 text-right">{{ Math.round(goal.progress) }}%</span>
                    </div>
                    <div class="flex items-center justify-between mt-1.5 text-[10px] text-gray-600">
                        <span>Tempo decorrido: {{ Math.round(goal.time_elapsed) }}%</span>
                        <span>{{ goal.days_remaining }} dias restantes</span>
                    </div>
                </div>
            </div>

            <!-- Completed goals -->
            <div v-if="completedGoals.length > 0" class="mt-4">
                <p class="text-xs text-gray-500 mb-2">Metas atingidas</p>
                <div class="space-y-1">
                    <div v-for="goal in completedGoals" :key="goal.id" class="flex items-center gap-2 text-xs text-gray-500">
                        <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><polyline points="20 6 9 17 4 12" /></svg>
                        <span>{{ goal.name }} - {{ goal.target_formatted }}</span>
                    </div>
                </div>
            </div>

            <div v-if="goals.length === 0 && !showAddGoal" class="text-center py-6 text-gray-500 text-sm">
                Nenhuma meta definida. Clique em "+ Nova Meta" para criar.
            </div>
        </div>

        <!-- Entry form + History -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 mb-6">
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Registrar Valor</h2>
                <form @submit.prevent="addEntry" class="space-y-4">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Valor</label>
                        <input v-model.number="entryForm.value" type="number" step="0.01" required class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="0.00" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Data</label>
                        <input v-model="entryForm.date" type="date" required class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Observacao (opcional)</label>
                        <textarea v-model="entryForm.notes" rows="2" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Anotacao..." />
                    </div>
                    <button type="submit" :disabled="entryForm.processing" class="w-full rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                        {{ entryForm.processing ? 'Salvando...' : 'Registrar' }}
                    </button>
                </form>

                <!-- All-time stats -->
                <div v-if="stats.total_entries > 0" class="mt-6 pt-4 border-t border-gray-800">
                    <h3 class="text-xs font-medium text-gray-400 mb-3">Estatisticas Gerais</h3>
                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between"><span class="text-gray-500">Total registros</span><span class="text-white">{{ stats.total_entries }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Primeiro</span><span class="text-white">{{ stats.first_date }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Ultimo</span><span class="text-white">{{ stats.last_date }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Minimo</span><span class="text-white">{{ stats.all_time_min_formatted }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Maximo</span><span class="text-white">{{ stats.all_time_max_formatted }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Media geral</span><span class="text-white">{{ stats.all_time_avg_formatted }}</span></div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2 rounded-2xl bg-gray-900 border border-gray-800 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Historico</h2>
                <div v-if="entries.length === 0" class="text-center py-8 text-gray-500 text-sm">Nenhum registro ainda.</div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-800">
                                <th class="text-left py-2.5 px-2 text-gray-400 font-medium text-xs">Data</th>
                                <th class="text-right py-2.5 px-2 text-gray-400 font-medium text-xs">Valor</th>
                                <th class="text-left py-2.5 px-2 text-gray-400 font-medium text-xs">Observacao</th>
                                <th class="text-center py-2.5 px-2 text-gray-400 font-medium text-xs">Fonte</th>
                                <th class="py-2.5 px-2 w-8"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="entry in [...entries].reverse()" :key="entry.id" class="border-b border-gray-800/50 hover:bg-gray-800/30 transition">
                                <td class="py-2 px-2 text-gray-300 text-xs">{{ entry.date_formatted }}</td>
                                <td class="py-2 px-2 text-right font-medium text-xs" :style="{ color: metric.color }">{{ formatValue(entry.value) }}</td>
                                <td class="py-2 px-2 text-gray-500 text-xs truncate max-w-48">{{ entry.notes || '-' }}</td>
                                <td class="py-2 px-2 text-center">
                                    <span :class="['rounded-md px-1.5 py-0.5 text-[9px]', entry.source === 'api' ? 'bg-blue-500/10 text-blue-400' : 'bg-gray-800 text-gray-500']">{{ entry.source }}</span>
                                </td>
                                <td class="py-2 px-2">
                                    <button @click="removeEntry(entry)" class="flex items-center justify-center h-6 w-6 rounded-lg text-gray-600 hover:bg-red-500/10 hover:text-red-400 transition">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
