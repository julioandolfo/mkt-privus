<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

interface Category {
    id: number;
    name: string;
    color: string;
}

interface ConnectedAccount {
    id: number;
    platform: string;
    username: string;
    display_name: string;
    avatar_url: string | null;
    metadata: Record<string, any>;
}

interface SocialTemplate {
    id: number;
    platform: string;
    metric_key: string;
    name: string;
    description: string;
    unit: string;
    value_prefix: string | null;
    value_suffix: string | null;
    decimal_places: number;
    direction: string;
    aggregation: string;
    color: string;
    icon: string;
    category: string;
}

const props = defineProps<{
    categories: Category[];
    allTags: string[];
    availablePlatforms: Record<string, string>;
    connectedAccounts?: ConnectedAccount[];
    socialTemplates?: Record<string, SocialTemplate[]>;
    linkedMetrics?: string[];
}>();

// ===== MODO: social ou manual =====
const mode = ref<'social' | 'manual'>('social');
const hasConnectedAccounts = computed(() => (props.connectedAccounts?.length ?? 0) > 0);
const hasSocialTemplates = computed(() => props.socialTemplates && Object.keys(props.socialTemplates).length > 0);

// ===== SOCIAL TEMPLATES =====
const selectedAccountId = ref<number | null>(null);
const selectedTemplateIds = ref<number[]>([]);
const templateAutoSync = ref(true);
const creatingFromTemplates = ref(false);

const selectedAccount = computed(() => {
    return props.connectedAccounts?.find(a => a.id === selectedAccountId.value) ?? null;
});

const availableTemplates = computed(() => {
    if (!selectedAccount.value || !props.socialTemplates) return [];
    const platform = selectedAccount.value.platform;
    return props.socialTemplates[platform] ?? [];
});

const platformGroups = computed(() => {
    if (!props.connectedAccounts) return {};
    const groups: Record<string, ConnectedAccount[]> = {};
    for (const account of props.connectedAccounts) {
        if (!groups[account.platform]) groups[account.platform] = [];
        groups[account.platform].push(account);
    }
    return groups;
});

function isTemplateLinked(template: SocialTemplate): boolean {
    if (!selectedAccountId.value) return false;
    return (props.linkedMetrics ?? []).includes(`${selectedAccountId.value}:${template.metric_key}`);
}

function toggleTemplate(templateId: number) {
    const idx = selectedTemplateIds.value.indexOf(templateId);
    if (idx === -1) {
        selectedTemplateIds.value.push(templateId);
    } else {
        selectedTemplateIds.value.splice(idx, 1);
    }
}

function selectAllTemplates() {
    const available = availableTemplates.value.filter(t => !isTemplateLinked(t));
    selectedTemplateIds.value = available.map(t => t.id);
}

function deselectAllTemplates() {
    selectedTemplateIds.value = [];
}

function submitTemplates() {
    if (!selectedAccountId.value || selectedTemplateIds.value.length === 0) return;
    creatingFromTemplates.value = true;

    router.post(route('metrics.fromTemplates'), {
        social_account_id: selectedAccountId.value,
        template_ids: selectedTemplateIds.value,
        auto_sync: templateAutoSync.value,
    }, {
        onFinish: () => { creatingFromTemplates.value = false; },
    });
}

// Platform visual info
const platformInfo: Record<string, { name: string; color: string; bgColor: string; icon: string }> = {
    instagram: { name: 'Instagram', color: '#E4405F', bgColor: 'bg-pink-500/10', icon: 'M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z' },
    facebook: { name: 'Facebook', color: '#1877F2', bgColor: 'bg-blue-500/10', icon: 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z' },
    youtube: { name: 'YouTube', color: '#FF0000', bgColor: 'bg-red-500/10', icon: 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z' },
    tiktok: { name: 'TikTok', color: '#000000', bgColor: 'bg-gray-500/10', icon: 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z' },
    linkedin: { name: 'LinkedIn', color: '#0A66C2', bgColor: 'bg-blue-600/10', icon: 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z' },
    pinterest: { name: 'Pinterest', color: '#E60023', bgColor: 'bg-red-600/10', icon: 'M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738.098.119.112.224.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.988C24.007 5.367 18.641.001 12.017.001z' },
};

function getCategoryIcon(category: string): string {
    return {
        growth: 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6',
        engagement: 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z',
        content: 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
        conversion: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    }[category] ?? 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z';
}

const templatesByCategory = computed(() => {
    const groups: Record<string, SocialTemplate[]> = {};
    for (const t of availableTemplates.value) {
        if (!groups[t.category]) groups[t.category] = [];
        groups[t.category].push(t);
    }
    return groups;
});

const categoryLabels: Record<string, string> = {
    growth: 'Crescimento',
    engagement: 'Engajamento',
    content: 'Conteudo',
    conversion: 'Conversao',
    social: 'Social',
};

// ===== MANUAL FORM =====
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
    // Social link (modo manual)
    social_account_id: null as number | null,
    social_metric_key: null as string | null,
    auto_sync: true,
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

        <div class="max-w-4xl">
            <!-- Seletor de Modo -->
            <div class="flex items-center gap-2 mb-6">
                <button
                    type="button"
                    @click="mode = 'social'"
                    :class="[
                        'flex items-center gap-2 rounded-xl px-5 py-3 text-sm font-semibold transition border',
                        mode === 'social'
                            ? 'bg-indigo-600/20 border-indigo-500 text-indigo-400'
                            : 'bg-gray-900 border-gray-700 text-gray-400 hover:border-gray-600'
                    ]"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" />
                    </svg>
                    Metricas das Redes Sociais
                </button>
                <button
                    type="button"
                    @click="mode = 'manual'"
                    :class="[
                        'flex items-center gap-2 rounded-xl px-5 py-3 text-sm font-semibold transition border',
                        mode === 'manual'
                            ? 'bg-indigo-600/20 border-indigo-500 text-indigo-400'
                            : 'bg-gray-900 border-gray-700 text-gray-400 hover:border-gray-600'
                    ]"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Metrica Manual
                </button>
            </div>

            <!-- ===== MODO SOCIAL: Criar metricas a partir de templates ===== -->
            <div v-if="mode === 'social'" class="space-y-6">
                <!-- Sem contas conectadas -->
                <div v-if="!hasConnectedAccounts" class="rounded-2xl bg-gray-900 border border-gray-800 p-8 text-center">
                    <svg class="w-12 h-12 mx-auto text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                    </svg>
                    <h3 class="text-white font-semibold mb-2">Nenhuma conta social conectada</h3>
                    <p class="text-gray-400 text-sm mb-4">Conecte suas contas sociais primeiro para criar metricas automaticas.</p>
                    <Link :href="route('social.accounts.index')" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                        Conectar Contas
                    </Link>
                </div>

                <template v-else>
                    <!-- Step 1: Selecionar conta -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                        <h2 class="text-lg font-semibold text-white mb-2">1. Escolha a Conta</h2>
                        <p class="text-sm text-gray-500 mb-4">Selecione a conta social para importar metricas automaticamente.</p>

                        <div class="space-y-3">
                            <div v-for="(accounts, platform) in platformGroups" :key="platform">
                                <p class="text-xs font-medium uppercase tracking-wider mb-2" :style="{ color: platformInfo[platform]?.color ?? '#888' }">
                                    {{ platformInfo[platform]?.name ?? platform }}
                                </p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <button
                                        v-for="account in accounts"
                                        :key="account.id"
                                        type="button"
                                        @click="selectedAccountId = account.id; selectedTemplateIds = []"
                                        :class="[
                                            'flex items-center gap-3 rounded-xl border p-3 text-left transition',
                                            selectedAccountId === account.id
                                                ? 'border-indigo-500 bg-indigo-600/10'
                                                : 'border-gray-700 bg-gray-800 hover:border-gray-600'
                                        ]"
                                    >
                                        <img
                                            v-if="account.avatar_url"
                                            :src="account.avatar_url"
                                            class="w-10 h-10 rounded-full object-cover"
                                            @error="($event.target as HTMLImageElement).style.display = 'none'"
                                        />
                                        <div v-else class="w-10 h-10 rounded-full flex items-center justify-center" :class="platformInfo[account.platform]?.bgColor ?? 'bg-gray-700'">
                                            <svg class="w-5 h-5" :style="{ color: platformInfo[account.platform]?.color ?? '#888' }" viewBox="0 0 24 24" fill="currentColor">
                                                <path :d="platformInfo[account.platform]?.icon ?? ''" />
                                            </svg>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-medium text-white truncate">{{ account.display_name }}</p>
                                            <p class="text-xs text-gray-500">@{{ account.username }}</p>
                                        </div>
                                        <svg v-if="selectedAccountId === account.id" class="w-5 h-5 text-indigo-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Selecionar metricas -->
                    <div v-if="selectedAccount" class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h2 class="text-lg font-semibold text-white">2. Escolha as Metricas</h2>
                                <p class="text-sm text-gray-500">Selecione quais metricas de <strong class="text-white">{{ selectedAccount.display_name }}</strong> deseja acompanhar.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="selectAllTemplates" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Selecionar Todas</button>
                                <span class="text-gray-600">|</span>
                                <button type="button" @click="deselectAllTemplates" class="text-xs text-gray-400 hover:text-white transition">Limpar</button>
                            </div>
                        </div>

                        <div v-if="availableTemplates.length === 0" class="text-center py-6">
                            <p class="text-gray-400 text-sm">Nenhum template disponivel para {{ platformInfo[selectedAccount.platform]?.name }}.</p>
                            <p class="text-gray-500 text-xs mt-1">Os templates serao criados automaticamente no primeiro deploy.</p>
                        </div>

                        <div v-else class="space-y-4">
                            <div v-for="(templates, category) in templatesByCategory" :key="category">
                                <div class="flex items-center gap-2 mb-2">
                                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" :d="getCategoryIcon(category)" />
                                    </svg>
                                    <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">{{ categoryLabels[category] ?? category }}</span>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <button
                                        v-for="template in templates"
                                        :key="template.id"
                                        type="button"
                                        @click="!isTemplateLinked(template) && toggleTemplate(template.id)"
                                        :disabled="isTemplateLinked(template)"
                                        :class="[
                                            'flex items-center gap-3 rounded-xl border p-3 text-left transition',
                                            isTemplateLinked(template)
                                                ? 'border-gray-800 bg-gray-800/50 opacity-50 cursor-not-allowed'
                                                : selectedTemplateIds.includes(template.id)
                                                    ? 'border-indigo-500 bg-indigo-600/10'
                                                    : 'border-gray-700 bg-gray-800 hover:border-gray-600 cursor-pointer'
                                        ]"
                                    >
                                        <!-- Checkbox visual -->
                                        <div :class="[
                                            'w-5 h-5 rounded border flex items-center justify-center flex-shrink-0 transition',
                                            isTemplateLinked(template)
                                                ? 'bg-gray-700 border-gray-600'
                                                : selectedTemplateIds.includes(template.id)
                                                    ? 'bg-indigo-600 border-indigo-500'
                                                    : 'border-gray-600'
                                        ]">
                                            <svg v-if="selectedTemplateIds.includes(template.id) || isTemplateLinked(template)" class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                            </svg>
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="w-2 h-2 rounded-full flex-shrink-0" :style="{ backgroundColor: template.color }"></span>
                                                <p class="text-sm font-medium text-white">{{ template.name }}</p>
                                            </div>
                                            <p class="text-xs text-gray-500 mt-0.5">{{ template.description }}</p>
                                            <p v-if="isTemplateLinked(template)" class="text-xs text-yellow-500 mt-0.5">Ja vinculada</p>
                                        </div>

                                        <span class="text-[10px] px-2 py-0.5 rounded bg-gray-700/50 text-gray-400 flex-shrink-0">
                                            {{ template.unit === 'percentage' ? '%' : template.value_suffix ?? template.unit }}
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Auto-sync toggle -->
                        <div v-if="selectedTemplateIds.length > 0" class="mt-4 pt-4 border-t border-gray-800">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <div :class="[
                                    'relative w-11 h-6 rounded-full transition',
                                    templateAutoSync ? 'bg-indigo-600' : 'bg-gray-700'
                                ]" @click="templateAutoSync = !templateAutoSync">
                                    <div :class="[
                                        'absolute top-0.5 w-5 h-5 rounded-full bg-white transition-transform',
                                        templateAutoSync ? 'translate-x-5.5' : 'translate-x-0.5'
                                    ]"></div>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-white">Sincronizacao automatica</p>
                                    <p class="text-xs text-gray-500">Os valores serao atualizados automaticamente 2x ao dia</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Step 3: Confirmar -->
                    <div v-if="selectedTemplateIds.length > 0" class="flex items-center justify-between rounded-2xl bg-gray-900 border border-indigo-500/30 p-6">
                        <div>
                            <p class="text-white font-semibold">
                                {{ selectedTemplateIds.length }} metrica(s) selecionada(s)
                            </p>
                            <p class="text-sm text-gray-400">para {{ selectedAccount?.display_name }} ({{ platformInfo[selectedAccount?.platform ?? '']?.name }})</p>
                        </div>
                        <button
                            type="button"
                            @click="submitTemplates"
                            :disabled="creatingFromTemplates"
                            class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50"
                        >
                            {{ creatingFromTemplates ? 'Criando...' : 'Criar Metricas' }}
                        </button>
                    </div>
                </template>
            </div>

            <!-- ===== MODO MANUAL: Formulario classico ===== -->
            <div v-if="mode === 'manual'">
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

                    <!-- Vincular a Conta Social (modo manual) -->
                    <div v-if="hasConnectedAccounts" class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                        <h2 class="text-lg font-semibold text-white mb-2">Vincular a Conta Social (opcional)</h2>
                        <p class="text-sm text-gray-500 mb-4">Se vinculada, os valores serao preenchidos automaticamente a partir dos insights da conta.</p>

                        <div class="space-y-3">
                            <select v-model="form.social_account_id" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                <option :value="null">Nenhuma (manual)</option>
                                <option v-for="account in connectedAccounts" :key="account.id" :value="account.id">
                                    {{ platformInfo[account.platform]?.name ?? account.platform }} - {{ account.display_name }} (@{{ account.username }})
                                </option>
                            </select>

                            <div v-if="form.social_account_id">
                                <label class="block text-sm font-medium text-gray-300 mb-1">Metrica social</label>
                                <select v-model="form.social_metric_key" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                    <option :value="null">Selecione...</option>
                                    <option v-for="template in (socialTemplates?.[connectedAccounts?.find(a => a.id === form.social_account_id)?.platform ?? ''] ?? [])" :key="template.metric_key" :value="template.metric_key">
                                        {{ template.name }} - {{ template.description }}
                                    </option>
                                </select>

                                <label v-if="form.social_metric_key" class="flex items-center gap-2 mt-3 cursor-pointer">
                                    <input type="checkbox" v-model="form.auto_sync" class="rounded bg-gray-800 border-gray-600 text-indigo-600 focus:ring-indigo-500" />
                                    <span class="text-sm text-gray-300">Sincronizar automaticamente (2x ao dia)</span>
                                </label>
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

                    <!-- Tipo de Valor -->
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
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Plataforma / Fonte</label>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" @click="form.platform = null" :class="['rounded-lg border px-3 py-1.5 text-xs font-medium transition', !form.platform ? 'border-indigo-500 bg-indigo-600/10 text-indigo-400' : 'border-gray-700 text-gray-500 hover:border-gray-600']">
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
        </div>
    </AuthenticatedLayout>
</template>
