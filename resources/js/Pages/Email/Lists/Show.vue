<script setup>
import { ref, watch, computed } from 'vue';
import { useForm, router, usePage, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({ list: Object, contacts: Object, sources: Array, wcConnections: Array });

// Toast notification reativo
const toastMessage = ref('');
const toastType = ref('success');
const toastVisible = ref(false);
let toastTimer = null;

function showToast(message, type = 'success') {
    toastMessage.value = message;
    toastType.value = type;
    toastVisible.value = true;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toastVisible.value = false; }, 4000);
}

// Verificar flash do server a cada navegacao
const page = usePage();
watch(() => page.props.flash, (flash) => {
    if (flash?.success) showToast(flash.success, 'success');
    if (flash?.error) showToast(flash.error, 'error');
}, { immediate: true, deep: true });

const activeTab = ref('contacts');
const showAddContact = ref(false);
const showImport = ref(false);
const showAddSource = ref(false);
const syncingSourceId = ref(null);

const contactForm = useForm({ email: '', first_name: '', last_name: '', phone: '', company: '' });
const importForm = useForm({ file: null, mapping: {} });
const sourceForm = useForm({
    type: 'woocommerce',
    sync_frequency: 'daily',
    analytics_connection_id: '',
    min_orders: 0,
    host: '', port: 3306, database: '', table: '', email_column: 'email', username: 'root', password: '', where_clause: '',
    name_columns: { first_name: '', last_name: '', phone: '', company: '' },
    spreadsheet_id: '', sheet_name: 'Sheet1',
});

// ===== MySQL dinâmico =====
const mysqlStep = ref(1); // 1=conexao, 2=tabela, 3=colunas+mapeamento
const mysqlConnecting = ref(false);
const mysqlConnected = ref(false);
const mysqlError = ref('');
const mysqlTables = ref([]);
const mysqlLoadingColumns = ref(false);
const mysqlColumns = ref([]);
const mysqlTotalRows = ref(0);
const mysqlSample = ref([]);
const mysqlSuggestions = ref({});
const mysqlShowSample = ref(false);
const mysqlTableSearch = ref('');

const filteredMysqlTables = computed(() => {
    if (!mysqlTableSearch.value) return mysqlTables.value;
    const q = mysqlTableSearch.value.toLowerCase();
    return mysqlTables.value.filter(t => t.toLowerCase().includes(q));
});

function resetMysqlState() {
    mysqlStep.value = 1;
    mysqlConnecting.value = false;
    mysqlConnected.value = false;
    mysqlError.value = '';
    mysqlTables.value = [];
    mysqlLoadingColumns.value = false;
    mysqlColumns.value = [];
    mysqlTotalRows.value = 0;
    mysqlSample.value = [];
    mysqlSuggestions.value = {};
    mysqlShowSample.value = false;
    mysqlTableSearch.value = '';
    sourceForm.table = '';
    sourceForm.email_column = '';
    sourceForm.name_columns = { first_name: '', last_name: '', phone: '', company: '' };
    sourceForm.where_clause = '';
}

async function mysqlConnect() {
    mysqlConnecting.value = true;
    mysqlError.value = '';
    try {
        const res = await axios.post(route('email.lists.mysql-tables'), {
            host: sourceForm.host,
            port: sourceForm.port,
            database: sourceForm.database,
            username: sourceForm.username,
            password: sourceForm.password,
        });
        if (res.data.success) {
            mysqlTables.value = res.data.tables;
            mysqlConnected.value = true;
            mysqlStep.value = 2;
        }
    } catch (err) {
        mysqlError.value = err.response?.data?.error || 'Falha ao conectar no banco de dados.';
    } finally {
        mysqlConnecting.value = false;
    }
}

async function mysqlSelectTable(tableName) {
    sourceForm.table = tableName;
    mysqlLoadingColumns.value = true;
    mysqlError.value = '';
    try {
        const res = await axios.post(route('email.lists.mysql-columns'), {
            host: sourceForm.host,
            port: sourceForm.port,
            database: sourceForm.database,
            username: sourceForm.username,
            password: sourceForm.password,
            table: tableName,
        });
        if (res.data.success) {
            mysqlColumns.value = res.data.columns;
            mysqlTotalRows.value = res.data.total_rows;
            mysqlSample.value = res.data.sample;
            mysqlSuggestions.value = res.data.suggestions || {};
            // Auto-aplicar sugestões
            if (mysqlSuggestions.value.email) sourceForm.email_column = mysqlSuggestions.value.email;
            if (mysqlSuggestions.value.first_name) sourceForm.name_columns.first_name = mysqlSuggestions.value.first_name;
            if (mysqlSuggestions.value.last_name) sourceForm.name_columns.last_name = mysqlSuggestions.value.last_name;
            if (mysqlSuggestions.value.phone) sourceForm.name_columns.phone = mysqlSuggestions.value.phone;
            if (mysqlSuggestions.value.company) sourceForm.name_columns.company = mysqlSuggestions.value.company;
            mysqlStep.value = 3;
        }
    } catch (err) {
        mysqlError.value = err.response?.data?.error || 'Falha ao ler colunas da tabela.';
    } finally {
        mysqlLoadingColumns.value = false;
    }
}

// Reset MySQL ao trocar tipo de fonte
watch(() => sourceForm.type, () => { resetMysqlState(); });

function addContact() {
    contactForm.post(route('email.lists.add-contact', props.list.id), {
        preserveScroll: true,
        onSuccess: () => { showAddContact.value = false; contactForm.reset(); },
    });
}

function removeContact(contactId) {
    if (confirm('Remover este contato da lista?')) {
        router.delete(route('email.lists.remove-contact', [props.list.id, contactId]), { preserveScroll: true });
    }
}

function submitImport() {
    importForm.post(route('email.lists.import', props.list.id), {
        preserveScroll: true,
        onSuccess: () => { showImport.value = false; },
    });
}

function addSource() {
    sourceForm.post(route('email.lists.add-source', props.list.id), {
        preserveScroll: true,
        onSuccess: () => { showAddSource.value = false; sourceForm.reset(); resetMysqlState(); },
    });
}

function openAddSource() {
    showAddSource.value = true;
    resetMysqlState();
}

function syncSource(sourceId) {
    syncingSourceId.value = sourceId;
    router.post(route('email.lists.sync-source', [props.list.id, sourceId]), {}, {
        preserveScroll: true,
        onFinish: () => { syncingSourceId.value = null; },
    });
}

function removeSource(sourceId) {
    if (confirm('Remover esta fonte?')) {
        router.delete(route('email.lists.remove-source', [props.list.id, sourceId]), { preserveScroll: true });
    }
}

// Paginacao
function goToPage(url) {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

const statusColors = { active: 'text-green-400', unsubscribed: 'text-red-400', bounced: 'text-orange-400', complained: 'text-red-500' };
const sourceLabels = { woocommerce: 'WooCommerce', mysql: 'MySQL Externo', google_sheets: 'Google Sheets', csv: 'Arquivo CSV' };

function columnTypeIcon(dataType) {
    const textTypes = ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set'];
    const numTypes = ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'float', 'double'];
    const dateTypes = ['date', 'datetime', 'timestamp', 'time', 'year'];
    if (textTypes.includes(dataType)) return '🔤';
    if (numTypes.includes(dataType)) return '🔢';
    if (dateTypes.includes(dataType)) return '📅';
    return '📎';
}
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
                    <button @click="openAddSource()" class="px-3 py-2 bg-gray-800 text-gray-300 rounded-lg text-sm hover:bg-gray-700">+ Fonte Externa</button>
                    <button @click="showAddContact = true" class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-500">+ Contato</button>
                </div>
            </div>
        </template>

        <!-- Toast Notification -->
        <Teleport to="body">
            <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-y-2 opacity-0" enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-200 ease-in" leave-from-class="translate-y-0 opacity-100" leave-to-class="translate-y-2 opacity-0">
                <div v-if="toastVisible" class="fixed bottom-6 right-6 z-[100] max-w-sm">
                    <div :class="['px-4 py-3 rounded-xl shadow-xl border text-sm flex items-center gap-2',
                        toastType === 'success' ? 'bg-green-900/90 border-green-700/50 text-green-200' : 'bg-red-900/90 border-red-700/50 text-red-200']">
                        <svg v-if="toastType === 'success'" class="w-5 h-5 text-green-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <svg v-else class="w-5 h-5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                        <span>{{ toastMessage }}</span>
                        <button @click="toastVisible = false" class="ml-2 text-gray-400 hover:text-white shrink-0">&times;</button>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Tabs -->
        <div class="flex gap-1 mb-6 border-b border-gray-800">
            <button v-for="tab in ['contacts', 'sources']" :key="tab" @click="activeTab = tab"
                :class="['px-4 py-2.5 text-sm font-medium border-b-2 transition', activeTab === tab ? 'text-indigo-400 border-indigo-400' : 'text-gray-500 border-transparent hover:text-gray-300']">
                {{ tab === 'contacts' ? `Contatos (${list.contacts_count})` : `Fontes (${sources?.length || 0})` }}
            </button>
        </div>

        <!-- Contacts Tab -->
        <div v-if="activeTab === 'contacts'">
            <div class="bg-gray-900 rounded-xl border border-gray-800">
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
                        <tr v-for="c in contacts?.data" :key="c.id" class="border-b border-gray-800/50 hover:bg-gray-800/30">
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

            <!-- Paginacao -->
            <div v-if="contacts?.last_page > 1" class="flex items-center justify-between mt-4 px-1">
                <p class="text-xs text-gray-500">
                    Mostrando {{ contacts.from }}-{{ contacts.to }} de {{ contacts.total.toLocaleString('pt-BR') }} contatos
                </p>
                <div class="flex items-center gap-1">
                    <button @click="goToPage(contacts.prev_page_url)" :disabled="!contacts.prev_page_url"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition disabled:opacity-30 disabled:cursor-not-allowed bg-gray-800 text-gray-300 hover:bg-gray-700 border border-gray-700">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                    </button>

                    <!-- Primeira pagina -->
                    <button v-if="contacts.current_page > 3" @click="goToPage(contacts.links[1]?.url)"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition bg-gray-800 text-gray-400 border border-gray-700 hover:bg-gray-700 hover:text-white">1</button>
                    <span v-if="contacts.current_page > 4" class="text-gray-600 text-xs px-1">...</span>

                    <!-- Paginas proximas -->
                    <template v-for="link in contacts.links" :key="link.label">
                        <button v-if="!link.label.includes('Previous') && !link.label.includes('Next') && !link.label.includes('&laquo;') && !link.label.includes('&raquo;')
                            && Math.abs(parseInt(link.label) - contacts.current_page) <= 2"
                            @click="goToPage(link.url)"
                            :disabled="!link.url"
                            :class="['px-3 py-1.5 rounded-lg text-xs font-medium transition border min-w-[32px] text-center',
                                link.active ? 'bg-indigo-600 text-white border-indigo-500' : 'bg-gray-800 text-gray-400 border-gray-700 hover:bg-gray-700 hover:text-white disabled:opacity-30']"
                            v-html="link.label">
                        </button>
                    </template>

                    <!-- Ultima pagina -->
                    <span v-if="contacts.current_page < contacts.last_page - 3" class="text-gray-600 text-xs px-1">...</span>
                    <button v-if="contacts.current_page < contacts.last_page - 2"
                        @click="goToPage(contacts.links[contacts.links.length - 2]?.url)"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition bg-gray-800 text-gray-400 border border-gray-700 hover:bg-gray-700 hover:text-white">{{ contacts.last_page }}</button>

                    <button @click="goToPage(contacts.next_page_url)" :disabled="!contacts.next_page_url"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition disabled:opacity-30 disabled:cursor-not-allowed bg-gray-800 text-gray-300 hover:bg-gray-700 border border-gray-700">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    </button>

                    <!-- Info pagina -->
                    <span class="text-xs text-gray-600 ml-2">Pag. {{ contacts.current_page }}/{{ contacts.last_page }}</span>
                </div>
            </div>
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
                    <button @click="syncSource(s.id)" :disabled="syncingSourceId === s.id"
                        class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg hover:bg-indigo-500 disabled:opacity-60 flex items-center gap-1.5 transition">
                        <svg v-if="syncingSourceId === s.id" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        {{ syncingSourceId === s.id ? 'Sincronizando...' : 'Sincronizar' }}
                    </button>
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
            <div v-if="showAddSource" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="showAddSource = false">
                <div class="bg-gray-900 rounded-2xl border border-gray-800 w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6">
                    <h2 class="text-lg font-bold text-white mb-4">Adicionar Fonte Externa</h2>
                    <form @submit.prevent="addSource" class="space-y-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-sm text-gray-400">Tipo de Fonte</label>
                                <select v-model="sourceForm.type" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                                    <option value="woocommerce">WooCommerce</option>
                                    <option value="mysql">MySQL Externo</option>
                                    <option value="google_sheets">Google Sheets</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm text-gray-400">Frequencia de Sincronizacao</label>
                                <select v-model="sourceForm.sync_frequency" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                                    <option value="manual">Manual</option>
                                    <option value="daily">Diaria</option>
                                    <option value="weekly">Semanal</option>
                                    <option value="monthly">Mensal</option>
                                </select>
                            </div>
                        </div>

                        <!-- WooCommerce -->
                        <template v-if="sourceForm.type === 'woocommerce'">
                            <div>
                                <label class="text-sm text-gray-400">Conexao WooCommerce</label>
                                <select v-model="sourceForm.analytics_connection_id" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                                    <option value="">Selecione...</option>
                                    <option v-for="c in wcConnections" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm text-gray-400">Minimo de Pedidos</label>
                                <input v-model.number="sourceForm.min_orders" type="number" min="0" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                            </div>
                        </template>

                        <!-- ===== MySQL Dinâmico ===== -->
                        <template v-if="sourceForm.type === 'mysql'">
                            <!-- Step Indicator -->
                            <div class="flex items-center gap-2 mb-2">
                                <div v-for="s in 3" :key="s" :class="['flex items-center gap-1.5', s <= mysqlStep ? 'text-indigo-400' : 'text-gray-600']">
                                    <span :class="['w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold border', s < mysqlStep ? 'bg-indigo-600 border-indigo-500 text-white' : s === mysqlStep ? 'border-indigo-500 text-indigo-400' : 'border-gray-700 text-gray-600']">{{ s }}</span>
                                    <span class="text-xs font-medium">{{ s === 1 ? 'Conexao' : s === 2 ? 'Tabela' : 'Colunas' }}</span>
                                    <span v-if="s < 3" class="w-6 h-px" :class="s < mysqlStep ? 'bg-indigo-500' : 'bg-gray-700'"></span>
                                </div>
                            </div>

                            <!-- Step 1: Credenciais de conexão -->
                            <div v-if="mysqlStep >= 1" :class="mysqlStep > 1 ? 'opacity-60 pointer-events-none' : ''">
                                <div class="bg-gray-800/50 rounded-xl p-4 space-y-3 border border-gray-700/50">
                                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wider">Credenciais do Banco</p>
                                    <div class="grid grid-cols-3 gap-3">
                                        <div class="col-span-2">
                                            <label class="text-xs text-gray-500">Host</label>
                                            <input v-model="sourceForm.host" placeholder="ex: 192.168.1.100" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                                        </div>
                                        <div>
                                            <label class="text-xs text-gray-500">Porta</label>
                                            <input v-model.number="sourceForm.port" type="number" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500">Database</label>
                                        <input v-model="sourceForm.database" placeholder="nome_do_banco" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="text-xs text-gray-500">Usuario</label>
                                            <input v-model="sourceForm.username" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                                        </div>
                                        <div>
                                            <label class="text-xs text-gray-500">Senha</label>
                                            <input v-model="sourceForm.password" type="password" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm" />
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 mt-3" v-if="mysqlStep === 1">
                                    <button type="button" @click="mysqlConnect" :disabled="mysqlConnecting || !sourceForm.host || !sourceForm.database || !sourceForm.username"
                                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition disabled:opacity-50 flex items-center gap-2">
                                        <svg v-if="mysqlConnecting" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                        {{ mysqlConnecting ? 'Conectando...' : 'Conectar e Listar Tabelas' }}
                                    </button>
                                </div>
                            </div>

                            <!-- Conectado badge -->
                            <div v-if="mysqlStep > 1" class="flex items-center justify-between">
                                <div class="flex items-center gap-2 text-sm text-emerald-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    Conectado a <span class="font-mono text-emerald-300">{{ sourceForm.database }}</span> ({{ mysqlTables.length }} tabelas)
                                </div>
                                <button type="button" @click="resetMysqlState()" class="text-xs text-gray-500 hover:text-white">Reconectar</button>
                            </div>

                            <!-- Step 2: Selecionar tabela -->
                            <div v-if="mysqlStep >= 2">
                                <div class="bg-gray-800/50 rounded-xl border border-gray-700/50 p-4">
                                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-3">Selecione uma Tabela</p>
                                    <div class="relative">
                                        <input v-model="mysqlTableSearch" placeholder="Buscar tabela..." class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm mb-2" />
                                    </div>
                                    <div class="max-h-48 overflow-y-auto space-y-1 custom-scrollbar">
                                        <button v-for="t in filteredMysqlTables" :key="t" type="button" @click="mysqlSelectTable(t)"
                                            :class="['w-full text-left px-3 py-2 rounded-lg text-sm transition flex items-center justify-between group',
                                                sourceForm.table === t ? 'bg-indigo-600/20 text-indigo-300 border border-indigo-500/30' : 'text-gray-300 hover:bg-gray-700/50']">
                                            <span class="flex items-center gap-2">
                                                <svg class="w-4 h-4 text-gray-500 group-hover:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 0v1.5c0 .621-.504 1.125-1.125 1.125" /></svg>
                                                <span class="font-mono">{{ t }}</span>
                                            </span>
                                            <svg v-if="sourceForm.table === t" class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                        </button>
                                    </div>
                                    <div v-if="mysqlLoadingColumns" class="flex items-center gap-2 mt-3 text-xs text-gray-400">
                                        <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                        Carregando colunas...
                                    </div>
                                </div>
                            </div>

                            <!-- Step 3: Mapeamento de colunas -->
                            <div v-if="mysqlStep === 3 && mysqlColumns.length">
                                <div class="bg-gray-800/50 rounded-xl border border-gray-700/50 p-4 space-y-4">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-xs font-medium text-gray-400 uppercase tracking-wider">Mapeamento de Colunas</p>
                                            <p class="text-xs text-gray-500 mt-0.5">Tabela <span class="font-mono text-indigo-400">{{ sourceForm.table }}</span> · {{ mysqlTotalRows.toLocaleString('pt-BR') }} registros · {{ mysqlColumns.length }} colunas</p>
                                        </div>
                                        <button type="button" @click="mysqlShowSample = !mysqlShowSample" class="text-xs text-indigo-400 hover:text-indigo-300 transition">
                                            {{ mysqlShowSample ? 'Esconder amostra' : 'Ver amostra' }}
                                        </button>
                                    </div>

                                    <!-- Amostra de dados -->
                                    <div v-if="mysqlShowSample && mysqlSample.length" class="overflow-x-auto">
                                        <table class="w-full text-xs">
                                            <thead><tr class="text-gray-500 border-b border-gray-700">
                                                <th v-for="col in mysqlColumns.slice(0, 8)" :key="col.COLUMN_NAME" class="text-left py-1.5 px-2 font-medium whitespace-nowrap">{{ col.COLUMN_NAME }}</th>
                                            </tr></thead>
                                            <tbody>
                                                <tr v-for="(row, i) in mysqlSample" :key="i" class="border-b border-gray-800/50">
                                                    <td v-for="col in mysqlColumns.slice(0, 8)" :key="col.COLUMN_NAME" class="py-1.5 px-2 text-gray-400 truncate max-w-[150px]">{{ row[col.COLUMN_NAME] ?? '-' }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <p v-if="mysqlColumns.length > 8" class="text-[10px] text-gray-600 mt-1">Mostrando 8 de {{ mysqlColumns.length }} colunas</p>
                                    </div>

                                    <!-- Mapeamento -->
                                    <div class="space-y-3">
                                        <p class="text-xs text-gray-500">Selecione qual coluna corresponde a cada campo. A IA pre-selecionou colunas provaveis.</p>
                                        <div class="grid grid-cols-1 gap-2.5">
                                            <!-- Email (obrigatório) -->
                                            <div class="flex items-center gap-3 bg-gray-800/80 rounded-lg px-3 py-2.5 border border-gray-700/50">
                                                <span class="text-xs font-medium text-red-400 w-24 shrink-0">Email *</span>
                                                <select v-model="sourceForm.email_column" class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-2.5 py-1.5 text-white text-sm">
                                                    <option value="">-- Selecione --</option>
                                                    <option v-for="col in mysqlColumns" :key="col.COLUMN_NAME" :value="col.COLUMN_NAME">
                                                        {{ columnTypeIcon(col.DATA_TYPE) }} {{ col.COLUMN_NAME }} ({{ col.COLUMN_TYPE }})
                                                    </option>
                                                </select>
                                                <span v-if="mysqlSuggestions.email === sourceForm.email_column && sourceForm.email_column" class="text-[10px] text-emerald-400 shrink-0">auto</span>
                                            </div>

                                            <!-- Nome -->
                                            <div class="flex items-center gap-3 bg-gray-800/80 rounded-lg px-3 py-2.5 border border-gray-700/50">
                                                <span class="text-xs font-medium text-gray-400 w-24 shrink-0">Nome</span>
                                                <select v-model="sourceForm.name_columns.first_name" class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-2.5 py-1.5 text-white text-sm">
                                                    <option value="">-- Nenhum --</option>
                                                    <option v-for="col in mysqlColumns" :key="col.COLUMN_NAME" :value="col.COLUMN_NAME">
                                                        {{ columnTypeIcon(col.DATA_TYPE) }} {{ col.COLUMN_NAME }} ({{ col.COLUMN_TYPE }})
                                                    </option>
                                                </select>
                                                <span v-if="mysqlSuggestions.first_name === sourceForm.name_columns.first_name && sourceForm.name_columns.first_name" class="text-[10px] text-emerald-400 shrink-0">auto</span>
                                            </div>

                                            <!-- Sobrenome -->
                                            <div class="flex items-center gap-3 bg-gray-800/80 rounded-lg px-3 py-2.5 border border-gray-700/50">
                                                <span class="text-xs font-medium text-gray-400 w-24 shrink-0">Sobrenome</span>
                                                <select v-model="sourceForm.name_columns.last_name" class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-2.5 py-1.5 text-white text-sm">
                                                    <option value="">-- Nenhum --</option>
                                                    <option v-for="col in mysqlColumns" :key="col.COLUMN_NAME" :value="col.COLUMN_NAME">
                                                        {{ columnTypeIcon(col.DATA_TYPE) }} {{ col.COLUMN_NAME }} ({{ col.COLUMN_TYPE }})
                                                    </option>
                                                </select>
                                                <span v-if="mysqlSuggestions.last_name === sourceForm.name_columns.last_name && sourceForm.name_columns.last_name" class="text-[10px] text-emerald-400 shrink-0">auto</span>
                                            </div>

                                            <!-- Telefone -->
                                            <div class="flex items-center gap-3 bg-gray-800/80 rounded-lg px-3 py-2.5 border border-gray-700/50">
                                                <span class="text-xs font-medium text-gray-400 w-24 shrink-0">Telefone</span>
                                                <select v-model="sourceForm.name_columns.phone" class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-2.5 py-1.5 text-white text-sm">
                                                    <option value="">-- Nenhum --</option>
                                                    <option v-for="col in mysqlColumns" :key="col.COLUMN_NAME" :value="col.COLUMN_NAME">
                                                        {{ columnTypeIcon(col.DATA_TYPE) }} {{ col.COLUMN_NAME }} ({{ col.COLUMN_TYPE }})
                                                    </option>
                                                </select>
                                                <span v-if="mysqlSuggestions.phone === sourceForm.name_columns.phone && sourceForm.name_columns.phone" class="text-[10px] text-emerald-400 shrink-0">auto</span>
                                            </div>

                                            <!-- Empresa -->
                                            <div class="flex items-center gap-3 bg-gray-800/80 rounded-lg px-3 py-2.5 border border-gray-700/50">
                                                <span class="text-xs font-medium text-gray-400 w-24 shrink-0">Empresa</span>
                                                <select v-model="sourceForm.name_columns.company" class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-2.5 py-1.5 text-white text-sm">
                                                    <option value="">-- Nenhum --</option>
                                                    <option v-for="col in mysqlColumns" :key="col.COLUMN_NAME" :value="col.COLUMN_NAME">
                                                        {{ columnTypeIcon(col.DATA_TYPE) }} {{ col.COLUMN_NAME }} ({{ col.COLUMN_TYPE }})
                                                    </option>
                                                </select>
                                                <span v-if="mysqlSuggestions.company === sourceForm.name_columns.company && sourceForm.name_columns.company" class="text-[10px] text-emerald-400 shrink-0">auto</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Filtro WHERE opcional -->
                                    <div>
                                        <label class="text-xs text-gray-500">Filtro WHERE (opcional)</label>
                                        <input v-model="sourceForm.where_clause" placeholder="ex: status = 'active' AND created_at > '2025-01-01'" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm font-mono text-xs" />
                                    </div>
                                </div>
                            </div>

                            <!-- Erro MySQL -->
                            <div v-if="mysqlError" class="px-4 py-3 rounded-lg bg-red-900/30 border border-red-700/50 text-red-300 text-sm">
                                {{ mysqlError }}
                            </div>
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
                            <button v-if="sourceForm.type !== 'mysql' || mysqlStep === 3" type="submit"
                                :disabled="sourceForm.processing || (sourceForm.type === 'mysql' && !sourceForm.email_column)"
                                class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium disabled:opacity-50 hover:bg-indigo-500 transition">
                                {{ sourceForm.processing ? 'Salvando...' : 'Adicionar Fonte' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
