<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GrapesEditor from '@/Components/Email/GrapesEditor.vue';
import axios from 'axios';

const props = defineProps({
    campaign: Object,
    providers: Array,
    lists: Array,
    templates: Array,
});

// Provedor selecionado com informações de quota
const selectedProvider = computed(() => {
    return props.providers?.find(p => p.id === form.email_provider_id);
});

const selectedProviderQuota = computed(() => {
    return selectedProvider.value?.quota_info;
});

const form = useForm({
    name: props.campaign.name || '',
    subject: props.campaign.subject || '',
    preview_text: props.campaign.preview_text || '',
    from_name: props.campaign.from_name || '',
    from_email: props.campaign.from_email || '',
    reply_to: props.campaign.reply_to || '',
    email_provider_id: props.campaign.email_provider_id || '',
    html_content: props.campaign.html_content || '',
    mjml_content: props.campaign.mjml_content || '',
    json_content: props.campaign.json_content || null,
    lists: props.campaign.lists || [],
    exclude_lists: props.campaign.exclude_lists || [],
    settings: props.campaign.settings || { track_opens: true, track_clicks: true, send_speed: 100 },
    tags: props.campaign.tags || [],
});

const activeTab = ref('info');
const saving = ref(false);

function save() {
    saving.value = true;
    form.put(route('email.campaigns.update', props.campaign.id), {
        onFinish: () => saving.value = false,
    });
}

function toggleList(listId) {
    const idx = form.lists.indexOf(listId);
    if (idx >= 0) form.lists.splice(idx, 1);
    else form.lists.push(listId);
}

function toggleExcludeList(listId) {
    const idx = form.exclude_lists.indexOf(listId);
    if (idx >= 0) form.exclude_lists.splice(idx, 1);
    else form.exclude_lists.push(listId);
}

const estimatedRecipients = computed(() => {
    return props.lists.filter(l => form.lists.includes(l.id)).reduce((sum, l) => sum + (l.contacts_count || 0), 0);
});
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a :href="route('email.campaigns.show', campaign.id)" class="text-gray-400 hover:text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                    </a>
                    <h1 class="text-xl font-bold text-white">Editar Campanha</h1>
                </div>
                <button @click="save" :disabled="saving" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition disabled:opacity-50">
                    {{ saving ? 'Salvando...' : 'Salvar Campanha' }}
                </button>
            </div>
        </template>

        <!-- Tabs -->
        <div class="flex gap-1 mb-6 border-b border-gray-800">
            <button v-for="t in [{id:'info',label:'Informações'},{id:'recipients',label:'Destinatários'},{id:'content',label:'Conteúdo'}]" :key="t.id"
                @click="activeTab = t.id"
                :class="['px-4 py-2.5 text-sm font-medium border-b-2 transition', activeTab === t.id ? 'text-indigo-400 border-indigo-400' : 'text-gray-500 border-transparent hover:text-gray-300']">
                {{ t.label }}
            </button>
        </div>

        <!-- Info Tab -->
        <div v-if="activeTab === 'info'" class="max-w-2xl space-y-5">
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-6 space-y-4">
                <div>
                    <label class="text-sm text-gray-400">Nome da Campanha *</label>
                    <input v-model="form.name" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-white" />
                </div>
                <div>
                    <label class="text-sm text-gray-400">Assunto *</label>
                    <input v-model="form.subject" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-white" />
                </div>
                <div>
                    <label class="text-sm text-gray-400">Preview Text</label>
                    <input v-model="form.preview_text" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-white" />
                </div>
                <!-- Remetente - Não editável (usa o configurado no provedor) -->
                <div class="rounded-lg border border-gray-700/50 bg-gray-800/30 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-sm text-gray-400">Remetente</label>
                        <span class="text-xs text-indigo-400 bg-indigo-900/20 px-2 py-0.5 rounded">Configurado no Provedor</span>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <input v-model="form.from_name" disabled
                                class="mt-1 w-full bg-gray-800/50 border border-gray-700/50 rounded-lg px-4 py-2.5 text-gray-400 cursor-not-allowed"
                                title="Usa o nome configurado no provedor" />
                        </div>
                        <div>
                            <input v-model="form.from_email" type="email" disabled
                                class="mt-1 w-full bg-gray-800/50 border border-gray-700/50 rounded-lg px-4 py-2.5 text-gray-400 cursor-not-allowed"
                                title="Usa o email verificado no provedor (obrigatório para SendPulse)" />
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">
                        O remetente é definido automaticamente pelo provedor selecionado para garantir a entregabilidade.
                        <a :href="route('email.providers.index')" class="text-indigo-400 hover:text-indigo-300">Editar provedor →</a>
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm text-gray-400">Responder Para</label>
                        <input v-model="form.reply_to" type="email" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-white" />
                    </div>
                    <div>
                        <label class="text-sm text-gray-400">Provedor de Email *</label>
                        <select v-model="form.email_provider_id" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-white">
                            <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }} ({{ p.type }})</option>
                        </select>

                        <!-- Informações de quota do provedor -->
                        <div v-if="selectedProviderQuota" class="mt-2 space-y-1">
                            <div v-if="selectedProviderQuota.hourly_limit" class="flex items-center gap-2 text-xs">
                                <span class="text-gray-500">Limite/hora:</span>
                                <span :class="selectedProviderQuota.hourly_remaining === 0 ? 'text-red-400' : selectedProviderQuota.hourly_remaining < 10 ? 'text-amber-400' : 'text-emerald-400'">
                                    {{ selectedProviderQuota.sends_this_hour }}/{{ selectedProviderQuota.hourly_limit }}
                                </span>
                                <span v-if="selectedProviderQuota.hourly_remaining === 0" class="text-red-500 text-[10px] bg-red-900/20 px-1.5 py-0.5 rounded">Limite atingido</span>
                            </div>
                            <div v-if="selectedProviderQuota.daily_limit" class="flex items-center gap-2 text-xs">
                                <span class="text-gray-500">Limite/dia:</span>
                                <span :class="selectedProviderQuota.daily_remaining === 0 ? 'text-red-400' : selectedProviderQuota.daily_remaining < 50 ? 'text-amber-400' : 'text-emerald-400'">
                                    {{ selectedProviderQuota.sends_today }}/{{ selectedProviderQuota.daily_limit }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recipients Tab -->
        <div v-if="activeTab === 'recipients'" class="max-w-2xl space-y-5">
            <div class="bg-gray-900 rounded-xl border border-gray-800 p-6">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">Incluir Listas</h3>
                <div class="space-y-2">
                    <label v-for="l in lists" :key="l.id" class="flex items-center gap-3 p-3 rounded-lg bg-gray-800/50 cursor-pointer hover:bg-gray-800">
                        <input type="checkbox" :checked="form.lists.includes(l.id)" @change="toggleList(l.id)" class="rounded bg-gray-700 border-gray-600 text-indigo-600" />
                        <span class="text-sm text-gray-200 flex-1">{{ l.name }}</span>
                        <span class="text-xs text-gray-500">{{ l.contacts_count }} contatos</span>
                    </label>
                </div>
                <p class="mt-4 text-sm text-gray-400">Destinatários estimados: <strong class="text-white">{{ estimatedRecipients.toLocaleString('pt-BR') }}</strong></p>
            </div>

            <div class="bg-gray-900 rounded-xl border border-gray-800 p-6">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">Excluir Listas (Opcional)</h3>
                <div class="space-y-2">
                    <label v-for="l in lists" :key="l.id" class="flex items-center gap-3 p-3 rounded-lg bg-gray-800/50 cursor-pointer hover:bg-gray-800">
                        <input type="checkbox" :checked="form.exclude_lists.includes(l.id)" @change="toggleExcludeList(l.id)" class="rounded bg-gray-700 border-gray-600 text-red-600" />
                        <span class="text-sm text-gray-200 flex-1">{{ l.name }}</span>
                        <span class="text-xs text-gray-500">{{ l.contacts_count }}</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Content Tab -->
        <div v-if="activeTab === 'content'">
            <GrapesEditor
                :htmlContent="form.html_content"
                :jsonContent="form.json_content"
                @update:htmlContent="v => form.html_content = v"
                @update:jsonContent="v => form.json_content = v"
                @save="save"
            />
        </div>
    </AuthenticatedLayout>
</template>
