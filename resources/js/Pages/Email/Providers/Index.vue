<script setup>
import { ref } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({ providers: Array });
const page = usePage();
const flash = page.props.flash || {};

const showModal = ref(false);
const editingProvider = ref(null);

const form = useForm({
    name: '',
    type: 'smtp',
    is_default: false,
    daily_limit: null,
    // SMTP
    host: '',
    port: 587,
    encryption: 'tls',
    username: '',
    password: '',
    from_address: '',
    from_name: '',
    // SendPulse (Email)
    api_user_id: '',
    api_secret: '',
    from_email: '',
    // SMS SendPulse
    sender_name: '',
});

function openCreate() {
    editingProvider.value = null;
    form.reset();
    showModal.value = true;
}

function openEdit(provider) {
    editingProvider.value = provider;
    form.name = provider.name;
    form.type = provider.type;
    form.is_default = provider.is_default;
    form.daily_limit = provider.daily_limit;
    if (provider.config_summary) {
        if (provider.type === 'smtp') {
            form.host = provider.config_summary.host || '';
            form.from_address = provider.config_summary.from || '';
        }
    }
    showModal.value = true;
}

function submit() {
    if (editingProvider.value) {
        form.put(route('email.providers.update', editingProvider.value.id), {
            onSuccess: () => { showModal.value = false; }
        });
    } else {
        form.post(route('email.providers.store'), {
            onSuccess: () => { showModal.value = false; }
        });
    }
}

function deleteProvider(id) {
    if (confirm('Tem certeza que deseja remover este provedor?')) {
        router.delete(route('email.providers.destroy', id));
    }
}

function testConnection(provider) {
    axios.post(route('email.providers.test', provider.id))
        .then(r => alert(r.data.success ? r.data.message : 'Erro: ' + r.data.error))
        .catch(e => alert('Erro: ' + (e.response?.data?.error || e.message)));
}

const typeLabels = { smtp: 'SMTP', sendpulse: 'SendPulse', sms_sendpulse: 'SMS SendPulse' };
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-white">Provedores de Email & SMS</h1>
                <button @click="openCreate" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition">
                    + Novo Provedor
                </button>
            </div>
        </template>

        <!-- Flash -->
        <div v-if="flash?.success" class="mb-6 px-4 py-3 rounded-lg bg-green-900/30 border border-green-700/50 text-green-300 text-sm">
            {{ flash.success }}
        </div>

        <!-- Lista -->
        <div class="grid gap-4">
            <div v-if="!providers?.length" class="bg-gray-900 rounded-xl border border-gray-800 p-12 text-center">
                <p class="text-gray-400 mb-4">Nenhum provedor configurado.</p>
                <button @click="openCreate" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">Configurar Primeiro Provedor</button>
            </div>

            <div v-for="p in providers" :key="p.id" class="bg-gray-900 rounded-xl border border-gray-800 p-5 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl" :class="p.type === 'sms_sendpulse' ? 'bg-green-600/20 text-green-400' : p.type === 'sendpulse' ? 'bg-blue-600/20 text-blue-400' : 'bg-gray-800 text-gray-400'">
                        <svg v-if="p.type === 'sms_sendpulse'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-white font-semibold">{{ p.name }}</h3>
                            <span class="px-2 py-0.5 text-xs rounded-full" :class="p.is_active ? 'bg-green-900/30 text-green-400' : 'bg-red-900/30 text-red-400'">
                                {{ p.is_active ? 'Ativo' : 'Inativo' }}
                            </span>
                            <span v-if="p.is_default" class="px-2 py-0.5 text-xs rounded-full bg-indigo-900/30 text-indigo-400">Padrão</span>
                        </div>
                        <p class="text-xs text-gray-500 mt-0.5">
                            {{ typeLabels[p.type] || p.type }} · {{ p.config_summary?.host || p.config_summary?.from || p.config_summary?.sender_name || '-' }}
                            <span v-if="p.daily_limit"> · Limite: {{ p.sends_today }}/{{ p.daily_limit }}</span>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="testConnection(p)" class="px-3 py-1.5 text-xs bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition">Testar</button>
                    <button @click="openEdit(p)" class="px-3 py-1.5 text-xs bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition">Editar</button>
                    <button @click="deleteProvider(p.id)" class="px-3 py-1.5 text-xs bg-red-900/30 text-red-400 rounded-lg hover:bg-red-900/50 transition">Remover</button>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
                <div class="bg-gray-900 rounded-2xl border border-gray-800 w-full max-w-lg max-h-[90vh] overflow-y-auto p-6">
                    <h2 class="text-lg font-bold text-white mb-4">{{ editingProvider ? 'Editar' : 'Novo' }} Provedor</h2>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div>
                            <label class="text-sm text-gray-400">Nome</label>
                            <input v-model="form.name" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" placeholder="Ex: SMTP Principal" />
                        </div>

                        <div v-if="!editingProvider">
                            <label class="text-sm text-gray-400">Tipo</label>
                            <select v-model="form.type" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                                <option value="smtp">SMTP (Email)</option>
                                <option value="sendpulse">SendPulse (Email)</option>
                                <option value="sms_sendpulse">SendPulse (SMS)</option>
                            </select>
                        </div>

                        <!-- SMTP fields -->
                        <template v-if="form.type === 'smtp'">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm text-gray-400">Host</label>
                                    <input v-model="form.host" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" placeholder="smtp.exemplo.com" />
                                </div>
                                <div>
                                    <label class="text-sm text-gray-400">Porta</label>
                                    <input v-model.number="form.port" type="number" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                                </div>
                            </div>
                            <div>
                                <label class="text-sm text-gray-400">Criptografia</label>
                                <select v-model="form.encryption" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                                    <option value="tls">TLS</option>
                                    <option value="ssl">SSL</option>
                                    <option value="none">Nenhuma</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm text-gray-400">Usuário</label>
                                    <input v-model="form.username" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                                </div>
                                <div>
                                    <label class="text-sm text-gray-400">Senha</label>
                                    <input v-model="form.password" type="password" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm text-gray-400">Email Remetente</label>
                                    <input v-model="form.from_address" type="email" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                                </div>
                                <div>
                                    <label class="text-sm text-gray-400">Nome Remetente</label>
                                    <input v-model="form.from_name" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                                </div>
                            </div>
                        </template>

                        <!-- SendPulse Email fields -->
                        <template v-if="form.type === 'sendpulse'">
                            <div>
                                <label class="text-sm text-gray-400">API User ID</label>
                                <input v-model="form.api_user_id" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                            </div>
                            <div>
                                <label class="text-sm text-gray-400">API Secret</label>
                                <input v-model="form.api_secret" type="password" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm text-gray-400">Email Remetente</label>
                                    <input v-model="form.from_email" type="email" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                                </div>
                                <div>
                                    <label class="text-sm text-gray-400">Nome Remetente</label>
                                    <input v-model="form.from_name" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                                </div>
                            </div>
                        </template>

                        <!-- SMS SendPulse fields -->
                        <template v-if="form.type === 'sms_sendpulse'">
                            <div class="bg-green-900/20 border border-green-800/30 rounded-lg p-3 mb-2">
                                <p class="text-xs text-green-400">Provedor SMS via SendPulse. Usa as mesmas credenciais da API SendPulse.</p>
                            </div>
                            <div>
                                <label class="text-sm text-gray-400">API User ID</label>
                                <input v-model="form.api_user_id" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                            </div>
                            <div>
                                <label class="text-sm text-gray-400">API Secret</label>
                                <input v-model="form.api_secret" type="password" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                            </div>
                            <div>
                                <label class="text-sm text-gray-400">Nome do Remetente (max 11 chars)</label>
                                <input v-model="form.sender_name" maxlength="11" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" placeholder="MinhaMarca" />
                                <p class="text-xs text-gray-500 mt-1">Alfanumérico, sem espaços. Ex: MinhaMarca</p>
                            </div>
                        </template>

                        <div class="grid grid-cols-2 gap-3">
                            <label class="flex items-center gap-2 text-sm text-gray-400">
                                <input v-model="form.is_default" type="checkbox" class="rounded bg-gray-800 border-gray-700 text-indigo-600" />
                                Provedor Padrão
                            </label>
                            <div>
                                <label class="text-sm text-gray-400">Limite Diário</label>
                                <input v-model.number="form.daily_limit" type="number" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" placeholder="Sem limite" />
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 pt-4 border-t border-gray-800">
                            <button type="button" @click="showModal = false" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                            <button type="submit" :disabled="form.processing" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition disabled:opacity-50">
                                {{ form.processing ? 'Salvando...' : 'Salvar' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
