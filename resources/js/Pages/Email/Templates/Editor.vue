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
    starterTemplates: { type: Array, default: () => [] },
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
const showStarterPicker = ref(false);

// Mostrar picker automaticamente se mode=create e nenhum conteudo
const showEditor = ref(!!(props.template?.html_content || props.template?.json_content));

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

function selectStarter(starter) {
    form.name = starter.name;
    form.html_content = starter.html_content;
    form.subject = starter.subject || '';
    form.category = starter.category || 'marketing';
    form.description = starter.description || '';
    showStarterPicker.value = false;
    showEditor.value = true;
}

function startFromScratch() {
    showStarterPicker.value = false;
    showEditor.value = true;
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

        <!-- Starter Picker (para novos templates sem conteudo) -->
        <div v-if="mode === 'create' && !showEditor" class="max-w-4xl mx-auto py-8">
            <div class="text-center mb-8">
                <h2 class="text-2xl font-bold text-white mb-2">Como deseja começar?</h2>
                <p class="text-gray-400">Escolha um template pronto para personalizar ou comece do zero.</p>
            </div>

            <div class="grid gap-4 grid-cols-2 lg:grid-cols-3 mb-6">
                <!-- Card: Do Zero -->
                <button @click="startFromScratch"
                    class="group rounded-xl border-2 border-dashed border-gray-700 bg-gray-900 p-6 text-center transition hover:border-indigo-500/50 hover:bg-gray-800/50">
                    <div class="w-14 h-14 rounded-xl bg-gray-800 flex items-center justify-center mx-auto mb-3 group-hover:bg-indigo-600/20">
                        <svg class="w-7 h-7 text-gray-400 group-hover:text-indigo-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    </div>
                    <p class="text-sm font-medium text-white">Começar do Zero</p>
                    <p class="text-xs text-gray-500 mt-1">Editor visual com blocos</p>
                </button>

                <!-- Starter templates -->
                <button v-for="s in starterTemplates" :key="s.id" @click="selectStarter(s)"
                    class="group rounded-xl border border-gray-700 bg-gray-900 overflow-hidden text-left transition hover:border-indigo-500/50 hover:bg-gray-800/50">
                    <!-- Mini preview -->
                    <div class="relative w-full h-28 overflow-hidden bg-gray-800">
                        <div :style="{ background: s.preview_color || '#6366f1' }" class="absolute inset-0 opacity-10"></div>
                        <div class="absolute inset-0 flex flex-col items-center justify-center p-3">
                            <div v-if="s.category === 'newsletter'" class="space-y-1 w-full max-w-[70px]">
                                <div class="h-2.5 rounded-sm" :style="{ background: s.preview_color }"></div>
                                <div class="h-5 rounded-sm bg-gray-600"></div>
                                <div class="flex gap-1"><div class="h-3 flex-1 rounded-sm bg-gray-600"></div><div class="h-3 flex-1 rounded-sm bg-gray-600"></div></div>
                                <div class="h-1.5 rounded-sm bg-gray-600 w-3/4"></div>
                            </div>
                            <div v-else-if="s.category === 'promotional'" class="space-y-1 w-full max-w-[70px]">
                                <div class="h-7 rounded-sm flex items-center justify-center" :style="{ background: s.preview_color }">
                                    <span class="text-white text-[7px] font-bold">50% OFF</span>
                                </div>
                                <div class="flex gap-1"><div class="h-4 flex-1 rounded-sm bg-gray-600"></div><div class="h-4 flex-1 rounded-sm bg-gray-600"></div><div class="h-4 flex-1 rounded-sm bg-gray-600"></div></div>
                            </div>
                            <div v-else-if="s.category === 'welcome'" class="space-y-1 w-full max-w-[70px]">
                                <div class="h-2.5 rounded-sm" :style="{ background: s.preview_color }"></div>
                                <div class="text-center text-base">👋</div>
                                <div class="space-y-0.5"><div class="h-1 rounded-sm bg-gray-600 w-full"></div><div class="h-1 rounded-sm bg-gray-600 w-full"></div><div class="h-1 rounded-sm bg-gray-600 w-full"></div></div>
                                <div class="h-2.5 rounded-full mx-3" :style="{ background: s.preview_color, opacity: 0.6 }"></div>
                            </div>
                            <div v-else class="space-y-1 w-full max-w-[70px]">
                                <div class="h-2.5 rounded-sm" :style="{ background: s.preview_color }"></div>
                                <div class="h-7 rounded-sm bg-gray-600"></div>
                                <div class="h-1.5 rounded-sm bg-gray-600"></div>
                                <div class="h-2.5 rounded-full mx-5" :style="{ background: s.preview_color, opacity: 0.6 }"></div>
                            </div>
                        </div>
                    </div>
                    <div class="p-3">
                        <p class="text-sm font-medium text-white">{{ s.name }}</p>
                        <p class="text-xs text-gray-500 mt-0.5 line-clamp-1">{{ s.description }}</p>
                    </div>
                </button>
            </div>
        </div>

        <!-- GrapesJS Editor -->
        <GrapesEditor v-if="showEditor"
            :htmlContent="form.html_content"
            :jsonContent="form.json_content"
            @update:htmlContent="onEditorHtmlUpdate"
            @update:jsonContent="onEditorJsonUpdate"
            @save="handleSave"
        />
    </AuthenticatedLayout>
</template>
