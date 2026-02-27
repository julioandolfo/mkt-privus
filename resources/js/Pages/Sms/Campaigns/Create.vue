<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    providers: Array,
    templates: Array,
    lists: Array,
});

const page = usePage();
const flash = page.props.flash || {};

const form = useForm({
    name: '',
    email_provider_id: props.providers?.[0]?.id || '',
    sms_template_id: null,
    body: '',
    sender_name: '',
    scheduled_at: '',
    list_ids: [],
    exclude_list_ids: [],
    settings: {
        skip_optout: false,
        optout_text: 'Resp. SAIR p/ cancelar',
    },
    tags: [],
});

const step = ref(1);
const showTemplateSelect = ref(false);

// Contagem de caracteres e segmentos
const charCount = computed(() => form.body.length);
const isUnicode = computed(() => {
    const gsm7 = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜabcdefghijklmnopqrstuvwxyzäöñüà";
    for (let i = 0; i < form.body.length; i++) {
        const ch = form.body[i];
        if (!gsm7.includes(ch) && !/\s/.test(ch)) return true;
    }
    return false;
});

const segments = computed(() => {
    const len = form.body.length;
    if (len === 0) return 0;
    if (isUnicode.value) {
        return len <= 70 ? 1 : Math.ceil(len / 67);
    }
    return len <= 160 ? 1 : Math.ceil(len / 153);
});

const maxChars = computed(() => {
    if (isUnicode.value) return segments.value <= 1 ? 70 : 67 * 10;
    return segments.value <= 1 ? 160 : 153 * 10;
});

const charPerSegment = computed(() => isUnicode.value ? (segments.value <= 1 ? 70 : 67) : (segments.value <= 1 ? 160 : 153));

// Estimar custo
const estimatedCost = computed(() => {
    const recipients = selectedListsContactCount.value;
    if (!recipients || !segments.value) return null;
    const pricePerSeg = 0.085;
    const total = recipients * segments.value * pricePerSeg;
    return 'R$ ' + total.toFixed(2).replace('.', ',');
});

const selectedListsContactCount = computed(() => {
    return form.list_ids.reduce((sum, id) => {
        const list = props.lists.find(l => l.id === id);
        return sum + (list?.contacts_count || 0);
    }, 0);
});

// Opt-out preview
const bodyPreview = computed(() => {
    let text = form.body;
    if (!form.settings.skip_optout) {
        if (text.includes('{{sms_optout}}')) {
            text = text.replace('{{sms_optout}}', form.settings.optout_text);
        } else {
            text = text.trim() + '\n' + form.settings.optout_text;
        }
    }
    return text;
});

function selectTemplate(tpl) {
    form.sms_template_id = tpl.id;
    form.body = tpl.body;
    showTemplateSelect.value = false;
}

function nextStep() {
    if (step.value < 3) step.value++;
}
function prevStep() {
    if (step.value > 1) step.value--;
}

function toLocalISO(datetimeLocal) {
    if (!datetimeLocal) return '';
    const d = new Date(datetimeLocal);
    const pad = (n) => String(n).padStart(2, '0');
    const offset = -d.getTimezoneOffset();
    const sign = offset >= 0 ? '+' : '-';
    const hh = pad(Math.floor(Math.abs(offset) / 60));
    const mm = pad(Math.abs(offset) % 60);
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:00${sign}${hh}:${mm}`;
}

function submit() {
    if (form.scheduled_at) {
        form.transform((data) => ({
            ...data,
            scheduled_at: toLocalISO(data.scheduled_at),
        }));
    }
    form.post(route('sms.campaigns.store'));
}

const tagInput = ref('');
function addTag() {
    const tag = tagInput.value.trim();
    if (tag && !form.tags.includes(tag)) {
        form.tags.push(tag);
    }
    tagInput.value = '';
}
function removeTag(i) {
    form.tags.splice(i, 1);
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">Nova Campanha SMS</h1>
                    <p class="text-sm text-gray-400 mt-1">Passo {{ step }} de 3</p>
                </div>
                <Link :href="route('sms.campaigns.index')" class="text-sm text-gray-400 hover:text-white transition">Cancelar</Link>
            </div>
        </template>

        <!-- Flash -->
        <div v-if="flash?.error" class="mb-4 px-4 py-3 rounded-lg bg-red-900/30 border border-red-700/50 text-red-300 text-sm">{{ flash.error }}</div>

        <!-- Steps indicator -->
        <div class="flex items-center gap-2 mb-6">
            <div v-for="s in 3" :key="s" :class="['flex items-center gap-2', s <= step ? 'text-indigo-400' : 'text-gray-600']">
                <div :class="['w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold border-2', s === step ? 'border-indigo-500 bg-indigo-600/20 text-white' : s < step ? 'border-green-600 bg-green-600/20 text-green-400' : 'border-gray-700 text-gray-600']">
                    {{ s < step ? '✓' : s }}
                </div>
                <span class="text-xs">{{ ['Config', 'Conteúdo', 'Revisar'][s-1] }}</span>
                <div v-if="s < 3" class="w-8 h-px bg-gray-700"></div>
            </div>
        </div>

        <form @submit.prevent="submit">
            <!-- STEP 1: Configuração -->
            <div v-show="step === 1" class="space-y-6">
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-white">Configuração Básica</h3>

                    <div>
                        <label class="text-sm text-gray-400">Nome da Campanha *</label>
                        <input v-model="form.name" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" placeholder="Ex: Promoção Black Friday" />
                        <div v-if="form.errors.name" class="text-red-400 text-xs mt-1">{{ form.errors.name }}</div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm text-gray-400">Provedor SMS *</label>
                            <select v-model="form.email_provider_id" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                                <option value="" disabled>Selecione...</option>
                                <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                            <div v-if="!providers?.length" class="text-yellow-400 text-xs mt-1">
                                Configure um provedor SMS em <Link :href="route('email.providers.index')" class="underline">Provedores</Link>
                            </div>
                        </div>
                        <div>
                            <label class="text-sm text-gray-400">Nome Remetente * (max 11 chars)</label>
                            <input v-model="form.sender_name" maxlength="11" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" placeholder="MinhaMarca" />
                            <p class="text-xs text-gray-500 mt-1">{{ form.sender_name.length }}/11 chars</p>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm text-gray-400">Listas de Contatos *</label>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-2 mt-2">
                            <label v-for="list in lists" :key="list.id" :class="['flex items-center gap-2 p-3 rounded-lg border cursor-pointer transition', form.list_ids.includes(list.id) ? 'bg-indigo-600/10 border-indigo-600/50 text-indigo-400' : 'bg-gray-800 border-gray-700 text-gray-400 hover:border-gray-600']">
                                <input type="checkbox" :value="list.id" v-model="form.list_ids" class="rounded bg-gray-800 border-gray-700 text-indigo-600" />
                                <div>
                                    <span class="text-sm">{{ list.name }}</span>
                                    <p class="text-xs text-gray-500">{{ list.contacts_count?.toLocaleString('pt-BR') }} contatos</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm text-gray-400">Excluir Listas (opcional)</label>
                        <div class="flex flex-wrap gap-2 mt-2">
                            <label v-for="list in lists" :key="'ex-'+list.id" :class="['flex items-center gap-2 px-3 py-1.5 rounded-lg border cursor-pointer text-xs transition', form.exclude_list_ids.includes(list.id) ? 'bg-red-600/10 border-red-600/50 text-red-400' : 'bg-gray-800 border-gray-700 text-gray-500']">
                                <input type="checkbox" :value="list.id" v-model="form.exclude_list_ids" class="rounded bg-gray-800 border-gray-700 text-red-600 w-3 h-3" />
                                {{ list.name }}
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm text-gray-400">Agendamento (opcional)</label>
                        <input v-model="form.scheduled_at" type="datetime-local" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="button" @click="nextStep" :disabled="!form.name || !form.email_provider_id || !form.sender_name || !form.list_ids.length" class="px-6 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 disabled:opacity-50 transition">
                        Próximo
                    </button>
                </div>
            </div>

            <!-- STEP 2: Conteúdo -->
            <div v-show="step === 2" class="space-y-6">
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-white">Conteúdo da Mensagem</h3>
                        <button type="button" @click="showTemplateSelect = !showTemplateSelect" class="text-xs text-indigo-400 hover:text-indigo-300">
                            {{ showTemplateSelect ? 'Fechar Templates' : 'Usar Template' }}
                        </button>
                    </div>

                    <!-- Template Selection -->
                    <div v-if="showTemplateSelect" class="grid grid-cols-1 md:grid-cols-2 gap-3 p-4 bg-gray-800/50 rounded-lg border border-gray-700">
                        <div v-for="tpl in templates" :key="tpl.id" class="p-3 bg-gray-800 rounded-lg border border-gray-700 hover:border-indigo-600/50 cursor-pointer transition" @click="selectTemplate(tpl)">
                            <h4 class="text-sm font-medium text-white">{{ tpl.name }}</h4>
                            <p class="text-xs text-gray-500 mt-1">{{ tpl.category }}</p>
                            <p class="text-xs text-gray-400 mt-2 line-clamp-2">{{ tpl.body }}</p>
                        </div>
                        <div v-if="!templates?.length" class="col-span-2 text-center py-4 text-gray-500 text-sm">
                            Nenhum template SMS criado. <Link :href="route('sms.templates.create')" class="text-indigo-400">Criar template</Link>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm text-gray-400">Mensagem *</label>
                        <textarea v-model="form.body" rows="6" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm font-mono" placeholder="Olá {{first_name}}, aproveite..."></textarea>

                        <!-- Segment Counter -->
                        <div class="flex items-center justify-between mt-2 text-xs">
                            <div class="flex items-center gap-4">
                                <span :class="charCount > 1600 ? 'text-red-400' : 'text-gray-400'">{{ charCount }} caracteres</span>
                                <span class="text-gray-500">|</span>
                                <span :class="segments > 3 ? 'text-yellow-400' : 'text-gray-400'">{{ segments }} segmento(s)</span>
                                <span class="text-gray-500">|</span>
                                <span :class="isUnicode ? 'text-yellow-400' : 'text-green-400'">{{ isUnicode ? 'Unicode (UCS-2)' : 'GSM-7' }}</span>
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

                    <!-- Opt-out LGPD -->
                    <div class="bg-gray-800/50 rounded-lg border border-gray-700 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-semibold text-white">Opt-out LGPD</h4>
                            <label class="flex items-center gap-2 text-xs text-gray-400">
                                <input type="checkbox" v-model="form.settings.skip_optout" class="rounded bg-gray-800 border-gray-700 text-indigo-600 w-3 h-3" />
                                Não incluir opt-out
                            </label>
                        </div>
                        <div v-if="!form.settings.skip_optout">
                            <label class="text-xs text-gray-400">Texto de opt-out</label>
                            <input v-model="form.settings.optout_text" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-white text-xs" />
                            <p class="text-[10px] text-gray-500 mt-1">Será adicionado ao final da mensagem automaticamente.</p>
                        </div>
                    </div>

                    <!-- Preview -->
                    <div class="bg-gray-800 rounded-lg p-4">
                        <h4 class="text-xs font-semibold text-gray-400 mb-2">Preview da Mensagem</h4>
                        <div class="max-w-xs mx-auto bg-gray-700 rounded-2xl p-4 relative">
                            <div class="bg-green-600 rounded-xl rounded-bl-sm px-3 py-2 text-white text-sm whitespace-pre-wrap">
                                {{ bodyPreview || 'Digite sua mensagem...' }}
                            </div>
                            <p class="text-[10px] text-gray-500 mt-1 text-right">{{ form.sender_name || 'Remetente' }}</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between">
                    <button type="button" @click="prevStep" class="px-6 py-2 bg-gray-800 text-gray-300 rounded-lg text-sm hover:bg-gray-700 transition">Voltar</button>
                    <button type="button" @click="nextStep" :disabled="!form.body" class="px-6 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 disabled:opacity-50 transition">Próximo</button>
                </div>
            </div>

            <!-- STEP 3: Revisar -->
            <div v-show="step === 3" class="space-y-6">
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-white">Resumo da Campanha</h3>

                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-gray-500 text-xs">Nome</p>
                            <p class="text-white">{{ form.name }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500 text-xs">Remetente</p>
                            <p class="text-white">{{ form.sender_name }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500 text-xs">Destinatários</p>
                            <p class="text-white">~{{ selectedListsContactCount.toLocaleString('pt-BR') }} contatos</p>
                        </div>
                        <div>
                            <p class="text-gray-500 text-xs">Segmentos</p>
                            <p class="text-white">{{ segments }} por mensagem ({{ isUnicode ? 'Unicode' : 'GSM-7' }})</p>
                        </div>
                        <div>
                            <p class="text-gray-500 text-xs">Agendamento</p>
                            <p class="text-white">{{ form.scheduled_at || 'Imediato (rascunho)' }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500 text-xs">Custo Estimado</p>
                            <p class="text-yellow-400 font-medium">{{ estimatedCost || '-' }}</p>
                        </div>
                    </div>

                    <div class="mt-4 p-3 bg-gray-800 rounded-lg">
                        <p class="text-xs text-gray-500 mb-1">Mensagem:</p>
                        <p class="text-sm text-white whitespace-pre-wrap">{{ bodyPreview }}</p>
                    </div>

                    <div v-if="form.list_ids.length" class="mt-2">
                        <p class="text-xs text-gray-500 mb-1">Listas selecionadas:</p>
                        <div class="flex flex-wrap gap-1">
                            <span v-for="id in form.list_ids" :key="id" class="px-2 py-0.5 text-xs bg-indigo-900/30 text-indigo-400 rounded-full">
                                {{ lists.find(l => l.id === id)?.name }}
                            </span>
                        </div>
                    </div>

                    <!-- Tags -->
                    <div>
                        <label class="text-xs text-gray-400">Tags (opcional)</label>
                        <div class="flex items-center gap-2 mt-1">
                            <input v-model="tagInput" @keyup.enter="addTag" class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-white text-xs" placeholder="Adicionar tag..." />
                            <button type="button" @click="addTag" class="px-3 py-1.5 bg-gray-800 text-gray-400 rounded-lg text-xs hover:bg-gray-700">+</button>
                        </div>
                        <div class="flex flex-wrap gap-1 mt-2" v-if="form.tags.length">
                            <span v-for="(tag, i) in form.tags" :key="i" class="px-2 py-0.5 text-xs bg-gray-800 text-gray-400 rounded-full flex items-center gap-1">
                                {{ tag }}
                                <button type="button" @click="removeTag(i)" class="text-gray-600 hover:text-red-400">&times;</button>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between">
                    <button type="button" @click="prevStep" class="px-6 py-2 bg-gray-800 text-gray-300 rounded-lg text-sm hover:bg-gray-700 transition">Voltar</button>
                    <button type="submit" :disabled="form.processing" class="px-6 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 disabled:opacity-50 transition">
                        {{ form.processing ? 'Criando...' : (form.scheduled_at ? 'Agendar Campanha' : 'Criar Rascunho') }}
                    </button>
                </div>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
