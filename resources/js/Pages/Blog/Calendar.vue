<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';

interface CalendarItem {
    id: number;
    date: string;
    title: string;
    description: string | null;
    keywords: string | null;
    tone: string | null;
    instructions: string | null;
    estimated_word_count: number;
    category: string | null;
    category_id: number | null;
    connection_id: number | null;
    status: string;
    status_label: string;
    status_color: string;
    article_id: number | null;
    article_title: string | null;
    article_status: string | null;
    batch_id: string | null;
    batch_status: string | null;
}

interface DraftBatch {
    batch_id: string;
    total: number;
    start_date: string;
    end_date: string;
}

const props = defineProps<{
    categories: { id: number; name: string }[];
    connections: { id: number; name: string; platform: string; platform_label: string; site_url: string }[];
}>();

const currentDate = ref(new Date());
const calendarItems = ref<CalendarItem[]>([]);
const draftBatches = ref<DraftBatch[]>([]);
const loading = ref(false);

// Generate modal
const showGenerateModal = ref(false);
const generating = ref(false);
const generateForm = ref({
    start_date: '',
    end_date: '',
    posts_per_week: 2,
    tone: '',
    instructions: '',
    wordpress_connection_id: null as number | null,
    blog_category_id: null as number | null,
    ai_model: 'gpt-4o-mini',
    cover_width: 1750,
    cover_height: 650,
});

// Item detail panel
const selectedItem = ref<CalendarItem | null>(null);
const generatingArticle = ref(false);
const generatingAll = ref(false);
const approvingBatch = ref<string | null>(null);
const rejectingBatch = ref<string | null>(null);

// Drag
const dragItemId = ref<number | null>(null);
const dragOverDate = ref<string | null>(null);

// Calendar logic
const currentMonth = computed(() => currentDate.value.getMonth());
const currentYear = computed(() => currentDate.value.getFullYear());
const monthLabel = computed(() => {
    const months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
        'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    return `${months[currentMonth.value]} ${currentYear.value}`;
});

const weekDays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

interface CalendarDay {
    date: Date;
    dateStr: string;
    day: number;
    isCurrentMonth: boolean;
    isToday: boolean;
}

const calendarDays = computed<CalendarDay[]>(() => {
    const year = currentYear.value;
    const month = currentMonth.value;
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startDow = firstDay.getDay();

    const days: CalendarDay[] = [];
    const today = new Date();
    const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

    // Previous month padding
    for (let i = startDow - 1; i >= 0; i--) {
        const d = new Date(year, month, -i);
        const ds = formatDate(d);
        days.push({ date: d, dateStr: ds, day: d.getDate(), isCurrentMonth: false, isToday: ds === todayStr });
    }

    // Current month
    for (let i = 1; i <= lastDay.getDate(); i++) {
        const d = new Date(year, month, i);
        const ds = formatDate(d);
        days.push({ date: d, dateStr: ds, day: i, isCurrentMonth: true, isToday: ds === todayStr });
    }

    // Next month padding
    const remaining = 42 - days.length;
    for (let i = 1; i <= remaining; i++) {
        const d = new Date(year, month + 1, i);
        const ds = formatDate(d);
        days.push({ date: d, dateStr: ds, day: i, isCurrentMonth: false, isToday: ds === todayStr });
    }

    return days;
});

function formatDate(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function prevMonth() {
    currentDate.value = new Date(currentYear.value, currentMonth.value - 1, 1);
}

function nextMonth() {
    currentDate.value = new Date(currentYear.value, currentMonth.value + 1, 1);
}

function goToday() {
    currentDate.value = new Date();
}

function getItemsForDate(dateStr: string): CalendarItem[] {
    return calendarItems.value.filter(i => i.date === dateStr);
}

const visibleStart = computed(() => {
    if (calendarDays.value.length === 0) return '';
    return calendarDays.value[0].dateStr;
});

const visibleEnd = computed(() => {
    if (calendarDays.value.length === 0) return '';
    return calendarDays.value[calendarDays.value.length - 1].dateStr;
});

// Fetch calendar data
async function fetchData() {
    if (!visibleStart.value || !visibleEnd.value) return;
    loading.value = true;
    try {
        const resp = await axios.get(route('blog.calendar.items'), {
            params: { start: visibleStart.value, end: visibleEnd.value },
        });
        calendarItems.value = resp.data.items || [];
        draftBatches.value = resp.data.draft_batches || [];
    } catch { }
    finally { loading.value = false; }
}

watch([currentMonth, currentYear], fetchData);
onMounted(() => {
    fetchData();
    // Pre-fill generate form dates
    const y = currentYear.value;
    const m = currentMonth.value;
    generateForm.value.start_date = formatDate(new Date(y, m, 1));
    generateForm.value.end_date = formatDate(new Date(y, m + 1, 0));
});

// Generate calendar
async function submitGenerate() {
    generating.value = true;
    try {
        const resp = await axios.post(route('blog.calendar.generate'), generateForm.value);
        if (resp.data.success) {
            showGenerateModal.value = false;
            fetchData();
        } else {
            alert(resp.data.error || 'Erro ao gerar calendário.');
        }
    } catch (e: any) {
        alert(e.response?.data?.error || 'Erro na geração.');
    } finally {
        generating.value = false;
    }
}

// Generate article from item
async function generateArticle(item: CalendarItem) {
    generatingArticle.value = true;
    try {
        const resp = await axios.post(route('blog.calendar.generate-article', item.id));
        if (resp.data.success) {
            fetchData();
            selectedItem.value = null;
        } else {
            alert(resp.data.error || 'Erro ao gerar artigo.');
        }
    } catch (e: any) {
        alert(e.response?.data?.error || 'Erro.');
    } finally {
        generatingArticle.value = false;
    }
}

// Generate all articles
async function generateAllArticles() {
    generatingAll.value = true;
    try {
        const resp = await axios.post(route('blog.calendar.generate-all-articles'), {
            start_date: visibleStart.value,
            end_date: visibleEnd.value,
            limit: 10,
        });
        if (resp.data.success !== false) {
            fetchData();
            alert(`Gerados: ${resp.data.generated || 0}, Falhas: ${resp.data.failed || 0}`);
        }
    } catch { }
    finally { generatingAll.value = false; }
}

// Batch actions
async function approveBatch(batchId: string) {
    approvingBatch.value = batchId;
    try {
        await axios.post(route('blog.calendar.approve-batch'), { batch_id: batchId });
        fetchData();
    } catch { }
    finally { approvingBatch.value = null; }
}

async function rejectBatch(batchId: string) {
    if (!confirm('Rejeitar e remover todas as pautas deste batch?')) return;
    rejectingBatch.value = batchId;
    try {
        await axios.post(route('blog.calendar.reject-batch'), { batch_id: batchId });
        fetchData();
    } catch { }
    finally { rejectingBatch.value = null; }
}

async function approveItem(item: CalendarItem) {
    try {
        await axios.post(route('blog.calendar.approve-item', item.id));
        fetchData();
    } catch { }
}

async function deleteItem(item: CalendarItem) {
    if (!confirm('Remover esta pauta?')) return;
    try {
        await axios.delete(route('blog.calendar.items.destroy', item.id));
        if (selectedItem.value?.id === item.id) selectedItem.value = null;
        fetchData();
    } catch { }
}

// Drag & drop
function onDragStart(item: CalendarItem, e: DragEvent) {
    if (item.status !== 'pending') return;
    dragItemId.value = item.id;
    if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
}

function onDragOver(dateStr: string, e: DragEvent) {
    e.preventDefault();
    dragOverDate.value = dateStr;
}

function onDragDrop(dateStr: string) {
    if (dragItemId.value !== null) {
        axios.put(route('blog.calendar.items.update', dragItemId.value), { scheduled_date: dateStr }).then(fetchData);
    }
    dragItemId.value = null;
    dragOverDate.value = null;
}

function onDragEnd() {
    dragItemId.value = null;
    dragOverDate.value = null;
}

// Status visuals
const statusDotColors: Record<string, string> = {
    yellow: 'bg-yellow-400',
    indigo: 'bg-indigo-400',
    blue: 'bg-blue-400',
    emerald: 'bg-emerald-400',
    green: 'bg-green-400',
    gray: 'bg-gray-500',
};

function openGenerateModal() {
    const y = currentYear.value;
    const m = currentMonth.value;
    generateForm.value.start_date = formatDate(new Date(y, m, 1));
    generateForm.value.end_date = formatDate(new Date(y, m + 1, 0));
    showGenerateModal.value = true;
}

const pendingCount = computed(() => calendarItems.value.filter(i => i.status === 'pending' && (i.batch_status === null || i.batch_status === 'approved')).length);
</script>

<template>
    <Head title="Blog - Calendário Editorial" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-white">Calendário Editorial</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Planeje e gere artigos de blog com IA</p>
                </div>
                <div class="flex items-center gap-2">
                    <button v-if="pendingCount > 0" @click="generateAllArticles" :disabled="generatingAll"
                        class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50 transition">
                        {{ generatingAll ? 'Gerando...' : `Gerar ${pendingCount} Artigo(s)` }}
                    </button>
                    <button @click="openGenerateModal"
                        class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 transition">
                        Gerar Pautas com IA
                    </button>
                </div>
            </div>
        </template>

        <!-- Draft batches banner -->
        <div v-for="batch in draftBatches" :key="batch.batch_id"
            class="rounded-xl bg-amber-900/20 border border-amber-600/30 p-3 mb-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-2 h-2 rounded-full bg-amber-400 animate-pulse" />
                <span class="text-sm text-amber-300">
                    {{ batch.total }} pauta(s) aguardando aprovação
                    <span class="text-amber-500/70 text-xs">({{ batch.start_date }} a {{ batch.end_date }})</span>
                </span>
            </div>
            <div class="flex gap-2">
                <button @click="approveBatch(batch.batch_id)" :disabled="approvingBatch === batch.batch_id"
                    class="rounded-lg bg-emerald-600/20 border border-emerald-500/30 px-3 py-1 text-xs text-emerald-400 hover:bg-emerald-600/30 transition disabled:opacity-50">
                    {{ approvingBatch === batch.batch_id ? '...' : 'Aprovar Todas' }}
                </button>
                <button @click="rejectBatch(batch.batch_id)" :disabled="rejectingBatch === batch.batch_id"
                    class="rounded-lg bg-red-600/20 border border-red-500/30 px-3 py-1 text-xs text-red-400 hover:bg-red-600/30 transition disabled:opacity-50">
                    Rejeitar
                </button>
            </div>
        </div>

        <div class="flex gap-4">
            <!-- Calendar Grid -->
            <div class="flex-1 min-w-0">
                <!-- Navigation -->
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <button @click="prevMonth" class="rounded-lg p-2 text-gray-400 hover:text-white hover:bg-gray-800 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                        </button>
                        <h2 class="text-lg font-semibold text-white min-w-[180px] text-center">{{ monthLabel }}</h2>
                        <button @click="nextMonth" class="rounded-lg p-2 text-gray-400 hover:text-white hover:bg-gray-800 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                        </button>
                        <button @click="goToday" class="rounded-lg px-3 py-1 text-xs text-gray-400 hover:text-white border border-gray-700 hover:bg-gray-800 transition ml-2">
                            Hoje
                        </button>
                    </div>
                    <div class="text-xs text-gray-600">
                        {{ calendarItems.length }} pauta(s) no período
                    </div>
                </div>

                <!-- Week days header -->
                <div class="grid grid-cols-7 mb-1">
                    <div v-for="wd in weekDays" :key="wd" class="text-center text-[10px] text-gray-500 uppercase tracking-wider py-1">
                        {{ wd }}
                    </div>
                </div>

                <!-- Days grid -->
                <div class="grid grid-cols-7 border border-gray-800 rounded-xl overflow-hidden">
                    <div v-for="day in calendarDays" :key="day.dateStr"
                        :class="['min-h-[100px] border-r border-b border-gray-800 p-1.5 transition',
                            day.isCurrentMonth ? 'bg-gray-900' : 'bg-gray-950/50',
                            day.isToday ? 'ring-1 ring-inset ring-indigo-500/50' : '',
                            dragOverDate === day.dateStr ? 'bg-indigo-900/20' : '']"
                        @dragover="onDragOver(day.dateStr, $event)" @drop="onDragDrop(day.dateStr)" @dragend="onDragEnd">

                        <!-- Day number -->
                        <div class="flex items-center justify-between mb-1">
                            <span :class="['text-[11px] font-medium', day.isToday ? 'text-indigo-400' : day.isCurrentMonth ? 'text-gray-400' : 'text-gray-600']">
                                {{ day.day }}
                            </span>
                        </div>

                        <!-- Items -->
                        <div class="space-y-0.5">
                            <div v-for="item in getItemsForDate(day.dateStr).slice(0, 3)" :key="item.id"
                                @click="selectedItem = item"
                                :draggable="item.status === 'pending'"
                                @dragstart="onDragStart(item, $event)"
                                :class="['flex items-center gap-1 px-1.5 py-1 rounded-md cursor-pointer transition text-[10px] truncate',
                                    item.batch_status === 'draft' ? 'border border-dashed border-amber-500/40 bg-amber-500/5 text-amber-300' :
                                    selectedItem?.id === item.id ? 'bg-indigo-600/20 text-indigo-300' :
                                    'bg-gray-800/60 text-gray-300 hover:bg-gray-800']">
                                <div :class="['w-1.5 h-1.5 rounded-full shrink-0', statusDotColors[item.status_color] || 'bg-gray-500']" />
                                <span class="truncate">{{ item.title }}</span>
                            </div>
                            <div v-if="getItemsForDate(day.dateStr).length > 3"
                                class="text-[9px] text-gray-600 text-center">
                                +{{ getItemsForDate(day.dateStr).length - 3 }} mais
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right panel: Item detail -->
            <div class="w-80 shrink-0 hidden lg:block">
                <div class="sticky top-4">
                    <div v-if="selectedItem" class="rounded-2xl bg-gray-900 border border-gray-800 p-4 space-y-3">
                        <!-- Status badge -->
                        <div class="flex items-center justify-between">
                            <span :class="['rounded-full px-2.5 py-0.5 text-[10px] font-medium border',
                                selectedItem.status_color === 'yellow' ? 'bg-yellow-500/10 text-yellow-400 border-yellow-500/30' :
                                selectedItem.status_color === 'blue' ? 'bg-blue-500/10 text-blue-400 border-blue-500/30' :
                                selectedItem.status_color === 'emerald' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30' :
                                selectedItem.status_color === 'green' ? 'bg-green-500/10 text-green-400 border-green-500/30' :
                                'bg-gray-500/10 text-gray-400 border-gray-500/30']">
                                {{ selectedItem.status_label }}
                            </span>
                            <span class="text-[10px] text-gray-600">{{ selectedItem.date }}</span>
                        </div>

                        <!-- Title -->
                        <h3 class="text-sm font-semibold text-white leading-snug">{{ selectedItem.title }}</h3>

                        <!-- Description -->
                        <p v-if="selectedItem.description" class="text-xs text-gray-400 leading-relaxed">{{ selectedItem.description }}</p>

                        <!-- Meta -->
                        <div class="space-y-1.5 text-[11px]">
                            <div v-if="selectedItem.keywords" class="flex items-start gap-2">
                                <span class="text-gray-600 shrink-0">Keywords:</span>
                                <span class="text-indigo-400">{{ selectedItem.keywords }}</span>
                            </div>
                            <div v-if="selectedItem.tone" class="flex items-start gap-2">
                                <span class="text-gray-600 shrink-0">Tom:</span>
                                <span class="text-gray-300">{{ selectedItem.tone }}</span>
                            </div>
                            <div v-if="selectedItem.instructions" class="flex items-start gap-2">
                                <span class="text-gray-600 shrink-0">Instruções:</span>
                                <span class="text-gray-400">{{ selectedItem.instructions }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-gray-600">Palavras:</span>
                                <span class="text-gray-300">~{{ selectedItem.estimated_word_count }}</span>
                            </div>
                            <div v-if="selectedItem.category" class="flex items-center gap-2">
                                <span class="text-gray-600">Categoria:</span>
                                <span class="text-gray-300">{{ selectedItem.category }}</span>
                            </div>
                        </div>

                        <!-- Article link -->
                        <div v-if="selectedItem.article_id" class="rounded-xl bg-blue-900/20 border border-blue-700/30 p-3">
                            <p class="text-xs text-blue-300 mb-1">Artigo gerado:</p>
                            <Link :href="route('blog.edit', selectedItem.article_id)" class="text-sm font-medium text-blue-400 hover:text-blue-300 transition">
                                {{ selectedItem.article_title }}
                            </Link>
                            <span v-if="selectedItem.article_status" class="ml-1.5 text-[10px] text-gray-500">({{ selectedItem.article_status }})</span>
                        </div>

                        <!-- Draft batch approval -->
                        <div v-if="selectedItem.batch_status === 'draft'" class="rounded-xl bg-amber-900/20 border border-amber-700/30 p-3">
                            <p class="text-xs text-amber-300 mb-2">Pauta aguardando aprovação</p>
                            <div class="flex gap-2">
                                <button @click="approveItem(selectedItem)" class="flex-1 rounded-lg bg-emerald-600/20 border border-emerald-500/30 px-3 py-1.5 text-xs text-emerald-400 hover:bg-emerald-600/30 transition">
                                    Aprovar
                                </button>
                                <button @click="deleteItem(selectedItem)" class="flex-1 rounded-lg bg-red-600/20 border border-red-500/30 px-3 py-1.5 text-xs text-red-400 hover:bg-red-600/30 transition">
                                    Remover
                                </button>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-col gap-2 pt-2 border-t border-gray-800">
                            <button v-if="selectedItem.status === 'pending' && selectedItem.batch_status !== 'draft'"
                                @click="generateArticle(selectedItem)" :disabled="generatingArticle"
                                class="w-full rounded-xl bg-indigo-600 py-2.5 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                                {{ generatingArticle ? 'Gerando artigo com IA...' : 'Gerar Artigo Completo com IA' }}
                            </button>
                            <Link v-if="selectedItem.article_id" :href="route('blog.edit', selectedItem.article_id)"
                                class="w-full rounded-xl bg-gray-800 border border-gray-700 py-2.5 text-xs font-medium text-gray-300 hover:text-white transition text-center">
                                Editar Artigo
                            </Link>
                            <button @click="deleteItem(selectedItem)"
                                class="w-full rounded-xl border border-red-500/30 py-2 text-xs text-red-400 hover:bg-red-500/10 transition">
                                Remover Pauta
                            </button>
                        </div>
                    </div>

                    <!-- No selection -->
                    <div v-else class="rounded-2xl bg-gray-900 border border-gray-800 border-dashed p-6 text-center">
                        <p class="text-sm text-gray-500">Clique em uma pauta no calendário para ver detalhes</p>
                        <p class="text-xs text-gray-600 mt-2">Ou gere pautas com IA clicando no botão acima</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Generate Modal -->
        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="showGenerateModal" class="fixed inset-0 z-[60] flex items-center justify-center">
                    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showGenerateModal = false" />
                    <div class="relative w-full max-w-lg rounded-2xl bg-gray-900 border border-gray-700 p-6 shadow-2xl mx-4 max-h-[90vh] overflow-y-auto">
                        <h3 class="text-lg font-semibold text-white mb-4">Gerar Calendário com IA</h3>

                        <div class="space-y-4">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm text-gray-400 mb-1 block">Data início</label>
                                    <input v-model="generateForm.start_date" type="date"
                                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                                <div>
                                    <label class="text-sm text-gray-400 mb-1 block">Data fim</label>
                                    <input v-model="generateForm.end_date" type="date"
                                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                            </div>

                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Artigos por semana: {{ generateForm.posts_per_week }}</label>
                                <input v-model.number="generateForm.posts_per_week" type="range" min="1" max="7" class="w-full accent-indigo-500" />
                                <div class="flex justify-between text-[10px] text-gray-600"><span>1</span><span>7</span></div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm text-gray-400 mb-1 block">Destino WordPress</label>
                                    <select v-model="generateForm.wordpress_connection_id"
                                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <option :value="null">Nenhum</option>
                                        <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.name }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-sm text-gray-400 mb-1 block">Categoria</label>
                                    <select v-model="generateForm.blog_category_id"
                                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <option :value="null">Nenhuma</option>
                                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Tom de voz</label>
                                <select v-model="generateForm.tone"
                                    class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Padrão da marca</option>
                                    <option value="profissional">Profissional</option>
                                    <option value="informal">Informal</option>
                                    <option value="educativo">Educativo</option>
                                    <option value="persuasivo">Persuasivo</option>
                                    <option value="técnico">Técnico</option>
                                </select>
                            </div>

                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Instruções adicionais</label>
                                <textarea v-model="generateForm.instructions" rows="3" placeholder="Ex: Focar em temas sazonais, incluir tendências do mercado..."
                                    class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>

                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Dimensões da Capa (px)</label>
                                <div class="flex items-center gap-2">
                                    <div class="flex-1">
                                        <input v-model.number="generateForm.cover_width" type="number" min="100" max="4000" placeholder="Largura"
                                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                        <p class="text-[10px] text-gray-600 mt-0.5 text-center">Largura</p>
                                    </div>
                                    <span class="text-gray-500 text-sm font-bold mt-[-14px]">x</span>
                                    <div class="flex-1">
                                        <input v-model.number="generateForm.cover_height" type="number" min="100" max="4000" placeholder="Altura"
                                            class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                        <p class="text-[10px] text-gray-600 mt-0.5 text-center">Altura</p>
                                    </div>
                                </div>
                                <p class="text-[10px] text-gray-600 mt-1">Padrão: 1750x650. A imagem é gerada via DALL-E 3 e redimensionada automaticamente.</p>
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 mt-6">
                            <button @click="showGenerateModal = false" type="button"
                                class="rounded-xl px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                            <button @click="submitGenerate" :disabled="generating || !generateForm.start_date || !generateForm.end_date"
                                class="rounded-xl bg-indigo-600 px-6 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                                {{ generating ? 'Gerando pautas...' : 'Gerar Calendário' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AuthenticatedLayout>
</template>
