<script setup>
import { ref } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({ list: Object, contacts: Object, sources: Array, wcConnections: Array });
const flash = usePage().props.flash || {};

const activeTab = ref('contacts');
const showAddContact = ref(false);
const showImport = ref(false);
const showAddSource = ref(false);

const contactForm = useForm({ email: '', first_name: '', last_name: '', phone: '', company: '' });
const importForm = useForm({ file: null, mapping: {} });
const sourceForm = useForm({
    type: 'woocommerce',
    sync_frequency: 'daily',
    analytics_connection_id: '',
    min_orders: 0,
    host: '', port: 3306, database: '', table: '', email_column: 'email', username: 'root', password: '', where_clause: '',
    spreadsheet_id: '', sheet_name: 'Sheet1',
});

function addContact() {
    contactForm.post(route('email.lists.add-contact', props.list.id), {
        onSuccess: () => { showAddContact.value = false; contactForm.reset(); },
    });
}

function removeContact(contactId) {
    if (confirm('Remover este contato da lista?')) {
        router.delete(route('email.lists.remove-contact', [props.list.id, contactId]));
    }
}

function submitImport() {
    importForm.post(route('email.lists.import', props.list.id), {
        onSuccess: () => { showImport.value = false; },
    });
}

function addSource() {
    sourceForm.post(route('email.lists.add-source', props.list.id), {
        onSuccess: () => { showAddSource.value = false; sourceForm.reset(); },
    });
}

function syncSource(sourceId) {
    router.post(route('email.lists.sync-source', [props.list.id, sourceId]));
}

function removeSource(sourceId) {
    if (confirm('Remover esta fonte?')) {
        router.delete(route('email.lists.remove-source', [props.list.id, sourceId]));
    }
}

const statusColors = { active: 'text-green-400', unsubscribed: 'text-red-400', bounced: 'text-orange-400', complained: 'text-red-500' };
const sourceLabels = { woocommerce: 'WooCommerce', mysql: 'MySQL Externo', google_sheets: 'Google Sheets', csv: 'Arquivo CSV' };
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ list.name }}</h1>
                    <p class="text-sm text-gray-500">{{ list.contacts_count }} contatos · {{ list.active_contacts }} ativos</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="showImport = true" class="px-3 py-2 bg-gray-800 text-gray-300 rounded-lg text-sm hover:bg-gray-700">Importar CSV</button>
                    <button @click="showAddSource = true" class="px-3 py-2 bg-gray-800 text-gray-300 rounded-lg text-sm hover:bg-gray-700">+ Fonte Externa</button>
                    <button @click="showAddContact = true" class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-500">+ Contato</button>
                </div>
            </div>
        </template>

        <div v-if="flash?.success" class="mb-6 px-4 py-3 rounded-lg bg-green-900/30 border border-green-700/50 text-green-300 text-sm">{{ flash.success }}</div>

        <!-- Tabs -->
        <div class="flex gap-1 mb-6 border-b border-gray-800">
            <button v-for="tab in ['contacts', 'sources']" :key="tab" @click="activeTab = tab"
                :class="['px-4 py-2.5 text-sm font-medium border-b-2 transition', activeTab === tab ? 'text-indigo-400 border-indigo-400' : 'text-gray-500 border-transparent hover:text-gray-300']">
                {{ tab === 'contacts' ? `Contatos (${list.contacts_count})` : `Fontes (${sources?.length || 0})` }}
            </button>
        </div>

        <!-- Contacts Tab -->
        <div v-if="activeTab === 'contacts'" class="bg-gray-900 rounded-xl border border-gray-800">
            <table class="w-full text-sm">
                <thead><tr class="text-gray-500 text-xs uppercase border-b border-gray-800">
                    <th class="text-left py-3 px-4">Email</th>
                    <th class="text-left py-3 px-4">Nome</th>
                    <th class="text-left py-3 px-4">Telefone</th>
                    <th class="text-left py-3 px-4">Status</th>
                    <th class="text-left py-3 px-4">Fonte</th>
                    <th class="text-right py-3 px-4"></th>
                </tr></thead>
                <tbody>
                    <tr v-for="c in contacts?.data" :key="c.id" class="border-b border-gray-800/50">
                        <td class="py-3 px-4 text-gray-200">{{ c.email }}</td>
                        <td class="py-3 px-4 text-gray-300">{{ c.full_name }}</td>
                        <td class="py-3 px-4 text-gray-400">{{ c.phone || '-' }}</td>
                        <td class="py-3 px-4"><span :class="statusColors[c.status]">{{ c.status }}</span></td>
                        <td class="py-3 px-4 text-gray-500 text-xs">{{ c.source }}</td>
                        <td class="py-3 px-4 text-right">
                            <button @click.stop="removeContact(c.id)" class="text-xs text-red-400 hover:text-red-300">Remover</button>
                        </td>
                    </tr>
                    <tr v-if="!contacts?.data?.length">
                        <td colspan="6" class="py-8 text-center text-gray-500">Nenhum contato nesta lista.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Sources Tab -->
        <div v-if="activeTab === 'sources'" class="space-y-4">
            <div v-if="!sources?.length" class="bg-gray-900 rounded-xl border border-gray-800 p-8 text-center text-gray-500">
                Nenhuma fonte externa configurada.
            </div>
            <div v-for="s in sources" :key="s.id" class="bg-gray-900 rounded-xl border border-gray-800 p-4 flex items-center justify-between">
                <div>
                    <h4 class="text-white font-medium">{{ sourceLabels[s.type] || s.type }}</h4>
                    <p class="text-xs text-gray-500">Sincronização: {{ s.sync_frequency }} · Status: <span :class="s.sync_status === 'success' ? 'text-green-400' : s.sync_status === 'error' ? 'text-red-400' : 'text-gray-400'">{{ s.sync_status }}</span></p>
                    <p v-if="s.last_synced_at" class="text-xs text-gray-500">Última sync: {{ s.last_synced_at }} ({{ s.records_synced }} registros)</p>
                    <p v-if="s.sync_error" class="text-xs text-red-400 mt-1">{{ s.sync_error }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="syncSource(s.id)" class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg hover:bg-indigo-500">Sincronizar</button>
                    <button @click="removeSource(s.id)" class="px-3 py-1.5 text-xs bg-red-900/30 text-red-400 rounded-lg hover:bg-red-900/50">Remover</button>
                </div>
            </div>
        </div>

        <!-- Modal: Add Contact -->
        <Teleport to="body">
            <div v-if="showAddContact" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
                <div class="bg-gray-900 rounded-2xl border border-gray-800 w-full max-w-md p-6">
                    <h2 class="text-lg font-bold text-white mb-4">Adicionar Contato</h2>
                    <form @submit.prevent="addContact" class="space-y-3">
                        <input v-model="contactForm.email" placeholder="Email *" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                        <div class="grid grid-cols-2 gap-3">
                            <input v-model="contactForm.first_name" placeholder="Nome" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                            <input v-model="contactForm.last_name" placeholder="Sobrenome" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <input v-model="contactForm.phone" placeholder="Telefone" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                            <input v-model="contactForm.company" placeholder="Empresa" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                        </div>
                        <div class="flex justify-end gap-3 pt-3">
                            <button type="button" @click="showAddContact = false" class="text-sm text-gray-400 hover:text-white">Cancelar</button>
                            <button type="submit" :disabled="contactForm.processing" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">Adicionar</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Modal: Import CSV -->
        <Teleport to="body">
            <div v-if="showImport" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
                <div class="bg-gray-900 rounded-2xl border border-gray-800 w-full max-w-md p-6">
                    <h2 class="text-lg font-bold text-white mb-4">Importar Contatos</h2>
                    <form @submit.prevent="submitImport" class="space-y-4">
                        <div>
                            <label class="text-sm text-gray-400">Arquivo CSV/XLSX (max 10MB)</label>
                            <input type="file" @change="importForm.file = $event.target.files[0]" accept=".csv,.txt,.xlsx" class="mt-1 w-full text-sm text-gray-400" />
                        </div>
                        <div class="bg-gray-800/50 rounded-lg p-3 text-xs text-gray-500">
                            <p class="font-medium text-gray-400 mb-1">Formato esperado:</p>
                            <p>O arquivo deve ter cabeçalhos na primeira linha. Colunas reconhecidas automaticamente: email, nome, sobrenome, telefone, empresa.</p>
                        </div>
                        <div class="flex justify-end gap-3 pt-3">
                            <button type="button" @click="showImport = false" class="text-sm text-gray-400 hover:text-white">Cancelar</button>
                            <button type="submit" :disabled="importForm.processing || !importForm.file" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm disabled:opacity-50">Importar</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Modal: Add Source -->
        <Teleport to="body">
            <div v-if="showAddSource" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
                <div class="bg-gray-900 rounded-2xl border border-gray-800 w-full max-w-lg max-h-[85vh] overflow-y-auto p-6">
                    <h2 class="text-lg font-bold text-white mb-4">Adicionar Fonte Externa</h2>
                    <form @submit.prevent="addSource" class="space-y-4">
                        <div>
                            <label class="text-sm text-gray-400">Tipo de Fonte</label>
                            <select v-model="sourceForm.type" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                                <option value="woocommerce">WooCommerce</option>
                                <option value="mysql">MySQL Externo</option>
                                <option value="google_sheets">Google Sheets</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm text-gray-400">Frequência de Sincronização</label>
                            <select v-model="sourceForm.sync_frequency" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                                <option value="manual">Manual</option>
                                <option value="daily">Diária</option>
                                <option value="weekly">Semanal</option>
                                <option value="monthly">Mensal</option>
                            </select>
                        </div>

                        <!-- WooCommerce -->
                        <template v-if="sourceForm.type === 'woocommerce'">
                            <div>
                                <label class="text-sm text-gray-400">Conexão WooCommerce</label>
                                <select v-model="sourceForm.analytics_connection_id" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                                    <option value="">Selecione...</option>
                                    <option v-for="c in wcConnections" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm text-gray-400">Mínimo de Pedidos</label>
                                <input v-model.number="sourceForm.min_orders" type="number" min="0" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                            </div>
                        </template>

                        <!-- MySQL -->
                        <template v-if="sourceForm.type === 'mysql'">
                            <div class="grid grid-cols-2 gap-3">
                                <div><label class="text-sm text-gray-400">Host</label><input v-model="sourceForm.host" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" /></div>
                                <div><label class="text-sm text-gray-400">Porta</label><input v-model.number="sourceForm.port" type="number" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" /></div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div><label class="text-sm text-gray-400">Database</label><input v-model="sourceForm.database" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" /></div>
                                <div><label class="text-sm text-gray-400">Tabela</label><input v-model="sourceForm.table" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" /></div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div><label class="text-sm text-gray-400">Coluna de Email</label><input v-model="sourceForm.email_column" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" /></div>
                                <div><label class="text-sm text-gray-400">Usuário DB</label><input v-model="sourceForm.username" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" /></div>
                            </div>
                            <div><label class="text-sm text-gray-400">Senha DB</label><input v-model="sourceForm.password" type="password" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" /></div>
                        </template>

                        <!-- Google Sheets -->
                        <template v-if="sourceForm.type === 'google_sheets'">
                            <div>
                                <label class="text-sm text-gray-400">ID da Planilha</label>
                                <input v-model="sourceForm.spreadsheet_id" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" placeholder="Ex: 1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms" />
                            </div>
                            <div>
                                <label class="text-sm text-gray-400">Nome da Aba</label>
                                <input v-model="sourceForm.sheet_name" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                            </div>
                        </template>

                        <div class="flex justify-end gap-3 pt-4 border-t border-gray-800">
                            <button type="button" @click="showAddSource = false" class="text-sm text-gray-400 hover:text-white">Cancelar</button>
                            <button type="submit" :disabled="sourceForm.processing" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm disabled:opacity-50">Adicionar Fonte</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
