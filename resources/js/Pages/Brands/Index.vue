<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const guideSteps = [
    { title: 'Crie uma marca', description: 'Clique em "Nova Marca" e preencha nome, segmento, tom de voz e cores. Quanto mais detalhes, melhor a IA gerará conteúdo.' },
    { title: 'Faça upload de assets', description: 'Na edição da marca, envie logotipos, ícones e imagens de referência. Eles serão usados na geração automática de conteúdo.' },
    { title: 'Defina palavras-chave', description: 'Adicione palavras-chave relevantes ao seu negócio. Elas orientam a IA na criação de legendas e hashtags.' },
    { title: 'Selecione a marca ativa', description: 'No menu superior, escolha qual marca está ativa. Todo conteúdo e métricas serão filtrados por ela.' },
];

const guideTips = [
    'Você pode ter múltiplas marcas no sistema, cada uma com identidade visual e tom de voz distintos.',
    'A marca ativa determina o contexto enviado à IA para geração de conteúdo personalizado.',
    'Marcas inativas não aparecem nos filtros, mas mantêm seus dados e histórico.',
];

interface Brand {
    id: number;
    name: string;
    slug: string;
    segment: string | null;
    primary_color: string;
    is_active: boolean;
    posts_count: number;
    social_accounts_count: number;
}

defineProps<{
    brands: Brand[];
}>();

function deleteBrand(brand: Brand) {
    if (confirm(`Tem certeza que deseja remover a marca "${brand.name}"?`)) {
        router.delete(route('brands.destroy', brand.id));
    }
}
</script>

<template>
    <Head title="Marcas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-white">Marcas</h1>
                <Link
                    :href="route('brands.create')"
                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Nova Marca
                </Link>
            </div>
        </template>

        <GuideBox
            title="Como gerenciar suas marcas"
            description="As marcas são o núcleo do sistema. Cada marca define a identidade visual, tom de voz e contexto que a IA usa para criar conteúdo."
            :steps="guideSteps"
            :tips="guideTips"
            color="indigo"
            storage-key="brands-guide"
            class="mb-6"
        />

        <div v-if="brands.length === 0" class="flex flex-col items-center justify-center rounded-2xl bg-gray-900 border border-gray-800 p-12 text-center">
            <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-600/20 mb-6">
                <svg class="w-8 h-8 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2" /><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" />
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-white mb-2">Nenhuma marca cadastrada</h3>
            <p class="text-gray-400 mb-6">Crie sua primeira marca para começar a usar a plataforma.</p>
            <Link
                :href="route('brands.create')"
                class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition"
            >
                Criar marca
            </Link>
        </div>

        <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="brand in brands"
                :key="brand.id"
                class="group rounded-2xl bg-gray-900 border border-gray-800 p-6 hover:border-gray-700 transition-all"
            >
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-xl text-lg font-bold text-white"
                            :style="{ backgroundColor: brand.primary_color }"
                        >
                            {{ brand.name.charAt(0).toUpperCase() }}
                        </div>
                        <div>
                            <h3 class="font-semibold text-white">{{ brand.name }}</h3>
                            <p class="text-sm text-gray-500">{{ brand.segment || 'Sem segmento' }}</p>
                        </div>
                    </div>
                    <span
                        :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', brand.is_active ? 'bg-emerald-500/10 text-emerald-400' : 'bg-gray-500/10 text-gray-400']"
                    >
                        {{ brand.is_active ? 'Ativa' : 'Inativa' }}
                    </span>
                </div>

                <div class="flex items-center gap-4 mb-4 text-sm text-gray-400">
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /></svg>
                        {{ brand.posts_count }} posts
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10" /></svg>
                        {{ brand.social_accounts_count }} redes
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="route('brands.edit', brand.id)"
                        class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-gray-800 px-4 py-2 text-sm font-medium text-gray-300 hover:bg-gray-700 hover:text-white transition"
                    >
                        Editar
                    </Link>
                    <button
                        @click="deleteBrand(brand)"
                        class="inline-flex items-center justify-center h-9 w-9 rounded-xl text-gray-500 hover:bg-red-500/10 hover:text-red-400 transition"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
