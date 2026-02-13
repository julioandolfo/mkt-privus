<script setup>
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    starterTemplates: Array,
});

const form = useForm({
    name: '',
    body: '',
    category: 'marketing',
});

const selectedStarter = ref(null);

function useStarter(tpl) {
    selectedStarter.value = tpl.id;
    form.name = tpl.name;
    form.body = tpl.body;
    form.category = tpl.category;
}

function clearStarter() {
    selectedStarter.value = null;
    form.reset();
}

// Character/segment counter
const charCount = computed(() => form.body.length);
const isUnicode = computed(() => {
    const gsm7 = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜabcdefghijklmnopqrstuvwxyzäöñüà";
    for (let i = 0; i < form.body.length; i++) {
        if (!gsm7.includes(form.body[i]) && !/\s/.test(form.body[i])) return true;
    }
    return false;
});
const segments = computed(() => {
    const len = form.body.length;
    if (len === 0) return 0;
    if (isUnicode.value) return len <= 70 ? 1 : Math.ceil(len / 67);
    return len <= 160 ? 1 : Math.ceil(len / 153);
});
const charPerSegment = computed(() => isUnicode.value ? (segments.value <= 1 ? 70 : 67) : (segments.value <= 1 ? 160 : 153));

function submit() {
    form.post(route('sms.templates.store'));
}

const categoryLabels = { marketing: 'Marketing', transactional: 'Transacional', welcome: 'Boas-vindas', reminder: 'Lembrete' };
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-white">Novo Template SMS</h1>
                <Link :href="route('sms.templates.index')" class="text-sm text-gray-400 hover:text-white transition">Cancelar</Link>
            </div>
        </template>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Form -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Templates Prontos -->
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-white mb-3">Templates Prontos</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div
                            v-for="tpl in starterTemplates" :key="tpl.id"
                            @click="useStarter(tpl)"
                            :class="['p-3 rounded-lg border cursor-pointer transition', selectedStarter === tpl.id ? 'bg-indigo-600/10 border-indigo-600/50' : 'bg-gray-800 border-gray-700 hover:border-gray-600']"
                        >
                            <h4 class="text-sm font-medium text-white">{{ tpl.name }}</h4>
                            <p class="text-[10px] text-gray-500 mt-0.5">{{ categoryLabels[tpl.category] }}</p>
                            <p class="text-xs text-gray-400 mt-1 line-clamp-2">{{ tpl.body }}</p>
                        </div>
                    </div>
                    <button v-if="selectedStarter" type="button" @click="clearStarter" class="mt-3 text-xs text-gray-500 hover:text-white transition">Limpar seleção</button>
                </div>

                <!-- Editor -->
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-sm text-gray-400">Nome do Template *</label>
                                <input v-model="form.name" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" placeholder="Ex: Promoção Flash" />
                                <div v-if="form.errors.name" class="text-red-400 text-xs mt-1">{{ form.errors.name }}</div>
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
                        </div>

                        <div>
                            <label class="text-sm text-gray-400">Mensagem *</label>
                            <textarea v-model="form.body" rows="8" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm font-mono" placeholder="Olá {{first_name}}, aproveite..."></textarea>
                            <div v-if="form.errors.body" class="text-red-400 text-xs mt-1">{{ form.errors.body }}</div>

                            <!-- Segment Counter -->
                            <div class="flex items-center justify-between mt-2 text-xs">
                                <div class="flex items-center gap-4">
                                    <span :class="charCount > 1600 ? 'text-red-400' : 'text-gray-400'">{{ charCount }} caracteres</span>
                                    <span class="text-gray-500">|</span>
                                    <span :class="segments > 3 ? 'text-yellow-400' : 'text-gray-400'">{{ segments }} segmento(s)</span>
                                    <span class="text-gray-500">|</span>
                                    <span :class="isUnicode ? 'text-yellow-400' : 'text-green-400'">{{ isUnicode ? 'Unicode' : 'GSM-7' }}</span>
                                </div>
                                <span class="text-gray-500">Máx: {{ charPerSegment }} chars/seg</span>
                            </div>
                        </div>

                        <!-- Merge Tags -->
                        <div>
                            <p class="text-xs text-gray-500 mb-2">Merge Tags disponíveis:</p>
                            <div class="flex flex-wrap gap-1">
                                <button v-for="tag in ['{{first_name}}', '{{last_name}}', '{{phone}}', '{{email}}', '{{company}}', '{{date}}', '{{sms_optout}}']" :key="tag" type="button" @click="form.body += tag" class="px-2 py-1 text-[10px] bg-gray-800 text-gray-400 rounded border border-gray-700 hover:border-indigo-600/50 hover:text-indigo-400 transition font-mono">
                                    {{ tag }}
                                </button>
                            </div>
                        </div>

                        <div class="flex justify-end pt-4 border-t border-gray-800">
                            <button type="submit" :disabled="form.processing || !form.name || !form.body" class="px-6 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 disabled:opacity-50 transition">
                                {{ form.processing ? 'Salvando...' : 'Criar Template' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Preview -->
            <div class="space-y-6">
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-5 sticky top-6">
                    <h3 class="text-sm font-semibold text-white mb-3">Preview</h3>
                    <div class="max-w-xs mx-auto">
                        <!-- Phone mockup -->
                        <div class="bg-gray-800 rounded-3xl p-3 border-2 border-gray-700">
                            <div class="bg-gray-900 rounded-2xl p-4 min-h-[200px]">
                                <div class="text-center mb-3">
                                    <p class="text-xs text-gray-500">SMS</p>
                                </div>
                                <div v-if="form.body" class="bg-green-600 rounded-xl rounded-bl-sm px-3 py-2 text-white text-sm whitespace-pre-wrap">
                                    {{ form.body }}
                                </div>
                                <div v-else class="text-center py-8 text-gray-600 text-xs">
                                    Digite sua mensagem...
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 space-y-2 text-xs text-gray-500">
                        <div class="flex justify-between"><span>Caracteres</span><span class="text-white">{{ charCount }}</span></div>
                        <div class="flex justify-between"><span>Segmentos</span><span class="text-white">{{ segments }}</span></div>
                        <div class="flex justify-between"><span>Encoding</span><span :class="isUnicode ? 'text-yellow-400' : 'text-green-400'">{{ isUnicode ? 'Unicode' : 'GSM-7' }}</span></div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
