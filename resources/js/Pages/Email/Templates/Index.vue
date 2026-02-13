<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({ templates: Array });
const flash = usePage().props.flash || {};

const categoryBadgeColors = {
    marketing: 'bg-indigo-900/40 text-indigo-400 border-indigo-500/30',
    newsletter: 'bg-emerald-900/40 text-emerald-400 border-emerald-500/30',
    promotional: 'bg-amber-900/40 text-amber-400 border-amber-500/30',
    transactional: 'bg-gray-800 text-gray-400 border-gray-600',
    welcome: 'bg-blue-900/40 text-blue-400 border-blue-500/30',
};

const categoryLabels = {
    marketing: 'Marketing',
    newsletter: 'Newsletter',
    promotional: 'Promocional',
    transactional: 'Transacional',
    welcome: 'Boas-vindas',
};

function getCategoryBadge(category) {
    const c = category || 'marketing';
    return categoryBadgeColors[c] || categoryBadgeColors.marketing;
}

function duplicateTemplate(t) {
    router.post(route('email.templates.duplicate', t.id));
}

function deleteTemplate(t) {
    if (confirm('Tem certeza que deseja excluir este template?')) {
        router.delete(route('email.templates.destroy', t.id));
    }
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-white">Templates de Email</h1>
                <Link
                    :href="route('email.templates.create')"
                    class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition"
                >
                    + Novo Template
                </Link>
            </div>
        </template>

        <div v-if="flash?.success" class="mb-6 px-4 py-3 rounded-xl bg-green-900/30 border border-green-700/50 text-green-300 text-sm">
            {{ flash.success }}
        </div>

        <div v-if="!templates?.length" class="bg-gray-900 rounded-xl border border-gray-800 p-12 text-center">
            <svg class="w-16 h-16 text-gray-700 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            <p class="text-gray-400 mb-4">Nenhum template criado ainda.</p>
            <Link
                :href="route('email.templates.create')"
                class="inline-flex px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition"
            >
                Criar Primeiro Template
            </Link>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <div
                v-for="t in templates"
                :key="t.id"
                class="bg-gray-900 rounded-xl border border-gray-800 p-5 hover:border-gray-700 transition flex flex-col"
            >
                <div class="flex items-start justify-between gap-3 mb-2">
                    <h3 class="text-white font-semibold text-sm truncate flex-1">{{ t.name }}</h3>
                    <span
                        class="px-2 py-0.5 text-xs rounded-lg border shrink-0"
                        :class="getCategoryBadge(t.category)"
                    >
                        {{ categoryLabels[t.category] || t.category || 'Marketing' }}
                    </span>
                </div>
                <p v-if="t.description" class="text-sm text-gray-500 line-clamp-2 mb-3">{{ t.description }}</p>
                <div class="text-xs text-gray-500 mt-auto pt-3 border-t border-gray-800">
                    <span>Criado: {{ t.created_at }}</span>
                    <span v-if="t.updated_at !== t.created_at" class="ml-2">· Atualizado: {{ t.updated_at }}</span>
                </div>
                <div class="flex items-center gap-2 mt-3">
                    <Link
                        :href="route('email.templates.edit', t.id)"
                        class="px-3 py-1.5 text-xs bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition"
                    >
                        Editar
                    </Link>
                    <button
                        @click="duplicateTemplate(t)"
                        class="px-3 py-1.5 text-xs bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition"
                    >
                        Duplicar
                    </button>
                    <button
                        @click="deleteTemplate(t)"
                        class="px-3 py-1.5 text-xs bg-red-900/30 text-red-400 rounded-lg hover:bg-red-900/50 transition"
                    >
                        Excluir
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
