<script setup>
import { ref } from 'vue';
import { useForm, router, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    templates: Object,
    filters: Object,
    starterTemplates: Array,
});

const page = usePage();
const flash = page.props.flash || {};
const search = ref(props.filters?.search || '');

const showEditModal = ref(false);
const editingTemplate = ref(null);

const form = useForm({
    name: '',
    body: '',
    category: 'marketing',
    is_active: true,
});

function applySearch() {
    router.get(route('sms.templates.index'), { search: search.value || undefined }, { preserveState: true });
}

function openEdit(tpl) {
    editingTemplate.value = tpl;
    form.name = tpl.name;
    form.body = tpl.body;
    form.category = tpl.category;
    form.is_active = tpl.is_active;
    showEditModal.value = true;
}

function submitEdit() {
    form.put(route('sms.templates.update', editingTemplate.value.id), {
        onSuccess: () => { showEditModal.value = false; }
    });
}

function deleteTemplate(id) {
    if (confirm('Tem certeza que deseja remover este template?')) {
        router.delete(route('sms.templates.destroy', id));
    }
}

const categoryLabels = { marketing: 'Marketing', transactional: 'Transacional', welcome: 'Boas-vindas', reminder: 'Lembrete' };
const categoryColors = {
    marketing: 'bg-indigo-900/30 text-indigo-400',
    transactional: 'bg-gray-800 text-gray-400',
    welcome: 'bg-green-900/30 text-green-400',
    reminder: 'bg-yellow-900/30 text-yellow-400',
};

// Segment counter (inline)
function getSegments(body) {
    const len = body?.length || 0;
    if (len === 0) return 0;
    // Check if unicode
    const gsm7 = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜabcdefghijklmnopqrstuvwxyzäöñüà";
    let isUni = false;
    for (let i = 0; i < body.length; i++) {
        if (!gsm7.includes(body[i]) && !/\s/.test(body[i])) { isUni = true; break; }
    }
    if (isUni) return len <= 70 ? 1 : Math.ceil(len / 67);
    return len <= 160 ? 1 : Math.ceil(len / 153);
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-white">Templates SMS</h1>
                <Link :href="route('sms.templates.create')" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition">
                    + Novo Template
                </Link>
            </div>
        </template>

        <!-- Flash -->
        <div v-if="flash?.success" class="mb-4 px-4 py-3 rounded-lg bg-green-900/30 border border-green-700/50 text-green-300 text-sm">{{ flash.success }}</div>

        <!-- Search -->
        <div class="mb-6">
            <input v-model="search" @keyup.enter="applySearch" placeholder="Buscar templates..." class="w-full bg-gray-900 border border-gray-800 rounded-lg px-3 py-2 text-white text-sm placeholder-gray-500" />
        </div>

        <!-- Templates Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div v-for="tpl in templates.data" :key="tpl.id" class="bg-gray-900 rounded-xl border border-gray-800 p-5 flex flex-col">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-white font-semibold text-sm">{{ tpl.name }}</h3>
                    <span :class="['px-2 py-0.5 text-[10px] rounded-full', categoryColors[tpl.category]]">{{ categoryLabels[tpl.category] }}</span>
                </div>

                <div class="flex-1 mb-3">
                    <div class="bg-gray-800 rounded-lg p-3">
                        <p class="text-sm text-gray-300 line-clamp-4 whitespace-pre-wrap">{{ tpl.body }}</p>
                    </div>
                </div>

                <div class="flex items-center justify-between text-xs text-gray-500 mb-3">
                    <span>{{ tpl.char_count }} chars · {{ tpl.segments }} seg</span>
                    <span :class="tpl.is_active ? 'text-green-400' : 'text-red-400'">{{ tpl.is_active ? 'Ativo' : 'Inativo' }}</span>
                </div>

                <div class="flex items-center gap-2">
                    <button @click="openEdit(tpl)" class="flex-1 px-3 py-1.5 text-xs bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition text-center">Editar</button>
                    <button @click="deleteTemplate(tpl.id)" class="px-3 py-1.5 text-xs bg-red-900/30 text-red-400 rounded-lg hover:bg-red-900/50 transition">Remover</button>
                </div>
            </div>

            <!-- Empty -->
            <div v-if="!templates?.data?.length" class="col-span-full bg-gray-900 rounded-xl border border-gray-800 p-12 text-center">
                <p class="text-gray-500 mb-4">Nenhum template SMS criado.</p>
                <Link :href="route('sms.templates.create')" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">Criar Primeiro Template</Link>
            </div>
        </div>

        <!-- Paginação -->
        <div v-if="templates.last_page > 1" class="flex items-center justify-center gap-1 mt-6">
            <template v-for="link in templates.links" :key="link.label">
                <button v-if="link.url" @click="router.get(link.url, {}, { preserveState: true })" :class="['px-3 py-1 text-xs rounded', link.active ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-400 hover:bg-gray-700']" v-html="link.label"></button>
                <span v-else class="px-2 py-1 text-xs text-gray-600" v-html="link.label"></span>
            </template>
        </div>

        <!-- Edit Modal -->
        <Teleport to="body">
            <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="showEditModal = false">
                <div class="bg-gray-900 rounded-2xl border border-gray-800 w-full max-w-lg max-h-[90vh] overflow-y-auto p-6">
                    <h2 class="text-lg font-bold text-white mb-4">Editar Template</h2>
                    <form @submit.prevent="submitEdit" class="space-y-4">
                        <div>
                            <label class="text-sm text-gray-400">Nome</label>
                            <input v-model="form.name" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                        </div>
                        <div>
                            <label class="text-sm text-gray-400">Categoria</label>
                            <select v-model="form.category" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                                <option value="marketing">Marketing</option>
                                <option value="transactional">Transacional</option>
                                <option value="welcome">Boas-vindas</option>
                                <option value="reminder">Lembrete</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm text-gray-400">Mensagem</label>
                            <textarea v-model="form.body" rows="6" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm font-mono"></textarea>
                            <div class="flex items-center gap-4 mt-1 text-xs">
                                <span class="text-gray-400">{{ form.body.length }} chars</span>
                                <span class="text-gray-400">{{ getSegments(form.body) }} segmento(s)</span>
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-400">
                            <input type="checkbox" v-model="form.is_active" class="rounded bg-gray-800 border-gray-700 text-indigo-600" />
                            Template ativo
                        </label>
                        <div class="flex justify-end gap-3 pt-4 border-t border-gray-800">
                            <button type="button" @click="showEditModal = false" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                            <button type="submit" :disabled="form.processing" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 disabled:opacity-50 transition">
                                {{ form.processing ? 'Salvando...' : 'Salvar' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
