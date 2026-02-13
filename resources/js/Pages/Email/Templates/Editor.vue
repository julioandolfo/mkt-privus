<script setup>
import { ref, computed } from 'vue';
import { useForm, usePage, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GrapesEditor from '@/Components/Email/GrapesEditor.vue';

const page = usePage();
const flash = computed(() => page.props.flash || {});

const props = defineProps({
    template: Object,
    mode: String,
});

const form = useForm({
    name: props.template?.name || '',
    description: props.template?.description || '',
    subject: props.template?.subject || '',
    category: props.template?.category || 'marketing',
    html_content: props.template?.html_content || '',
    mjml_content: props.template?.mjml_content || '',
    json_content: props.template?.json_content || null,
    is_active: props.template?.is_active ?? true,
});

const saving = ref(false);
const savedMessage = ref('');

const categoryOptions = [
    { value: 'marketing', label: 'Marketing' },
    { value: 'newsletter', label: 'Newsletter' },
    { value: 'promotional', label: 'Promocional' },
    { value: 'transactional', label: 'Transacional' },
    { value: 'welcome', label: 'Boas-vindas' },
];

function handleSave() {
    saving.value = true;

    if (props.mode === 'edit' && props.template?.id) {
        form.put(route('email.templates.update', props.template.id), {
            preserveScroll: true,
            onSuccess: () => {
                savedMessage.value = 'Template salvo!';
                setTimeout(() => savedMessage.value = '', 3000);
            },
            onFinish: () => saving.value = false,
        });
    } else {
        form.post(route('email.templates.store'), {
            onFinish: () => saving.value = false,
        });
    }
}

function onEditorHtmlUpdate(html) {
    form.html_content = html;
}

function onEditorJsonUpdate(json) {
    form.json_content = json;
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4 flex-1">
                    <a :href="route('email.templates.index')" class="text-gray-400 hover:text-white transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                    </a>
                    <input v-model="form.name" placeholder="Nome do template..." class="bg-transparent text-xl font-bold text-white border-0 focus:ring-0 px-0 flex-1" />
                </div>
                <div class="flex items-center gap-3">
                    <input v-model="form.subject" placeholder="Assunto do email..." class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-sm text-white w-64" />
                    <select v-model="form.category" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-sm text-white">
                        <option v-for="c in categoryOptions" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </select>
                    <span v-if="savedMessage" class="text-xs text-green-400 animate-pulse">{{ savedMessage }}</span>
                    <button @click="handleSave" :disabled="saving" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition disabled:opacity-50">
                        {{ saving ? 'Salvando...' : 'Salvar' }}
                    </button>
                </div>
            </div>
        </template>

        <!-- Flash -->
        <div v-if="flash?.success" class="mb-4 px-4 py-3 rounded-lg bg-green-900/30 border border-green-700/50 text-green-300 text-sm">
            {{ flash.success }}
        </div>

        <!-- GrapesJS Editor -->
        <GrapesEditor
            :htmlContent="form.html_content"
            :jsonContent="form.json_content"
            @update:htmlContent="onEditorHtmlUpdate"
            @update:jsonContent="onEditorJsonUpdate"
            @save="handleSave"
        />
    </AuthenticatedLayout>
</template>
