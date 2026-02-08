<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link } from '@inertiajs/vue3';

interface Stats {
    posts_this_month: number;
    scheduled_posts: number;
    published_posts: number;
    connected_platforms: number;
}

const props = defineProps<{
    stats: Stats;
}>();

const statCards = [
    {
        title: 'Posts este mês',
        value: props.stats.posts_this_month,
        icon: 'edit',
        color: 'from-indigo-500 to-indigo-600',
        textColor: 'text-indigo-400',
    },
    {
        title: 'Agendados',
        value: props.stats.scheduled_posts,
        icon: 'clock',
        color: 'from-amber-500 to-amber-600',
        textColor: 'text-amber-400',
    },
    {
        title: 'Publicados',
        value: props.stats.published_posts,
        icon: 'check-circle',
        color: 'from-emerald-500 to-emerald-600',
        textColor: 'text-emerald-400',
    },
    {
        title: 'Plataformas',
        value: props.stats.connected_platforms,
        icon: 'globe',
        color: 'from-violet-500 to-violet-600',
        textColor: 'text-violet-400',
    },
];

const quickActions = [
    { name: 'Criar Post', description: 'Gere conteúdo com IA', href: 'social.posts.create', icon: 'plus-circle', color: 'bg-indigo-600 hover:bg-indigo-700' },
    { name: 'Chat IA', description: 'Converse com múltiplos modelos', href: 'chat.index', icon: 'message', color: 'bg-violet-600 hover:bg-violet-700' },
    { name: 'Content Engine', description: 'Geração automática de conteúdo', href: 'social.content-engine.index', icon: 'file-plus', color: 'bg-emerald-600 hover:bg-emerald-700' },
    { name: 'Ver Métricas', description: 'Acompanhe seus resultados', href: 'metrics.index', icon: 'trending', color: 'bg-amber-600 hover:bg-amber-700' },
];

const guideSteps = [
    { title: 'Configure sua marca', description: 'Vá em Marcas e cadastre nome, segmento, tom de voz, cores e palavras-chave. Faça upload de logotipos e referências visuais.' },
    { title: 'Conecte suas redes', description: 'Em Social > Contas, conecte Instagram, Facebook, LinkedIn e outras redes sociais da marca.' },
    { title: 'Crie conteúdo com IA', description: 'Use Social > Posts para criar posts manualmente, ou Social > Content Engine para gerar automaticamente.' },
    { title: 'Agende e publique', description: 'Agende posts no calendário e o Autopilot publicará automaticamente nos horários definidos.' },
    { title: 'Acompanhe resultados', description: 'Use Métricas para criar indicadores customizados e acompanhar a evolução ao longo do tempo.' },
];
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-white">Dashboard</h1>
        </template>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-8">
            <div
                v-for="stat in statCards"
                :key="stat.title"
                class="rounded-2xl bg-gray-900 border border-gray-800 p-6 hover:border-gray-700 transition-colors"
            >
                <div class="flex items-center justify-between mb-4">
                    <span class="text-sm font-medium text-gray-400">{{ stat.title }}</span>
                    <div :class="['flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br', stat.color]">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <template v-if="stat.icon === 'edit'">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                            </template>
                            <template v-else-if="stat.icon === 'clock'">
                                <circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />
                            </template>
                            <template v-else-if="stat.icon === 'check-circle'">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" />
                            </template>
                            <template v-else-if="stat.icon === 'globe'">
                                <circle cx="12" cy="12" r="10" /><line x1="2" y1="12" x2="22" y2="12" /><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
                            </template>
                        </svg>
                    </div>
                </div>
                <div :class="['text-3xl font-bold', stat.textColor]">
                    {{ stat.value }}
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="mb-8">
            <h2 class="text-lg font-semibold text-white mb-4">Ações Rápidas</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Link
                    v-for="action in quickActions"
                    :key="action.name"
                    :href="route(action.href)"
                    class="group flex items-center gap-4 rounded-2xl bg-gray-900 border border-gray-800 p-5 hover:border-gray-700 transition-all hover:shadow-lg hover:shadow-indigo-500/5"
                >
                    <div :class="['flex h-12 w-12 items-center justify-center rounded-xl text-white transition-transform group-hover:scale-110', action.color]">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <template v-if="action.icon === 'plus-circle'">
                                <circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="16" /><line x1="8" y1="12" x2="16" y2="12" />
                            </template>
                            <template v-else-if="action.icon === 'message'">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                            </template>
                            <template v-else-if="action.icon === 'file-plus'">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="12" y1="18" x2="12" y2="12" /><line x1="9" y1="15" x2="15" y2="15" />
                            </template>
                            <template v-else-if="action.icon === 'trending'">
                                <polyline points="23 6 13.5 15.5 8.5 10.5 1 18" /><polyline points="17 6 23 6 23 12" />
                            </template>
                        </svg>
                    </div>
                    <div>
                        <p class="font-semibold text-white group-hover:text-indigo-400 transition-colors">{{ action.name }}</p>
                        <p class="text-sm text-gray-500">{{ action.description }}</p>
                    </div>
                </Link>
            </div>
        </div>

        <!-- Empty state when no brand -->
        <div v-if="!$page.props.currentBrand" class="flex flex-col items-center justify-center rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
            <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-600/20 mb-6">
                <svg class="w-8 h-8 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2" /><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" />
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-white mb-2">Nenhuma marca cadastrada</h3>
            <p class="text-gray-400 mb-6 max-w-md">
                Comece criando sua primeira marca para desbloquear todas as funcionalidades da plataforma.
            </p>
            <Link
                :href="route('brands.create')"
                class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                Criar primeira marca
            </Link>
        </div>

        <!-- Recent activity / Guide -->
        <template v-else>
            <!-- Guia de inicio -->
            <GuideBox
                title="Primeiros passos no MKT Privus"
                description="Esta plataforma centraliza a criação, agendamento e análise de conteúdo para todas as suas marcas. Siga os passos abaixo para começar:"
                :steps="guideSteps"
                color="indigo"
                storage-key="dashboard-guide"
                class="mb-6"
            />

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Ultimos Posts -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Últimos Posts</h3>
                    <div class="flex flex-col items-center justify-center py-8 text-center">
                        <p class="text-gray-500 text-sm">Nenhum post criado ainda.</p>
                        <Link :href="route('social.posts.create')" class="mt-3 text-sm text-indigo-400 hover:text-indigo-300">
                            Criar primeiro post
                        </Link>
                    </div>
                </div>

                <!-- Proximos Agendamentos -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Próximos Agendamentos</h3>
                    <div class="flex flex-col items-center justify-center py-8 text-center">
                        <p class="text-gray-500 text-sm">Nenhum agendamento pendente.</p>
                        <Link :href="route('social.calendar.index')" class="mt-3 text-sm text-indigo-400 hover:text-indigo-300">
                            Agendar conteúdo
                        </Link>
                    </div>
                </div>
            </div>
        </template>
    </AuthenticatedLayout>
</template>
