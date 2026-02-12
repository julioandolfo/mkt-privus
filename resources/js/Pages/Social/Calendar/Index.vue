<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import axios from 'axios';

const page = usePage();
const currentBrand = computed(() => page.props.currentBrand as any);
const brands = computed(() => (page.props.brands || []) as any[]);

interface CalendarPost {
    id: number;
    title: string;
    date: string;
    time: string;
    status: string;
    status_label: string;
    status_color: string;
    platforms: string[];
    type: string | null;
    has_media: boolean;
}

interface CalendarItem {
    id: number;
    date: string;
    title: string;
    description: string | null;
    category: string;
    category_label: string;
    platforms: string[];
    post_type: string;
    tone: string | null;
    instructions: string | null;
    status: string;
    status_label: string;
    status_color: string;
    post_id: number | null;
    suggestion_id: number | null;
    batch_id: string | null;
    batch_status: string | null;
}

interface DraftBatch {
    batch_id: string;
    total: number;
    start_date: string;
    end_date: string;
}

const currentDate = ref(new Date());
const posts = ref<CalendarPost[]>([]);
const calendarItems = ref<CalendarItem[]>([]);
const draftBatches = ref<DraftBatch[]>([]);
const loading = ref(false);
const activeTab = ref<'posts' | 'content'>('posts');
const approvingBatch = ref<string | null>(null);
const rejectingBatch = ref<string | null>(null);

// AI Generation state
const showGenerateModal = ref(false);
const generating = ref(false);
const generatingPost = ref<number | null>(null);
const generatingAll = ref(false);
const generateResult = ref<string | null>(null);

const generateForm = ref({
    start_date: '',
    end_date: '',
    posts_per_week: 5,
    platforms: ['instagram'] as string[],
    categories: [] as string[],
    tone: '',
    ai_model: 'gemini-2.0-flash',
    instructions: '',
});

// Selected item for edit
const selectedItem = ref<CalendarItem | null>(null);
const showEditModal = ref(false);
const editForm = ref({
    title: '',
    description: '',
    category: '',
    platforms: [] as string[],
    post_type: '',
    tone: '',
    instructions: '',
    scheduled_date: '',
});

const platformColors: Record<string, string> = {
    instagram: '#E4405F',
    facebook: '#1877F2',
    linkedin: '#0A66C2',
    tiktok: '#000000',
    youtube: '#FF0000',
    pinterest: '#BD081C',
};

const platformLabels: Record<string, string> = {
    instagram: 'Instagram',
    facebook: 'Facebook',
    linkedin: 'LinkedIn',
    tiktok: 'TikTok',
    youtube: 'YouTube',
    pinterest: 'Pinterest',
};

const statusDotColors: Record<string, string> = {
    gray: '#6B7280',
    yellow: '#F59E0B',
    blue: '#3B82F6',
    indigo: '#6366F1',
    orange: '#F97316',
    green: '#22C55E',
    red: '#EF4444',
};

const categoryOptions = [
    'dica', 'novidade', 'bastidores', 'promocao', 'educativo',
    'inspiracional', 'engajamento', 'produto', 'institucional',
    'depoimento', 'lancamento', 'tendencia',
];

const categoryColors: Record<string, string> = {
    dica: 'bg-blue-500/20 text-blue-400',
    novidade: 'bg-purple-500/20 text-purple-400',
    bastidores: 'bg-amber-500/20 text-amber-400',
    promocao: 'bg-red-500/20 text-red-400',
    educativo: 'bg-emerald-500/20 text-emerald-400',
    inspiracional: 'bg-pink-500/20 text-pink-400',
    engajamento: 'bg-indigo-500/20 text-indigo-400',
    produto: 'bg-orange-500/20 text-orange-400',
    institucional: 'bg-gray-500/20 text-gray-400',
    depoimento: 'bg-teal-500/20 text-teal-400',
    lancamento: 'bg-rose-500/20 text-rose-400',
    tendencia: 'bg-violet-500/20 text-violet-400',
};

const postTypeLabels: Record<string, string> = {
    feed: 'Feed', carousel: 'Carousel', story: 'Story', reel: 'Reel', video: 'Video', pin: 'Pin',
};

const aiModels = [
    { value: 'gemini-2.0-flash', label: 'Gemini 2.0 Flash' },
    { value: 'gemini-2.0-pro', label: 'Gemini 2.0 Pro' },
    { value: 'gpt-4o-mini', label: 'GPT-4o Mini' },
    { value: 'gpt-4o', label: 'GPT-4o' },
    { value: 'claude-3-5-haiku-20241022', label: 'Claude 3.5 Haiku' },
    { value: 'claude-3-5-sonnet-20241022', label: 'Claude 3.5 Sonnet' },
];

// Calendar computations
const currentMonth = computed(() => currentDate.value.getMonth());
const currentYear = computed(() => currentDate.value.getFullYear());
const monthName = computed(() => currentDate.value.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' }));
const weekDays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'];

const calendarDays = computed(() => {
    const year = currentYear.value;
    const month = currentMonth.value;
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startDayOfWeek = firstDay.getDay();
    const daysInMonth = lastDay.getDate();

    const days: Array<{ date: number; month: number; year: number; isCurrentMonth: boolean; isToday: boolean; dateStr: string }> = [];

    const prevMonthLastDay = new Date(year, month, 0).getDate();
    for (let i = startDayOfWeek - 1; i >= 0; i--) {
        const d = prevMonthLastDay - i;
        const m = month === 0 ? 11 : month - 1;
        const y = month === 0 ? year - 1 : year;
        days.push({ date: d, month: m, year: y, isCurrentMonth: false, isToday: false, dateStr: formatDate(y, m, d) });
    }

    const today = new Date();
    for (let d = 1; d <= daysInMonth; d++) {
        const isToday = d === today.getDate() && month === today.getMonth() && year === today.getFullYear();
        days.push({ date: d, month, year, isCurrentMonth: true, isToday, dateStr: formatDate(year, month, d) });
    }

    const remaining = 42 - days.length;
    for (let d = 1; d <= remaining; d++) {
        const m = month === 11 ? 0 : month + 1;
        const y = month === 11 ? year + 1 : year;
        days.push({ date: d, month: m, year: y, isCurrentMonth: false, isToday: false, dateStr: formatDate(y, m, d) });
    }

    return days;
});

const pendingItemsCount = computed(() => calendarItems.value.filter(i => i.status === 'pending').length);

function formatDate(year: number, month: number, day: number): string {
    return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function getPostsForDate(dateStr: string): CalendarPost[] {
    return posts.value.filter(p => p.date === dateStr);
}

function getItemsForDate(dateStr: string): CalendarItem[] {
    return calendarItems.value.filter(i => i.date === dateStr);
}

function prevMonth() { const d = new Date(currentDate.value); d.setMonth(d.getMonth() - 1); currentDate.value = d; }
function nextMonth() { const d = new Date(currentDate.value); d.setMonth(d.getMonth() + 1); currentDate.value = d; }
function goToToday() { currentDate.value = new Date(); }

async function fetchCalendarData() {
    if (!currentBrand.value) return;
    loading.value = true;
    try {
        const start = formatDate(currentYear.value, currentMonth.value, 1);
        const lastDay = new Date(currentYear.value, currentMonth.value + 1, 0).getDate();
        const end = formatDate(currentYear.value, currentMonth.value, lastDay);

        const [postsRes, itemsRes] = await Promise.all([
            axios.get(route('social.calendar.data'), { params: { start, end } }),
            axios.get(route('social.calendar.content.items'), { params: { start, end } }),
        ]);

        posts.value = postsRes.data.posts || [];
        calendarItems.value = itemsRes.data.items || [];
        draftBatches.value = itemsRes.data.draft_batches || [];
    } catch (e) {
        console.error('Erro ao carregar calendario:', e);
    } finally {
        loading.value = false;
    }
}

function openGenerateModal() {
    const year = currentYear.value;
    const month = currentMonth.value;
    generateForm.value.start_date = formatDate(year, month, 1);
    const lastDay = new Date(year, month + 1, 0).getDate();
    generateForm.value.end_date = formatDate(year, month, lastDay);
    generateForm.value.tone = '';
    generateForm.value.instructions = '';
    generateForm.value.categories = [];
    generateResult.value = null;
    showGenerateModal.value = true;
}

async function submitGenerate() {
    generating.value = true;
    generateResult.value = null;
    try {
        const res = await axios.post(route('social.calendar.content.generate'), generateForm.value);
        generateResult.value = res.data.message;
        await fetchCalendarData();
    } catch (e: any) {
        generateResult.value = e.response?.data?.error || 'Erro ao gerar calendario.';
    } finally {
        generating.value = false;
    }
}

async function generatePostFromItem(itemId: number) {
    generatingPost.value = itemId;
    try {
        const res = await axios.post(route('social.calendar.content.generate-post', itemId));
        generateResult.value = res.data.message;
        await fetchCalendarData();
    } catch (e: any) {
        alert(e.response?.data?.error || 'Erro ao gerar post.');
    } finally {
        generatingPost.value = null;
    }
}

async function generateAllPosts() {
    if (!confirm('Gerar posts para TODAS as pautas pendentes do periodo visivel?')) return;
    generatingAll.value = true;
    try {
        const start = formatDate(currentYear.value, currentMonth.value, 1);
        const lastDay = new Date(currentYear.value, currentMonth.value + 1, 0).getDate();
        const end = formatDate(currentYear.value, currentMonth.value, lastDay);

        const res = await axios.post(route('social.calendar.content.generate-all-posts'), { start_date: start, end_date: end, limit: 20 });
        generateResult.value = res.data.message;
        await fetchCalendarData();
    } catch (e: any) {
        alert(e.response?.data?.error || 'Erro ao gerar posts.');
    } finally {
        generatingAll.value = false;
    }
}

function openEditItem(item: CalendarItem) {
    selectedItem.value = item;
    editForm.value = {
        title: item.title,
        description: item.description || '',
        category: item.category,
        platforms: [...(item.platforms || [])],
        post_type: item.post_type,
        tone: item.tone || '',
        instructions: item.instructions || '',
        scheduled_date: item.date,
    };
    showEditModal.value = true;
}

async function saveEditItem() {
    if (!selectedItem.value) return;
    try {
        await axios.put(route('social.calendar.content.update', selectedItem.value.id), editForm.value);
        showEditModal.value = false;
        await fetchCalendarData();
    } catch (e: any) {
        alert(e.response?.data?.error || 'Erro ao salvar.');
    }
}

async function deleteItem(itemId: number) {
    if (!confirm('Remover esta pauta?')) return;
    try {
        await axios.delete(route('social.calendar.content.destroy', itemId));
        showEditModal.value = false;
        await fetchCalendarData();
    } catch (e: any) {
        alert(e.response?.data?.error || 'Erro ao remover.');
    }
}

function togglePlatform(platform: string, formPlatforms: string[]) {
    const idx = formPlatforms.indexOf(platform);
    if (idx >= 0) formPlatforms.splice(idx, 1);
    else formPlatforms.push(platform);
}

function toggleCategory(cat: string) {
    const idx = generateForm.value.categories.indexOf(cat);
    if (idx >= 0) generateForm.value.categories.splice(idx, 1);
    else generateForm.value.categories.push(cat);
}

function getPlatformDots(platforms: string[]): Array<{ color: string }> {
    return (platforms || []).slice(0, 3).map(p => ({ color: platformColors[p] || '#6B7280' }));
}

function switchBrandFromModal(event: Event) {
    const brandId = parseInt((event.target as HTMLSelectElement).value);
    if (brandId && brandId !== currentBrand.value?.id) {
        showGenerateModal.value = false;
        router.post(route('brands.switch', brandId), {}, {
            preserveState: false,
        });
    }
}

// Drafts count
const totalDraftItems = computed(() => draftBatches.value.reduce((sum, b) => sum + b.total, 0));
const draftItemsInView = computed(() => calendarItems.value.filter(i => i.batch_status === 'draft'));

async function approveBatch(batchId: string) {
    approvingBatch.value = batchId;
    try {
        const res = await axios.post(route('social.calendar.content.approve-batch'), { batch_id: batchId });
        generateResult.value = res.data.message;
        await fetchCalendarData();
    } catch (e: any) {
        alert(e.response?.data?.error || 'Erro ao aprovar batch.');
    } finally {
        approvingBatch.value = null;
    }
}

async function rejectBatch(batchId: string) {
    if (!confirm('Rejeitar e remover TODAS as pautas deste lote? Esta acao nao pode ser desfeita.')) return;
    rejectingBatch.value = batchId;
    try {
        const res = await axios.post(route('social.calendar.content.reject-batch'), { batch_id: batchId });
        generateResult.value = res.data.message;
        await fetchCalendarData();
    } catch (e: any) {
        alert(e.response?.data?.error || 'Erro ao rejeitar batch.');
    } finally {
        rejectingBatch.value = null;
    }
}

async function approveItem(itemId: number) {
    try {
        await axios.post(route('social.calendar.content.approve-item', itemId));
        await fetchCalendarData();
    } catch (e: any) {
        alert(e.response?.data?.error || 'Erro ao aprovar pauta.');
    }
}

const calendarGuideSteps = [
    { title: 'Calendario inteligente', description: 'A IA gera um calendario editorial completo analisando suas redes sociais, analytics, e-commerce e historico de conteudo.' },
    { title: 'Geracao automatica', description: 'No dia 25 de cada mes, o sistema gera automaticamente um calendario para o mes seguinte. Pautas aparecem como "Proposta IA" para sua aprovacao.' },
    { title: 'Gere manualmente', description: 'Voce tambem pode clicar em "Gerar Calendario com IA" para criar pautas sob demanda para qualquer periodo.' },
    { title: 'Aprove e publique', description: 'Revise as pautas, edite se necessario, e aprove. Pautas aprovadas viram posts automaticamente com legendas e hashtags.' },
];

watch(currentDate, fetchCalendarData);
onMounted(fetchCalendarData);
</script>

<template>
    <Head title="Social - Calendario" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-white">Calendario</h1>
                <div class="flex items-center gap-2">
                    <button @click="openGenerateModal" class="rounded-xl bg-gradient-to-r from-violet-600 to-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:from-violet-700 hover:to-indigo-700 transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                        Gerar Calendario com IA
                    </button>
                    <Link :href="route('social.posts.index')" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        Posts
                    </Link>
                    <Link :href="route('social.posts.create')" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                        + Novo Post
                    </Link>
                </div>
            </div>
        </template>

        <GuideBox title="Calendario Editorial com IA" color="purple" storage-key="calendar-ai-guide" class="mb-6" :steps="calendarGuideSteps" />

        <div v-if="!currentBrand" class="rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
            <h3 class="text-lg font-medium text-gray-300">Nenhuma marca selecionada</h3>
            <p class="mt-2 text-sm text-gray-500">Selecione uma marca para ver o calendario.</p>
        </div>

        <template v-else>
            <!-- Tab switcher + actions -->
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-1 bg-gray-900 rounded-xl p-1 border border-gray-800">
                    <button @click="activeTab = 'posts'" :class="['rounded-lg px-4 py-2 text-sm font-medium transition', activeTab === 'posts' ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white']">
                        Posts Agendados
                    </button>
                    <button @click="activeTab = 'content'" :class="['rounded-lg px-4 py-2 text-sm font-medium transition flex items-center gap-2', activeTab === 'content' ? 'bg-violet-600 text-white' : 'text-gray-400 hover:text-white']">
                        Pautas IA
                        <span v-if="pendingItemsCount > 0" class="bg-amber-500/20 text-amber-400 text-[10px] font-bold px-1.5 py-0.5 rounded">{{ pendingItemsCount }}</span>
                    </button>
                </div>

                <div v-if="activeTab === 'content' && pendingItemsCount > 0" class="flex items-center gap-2">
                    <button @click="generateAllPosts" :disabled="generatingAll" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 transition disabled:opacity-50 flex items-center gap-2">
                        <svg v-if="generatingAll" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        {{ generatingAll ? 'Gerando...' : 'Gerar Posts de Todas as Pautas' }}
                    </button>
                </div>
            </div>

            <!-- Result message -->
            <div v-if="generateResult" class="mb-4 rounded-xl bg-indigo-900/30 border border-indigo-700/30 px-4 py-3 text-sm text-indigo-300 flex items-center justify-between">
                <span>{{ generateResult }}</span>
                <button @click="generateResult = null" class="text-indigo-400 hover:text-white">&times;</button>
            </div>

            <!-- Draft Batch Approval Banners -->
            <div v-for="batch in draftBatches" :key="batch.batch_id" class="mb-4 rounded-xl bg-amber-900/20 border border-amber-700/40 px-5 py-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/20 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                        </div>
                        <div>
                            <p class="text-amber-200 font-semibold text-sm">Proposta de Calendario Gerada pela IA</p>
                            <p class="text-amber-400/70 text-xs mt-0.5">{{ batch.total }} pautas de {{ batch.start_date }} a {{ batch.end_date }} aguardando sua aprovacao</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="rejectBatch(batch.batch_id)" :disabled="rejectingBatch === batch.batch_id"
                            class="rounded-xl px-4 py-2 text-xs font-medium text-red-400 hover:text-red-300 border border-red-800/50 hover:border-red-700 transition disabled:opacity-50">
                            {{ rejectingBatch === batch.batch_id ? 'Rejeitando...' : 'Rejeitar Todas' }}
                        </button>
                        <button @click="activeTab = 'content'" class="rounded-xl px-4 py-2 text-xs font-medium text-amber-300 hover:text-amber-200 border border-amber-700/50 transition">
                            Revisar
                        </button>
                        <button @click="approveBatch(batch.batch_id)" :disabled="approvingBatch === batch.batch_id"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-xs font-semibold text-white hover:bg-emerald-700 transition disabled:opacity-50 flex items-center gap-1.5">
                            <svg v-if="approvingBatch === batch.batch_id" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            {{ approvingBatch === batch.batch_id ? 'Aprovando...' : 'Aprovar Todas' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Calendar -->
            <div class="rounded-2xl bg-gray-900 border border-gray-800 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800">
                    <div class="flex items-center gap-3">
                        <button @click="prevMonth" class="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                        </button>
                        <h2 class="text-lg font-semibold text-white capitalize min-w-[200px] text-center">{{ monthName }}</h2>
                        <button @click="nextMonth" class="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                        </button>
                    </div>
                    <button @click="goToToday" class="rounded-xl px-4 py-1.5 text-sm text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">Hoje</button>
                </div>

                <div class="grid grid-cols-7 border-b border-gray-800">
                    <div v-for="day in weekDays" :key="day" class="py-3 text-center text-xs font-medium text-gray-500 uppercase">{{ day }}</div>
                </div>

                <div class="grid grid-cols-7" :class="loading ? 'opacity-50' : ''">
                    <div v-for="(day, index) in calendarDays" :key="index"
                        :class="['min-h-[110px] border-b border-r border-gray-800 p-1.5 transition-colors',
                            day.isCurrentMonth ? 'bg-gray-900' : 'bg-gray-950',
                            day.isToday ? 'bg-indigo-950/30' : '',
                            (index + 1) % 7 === 0 ? 'border-r-0' : '']">

                        <div class="flex items-center justify-between mb-1">
                            <span :class="['text-sm font-medium', day.isToday ? 'flex items-center justify-center w-6 h-6 rounded-full bg-indigo-600 text-white text-xs' : '', day.isCurrentMonth ? (day.isToday ? '' : 'text-gray-300') : 'text-gray-600']">
                                {{ day.date }}
                            </span>
                        </div>

                        <!-- Posts (tab posts) -->
                        <template v-if="activeTab === 'posts'">
                            <div class="space-y-0.5">
                                <Link v-for="post in getPostsForDate(day.dateStr).slice(0, 3)" :key="'post-' + post.id"
                                    :href="route('social.posts.edit', post.id)"
                                    class="flex items-center gap-1 rounded px-1 py-0.5 text-[10px] truncate hover:bg-gray-800 transition group">
                                    <span class="w-1.5 h-1.5 rounded-full shrink-0" :style="{ backgroundColor: statusDotColors[post.status_color] || '#6B7280' }" />
                                    <span class="truncate text-gray-400 group-hover:text-gray-200">{{ post.time }} {{ post.title }}</span>
                                    <span class="flex items-center gap-0.5 ml-auto shrink-0">
                                        <span v-for="(dot, di) in getPlatformDots(post.platforms)" :key="di" class="w-1 h-1 rounded-full" :style="{ backgroundColor: dot.color }" />
                                    </span>
                                </Link>
                                <span v-if="getPostsForDate(day.dateStr).length > 3" class="text-[9px] text-gray-600 px-1">+{{ getPostsForDate(day.dateStr).length - 3 }}</span>
                            </div>
                        </template>

                        <!-- Calendar Items (tab content) -->
                        <template v-if="activeTab === 'content'">
                            <div class="space-y-0.5">
                                <button v-for="item in getItemsForDate(day.dateStr).slice(0, 3)" :key="'item-' + item.id"
                                    @click="openEditItem(item)"
                                    :class="['flex items-center gap-1 rounded px-1 py-0.5 text-[10px] truncate transition group w-full text-left',
                                        item.batch_status === 'draft' ? 'border border-dashed border-amber-700/50 bg-amber-950/20 hover:bg-amber-950/40' : 'hover:bg-gray-800']">
                                    <span v-if="item.batch_status === 'draft'" class="w-1.5 h-1.5 rounded shrink-0 bg-amber-500 animate-pulse" />
                                    <span v-else class="w-1.5 h-1.5 rounded-full shrink-0" :style="{ backgroundColor: statusDotColors[item.status_color] || '#F59E0B' }" />
                                    <span :class="['truncate group-hover:text-gray-200', item.batch_status === 'draft' ? 'text-amber-400/80' : 'text-gray-400']">{{ item.title }}</span>
                                    <span class="flex items-center gap-0.5 ml-auto shrink-0">
                                        <span v-for="(dot, di) in getPlatformDots(item.platforms)" :key="di" class="w-1 h-1 rounded-full" :style="{ backgroundColor: dot.color }" />
                                    </span>
                                </button>
                                <span v-if="getItemsForDate(day.dateStr).length > 3" class="text-[9px] text-gray-600 px-1">+{{ getItemsForDate(day.dateStr).length - 3 }}</span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Legend -->
            <div class="mt-4 flex flex-wrap items-center gap-4 text-xs text-gray-500">
                <template v-if="activeTab === 'posts'">
                    <span class="font-medium text-gray-400">Status:</span>
                    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-gray-500" /> Rascunho</span>
                    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-indigo-500" /> Agendado</span>
                    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-green-500" /> Publicado</span>
                </template>
                <template v-else>
                    <span class="font-medium text-gray-400">Status Pauta:</span>
                    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded bg-amber-500 animate-pulse" /> Proposta IA</span>
                    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-yellow-500" /> Pendente</span>
                    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-blue-500" /> Post Gerado</span>
                    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-green-500" /> Publicado</span>
                    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-gray-500" /> Pulado</span>
                </template>
                <span class="ml-4 font-medium text-gray-400">Plataformas:</span>
                <span v-for="(color, platform) in platformColors" :key="platform" class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: color }" /> {{ platformLabels[platform] }}
                </span>
            </div>
        </template>

        <!-- ===== MODAL: Gerar Calendario com IA ===== -->
        <Teleport to="body">
            <div v-if="showGenerateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showGenerateModal = false">
                <div class="bg-gray-900 border border-gray-700 rounded-2xl w-full max-w-xl max-h-[90vh] overflow-y-auto p-6 mx-4">
                    <div class="flex items-center justify-between mb-5">
                        <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                            Gerar Calendario com IA
                        </h3>
                        <button @click="showGenerateModal = false" class="text-gray-500 hover:text-white text-xl">&times;</button>
                    </div>

                    <div class="space-y-4">
                        <!-- Marca selecionada -->
                        <div class="rounded-xl border border-gray-700 bg-gray-800/50 p-4">
                            <label class="text-xs text-gray-400 mb-2 block font-medium">Gerando calendario para:</label>
                            <div v-if="currentBrand" class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-sm font-bold shrink-0"
                                    :style="{ backgroundColor: currentBrand.primary_color || '#6366F1' }">
                                    <img v-if="currentBrand.logo_path" :src="'/storage/' + currentBrand.logo_path" class="w-full h-full object-cover rounded-xl" />
                                    <span v-else>{{ currentBrand.name?.charAt(0)?.toUpperCase() }}</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-white font-semibold text-sm truncate">{{ currentBrand.name }}</p>
                                    <p class="text-gray-400 text-xs truncate">{{ currentBrand.segment || 'Segmento nao definido' }}</p>
                                </div>
                                <div v-if="brands.length > 1" class="shrink-0">
                                    <select @change="switchBrandFromModal($event)" :value="currentBrand.id"
                                        class="bg-gray-700 border border-gray-600 rounded-lg px-2 py-1.5 text-xs text-gray-300 cursor-pointer">
                                        <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                                    </select>
                                </div>
                            </div>
                            <div v-else class="text-amber-400 text-sm flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" /></svg>
                                Nenhuma marca selecionada. Selecione uma marca no menu superior.
                            </div>
                            <p class="mt-2 text-[11px] text-gray-500">A IA usara o nome, segmento, publico-alvo, tom de voz e palavras-chave da marca para criar pautas contextualizadas.</p>
                        </div>

                        <!-- Periodo -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs text-gray-400 mb-1 block">Data Inicio</label>
                                <input v-model="generateForm.start_date" type="date" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white" />
                            </div>
                            <div>
                                <label class="text-xs text-gray-400 mb-1 block">Data Fim</label>
                                <input v-model="generateForm.end_date" type="date" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white" />
                            </div>
                        </div>

                        <!-- Posts por semana -->
                        <div>
                            <label class="text-xs text-gray-400 mb-1 block">Posts por semana</label>
                            <input v-model.number="generateForm.posts_per_week" type="number" min="1" max="14" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white" />
                        </div>

                        <!-- Plataformas -->
                        <div>
                            <label class="text-xs text-gray-400 mb-2 block">Plataformas</label>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="(label, p) in platformLabels" :key="p"
                                    @click="togglePlatform(p, generateForm.platforms)"
                                    :class="['rounded-lg px-3 py-1.5 text-xs font-medium border transition', generateForm.platforms.includes(p) ? 'border-indigo-500 bg-indigo-500/20 text-indigo-300' : 'border-gray-700 text-gray-500 hover:text-white']">
                                    {{ label }}
                                </button>
                            </div>
                        </div>

                        <!-- Categorias -->
                        <div>
                            <label class="text-xs text-gray-400 mb-2 block">Categorias (vazio = todas)</label>
                            <div class="flex flex-wrap gap-1.5">
                                <button v-for="cat in categoryOptions" :key="cat"
                                    @click="toggleCategory(cat)"
                                    :class="['rounded-lg px-2.5 py-1 text-[11px] font-medium border transition', generateForm.categories.includes(cat) ? 'border-violet-500 bg-violet-500/20 text-violet-300' : 'border-gray-700 text-gray-500 hover:text-white']">
                                    {{ cat }}
                                </button>
                            </div>
                        </div>

                        <!-- Tom e modelo IA -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs text-gray-400 mb-1 block">Tom de voz (opcional)</label>
                                <input v-model="generateForm.tone" type="text" placeholder="Ex: descontraido e jovem" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white placeholder-gray-600" />
                            </div>
                            <div>
                                <label class="text-xs text-gray-400 mb-1 block">Modelo IA</label>
                                <select v-model="generateForm.ai_model" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white">
                                    <option v-for="m in aiModels" :key="m.value" :value="m.value">{{ m.label }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Instrucoes extras -->
                        <div>
                            <label class="text-xs text-gray-400 mb-1 block">Instrucoes extras (opcional)</label>
                            <textarea v-model="generateForm.instructions" rows="3" placeholder="Ex: Foque em datas comemorativas, inclua CTA de vendas..." class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white placeholder-gray-600 resize-none" />
                        </div>

                        <!-- Result -->
                        <div v-if="generateResult" class="rounded-xl bg-emerald-900/30 border border-emerald-700/30 px-4 py-3 text-sm text-emerald-300">
                            {{ generateResult }}
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <button @click="showGenerateModal = false" class="rounded-xl px-4 py-2 text-sm text-gray-400 hover:text-white border border-gray-700 transition">Cancelar</button>
                            <button @click="submitGenerate" :disabled="generating || !currentBrand" class="rounded-xl bg-gradient-to-r from-violet-600 to-indigo-600 px-6 py-2 text-sm font-semibold text-white hover:from-violet-700 hover:to-indigo-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                                <svg v-if="generating" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                {{ generating ? 'Gerando...' : 'Gerar Calendario' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ===== MODAL: Editar/Ver Pauta ===== -->
        <Teleport to="body">
            <div v-if="showEditModal && selectedItem" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showEditModal = false">
                <div class="bg-gray-900 border border-gray-700 rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto p-6 mx-4">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-lg font-semibold text-white">{{ selectedItem.status === 'pending' ? 'Editar Pauta' : 'Detalhes da Pauta' }}</h3>
                                <span v-if="selectedItem.batch_status === 'draft'" class="text-[10px] font-bold px-2 py-0.5 rounded bg-amber-500/20 text-amber-400 border border-amber-700/30">PROPOSTA IA</span>
                            </div>
                            <span :class="['text-xs font-medium px-2 py-0.5 rounded mt-1 inline-block', categoryColors[selectedItem.category] || 'bg-gray-500/20 text-gray-400']">{{ selectedItem.category_label }}</span>
                        </div>
                        <button @click="showEditModal = false" class="text-gray-500 hover:text-white text-xl">&times;</button>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label class="text-xs text-gray-400 mb-1 block">Titulo</label>
                            <input v-model="editForm.title" type="text" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white" :disabled="selectedItem.status !== 'pending'" />
                        </div>
                        <div>
                            <label class="text-xs text-gray-400 mb-1 block">Descricao / Briefing</label>
                            <textarea v-model="editForm.description" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white resize-none" :disabled="selectedItem.status !== 'pending'" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs text-gray-400 mb-1 block">Data</label>
                                <input v-model="editForm.scheduled_date" type="date" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white" :disabled="selectedItem.status !== 'pending'" />
                            </div>
                            <div>
                                <label class="text-xs text-gray-400 mb-1 block">Tipo</label>
                                <select v-model="editForm.post_type" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white" :disabled="selectedItem.status !== 'pending'">
                                    <option v-for="(label, value) in postTypeLabels" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs text-gray-400 mb-2 block">Plataformas</label>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="(label, p) in platformLabels" :key="p"
                                    @click="selectedItem.status === 'pending' && togglePlatform(p, editForm.platforms)"
                                    :class="['rounded-lg px-3 py-1 text-xs font-medium border transition', editForm.platforms.includes(p) ? 'border-indigo-500 bg-indigo-500/20 text-indigo-300' : 'border-gray-700 text-gray-500']">
                                    {{ label }}
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs text-gray-400 mb-1 block">Instrucoes extras</label>
                            <textarea v-model="editForm.instructions" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white resize-none" :disabled="selectedItem.status !== 'pending'" />
                        </div>
                    </div>

                    <!-- Draft approval notice -->
                    <div v-if="selectedItem.batch_status === 'draft'" class="mt-4 rounded-xl bg-amber-900/20 border border-amber-700/30 px-4 py-3">
                        <p class="text-xs text-amber-300">Esta pauta foi gerada automaticamente pela IA e aguarda sua aprovacao. Edite se necessario e aprove para ativa-la.</p>
                    </div>

                    <div class="flex items-center justify-between mt-5 pt-4 border-t border-gray-800">
                        <div class="flex items-center gap-2">
                            <button v-if="selectedItem.status === 'pending'" @click="deleteItem(selectedItem.id)" class="rounded-xl px-3 py-2 text-xs text-red-400 hover:text-red-300 border border-red-800/50 hover:border-red-700 transition">
                                Remover
                            </button>
                            <Link v-if="selectedItem.suggestion_id" :href="route('social.content-engine.index')" class="rounded-xl px-3 py-2 text-xs text-indigo-400 hover:text-indigo-300 border border-indigo-800/50 transition">
                                Ver Sugestao
                            </Link>
                        </div>
                        <div class="flex items-center gap-2">
                            <button v-if="selectedItem.batch_status === 'draft'" @click="approveItem(selectedItem.id); showEditModal = false;"
                                class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-medium text-white hover:bg-emerald-700 transition">
                                Aprovar Pauta
                            </button>
                            <button v-if="selectedItem.status === 'pending' && selectedItem.batch_status !== 'draft'" @click="generatePostFromItem(selectedItem.id)" :disabled="generatingPost === selectedItem.id"
                                class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-medium text-white hover:bg-emerald-700 transition disabled:opacity-50 flex items-center gap-1.5">
                                <svg v-if="generatingPost === selectedItem.id" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                {{ generatingPost === selectedItem.id ? 'Gerando...' : 'Gerar Post com IA' }}
                            </button>
                            <button v-if="selectedItem.status === 'pending'" @click="saveEditItem" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-medium text-white hover:bg-indigo-700 transition">
                                Salvar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
