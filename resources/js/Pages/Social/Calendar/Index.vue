<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';

const page = usePage();
const currentBrand = computed(() => page.props.currentBrand);

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

const currentDate = ref(new Date());
const posts = ref<CalendarPost[]>([]);
const loading = ref(false);
const viewMode = ref<'month' | 'week'>('month');

const platformColors: Record<string, string> = {
    instagram: '#E4405F',
    facebook: '#1877F2',
    linkedin: '#0A66C2',
    tiktok: '#000000',
    youtube: '#FF0000',
    pinterest: '#BD081C',
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

// Calendar computations
const currentMonth = computed(() => currentDate.value.getMonth());
const currentYear = computed(() => currentDate.value.getFullYear());

const monthName = computed(() => {
    return currentDate.value.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
});

const weekDays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

const calendarDays = computed(() => {
    const year = currentYear.value;
    const month = currentMonth.value;

    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startDayOfWeek = firstDay.getDay();
    const daysInMonth = lastDay.getDate();

    const days: Array<{ date: number; month: number; year: number; isCurrentMonth: boolean; isToday: boolean; dateStr: string }> = [];

    // Previous month filler days
    const prevMonthLastDay = new Date(year, month, 0).getDate();
    for (let i = startDayOfWeek - 1; i >= 0; i--) {
        const d = prevMonthLastDay - i;
        const m = month === 0 ? 11 : month - 1;
        const y = month === 0 ? year - 1 : year;
        days.push({ date: d, month: m, year: y, isCurrentMonth: false, isToday: false, dateStr: formatDate(y, m, d) });
    }

    // Current month days
    const today = new Date();
    for (let d = 1; d <= daysInMonth; d++) {
        const isToday = d === today.getDate() && month === today.getMonth() && year === today.getFullYear();
        days.push({ date: d, month, year, isCurrentMonth: true, isToday, dateStr: formatDate(year, month, d) });
    }

    // Next month filler days
    const remaining = 42 - days.length;
    for (let d = 1; d <= remaining; d++) {
        const m = month === 11 ? 0 : month + 1;
        const y = month === 11 ? year + 1 : year;
        days.push({ date: d, month: m, year: y, isCurrentMonth: false, isToday: false, dateStr: formatDate(y, m, d) });
    }

    return days;
});

function formatDate(year: number, month: number, day: number): string {
    return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function getPostsForDate(dateStr: string): CalendarPost[] {
    return posts.value.filter(p => p.date === dateStr);
}

function prevMonth() {
    const d = new Date(currentDate.value);
    d.setMonth(d.getMonth() - 1);
    currentDate.value = d;
}

function nextMonth() {
    const d = new Date(currentDate.value);
    d.setMonth(d.getMonth() + 1);
    currentDate.value = d;
}

function goToToday() {
    currentDate.value = new Date();
}

async function fetchCalendarData() {
    if (!currentBrand.value) return;

    loading.value = true;
    try {
        const start = formatDate(currentYear.value, currentMonth.value, 1);
        const lastDay = new Date(currentYear.value, currentMonth.value + 1, 0).getDate();
        const end = formatDate(currentYear.value, currentMonth.value, lastDay);

        const response = await axios.get(route('social.calendar.data'), {
            params: { start, end },
        });

        posts.value = response.data.posts || [];
    } catch (e) {
        console.error('Erro ao carregar calendário:', e);
    } finally {
        loading.value = false;
    }
}

function getPlatformDots(platforms: string[]): Array<{ color: string }> {
    return platforms.slice(0, 3).map(p => ({
        color: platformColors[p] || '#6B7280',
    }));
}

const calendarGuideSteps = [
    { title: 'Visualize agendamentos', description: 'O calendario mostra todos os posts agendados da marca ativa com cores por plataforma e status.' },
    { title: 'Navegue entre meses', description: 'Use as setas para avancar/retroceder e o botao "Hoje" para voltar ao mes atual.' },
    { title: 'Clique para detalhes', description: 'Clique em um post no calendario para ver detalhes e edita-lo.' },
    { title: 'Planeje sua estrategia', description: 'Identifique lacunas na programacao e crie novos posts para manter uma presenca consistente.' },
];

const calendarGuideTips = [
    'Cores dos indicadores representam as plataformas: rosa (Instagram), azul (Facebook), azul escuro (LinkedIn), preto (TikTok), vermelho (YouTube).',
    'O status do post e indicado pelo formato do badge: agendado, publicando ou publicado.',
    'Para criar um post ja agendado, clique em "+ Novo Post" e defina data/hora no formulario.',
];

watch(currentDate, fetchCalendarData);
onMounted(fetchCalendarData);
</script>

<template>
    <Head title="Social - Calendário" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-white">Calendário de Posts</h1>
                <div class="flex items-center gap-3">
                    <Link :href="route('social.posts.index')" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        Lista de Posts
                    </Link>
                    <Link :href="route('social.posts.create')" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                        + Novo Post
                    </Link>
                </div>
            </div>
        </template>

        <GuideBox
            title="Como usar o Calendário de Publicações"
            color="blue"
            storage-key="calendar-guide"
            class="mb-6"
            :steps="calendarGuideSteps"
            :tips="calendarGuideTips"
        />

        <div v-if="!currentBrand" class="rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
            <h3 class="text-lg font-medium text-gray-300">Nenhuma marca selecionada</h3>
            <p class="mt-2 text-sm text-gray-500">Selecione uma marca para ver o calendário.</p>
        </div>

        <template v-else>
            <!-- Calendar header -->
            <div class="rounded-2xl bg-gray-900 border border-gray-800 overflow-hidden">
                <!-- Navigation -->
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

                    <button @click="goToToday" class="rounded-xl px-4 py-1.5 text-sm text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition">
                        Hoje
                    </button>
                </div>

                <!-- Week day headers -->
                <div class="grid grid-cols-7 border-b border-gray-800">
                    <div v-for="day in weekDays" :key="day" class="py-3 text-center text-xs font-medium text-gray-500 uppercase">
                        {{ day }}
                    </div>
                </div>

                <!-- Calendar grid -->
                <div class="grid grid-cols-7" :class="loading ? 'opacity-50' : ''">
                    <div
                        v-for="(day, index) in calendarDays"
                        :key="index"
                        :class="[
                            'min-h-[100px] border-b border-r border-gray-800 p-2 transition-colors',
                            day.isCurrentMonth ? 'bg-gray-900' : 'bg-gray-950',
                            day.isToday ? 'bg-indigo-950/30' : '',
                            (index + 1) % 7 === 0 ? 'border-r-0' : '',
                        ]"
                    >
                        <!-- Day number -->
                        <div class="flex items-center justify-between mb-1">
                            <span :class="[
                                'text-sm font-medium',
                                day.isToday ? 'flex items-center justify-center w-7 h-7 rounded-full bg-indigo-600 text-white' : '',
                                day.isCurrentMonth ? (day.isToday ? '' : 'text-gray-300') : 'text-gray-600',
                            ]">
                                {{ day.date }}
                            </span>
                        </div>

                        <!-- Posts for this day -->
                        <div class="space-y-1">
                            <Link
                                v-for="post in getPostsForDate(day.dateStr).slice(0, 3)"
                                :key="post.id"
                                :href="route('social.posts.edit', post.id)"
                                class="flex items-center gap-1.5 rounded-md px-1.5 py-0.5 text-[11px] truncate hover:bg-gray-800 transition group"
                            >
                                <span
                                    class="w-1.5 h-1.5 rounded-full shrink-0"
                                    :style="{ backgroundColor: statusDotColors[post.status_color] || '#6B7280' }"
                                />
                                <span class="truncate text-gray-400 group-hover:text-gray-200">
                                    {{ post.time }} {{ post.title }}
                                </span>
                                <!-- Platform dots -->
                                <span class="flex items-center gap-0.5 ml-auto shrink-0">
                                    <span
                                        v-for="(dot, di) in getPlatformDots(post.platforms)"
                                        :key="di"
                                        class="w-1.5 h-1.5 rounded-full"
                                        :style="{ backgroundColor: dot.color }"
                                    />
                                </span>
                            </Link>
                            <span
                                v-if="getPostsForDate(day.dateStr).length > 3"
                                class="text-[10px] text-gray-500 px-1.5"
                            >
                                +{{ getPostsForDate(day.dateStr).length - 3 }} mais
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Legend -->
            <div class="mt-4 flex flex-wrap items-center gap-4 text-xs text-gray-500">
                <span class="font-medium text-gray-400">Legenda:</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-gray-500" /> Rascunho</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-indigo-500" /> Agendado</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-green-500" /> Publicado</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-red-500" /> Falhou</span>
                <span class="ml-4 font-medium text-gray-400">Plataformas:</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" style="background-color: #E4405F" /> Instagram</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" style="background-color: #1877F2" /> Facebook</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" style="background-color: #0A66C2" /> LinkedIn</span>
            </div>
        </template>
    </AuthenticatedLayout>
</template>
