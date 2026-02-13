<script setup>
import { useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({ wcConnections: Array });

const form = useForm({
    name: '',
    description: '',
    tags: [],
});

const tagInput = '';

function submit() {
    form.post(route('email.lists.store'));
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-2xl font-bold text-white">Nova Lista de Contatos</h1>
        </template>

        <div class="max-w-2xl">
            <form @submit.prevent="submit" class="bg-gray-900 rounded-xl border border-gray-800 p-6 space-y-5">
                <div>
                    <label class="text-sm font-medium text-gray-300">Nome da Lista *</label>
                    <input v-model="form.name" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-white" placeholder="Ex: Clientes VIP, Newsletter, Leads" />
                    <p v-if="form.errors.name" class="text-xs text-red-400 mt-1">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-300">Descrição</label>
                    <textarea v-model="form.description" rows="3" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-white" placeholder="Descrição opcional da lista..."></textarea>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-gray-800">
                    <a :href="route('email.lists.index')" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</a>
                    <button type="submit" :disabled="form.processing" class="px-6 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition disabled:opacity-50">
                        {{ form.processing ? 'Criando...' : 'Criar Lista' }}
                    </button>
                </div>
            </form>

            <div class="mt-6 bg-gray-900/50 rounded-xl border border-gray-800/50 p-5">
                <h3 class="text-sm font-semibold text-gray-400 mb-2">Após criar a lista você poderá:</h3>
                <ul class="space-y-1.5 text-sm text-gray-500">
                    <li>Adicionar contatos manualmente</li>
                    <li>Importar contatos de CSV/XLSX</li>
                    <li>Conectar fontes externas: WooCommerce, MySQL, Google Sheets</li>
                    <li>Configurar sincronização automática</li>
                </ul>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
