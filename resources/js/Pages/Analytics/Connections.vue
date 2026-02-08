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

const props = defineProps<{
    brand: any;
    brands: any[];
    connections: Connection[];
    oauthConfigured: Record<string, boolean>;
    discoveredAccounts: DiscoveredAccount[];
    discoveredPlatform: string | null;
    platforms: Record<string, string>;
    platformColors: Record<string, string>;
}>();

const showManualForm = ref(false);
const showDiscoveredModal = ref(props.discoveredAccounts?.length > 0);
const selectedAccounts = ref<string[]>([]);
const syncingId = ref<number | null>(null);
const connectingPlatform = ref<string | null>(null);

const manualForm = useForm({
    brand_id: props.brand?.id,
    platform: 'google_analytics',
    name: '',
    external_id: '',
    access_token: '',
    refresh_token: '',
});

const guideTips = [
    'Recomendamos conectar via OAuth para autenticação segura e automática',
    'Google Analytics, Google Ads e Search Console usam a mesma conta Google OAuth',
    'Configure as credenciais OAuth em Configurações > Integrações OAuth',
    'Sincronize regularmente para manter os dados atualizados',
];

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
        // Recarregar a página para mostrar as contas descobertas
        router.reload({ preserveScroll: true });
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
    const accounts = props.discoveredAccounts
        .filter(a => selectedAccounts.value.includes(a.id))
        .map(a => ({ id: a.id, name: a.name, account_name: a.account_name }));

    router.post(route('analytics.oauth.save'), {
        brand_id: props.brand?.id,
        accounts,
    }, {
        onSuccess: () => {
            showDiscoveredModal.value = false;
            selectedAccounts.value = [];
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

                    <button
                        v-if="oauthConfigured[platform.key]"
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
                        <!-- Loading spinner -->
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

            <!-- Discovered Accounts Modal -->
            <div v-if="showDiscoveredModal && discoveredAccounts.length > 0" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
                <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 w-full max-w-lg mx-4">
                    <h3 class="text-lg font-semibold text-white mb-2">Contas Encontradas</h3>
                    <p class="text-sm text-gray-400 mb-4">
                        Selecione as contas que deseja conectar ao MKT Privus.
                        <span class="text-indigo-400 capitalize">{{ discoveredPlatform?.replace('_', ' ') }}</span>
                    </p>

                    <div class="space-y-2 max-h-64 overflow-y-auto mb-4">
                        <button
                            v-for="account in discoveredAccounts"
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
