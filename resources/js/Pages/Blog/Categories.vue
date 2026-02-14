<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    categories: { id: number; name: string; slug: string; description: string | null; articles_count: number; wp_category_id: number | null; wordpress_connection_id: number | null }[];
    connections: { id: number; name: string; platform: string; platform_label: string; site_url: string }[];
}>();

const showForm = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    description: '',
    wordpress_connection_id: null as number | null,
});

function openCreate() {
    editingId.value = null;
    form.reset();
    showForm.value = true;
}

function openEdit(cat: typeof props.categories[0]) {
    editingId.value = cat.id;
    form.name = cat.name;
    form.description = cat.description || '';
    form.wordpress_connection_id = cat.wordpress_connection_id;
    showForm.value = true;
}

function submitForm() {
    if (editingId.value) {
        form.put(route('blog.categories.update', editingId.value), {
            onSuccess: () => { showForm.value = false; form.reset(); editingId.value = null; },
        });
    } else {
        form.post(route('blog.categories.store'), {
            onSuccess: () => { showForm.value = false; form.reset(); },
        });
    }
}

function deleteCategory(id: number) {
    if (confirm('Excluir esta categoria?')) {
        router.delete(route('blog.categories.destroy', id));
    }
}
</script>

<template>
    <Head title="Blog - Categorias" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-white">Categorias do Blog</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Organize seus artigos por categorias</p>
                </div>
                <button @click="openCreate" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 transition">
                    + Nova Categoria
                </button>
            </div>
        </template>

        <!-- Form -->
        <div v-if="showForm" class="rounded-2xl bg-gray-900 border border-gray-800 p-5 mb-6">
            <h3 class="text-sm font-semibold text-white mb-3">{{ editingId ? 'Editar' : 'Nova' }} Categoria</h3>
            <form @submit.prevent="submitForm" class="space-y-3">
                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Nome *</label>
                    <input v-model="form.name" type="text" required
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Descrição</label>
                    <input v-model="form.description" type="text"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
                <div v-if="!editingId">
                    <label class="text-sm text-gray-400 mb-1 block">Vincular a site WordPress</label>
                    <select v-model="form.wordpress_connection_id"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option :value="null">Nenhum</option>
                        <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" :disabled="form.processing" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                        {{ form.processing ? 'Salvando...' : 'Salvar' }}
                    </button>
                    <button @click="showForm = false" type="button" class="rounded-xl px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                </div>
            </form>
        </div>

        <!-- List -->
        <div v-if="categories.length > 0" class="space-y-2">
            <div v-for="cat in categories" :key="cat.id"
                class="rounded-xl bg-gray-900 border border-gray-800 p-4 flex items-center justify-between hover:border-gray-700 transition">
                <div>
                    <p class="text-sm font-medium text-white">{{ cat.name }}</p>
                    <div class="flex items-center gap-2 mt-0.5 text-[10px] text-gray-500">
                        <span>{{ cat.articles_count }} artigo(s)</span>
                        <span v-if="cat.description">{{ cat.description }}</span>
                        <span v-if="cat.wp_category_id" class="text-indigo-400">WP #{{ cat.wp_category_id }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-1.5">
                    <button @click="openEdit(cat)" class="rounded-lg px-2.5 py-1 text-[11px] text-gray-400 hover:bg-gray-700/50 border border-gray-700 transition">Editar</button>
                    <button @click="deleteCategory(cat.id)" class="rounded-lg px-2.5 py-1 text-[11px] text-red-400 hover:bg-red-500/10 border border-red-500/30 transition">Excluir</button>
                </div>
            </div>
        </div>

        <div v-else class="rounded-2xl bg-gray-900 border border-gray-800 border-dashed p-10 text-center">
            <p class="text-gray-400">Nenhuma categoria criada ainda.</p>
            <button @click="openCreate" class="mt-3 text-sm text-indigo-400 hover:text-indigo-300 transition">Criar primeira categoria</button>
        </div>
    </AuthenticatedLayout>
</template>
