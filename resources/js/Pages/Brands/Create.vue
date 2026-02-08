<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const form = useForm({
    name: '',
    description: '',
    website: '',
    segment: '',
    target_audience: '',
    tone_of_voice: 'profissional',
    primary_color: '#6366F1',
    secondary_color: '#8B5CF6',
    accent_color: '#F59E0B',
    keywords: [] as string[],
});

const keywordInput = ref('');

function addKeyword() {
    const keyword = keywordInput.value.trim();
    if (keyword && !form.keywords.includes(keyword)) {
        form.keywords.push(keyword);
        keywordInput.value = '';
    }
}

function removeKeyword(index: number) {
    form.keywords.splice(index, 1);
}

function submit() {
    form.post(route('brands.store'));
}

const brandCreateTips = [
    'Descrição detalhada: Quanto mais detalhes você fornecer sobre a marca, mais preciso será o conteúdo gerado pela IA.',
    'Tom de voz: Define como a IA se comunica. Ex: "Profissional" gera textos mais formais, "Descontraído" usa linguagem informal.',
    'Cores: São usadas para identificação visual nos dashboards e podem ser incorporadas em conteúdos gerados.',
    'Palavras-chave: Orientam a IA sobre os principais temas e termos relevantes para seu negócio.',
    'Público-alvo: Ajuda a IA a adaptar a linguagem e abordagem para atingir as pessoas certas.',
    'Após criar, vá em Editar para fazer upload de logotipos e referências visuais.',
];

const toneOptions = [
    { value: 'profissional', label: 'Profissional' },
    { value: 'informal', label: 'Informal' },
    { value: 'tecnico', label: 'Técnico' },
    { value: 'descontraido', label: 'Descontraído' },
    { value: 'inspirador', label: 'Inspirador' },
    { value: 'educativo', label: 'Educativo' },
    { value: 'autoritativo', label: 'Autoritativo' },
];
</script>

<template>
    <Head title="Nova Marca" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('brands.index')" class="text-gray-400 hover:text-white transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-xl font-semibold text-white">Nova Marca</h1>
            </div>
        </template>

        <div class="max-w-3xl">
            <GuideBox
                title="Dicas para configurar sua marca"
                color="purple"
                storage-key="brands-create-guide"
                class="mb-6"
                :tips="brandCreateTips"
            />

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Informacoes Basicas -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-6">Informações Básicas</h2>

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <InputLabel for="name" value="Nome da Marca" class="text-gray-300" />
                            <TextInput
                                id="name"
                                v-model="form.name"
                                type="text"
                                class="mt-1 block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl"
                                required
                                placeholder="Ex: Minha Empresa"
                            />
                            <InputError :message="form.errors.name" class="mt-2" />
                        </div>

                        <div class="sm:col-span-2">
                            <InputLabel for="description" value="Descrição" class="text-gray-300" />
                            <textarea
                                id="description"
                                v-model="form.description"
                                rows="3"
                                class="mt-1 block w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Breve descrição da marca..."
                            />
                            <InputError :message="form.errors.description" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="website" value="Website" class="text-gray-300" />
                            <TextInput
                                id="website"
                                v-model="form.website"
                                type="url"
                                class="mt-1 block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl"
                                placeholder="https://..."
                            />
                            <InputError :message="form.errors.website" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="segment" value="Segmento" class="text-gray-300" />
                            <TextInput
                                id="segment"
                                v-model="form.segment"
                                type="text"
                                class="mt-1 block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl"
                                placeholder="Ex: Tecnologia, Saúde, Moda..."
                            />
                            <InputError :message="form.errors.segment" class="mt-2" />
                        </div>
                    </div>
                </div>

                <!-- Identidade -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-6">Identidade e Tom de Voz</h2>

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <InputLabel for="target_audience" value="Público-alvo" class="text-gray-300" />
                            <textarea
                                id="target_audience"
                                v-model="form.target_audience"
                                rows="2"
                                class="mt-1 block w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Descreva o público-alvo da marca..."
                            />
                            <InputError :message="form.errors.target_audience" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="tone_of_voice" value="Tom de Voz" class="text-gray-300" />
                            <select
                                id="tone_of_voice"
                                v-model="form.tone_of_voice"
                                class="mt-1 block w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="tone in toneOptions" :key="tone.value" :value="tone.value">
                                    {{ tone.label }}
                                </option>
                            </select>
                            <InputError :message="form.errors.tone_of_voice" class="mt-2" />
                        </div>

                        <!-- Cores -->
                        <div class="sm:col-span-2 grid grid-cols-3 gap-4">
                            <div>
                                <InputLabel for="primary_color" value="Cor Primária" class="text-gray-300" />
                                <div class="mt-1 flex items-center gap-2">
                                    <input
                                        type="color"
                                        id="primary_color"
                                        v-model="form.primary_color"
                                        class="h-10 w-14 rounded-lg border border-gray-700 bg-gray-800 cursor-pointer"
                                    />
                                    <TextInput
                                        v-model="form.primary_color"
                                        class="block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl text-sm"
                                    />
                                </div>
                            </div>
                            <div>
                                <InputLabel for="secondary_color" value="Cor Secundária" class="text-gray-300" />
                                <div class="mt-1 flex items-center gap-2">
                                    <input
                                        type="color"
                                        id="secondary_color"
                                        v-model="form.secondary_color"
                                        class="h-10 w-14 rounded-lg border border-gray-700 bg-gray-800 cursor-pointer"
                                    />
                                    <TextInput
                                        v-model="form.secondary_color"
                                        class="block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl text-sm"
                                    />
                                </div>
                            </div>
                            <div>
                                <InputLabel for="accent_color" value="Cor de Destaque" class="text-gray-300" />
                                <div class="mt-1 flex items-center gap-2">
                                    <input
                                        type="color"
                                        id="accent_color"
                                        v-model="form.accent_color"
                                        class="h-10 w-14 rounded-lg border border-gray-700 bg-gray-800 cursor-pointer"
                                    />
                                    <TextInput
                                        v-model="form.accent_color"
                                        class="block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl text-sm"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Keywords -->
                        <div class="sm:col-span-2">
                            <InputLabel value="Palavras-chave" class="text-gray-300" />
                            <div class="mt-1 flex gap-2">
                                <TextInput
                                    v-model="keywordInput"
                                    @keydown.enter.prevent="addKeyword"
                                    class="block w-full bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500 rounded-xl"
                                    placeholder="Digite e pressione Enter..."
                                />
                                <button
                                    type="button"
                                    @click="addKeyword"
                                    class="rounded-xl bg-gray-800 border border-gray-700 px-4 text-gray-300 hover:bg-gray-700 transition"
                                >
                                    Adicionar
                                </button>
                            </div>
                            <div v-if="form.keywords.length" class="mt-3 flex flex-wrap gap-2">
                                <span
                                    v-for="(keyword, index) in form.keywords"
                                    :key="index"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600/20 px-3 py-1 text-sm text-indigo-300"
                                >
                                    {{ keyword }}
                                    <button type="button" @click="removeKeyword(index)" class="hover:text-red-400 transition">
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                                    </button>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Preview -->
                <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Preview</h2>
                    <div class="flex items-center gap-4 rounded-xl bg-gray-800 p-4">
                        <div
                            class="flex h-14 w-14 items-center justify-center rounded-xl text-xl font-bold text-white"
                            :style="{ backgroundColor: form.primary_color }"
                        >
                            {{ form.name ? form.name.charAt(0).toUpperCase() : 'M' }}
                        </div>
                        <div>
                            <p class="font-semibold text-white text-lg">{{ form.name || 'Nome da Marca' }}</p>
                            <p class="text-sm text-gray-400">{{ form.segment || 'Segmento' }} &middot; Tom: {{ form.tone_of_voice }}</p>
                        </div>
                        <div class="ml-auto flex gap-2">
                            <div class="h-8 w-8 rounded-lg" :style="{ backgroundColor: form.primary_color }" :title="'Primária: ' + form.primary_color" />
                            <div class="h-8 w-8 rounded-lg" :style="{ backgroundColor: form.secondary_color }" :title="'Secundária: ' + form.secondary_color" />
                            <div class="h-8 w-8 rounded-lg" :style="{ backgroundColor: form.accent_color }" :title="'Destaque: ' + form.accent_color" />
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-4">
                    <Link
                        :href="route('brands.index')"
                        class="rounded-xl px-6 py-2.5 text-sm font-medium text-gray-400 hover:text-white transition"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50"
                    >
                        {{ form.processing ? 'Criando...' : 'Criar Marca' }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
