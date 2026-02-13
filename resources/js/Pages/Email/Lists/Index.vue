<script setup>
import { router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({ lists: Array });
const flash = usePage().props.flash || {};

const sourceLabels = { csv: 'CSV', woocommerce: 'WooCommerce', mysql: 'MySQL', google_sheets: 'Google Sheets' };
const syncStatusColors = { success: 'text-green-400', error: 'text-red-400', syncing: 'text-amber-400', pending: 'text-gray-500' };

function deleteList(id) {
    if (confirm('Remover esta lista e desvincular todos os contatos?')) {
        router.delete(route('email.lists.destroy', id));
    }
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-white">Listas de Contatos</h1>
                <a :href="route('email.lists.create')" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition">
                    + Nova Lista
                </a>
            </div>
        </template>

        <div v-if="flash?.success" class="mb-6 px-4 py-3 rounded-lg bg-green-900/30 border border-green-700/50 text-green-300 text-sm">
            {{ flash.success }}
        </div>

        <div v-if="!lists?.length" class="bg-gray-900 rounded-xl border border-gray-800 p-12 text-center">
            <svg class="w-16 h-16 text-gray-700 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <p class="text-gray-400 mb-4">Nenhuma lista criada ainda.</p>
            <a :href="route('email.lists.create')" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">Criar Primeira Lista</a>
        </div>

        <div v-else class="grid gap-4">
            <div v-for="list in lists" :key="list.id"
                 class="bg-gray-900 rounded-xl border border-gray-800 p-5 hover:border-gray-700 transition cursor-pointer"
                 @click="router.visit(route('email.lists.show', list.id))">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-white font-semibold">{{ list.name }}</h3>
                            <span v-if="!list.is_active" class="px-2 py-0.5 text-xs rounded-full bg-red-900/30 text-red-400">Inativa</span>
                        </div>
                        <p v-if="list.description" class="text-sm text-gray-500 mt-0.5">{{ list.description }}</p>
                    </div>
                    <div class="flex items-center gap-6 text-right">
                        <div>
                            <p class="text-xl font-bold text-white">{{ list.contacts_count?.toLocaleString('pt-BR') }}</p>
                            <p class="text-xs text-gray-500">contatos</p>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-green-400">{{ list.active_contacts?.toLocaleString('pt-BR') }}</p>
                            <p class="text-xs text-gray-500">ativos</p>
                        </div>
                    </div>
                </div>

                <!-- Fontes -->
                <div v-if="list.sources?.length" class="mt-3 pt-3 border-t border-gray-800 flex flex-wrap gap-2">
                    <span v-for="s in list.sources" :key="s.id" class="flex items-center gap-1.5 px-2 py-1 rounded-lg bg-gray-800 text-xs">
                        <span class="font-medium text-gray-300">{{ sourceLabels[s.type] || s.type }}</span>
                        <span :class="syncStatusColors[s.sync_status] || 'text-gray-500'">
                            {{ s.sync_status === 'success' ? s.records_synced + ' sync' : s.sync_status }}
                        </span>
                    </span>
                </div>

                <!-- Tags -->
                <div v-if="list.tags?.length" class="mt-2 flex flex-wrap gap-1.5">
                    <span v-for="tag in list.tags" :key="tag" class="px-2 py-0.5 text-xs rounded bg-indigo-900/30 text-indigo-400">{{ tag }}</span>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
