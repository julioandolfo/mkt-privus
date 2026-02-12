<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';

interface Connection {
    id: number;
    platform: string;
    platform_label: string;
    platform_color: string;
    name: string;
    external_name: string | null;
    external_id: string;
    is_active: boolean;
    sync_status: string;
    sync_error: string | null;
    last_synced_at: string | null;
    created_at: string;
    user_name: string;
    config: any;
}

interface DiscoveredAccount {
    id: string;
    name: string;
    account_name?: string;
    business_name?: string;
    currency?: string;
    permission?: string;
}

interface ManualEntry {
    id: number;
    platform: string;
    platform_label: string;
    platform_custom: string | null;
    date_start: string;
    date_end: string;
    date_start_display: string;
    date_end_display: string;
    amount: number;
    daily_amount: number;
    period_days: number;
    description: string | null;
    user_name: string;
    created_at: string;
}

const props = defineProps<{
    brand: any;
    brands: any[];
    connections: Connection[];
    oauthConfigured: Record<string, boolean>;
    discoveredAccounts: DiscoveredAccount[];
    discoveredPlatform: string | null;
    discoveryToken: string | null;
    platforms: Record<string, string>;
    platformColors: Record<string, string>;
    manualEntries: ManualEntry[];
    manualPlatforms: Record<string, string>;
}>();

const showManualForm = ref(false);
const showDiscoveredModal = ref(props.discoveredAccounts?.length > 0);
const selectedAccounts = ref<string[]>([]);
const syncingId = ref<number | null>(null);
const connectingPlatform = ref<string | null>(null);
const currentDiscoveryToken = ref<string | null>(props.discoveryToken ?? null);
const discoveredAccountsLocal = ref<DiscoveredAccount[]>(props.discoveredAccounts ?? []);
const discoveredPlatformLocal = ref<string | null>(props.discoveredPlatform ?? null);

// WooCommerce form
const showWcForm = ref(false);
const wcTesting = ref(false);
const wcTestResult = ref<{success: boolean; message: string} | null>(null);
const wcAvailableStatuses = ref<{slug: string; name: string; total?: number}[]>([]);
const wcLoadingStatuses = ref(false);
const wcForm = useForm({
    brand_id: props.brand?.id,
    platform: 'woocommerce',
    name: '',
    store_url: '',
    consumer_key: '',
    consumer_secret: '',
    order_statuses: [] as string[],
});

// Modal de edição de status WooCommerce para conexões existentes
const showWcStatusModal = ref(false);
const wcStatusEditConnection = ref<Connection | null>(null);
const wcStatusEditStatuses = ref<string[]>([]);
const wcStatusEditAvailable = ref<{slug: string; name: string; total?: number}[]>([]);
const wcStatusEditLoading = ref(false);
const wcStatusEditSaving = ref(false);

// Manual entry form
const showManualEntryForm = ref(false);
const editingEntry = ref<ManualEntry | null>(null);
const manualEntryForm = useForm({
    brand_id: props.brand?.id,
    platform: 'google_ads',
    platform_label: '',
    date_start: '',
    date_end: '',
    amount: 0,
    description: '',
});

const manualForm = useForm({
    brand_id: props.brand?.id,
    platform: 'google_analytics',
    name: '',
    external_id: '',
    access_token: '',
    refresh_token: '',
});

const guideTips = [
    'Conecte via OAuth para autenticação segura e automática',
    'Google Analytics, Ads e Search Console usam a mesma conta Google OAuth',
    'Adicione investimentos manuais quando não tiver API conectada (ex: Google Ads sem Developer Token)',
    'Conecte WooCommerce para calcular ROAS real baseado em vendas efetivas',
    'Sincronize regularmente para manter os dados atualizados',
];

const manualTotalAmount = computed(() => {
    return (props.manualEntries || []).reduce((sum: number, e: ManualEntry) => sum + e.amount, 0);
});

function testWcConnection() {
    wcTesting.value = true;
    wcTestResult.value = null;

    fetch(route('analytics.connections.test-woocommerce'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
        },
        body: JSON.stringify({
            store_url: wcForm.store_url,
            consumer_key: wcForm.consumer_key,
            consumer_secret: wcForm.consumer_secret,
        }),
    })
    .then(r => r.json())
    .then(data => {
        wcTestResult.value = {
            success: data.success,
            message: data.success
                ? `Conectado! WooCommerce ${data.wc_version} • Moeda: ${data.currency}`
                : (data.error || 'Falha na conexão'),
        };
        // Ao conectar com sucesso, buscar status disponíveis automaticamente
        if (data.success) {
            fetchWcStatuses();
        }
    })
    .catch(() => {
        wcTestResult.value = { success: false, message: 'Erro de rede ao testar conexão' };
    })
    .finally(() => wcTesting.value = false);
}

function fetchWcStatuses() {
    if (!wcForm.store_url || !wcForm.consumer_key || !wcForm.consumer_secret) return;
    wcLoadingStatuses.value = true;
    wcAvailableStatuses.value = [];

    const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

    fetch(route('analytics.connections.woocommerce-statuses'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify({
            store_url: wcForm.store_url,
            consumer_key: wcForm.consumer_key,
            consumer_secret: wcForm.consumer_secret,
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.statuses) {
            wcAvailableStatuses.value = data.statuses;
            // Se o usuário ainda não selecionou status, marcar os padrão
            if (wcForm.order_statuses.length === 0 && data.default_statuses) {
                wcForm.order_statuses = [...data.default_statuses];
            }
        }
    })
    .catch(() => {})
    .finally(() => wcLoadingStatuses.value = false);
}

function toggleWcStatus(slug: string) {
    const idx = wcForm.order_statuses.indexOf(slug);
    if (idx >= 0) {
        wcForm.order_statuses.splice(idx, 1);
    } else {
        wcForm.order_statuses.push(slug);
    }
}

function submitWcForm() {
    wcForm.post(route('analytics.connections.store'), {
        onSuccess: () => {
            showWcForm.value = false;
            wcForm.reset();
            wcTestResult.value = null;
            wcAvailableStatuses.value = [];
        },
    });
}

// ===== Edição de status WooCommerce para conexões existentes =====
function openWcStatusEditor(conn: Connection) {
    wcStatusEditConnection.value = conn;
    wcStatusEditStatuses.value = [...(conn.config?.order_statuses || ['completed', 'processing', 'on-hold'])];
    wcStatusEditAvailable.value = [];
    showWcStatusModal.value = true;

    // Buscar status disponíveis da loja
    wcStatusEditLoading.value = true;
    const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

    fetch(route('analytics.connections.woocommerce-statuses'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify({
            store_url: conn.config?.store_url || '',
            consumer_key: conn.config?.consumer_key || '',
            consumer_secret: conn.config?.consumer_secret || '',
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.statuses) {
            wcStatusEditAvailable.value = data.statuses;
        }
    })
    .catch(() => {})
    .finally(() => wcStatusEditLoading.value = false);
}

function toggleWcStatusEdit(slug: string) {
    const idx = wcStatusEditStatuses.value.indexOf(slug);
    if (idx >= 0) {
        wcStatusEditStatuses.value.splice(idx, 1);
    } else {
        wcStatusEditStatuses.value.push(slug);
    }
}

function addCustomStatus() {
    const input = document.querySelector('[ref="customStatusInput"]') as HTMLInputElement
        || document.querySelector('input[placeholder="ex: pagamento-aprovado"]') as HTMLInputElement;
    if (!input || !input.value.trim()) return;

    const slug = input.value.trim().toLowerCase().replace(/\s+/g, '-').replace(/^wc-/, '');

    if (showWcStatusModal.value) {
        if (!wcStatusEditStatuses.value.includes(slug)) {
            wcStatusEditStatuses.value.push(slug);
            // Adicionar aos disponíveis também para visualizar
            if (!wcStatusEditAvailable.value.find(s => s.slug === slug)) {
                wcStatusEditAvailable.value.push({ slug, name: slug, total: undefined });
            }
        }
    } else {
        if (!wcForm.order_statuses.includes(slug)) {
            wcForm.order_statuses.push(slug);
            if (!wcAvailableStatuses.value.find(s => s.slug === slug)) {
                wcAvailableStatuses.value.push({ slug, name: slug, total: undefined });
            }
        }
    }
    input.value = '';
}

function saveWcStatuses() {
    if (!wcStatusEditConnection.value || wcStatusEditStatuses.value.length === 0) return;
    wcStatusEditSaving.value = true;
    const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

    fetch(route('analytics.connections.update-woocommerce-statuses', { connection: wcStatusEditConnection.value.id }), {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify({ order_statuses: wcStatusEditStatuses.value }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showWcStatusModal.value = false;
            router.reload();
        }
    })
    .catch(() => {})
    .finally(() => wcStatusEditSaving.value = false);
}

function openManualEntryForm(entry?: ManualEntry) {
    if (entry) {
        editingEntry.value = entry;
        manualEntryForm.platform = entry.platform;
        manualEntryForm.platform_label = entry.platform_custom || '';
        manualEntryForm.date_start = entry.date_start;
        manualEntryForm.date_end = entry.date_end;
        manualEntryForm.amount = entry.amount;
        manualEntryForm.description = entry.description || '';
    } else {
        editingEntry.value = null;
        manualEntryForm.reset();
        manualEntryForm.brand_id = props.brand?.id;
        manualEntryForm.platform = 'google_ads';
    }
    showManualEntryForm.value = true;
}

function submitManualEntry() {
    if (editingEntry.value) {
        manualEntryForm.put(route('analytics.manual-entries.update', editingEntry.value.id), {
            onSuccess: () => { showManualEntryForm.value = false; editingEntry.value = null; },
        });
    } else {
        manualEntryForm.post(route('analytics.manual-entries.store'), {
            onSuccess: () => { showManualEntryForm.value = false; manualEntryForm.reset(); manualEntryForm.brand_id = props.brand?.id; manualEntryForm.platform = 'google_ads'; },
        });
    }
}

function deleteManualEntry(id: number) {
    if (confirm('Remover este investimento? Os cálculos de ROAS serão recalculados.')) {
        router.delete(route('analytics.manual-entries.destroy', id), { preserveScroll: true });
    }
}

const platformCards = [
    {
        key: 'google_analytics',
        name: 'Google Analytics 4',
        description: 'Tráfego do site, sessões, comportamento do usuário',
        icon: 'ga',
        color: '#F57C00',
        bgColor: 'bg-orange-500/10',
    },
    {
        key: 'google_ads',
        name: 'Google Ads',
        description: 'Campanhas, investimento, conversões, ROAS',
        icon: 'gads',
        color: '#4285F4',
        bgColor: 'bg-blue-500/10',
    },
    {
        key: 'google_search_console',
        name: 'Google Search Console',
        description: 'Posição orgânica, impressões, CTR, queries',
        icon: 'gsc',
        color: '#34A853',
        bgColor: 'bg-green-500/10',
    },
    {
        key: 'meta_ads',
        name: 'Meta Ads',
        description: 'Facebook & Instagram Ads, alcance, engajamento',
        icon: 'meta',
        color: '#1877F2',
        bgColor: 'bg-blue-600/10',
    },
    {
        key: 'woocommerce',
        name: 'WooCommerce',
        description: 'Pedidos, receita, ticket médio, ROAS real',
        icon: 'wc',
        color: '#96588A',
        bgColor: 'bg-purple-500/10',
        isManual: true,
    },
];

function connectOAuth(platform: string) {
    connectingPlatform.value = platform;

    // Buscar URL OAuth via API e abrir popup
    const params = new URLSearchParams({
        brand_id: String(props.brand?.id || ''),
        popup: '1',
    });

    fetch(route('analytics.oauth.redirect', platform) + '?' + params.toString(), {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })
    .then(res => res.json())
    .then(data => {
        if (data.url) {
            // Abrir popup centralizada
            const width = 600;
            const height = 700;
            const left = window.screenX + (window.outerWidth - width) / 2;
            const top = window.screenY + (window.outerHeight - height) / 2;
            const features = `width=${width},height=${height},left=${left},top=${top},toolbar=no,menubar=no,scrollbars=yes,resizable=yes`;

            const popup = window.open(data.url, 'oauth_popup', features);

            // Monitorar se o popup foi fechado manualmente
            const checkClosed = setInterval(() => {
                if (popup && popup.closed) {
                    clearInterval(checkClosed);
                    connectingPlatform.value = null;
                }
            }, 500);
        }
    })
    .catch(() => {
        connectingPlatform.value = null;
    });
}

// Listener para mensagens do popup OAuth
function handleOAuthMessage(event: MessageEvent) {
    if (event.data?.type !== 'oauth_callback') return;

    connectingPlatform.value = null;

    if (event.data.status === 'success') {
        const token = event.data.discoveryToken;

        if (token) {
            // Buscar contas descobertas via API usando o token
            currentDiscoveryToken.value = token;
            fetch(route('analytics.oauth.discovered') + '?token=' + token, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            .then(res => res.json())
            .then(data => {
                if (data.accounts?.length > 0) {
                    discoveredAccountsLocal.value = data.accounts;
                    discoveredPlatformLocal.value = data.platform;
                    currentDiscoveryToken.value = data.token;
                    showDiscoveredModal.value = true;
                } else {
                    // Fallback: recarregar a pagina
                    router.reload({ preserveScroll: true });
                }
            })
            .catch(() => {
                router.reload({ preserveScroll: true });
            });
        } else {
            // Sem token, recarregar a página
            router.reload({ preserveScroll: true });
        }
    }
}

onMounted(() => {
    window.addEventListener('message', handleOAuthMessage);
});

onUnmounted(() => {
    window.removeEventListener('message', handleOAuthMessage);
});

function toggleAccountSelection(id: string) {
    const idx = selectedAccounts.value.indexOf(id);
    if (idx >= 0) selectedAccounts.value.splice(idx, 1);
    else selectedAccounts.value.push(id);
}

function saveSelectedAccounts() {
    const allAccounts = discoveredAccountsLocal.value.length > 0
        ? discoveredAccountsLocal.value
        : props.discoveredAccounts;

    const accounts = allAccounts
        .filter(a => selectedAccounts.value.includes(a.id))
        .map(a => ({ id: a.id, name: a.name, account_name: a.account_name }));

    router.post(route('analytics.oauth.save'), {
        brand_id: props.brand?.id,
        accounts,
        discovery_token: currentDiscoveryToken.value || props.discoveryToken,
    }, {
        onSuccess: () => {
            showDiscoveredModal.value = false;
            selectedAccounts.value = [];
            discoveredAccountsLocal.value = [];
            currentDiscoveryToken.value = null;
        },
    });
}

function submitManualForm() {
    manualForm.post(route('analytics.connections.store'), {
        onSuccess: () => {
            showManualForm.value = false;
            manualForm.reset();
        },
    });
}

function syncConnection(id: number) {
    syncingId.value = id;
    router.post(route('analytics.connections.sync', id), {}, {
        preserveScroll: true,
        onFinish: () => syncingId.value = null,
    });
}

function toggleConnection(id: number) {
    router.post(route('analytics.connections.toggle', id), {}, { preserveScroll: true });
}

function deleteConnection(id: number) {
    if (confirm('Remover esta conexão? Os dados sincronizados serão perdidos.')) {
        router.delete(route('analytics.connections.destroy', id), { preserveScroll: true });
    }
}

function getStatusLabel(status: string): string {
    return { pending: 'Pendente', syncing: 'Sincronizando', success: 'Sincronizado', error: 'Erro' }[status] || status;
}

function getStatusColor(status: string): string {
    return { pending: 'text-gray-400', syncing: 'text-amber-400', success: 'text-emerald-400', error: 'text-red-400' }[status] || 'text-gray-400';
}

function isConnected(platform: string): boolean {
    return props.connections.some(c => c.platform === platform && c.is_active);
}
</script>

<template>
    <Head title="Analytics - Conexões" />
    <AuthenticatedLayout>
        <div class="p-4 lg:p-6 space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <Link :href="route('analytics.index')" class="text-gray-400 hover:text-white transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M15 19l-7-7 7-7"/></svg>
                        </Link>
                        <h1 class="text-2xl font-bold text-white">Conexões de Analytics</h1>
                    </div>
                    <p class="text-sm text-gray-400 mt-1 ml-8">
                        Gerencie as integrações com plataformas de dados
                        <span v-if="brand" class="text-indigo-400">• {{ brand.name }}</span>
                    </p>
                </div>
                <button @click="showManualForm = true" class="flex items-center gap-2 px-4 py-2 bg-gray-800 hover:bg-gray-700 text-white rounded-xl text-sm transition border border-gray-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
                    Adicionar Manual
                </button>
            </div>

            <!-- Guide -->
            <GuideBox
                title="Integrações de Analytics"
                description="Conecte suas plataformas de analytics para centralizar dados de tráfego, ads e SEO no MKT Privus."
                :tips="guideTips"
                color="blue"
                storage-key="analytics-connections-guide"
            />

            <!-- Platform Cards (Connect) -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div
                    v-for="platform in platformCards"
                    :key="platform.key"
                    class="bg-gray-900/50 rounded-2xl border border-gray-800 p-5 hover:border-gray-700 transition"
                >
                    <div class="flex items-start justify-between mb-4">
                        <div :class="['w-12 h-12 rounded-xl flex items-center justify-center', platform.bgColor]">
                            <!-- GA4 -->
                            <svg v-if="platform.icon === 'ga'" class="w-6 h-6" viewBox="0 0 24 24" fill="none">
                                <path d="M20 12v6a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h6" :stroke="platform.color" stroke-width="2" stroke-linecap="round"/>
                                <path d="M8 16V13M12 16V10M16 16V7M20 4v4M20 4h-4M20 4l-5 5" :stroke="platform.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <!-- Google Ads -->
                            <svg v-else-if="platform.icon === 'gads'" class="w-6 h-6" viewBox="0 0 24 24" fill="none">
                                <path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 9v1" :stroke="platform.color" stroke-width="2" stroke-linecap="round"/>
                                <circle cx="12" cy="12" r="9" :stroke="platform.color" stroke-width="2"/>
                            </svg>
                            <!-- GSC -->
                            <svg v-else-if="platform.icon === 'gsc'" class="w-6 h-6" viewBox="0 0 24 24" fill="none">
                                <circle cx="11" cy="11" r="7" :stroke="platform.color" stroke-width="2"/>
                                <path d="M21 21l-4.35-4.35" :stroke="platform.color" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            <!-- WooCommerce -->
                            <svg v-else-if="platform.icon === 'wc'" class="w-6 h-6" viewBox="0 0 24 24" fill="none">
                                <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z" :stroke="platform.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M3 6h18" :stroke="platform.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M16 10a4 4 0 01-8 0" :stroke="platform.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <!-- Meta -->
                            <svg v-else class="w-6 h-6" viewBox="0 0 24 24" fill="none">
                                <path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z" :stroke="platform.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <span
                            v-if="isConnected(platform.key)"
                            class="flex items-center gap-1.5 px-2 py-0.5 bg-emerald-500/10 rounded-full"
                        >
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"/>
                            <span class="text-[10px] text-emerald-400 font-medium">Conectado</span>
                        </span>
                    </div>

                    <h3 class="text-sm font-semibold text-white mb-1">{{ platform.name }}</h3>
                    <p class="text-xs text-gray-500 mb-4">{{ platform.description }}</p>

                    <!-- WooCommerce: formulario manual -->
                    <button
                        v-if="platform.isManual"
                        @click="showWcForm = true"
                        class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs font-medium transition"
                        :class="isConnected(platform.key)
                            ? 'bg-gray-800 hover:bg-gray-700 text-gray-300 border border-gray-700'
                            : 'text-white hover:opacity-90'"
                        :style="!isConnected(platform.key) ? { backgroundColor: platform.color } : {}"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
                        {{ isConnected(platform.key) ? 'Adicionar outra loja' : 'Conectar loja' }}
                    </button>
                    <!-- OAuth platforms -->
                    <button
                        v-else-if="oauthConfigured[platform.key]"
                        @click="connectOAuth(platform.key)"
                        :disabled="connectingPlatform === platform.key"
                        class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs font-medium transition"
                        :class="connectingPlatform === platform.key
                            ? 'bg-gray-800 text-indigo-400 border border-indigo-500/30 cursor-wait'
                            : isConnected(platform.key)
                                ? 'bg-gray-800 hover:bg-gray-700 text-gray-300 border border-gray-700'
                                : 'text-white hover:opacity-90'"
                        :style="!isConnected(platform.key) && connectingPlatform !== platform.key ? { backgroundColor: platform.color } : {}"
                    >
                        <svg v-if="connectingPlatform === platform.key" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                        </svg>
                        <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                        {{ connectingPlatform === platform.key ? 'Aguardando login...' : isConnected(platform.key) ? 'Reconectar' : 'Conectar conta' }}
                    </button>
                    <Link
                        v-else
                        :href="route('settings.index') + '?tab=oauth'"
                        class="w-full flex items-center justify-center gap-2 px-3 py-2 bg-gray-800 hover:bg-gray-700 rounded-xl text-xs text-amber-400 border border-gray-700 hover:border-amber-500/30 transition cursor-pointer"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Configurar OAuth
                    </Link>
                </div>
            </div>

            <!-- Connected Accounts List -->
            <div v-if="connections.length > 0" class="bg-gray-900/50 rounded-2xl border border-gray-800 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-800">
                    <h3 class="text-sm font-semibold text-white">Conexões Ativas ({{ connections.length }})</h3>
                </div>
                <div class="divide-y divide-gray-800/50">
                    <div v-for="conn in connections" :key="conn.id" class="p-4 flex items-center gap-4 hover:bg-gray-800/30 transition">
                        <div class="w-3 h-3 rounded-full shrink-0" :style="{ backgroundColor: conn.platform_color }"/>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium text-white truncate">{{ conn.name }}</p>
                                <span class="px-2 py-0.5 bg-gray-800 rounded text-[10px] text-gray-400">{{ conn.platform_label }}</span>
                            </div>
                            <div class="flex items-center gap-3 mt-1">
                                <span class="text-[10px] text-gray-500">ID: {{ conn.external_id }}</span>
                                <span class="text-[10px] text-gray-600">•</span>
                                <span class="text-[10px] text-gray-500">Adicionada {{ conn.created_at }}</span>
                                <span class="text-[10px] text-gray-600">•</span>
                                <span class="text-[10px] text-gray-500">Por {{ conn.user_name }}</span>
                            </div>
                            <div v-if="conn.platform === 'woocommerce' && conn.config?.order_statuses?.length > 0" class="flex items-center gap-1.5 mt-1 flex-wrap">
                                <span class="text-[10px] text-purple-400">Status:</span>
                                <span
                                    v-for="st in conn.config.order_statuses"
                                    :key="st"
                                    class="px-1.5 py-0.5 bg-purple-500/10 text-purple-300 rounded text-[9px] font-mono border border-purple-500/20"
                                >{{ st }}</span>
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="flex items-center gap-2">
                            <span
                                :class="[
                                    'w-2 h-2 rounded-full',
                                    conn.sync_status === 'success' ? 'bg-emerald-400' :
                                    conn.sync_status === 'syncing' ? 'bg-amber-400 animate-pulse' :
                                    conn.sync_status === 'error' ? 'bg-red-400' : 'bg-gray-600'
                                ]"
                            />
                            <div class="text-right">
                                <p :class="['text-[11px] font-medium', getStatusColor(conn.sync_status)]">
                                    {{ getStatusLabel(conn.sync_status) }}
                                </p>
                                <p v-if="conn.last_synced_at" class="text-[10px] text-gray-600">{{ conn.last_synced_at }}</p>
                            </div>
                        </div>

                        <!-- Error tooltip -->
                        <div v-if="conn.sync_error" class="text-red-500 cursor-help" :title="conn.sync_error">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-1">
                            <!-- Botão de configurar status (apenas WooCommerce) -->
                            <button
                                v-if="conn.platform === 'woocommerce'"
                                @click="openWcStatusEditor(conn)"
                                class="p-2 text-gray-400 hover:text-purple-400 transition rounded-lg hover:bg-gray-800"
                                title="Configurar Status de Pedido"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                            <button
                                @click="syncConnection(conn.id)"
                                :disabled="syncingId === conn.id"
                                class="p-2 text-gray-400 hover:text-indigo-400 transition rounded-lg hover:bg-gray-800"
                                title="Sincronizar"
                            >
                                <svg :class="['w-4 h-4', { 'animate-spin': syncingId === conn.id }]" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                            </button>
                            <button
                                @click="toggleConnection(conn.id)"
                                class="p-2 transition rounded-lg hover:bg-gray-800"
                                :class="conn.is_active ? 'text-emerald-400 hover:text-amber-400' : 'text-gray-600 hover:text-emerald-400'"
                                :title="conn.is_active ? 'Desativar' : 'Ativar'"
                            >
                                <svg v-if="conn.is_active" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                            </button>
                            <button
                                @click="deleteConnection(conn.id)"
                                class="p-2 text-gray-400 hover:text-red-400 transition rounded-lg hover:bg-gray-800"
                                title="Remover"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Manual Form Modal -->
            <div v-if="showManualForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showManualForm = false">
                <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 w-full max-w-md mx-4">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-white">Adicionar Conexão Manual</h3>
                        <button @click="showManualForm = false" class="text-gray-400 hover:text-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                    </div>

                    <form @submit.prevent="submitManualForm" class="space-y-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Plataforma</label>
                            <select v-model="manualForm.platform" class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <option v-for="(label, key) in platforms" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Nome</label>
                            <input v-model="manualForm.name" type="text" placeholder="Ex: GA4 - Site Principal" class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">ID Externo (Property ID, Account ID, URL)</label>
                            <input v-model="manualForm.external_id" type="text" placeholder="Ex: 123456789" class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Access Token (opcional)</label>
                            <textarea v-model="manualForm.access_token" rows="2" class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500"/>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Refresh Token (opcional)</label>
                            <textarea v-model="manualForm.refresh_token" rows="2" class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500"/>
                        </div>

                        <div class="flex gap-3">
                            <button type="button" @click="showManualForm = false" class="flex-1 px-4 py-2 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl text-sm transition border border-gray-700">Cancelar</button>
                            <button type="submit" :disabled="manualForm.processing" class="flex-1 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium transition">
                                {{ manualForm.processing ? 'Salvando...' : 'Adicionar' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Investimentos Manuais -->
            <div class="bg-gray-900/50 rounded-2xl border border-gray-800 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-white">Investimentos Manuais</h3>
                        <p class="text-[10px] text-gray-500 mt-0.5">Registre gastos de qualquer plataforma. O valor é distribuído por dia no cálculo de ROAS.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span v-if="manualTotalAmount > 0" class="text-xs text-amber-400 font-medium">
                            Total: R$ {{ manualTotalAmount.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}
                        </span>
                        <button @click="openManualEntryForm()" class="flex items-center gap-1.5 px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-medium transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
                            Novo Investimento
                        </button>
                    </div>
                </div>

                <div v-if="manualEntries && manualEntries.length > 0" class="divide-y divide-gray-800/50">
                    <div v-for="entry in manualEntries" :key="entry.id" class="p-4 flex items-center gap-4 hover:bg-gray-800/30 transition">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 9v1m9-5a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium text-white">{{ entry.platform_label }}</p>
                                <span class="px-2 py-0.5 bg-amber-500/10 rounded text-[10px] text-amber-400">R$ {{ entry.amount.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}</span>
                            </div>
                            <div class="flex items-center gap-3 mt-1">
                                <span class="text-[10px] text-gray-500">{{ entry.date_start_display }} — {{ entry.date_end_display }}</span>
                                <span class="text-[10px] text-gray-600">•</span>
                                <span class="text-[10px] text-gray-500">{{ entry.period_days }} dias (R$ {{ entry.daily_amount.toFixed(2) }}/dia)</span>
                                <span v-if="entry.description" class="text-[10px] text-gray-600">• {{ entry.description }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button @click="openManualEntryForm(entry)" class="p-2 text-gray-400 hover:text-indigo-400 transition rounded-lg hover:bg-gray-800" title="Editar">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <button @click="deleteManualEntry(entry.id)" class="p-2 text-gray-400 hover:text-red-400 transition rounded-lg hover:bg-gray-800" title="Remover">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
                <div v-else class="p-8 text-center">
                    <svg class="w-12 h-12 text-gray-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 9v1m9-5a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-sm text-gray-500 mb-1">Nenhum investimento manual cadastrado</p>
                    <p class="text-[10px] text-gray-600">Use para registrar gastos de Google Ads, Meta Ads, TikTok Ads ou qualquer outra plataforma</p>
                </div>
            </div>

            <!-- WooCommerce Form Modal -->
            <div v-if="showWcForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showWcForm = false">
                <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 w-full max-w-md mx-4">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-purple-500/10 flex items-center justify-center">
                                <svg class="w-5 h-5 text-purple-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M3 6h18" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M16 10a4 4 0 01-8 0" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-white">Conectar WooCommerce</h3>
                        </div>
                        <button @click="showWcForm = false" class="text-gray-400 hover:text-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                    </div>

                    <p class="text-xs text-gray-500 mb-4">Gere as credenciais em WooCommerce > Settings > Advanced > REST API com permissão de <strong class="text-gray-400">Leitura</strong>.</p>

                    <form @submit.prevent="submitWcForm" class="space-y-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Nome da conexão</label>
                            <input v-model="wcForm.name" type="text" placeholder="Ex: Loja Principal" class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">URL da loja</label>
                            <input v-model="wcForm.store_url" type="url" placeholder="https://minhaloja.com.br" class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Consumer Key</label>
                            <input v-model="wcForm.consumer_key" type="text" placeholder="ck_..." class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500 font-mono text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Consumer Secret</label>
                            <input v-model="wcForm.consumer_secret" type="password" placeholder="cs_..." class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500 font-mono text-xs" />
                        </div>

                        <!-- Test result -->
                        <div v-if="wcTestResult" :class="['p-3 rounded-xl text-xs', wcTestResult.success ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20']">
                            {{ wcTestResult.message }}
                        </div>

                        <!-- Status de Pedido (aparece após teste bem-sucedido) -->
                        <div v-if="wcTestResult?.success || wcAvailableStatuses.length > 0">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-xs text-gray-400">Status que contam como receita</label>
                                <button v-if="!wcLoadingStatuses && wcAvailableStatuses.length === 0" type="button" @click="fetchWcStatuses" class="text-[10px] text-indigo-400 hover:text-indigo-300">Carregar status</button>
                            </div>

                            <div v-if="wcLoadingStatuses" class="flex items-center gap-2 py-2">
                                <svg class="w-3.5 h-3.5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                <span class="text-[11px] text-gray-500">Buscando status da loja...</span>
                            </div>

                            <div v-else-if="wcAvailableStatuses.length > 0" class="space-y-1.5 max-h-48 overflow-y-auto pr-1">
                                <label
                                    v-for="st in wcAvailableStatuses"
                                    :key="st.slug"
                                    class="flex items-center gap-2.5 px-3 py-2 rounded-lg cursor-pointer transition"
                                    :class="wcForm.order_statuses.includes(st.slug) ? 'bg-purple-500/10 border border-purple-500/30' : 'bg-gray-800/50 border border-gray-700/50 hover:border-gray-600'"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="wcForm.order_statuses.includes(st.slug)"
                                        @change="toggleWcStatus(st.slug)"
                                        class="rounded border-gray-600 bg-gray-700 text-purple-500 focus:ring-purple-500 focus:ring-offset-0 w-3.5 h-3.5"
                                    />
                                    <div class="flex-1 min-w-0 flex items-center gap-2">
                                        <span class="text-xs text-white truncate">{{ st.name }}</span>
                                        <span class="text-[10px] text-gray-500 font-mono">{{ st.slug }}</span>
                                    </div>
                                    <span v-if="st.total !== undefined" class="text-[10px] text-gray-500">{{ st.total }} pedidos</span>
                                </label>
                            </div>

                            <p v-if="wcForm.order_statuses.length > 0" class="text-[10px] text-gray-500 mt-1.5">
                                {{ wcForm.order_statuses.length }} status selecionado(s): <span class="text-gray-400">{{ wcForm.order_statuses.join(', ') }}</span>
                            </p>
                            <p v-else class="text-[10px] text-amber-400 mt-1.5">
                                Nenhum status selecionado. Serão usados os padrão (completed, processing, on-hold).
                            </p>
                        </div>

                        <div class="flex gap-3">
                            <button type="button" @click="testWcConnection" :disabled="wcTesting || !wcForm.store_url || !wcForm.consumer_key || !wcForm.consumer_secret" class="flex-1 px-4 py-2 bg-gray-800 hover:bg-gray-700 disabled:opacity-50 text-gray-300 rounded-xl text-sm transition border border-gray-700 flex items-center justify-center gap-2">
                                <svg v-if="wcTesting" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                {{ wcTesting ? 'Testando...' : 'Testar Conexão' }}
                            </button>
                            <button type="submit" :disabled="wcForm.processing || !wcForm.name || !wcForm.store_url || !wcForm.consumer_key || !wcForm.consumer_secret" class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium transition">
                                {{ wcForm.processing ? 'Salvando...' : 'Conectar' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- WooCommerce Status Edit Modal -->
            <div v-if="showWcStatusModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showWcStatusModal = false">
                <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 w-full max-w-md mx-4">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl bg-purple-500/10 flex items-center justify-center">
                                <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-white">Status de Pedido</h3>
                                <p class="text-[11px] text-gray-500">{{ wcStatusEditConnection?.name }}</p>
                            </div>
                        </div>
                        <button @click="showWcStatusModal = false" class="text-gray-400 hover:text-white">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>

                    <p class="text-xs text-gray-400 mb-4">
                        Selecione quais status de pedido devem contar como <strong class="text-white">receita</strong>.
                        Inclua status personalizados do plugin <strong class="text-purple-400">Woo Order Status</strong> ou similar.
                    </p>

                    <div v-if="wcStatusEditLoading" class="flex items-center justify-center gap-2 py-8">
                        <svg class="w-5 h-5 animate-spin text-purple-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <span class="text-sm text-gray-400">Buscando status da loja...</span>
                    </div>

                    <div v-else class="space-y-1.5 max-h-64 overflow-y-auto pr-1 mb-4">
                        <label
                            v-for="st in wcStatusEditAvailable"
                            :key="st.slug"
                            class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg cursor-pointer transition"
                            :class="wcStatusEditStatuses.includes(st.slug) ? 'bg-purple-500/10 border border-purple-500/30' : 'bg-gray-800/50 border border-gray-700/50 hover:border-gray-600'"
                        >
                            <input
                                type="checkbox"
                                :checked="wcStatusEditStatuses.includes(st.slug)"
                                @change="toggleWcStatusEdit(st.slug)"
                                class="rounded border-gray-600 bg-gray-700 text-purple-500 focus:ring-purple-500 focus:ring-offset-0 w-4 h-4"
                            />
                            <div class="flex-1 min-w-0 flex items-center gap-2">
                                <span class="text-sm text-white">{{ st.name }}</span>
                                <span class="text-[10px] text-gray-500 font-mono bg-gray-800 px-1.5 py-0.5 rounded">{{ st.slug }}</span>
                            </div>
                            <span v-if="st.total !== undefined" class="text-xs text-gray-500">{{ st.total }}</span>
                        </label>

                        <!-- Campo para adicionar status manual (caso o plugin não exponha via API) -->
                        <div class="mt-3 pt-3 border-t border-gray-800">
                            <label class="block text-[11px] text-gray-500 mb-1.5">Adicionar status personalizado manualmente</label>
                            <div class="flex gap-2">
                                <input
                                    ref="customStatusInput"
                                    type="text"
                                    placeholder="ex: pagamento-aprovado"
                                    class="flex-1 bg-gray-800 border border-gray-700 text-white text-xs rounded-lg px-3 py-1.5 focus:ring-purple-500 focus:border-purple-500 font-mono"
                                    @keydown.enter.prevent="addCustomStatus"
                                />
                                <button type="button" @click="addCustomStatus" class="px-3 py-1.5 bg-purple-600/20 text-purple-400 hover:bg-purple-600/30 rounded-lg text-xs transition border border-purple-500/20">
                                    Adicionar
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-if="wcStatusEditStatuses.length > 0" class="p-3 bg-gray-800/50 rounded-xl mb-4">
                        <p class="text-[11px] text-gray-400 mb-1">Status selecionados ({{ wcStatusEditStatuses.length }}):</p>
                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="slug in wcStatusEditStatuses"
                                :key="slug"
                                class="inline-flex items-center gap-1 px-2 py-0.5 bg-purple-500/10 text-purple-300 rounded text-[10px] font-mono border border-purple-500/20"
                            >
                                {{ slug }}
                                <button @click="toggleWcStatusEdit(slug)" class="text-purple-400 hover:text-red-400 ml-0.5">&times;</button>
                            </span>
                        </div>
                    </div>
                    <p v-else class="text-xs text-amber-400 mb-4">Selecione ao menos um status.</p>

                    <div class="flex gap-3">
                        <button @click="showWcStatusModal = false" class="flex-1 px-4 py-2 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl text-sm transition border border-gray-700">
                            Cancelar
                        </button>
                        <button
                            @click="saveWcStatuses"
                            :disabled="wcStatusEditSaving || wcStatusEditStatuses.length === 0"
                            class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium transition flex items-center justify-center gap-2"
                        >
                            <svg v-if="wcStatusEditSaving" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            {{ wcStatusEditSaving ? 'Salvando...' : 'Salvar Status' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Manual Entry Form Modal -->
            <div v-if="showManualEntryForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showManualEntryForm = false">
                <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 w-full max-w-md mx-4">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-white">{{ editingEntry ? 'Editar' : 'Novo' }} Investimento Manual</h3>
                        <button @click="showManualEntryForm = false" class="text-gray-400 hover:text-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                    </div>

                    <form @submit.prevent="submitManualEntry" class="space-y-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Plataforma</label>
                            <select v-model="manualEntryForm.platform" class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <option v-for="(label, key) in manualPlatforms" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div v-if="manualEntryForm.platform === 'other'">
                            <label class="block text-xs text-gray-400 mb-1">Nome da plataforma</label>
                            <input v-model="manualEntryForm.platform_label" type="text" placeholder="Ex: Taboola, Mídia Offline..." class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Data início</label>
                                <input v-model="manualEntryForm.date_start" type="date" class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Data fim</label>
                                <input v-model="manualEntryForm.date_end" type="date" class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Valor total investido (R$)</label>
                            <input v-model.number="manualEntryForm.amount" type="number" step="0.01" min="0" placeholder="0,00" class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                            <p v-if="manualEntryForm.date_start && manualEntryForm.date_end && manualEntryForm.amount > 0" class="text-[10px] text-indigo-400 mt-1">
                                ≈ R$ {{ (manualEntryForm.amount / (Math.max(1, Math.ceil((new Date(manualEntryForm.date_end).getTime() - new Date(manualEntryForm.date_start).getTime()) / 86400000) + 1))).toFixed(2) }}/dia
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Descrição (opcional)</label>
                            <input v-model="manualEntryForm.description" type="text" placeholder="Ex: Campanha Black Friday" class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500" />
                        </div>

                        <div class="flex gap-3">
                            <button type="button" @click="showManualEntryForm = false" class="flex-1 px-4 py-2 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl text-sm transition border border-gray-700">Cancelar</button>
                            <button type="submit" :disabled="manualEntryForm.processing || !manualEntryForm.date_start || !manualEntryForm.date_end || !manualEntryForm.amount" class="flex-1 px-4 py-2 bg-amber-600 hover:bg-amber-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium transition">
                                {{ manualEntryForm.processing ? 'Salvando...' : editingEntry ? 'Atualizar' : 'Cadastrar' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Discovered Accounts Modal -->
            <div v-if="showDiscoveredModal && (discoveredAccountsLocal.length > 0 || discoveredAccounts.length > 0)" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
                <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 w-full max-w-lg mx-4">
                    <h3 class="text-lg font-semibold text-white mb-2">Contas Encontradas</h3>
                    <p class="text-sm text-gray-400 mb-4">
                        Selecione as contas que deseja conectar ao MKT Privus.
                        <span class="text-indigo-400 capitalize">{{ (discoveredPlatformLocal || discoveredPlatform)?.replace('_', ' ') }}</span>
                    </p>

                    <div class="space-y-2 max-h-64 overflow-y-auto mb-4">
                        <button
                            v-for="account in (discoveredAccountsLocal.length > 0 ? discoveredAccountsLocal : discoveredAccounts)"
                            :key="account.id"
                            @click="toggleAccountSelection(account.id)"
                            :class="[
                                'w-full flex items-center gap-3 p-3 rounded-xl border transition text-left',
                                selectedAccounts.includes(account.id)
                                    ? 'border-indigo-500 bg-indigo-500/10'
                                    : 'border-gray-800 bg-gray-800/50 hover:border-gray-700'
                            ]"
                        >
                            <div :class="['w-5 h-5 rounded-md border-2 flex items-center justify-center shrink-0',
                                selectedAccounts.includes(account.id) ? 'border-indigo-500 bg-indigo-600' : 'border-gray-600']">
                                <svg v-if="selectedAccounts.includes(account.id)" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                    <path d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-white truncate">{{ account.name }}</p>
                                <p v-if="account.account_name" class="text-[10px] text-gray-500">{{ account.account_name }}</p>
                                <p class="text-[10px] text-gray-600">ID: {{ account.id }}</p>
                            </div>
                        </button>
                    </div>

                    <div class="flex gap-3">
                        <button @click="showDiscoveredModal = false" class="flex-1 px-4 py-2 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl text-sm transition border border-gray-700">Cancelar</button>
                        <button
                            @click="saveSelectedAccounts"
                            :disabled="selectedAccounts.length === 0"
                            class="flex-1 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium transition"
                        >
                            Conectar ({{ selectedAccounts.length }})
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
