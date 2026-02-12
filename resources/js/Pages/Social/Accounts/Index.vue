<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import axios from 'axios';

interface SocialAccountInsight {
    date: string;
    followers_count: number | null;
    following_count: number | null;
    posts_count: number | null;
    impressions: number | null;
    reach: number | null;
    engagement: number | null;
    engagement_rate: number | null;
    likes: number | null;
    comments: number | null;
    shares: number | null;
    saves: number | null;
    clicks: number | null;
    video_views: number | null;
    net_followers: number | null;
    audience_gender: Record<string, number> | null;
    audience_age: Record<string, number> | null;
    audience_cities: Record<string, number> | null;
    audience_countries: Record<string, number> | null;
    platform_data: Record<string, any> | null;
    followers_variation: number | null;
}

interface SocialAccount {
    id: number;
    platform: string;
    platform_label: string;
    platform_color: string;
    username: string;
    display_name: string | null;
    avatar_url: string | null;
    is_active: boolean;
    token_status: string;
    metadata: Record<string, any> | null;
    created_at: string;
    insights: SocialAccountInsight | null;
    brand_id: number | null;
    brand_name?: string | null;
}

interface Platform {
    value: string;
    label: string;
    color: string;
}

interface DiscoveredAccount {
    platform: string;
    type: string;
    platform_user_id: string;
    username: string;
    display_name: string;
    avatar_url: string | null;
    metadata: Record<string, any> | null;
}

const props = defineProps<{
    accounts: SocialAccount[];
    platforms: Platform[];
    oauthConfigured: Record<string, boolean>;
    discoveredAccounts: DiscoveredAccount[];
    oauthPlatform: string | null;
    discoveryToken: string | null;
    brands?: { id: number; name: string }[];
}>();

const page = usePage();
const currentBrand = computed(() => page.props.currentBrand);

// Toast notification
const toast = ref<{ message: string; type: 'success' | 'error' | 'info' } | null>(null);
const toastTimeout = ref<ReturnType<typeof setTimeout> | null>(null);

function showToast(message: string, type: 'success' | 'error' | 'info' = 'success') {
    if (toastTimeout.value) clearTimeout(toastTimeout.value);
    toast.value = { message, type };
    toastTimeout.value = setTimeout(() => { toast.value = null; }, 5000);
}

// Vincular conta social a marca
const linkingBrandAccount = ref<number | null>(null);
async function linkBrandToAccount(accountId: number, brandId: string | null) {
    linkingBrandAccount.value = accountId;
    try {
        const response = await axios.post(route('social.accounts.link-brand', accountId), {
            brand_id: brandId === 'global' || brandId === '' ? null : brandId,
        });
        if (response.data.success) {
            const acc = props.accounts.find(a => a.id === accountId);
            if (acc) {
                acc.brand_id = response.data.brand_id;
                acc.brand_name = response.data.brand_name;
            }
            showToast(response.data.message, 'success');
        }
    } catch (e: any) {
        console.error('Erro ao vincular marca', e);
        showToast('Erro ao vincular marca', 'error');
    } finally {
        linkingBrandAccount.value = null;
    }
}

// Mostrar flash messages
function checkFlash() {
    const flash = page.props.flash as any;
    if (flash?.success) showToast(flash.success, 'success');
    if (flash?.error) showToast(flash.error, 'error');
    if (flash?.info) showToast(flash.info, 'info');
}

// Watch flash changes de forma robusta (funciona com redirect do Inertia)
watch(() => page.props.flash, () => checkFlash(), { deep: true });

// Insights expandidos
const expandedAccount = ref<number | null>(null);

// Estado do modal de selecao OAuth
const showDiscoveryModal = ref(false);
const selectedDiscovered = ref<number[]>([]);
const savingAccounts = ref(false);
// Token de descoberta (do banco, nao da sessao)
const currentDiscoveryToken = ref<string | null>(null);

// Estado do modal manual
const showManualModal = ref(false);
const manualForm = useForm({
    platform: '',
    username: '',
    display_name: '',
    platform_user_id: '',
    access_token: '',
    refresh_token: '',
    token_expires_at: '',
});

// Connecting state
const connectingPlatform = ref<string | null>(null);

// Watch para abrir modal quando contas descobertas mudarem (via props do servidor)
watch(() => props.discoveredAccounts, (newVal) => {
    console.log('[OAuth] discoveredAccounts prop changed:', newVal?.length, 'token:', props.discoveryToken);
    if (newVal && newVal.length > 0) {
        currentDiscoveryToken.value = props.discoveryToken;
        showDiscoveryModal.value = true;
        selectedDiscovered.value = newVal.map((_, i) => i);
    }
}, { immediate: true });

onMounted(() => {
    window.addEventListener('message', handleOAuthMessage);
    checkFlash();
    console.log('[OAuth] Pagina montada. discoveredAccounts:', props.discoveredAccounts?.length, 'token:', props.discoveryToken);
});

onUnmounted(() => {
    window.removeEventListener('message', handleOAuthMessage);
    if (toastTimeout.value) clearTimeout(toastTimeout.value);
});

function connectOAuth(platform: string) {
    connectingPlatform.value = platform;
    const params = new URLSearchParams({ popup: '1' });

    fetch(route('social.oauth.redirect', platform) + '?' + params.toString(), {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })
    .then(res => res.json())
    .then(data => {
        if (data.url) {
            const width = 600;
            const height = 700;
            const left = window.screenX + (window.outerWidth - width) / 2;
            const top = window.screenY + (window.outerHeight - height) / 2;
            const features = `width=${width},height=${height},left=${left},top=${top},toolbar=no,menubar=no,scrollbars=yes,resizable=yes`;
            const popup = window.open(data.url, 'oauth_popup', features);
            const checkClosed = setInterval(() => {
                if (popup && popup.closed) {
                    clearInterval(checkClosed);
                    connectingPlatform.value = null;
                }
            }, 500);
        } else {
            connectingPlatform.value = null;
            showToast(data.error || 'Erro ao iniciar autenticação', 'error');
        }
    })
    .catch(() => {
        connectingPlatform.value = null;
        showToast('Erro ao conectar. Verifique sua conexão.', 'error');
    });
}

function handleOAuthMessage(event: MessageEvent) {
    if (event.data?.type !== 'oauth_callback') return;

    console.log('[OAuth] Mensagem recebida do popup:', event.data);
    connectingPlatform.value = null;

    if (event.data.status === 'success') {
        const token = event.data.discoveryToken;
        console.log('[OAuth] Token de descoberta recebido:', token ? token.substring(0, 12) + '...' : 'NENHUM');

        if (token) {
            showToast(`${event.data.accountsCount} conta(s) encontrada(s)! Carregando...`, 'info');

            // Buscar contas via API usando o token do banco
            fetch(route('social.oauth.discovered') + '?token=' + encodeURIComponent(token), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            .then(res => res.json())
            .then(data => {
                console.log('[OAuth] Contas recuperadas do banco:', data);
                if (data.accounts && data.accounts.length > 0) {
                    // Abrir modal diretamente com os dados da API (sem depender do Inertia props)
                    currentDiscoveryToken.value = data.token;
                    openDiscoveryModalWithAccounts(data.accounts);
                } else {
                    showToast('Nenhuma conta encontrada. Tente novamente.', 'error');
                }
            })
            .catch(err => {
                console.error('[OAuth] Erro ao buscar contas:', err);
                showToast('Erro ao carregar contas descobertas.', 'error');
            });
        } else {
            showToast('Token não recebido. Tente novamente.', 'error');
        }
    } else {
        showToast(event.data.message || 'Erro no OAuth', 'error');
    }
}

// Contas carregadas diretamente da API (bypass do Inertia props)
const apiDiscoveredAccounts = ref<DiscoveredAccount[]>([]);

// Lista combinada: usa api se disponivel, senao usa props
const activeDiscoveredAccounts = computed(() => {
    if (apiDiscoveredAccounts.value.length > 0) return apiDiscoveredAccounts.value;
    return props.discoveredAccounts || [];
});

function openDiscoveryModalWithAccounts(accounts: DiscoveredAccount[]) {
    apiDiscoveredAccounts.value = accounts;
    selectedDiscovered.value = accounts.map((_, i) => i);
    showDiscoveryModal.value = true;
    console.log('[OAuth] Modal aberto com', accounts.length, 'contas');
}

function toggleDiscoveredAccount(index: number) {
    const idx = selectedDiscovered.value.indexOf(index);
    if (idx > -1) {
        selectedDiscovered.value.splice(idx, 1);
    } else {
        selectedDiscovered.value.push(index);
    }
}

function saveDiscoveredAccounts() {
    if (selectedDiscovered.value.length === 0) return;

    const token = currentDiscoveryToken.value;
    if (!token) {
        showToast('Token de descoberta nao encontrado. Tente reconectar.', 'error');
        return;
    }

    savingAccounts.value = true;
    console.log('[OAuth] Salvando contas com token:', token.substring(0, 12) + '...', 'selected:', selectedDiscovered.value);

    router.post(route('social.oauth.save'), {
        selected: selectedDiscovered.value,
        discovery_token: token,
    }, {
        preserveScroll: true,
        onSuccess: (page: any) => {
            showDiscoveryModal.value = false;
            selectedDiscovered.value = [];
            apiDiscoveredAccounts.value = [];
            currentDiscoveryToken.value = null;
            const flash = page?.props?.flash;
            if (flash?.success) showToast(flash.success, 'success');
            else if (flash?.error) showToast(flash.error, 'error');
            else showToast('Contas processadas!', 'success');
            console.log('[OAuth] Contas salvas com sucesso');
        },
        onError: (errors: any) => {
            console.error('[OAuth] Erro ao salvar contas:', errors);
            const msg = Object.values(errors).flat().join(', ') || 'Erro ao salvar contas.';
            showToast(msg, 'error');
        },
        onFinish: () => {
            savingAccounts.value = false;
        },
    });
}

function closeDiscoveryModal() {
    showDiscoveryModal.value = false;
    apiDiscoveredAccounts.value = [];
    currentDiscoveryToken.value = null;
}

function openManualModal(platform?: string) {
    manualForm.reset();
    if (platform) manualForm.platform = platform;
    showManualModal.value = true;
}

function submitManual() {
    manualForm.post(route('social.accounts.store'), {
        onSuccess: () => {
            showManualModal.value = false;
            manualForm.reset();
        },
    });
}

function toggleAccount(accountId: number) {
    router.post(route('social.accounts.toggle', accountId));
}

function removeAccount(accountId: number) {
    if (confirm('Tem certeza que deseja desconectar esta conta?')) {
        router.delete(route('social.accounts.destroy', accountId));
    }
}

function reconnectOAuth(account: SocialAccount) {
    connectOAuth(account.platform);
}

const tokenStatusLabels: Record<string, { label: string; color: string; bg: string; icon: string }> = {
    ativo: { label: 'Conectado', color: 'text-emerald-400', bg: 'bg-emerald-500/10 border-emerald-500/30', icon: '●' },
    expirado: { label: 'Token expirado', color: 'text-red-400', bg: 'bg-red-500/10 border-red-500/30', icon: '!' },
    renovar: { label: 'Renovar token', color: 'text-amber-400', bg: 'bg-amber-500/10 border-amber-500/30', icon: '↻' },
    sem_token: { label: 'Sem token', color: 'text-gray-500', bg: 'bg-gray-500/10 border-gray-500/30', icon: '○' },
};

// Agrupar contas por plataforma
const accountsByPlatform = computed(() => {
    const grouped: Record<string, SocialAccount[]> = {};
    for (const acc of props.accounts) {
        if (!grouped[acc.platform]) grouped[acc.platform] = [];
        grouped[acc.platform].push(acc);
    }
    return grouped;
});

const totalActive = computed(() => props.accounts.filter(a => a.is_active).length);
const totalInactive = computed(() => props.accounts.filter(a => !a.is_active).length);
const totalExpired = computed(() => props.accounts.filter(a => a.token_status === 'expirado').length);

const connectedPlatforms = computed(() => {
    const set = new Set(props.accounts.map(a => a.platform));
    return props.platforms.filter(p => set.has(p.value));
});

const unconnectedPlatforms = computed(() => {
    const set = new Set(props.accounts.map(a => a.platform));
    return props.platforms.filter(p => !set.has(p.value));
});

// Infos visuais
const platformInfo: Record<string, { desc: string; features: string[] }> = {
    instagram: {
        desc: 'Meta Business Suite para posts, reels e stories.',
        features: ['Publicar posts/reels', 'Ver insights', 'Agendar conteudo'],
    },
    facebook: {
        desc: 'Gerenciar paginas, publicacoes e metricas.',
        features: ['Publicar em paginas', 'Gerenciar respostas', 'Ver metricas'],
    },
    linkedin: {
        desc: 'Perfil ou pagina da empresa para conteudo profissional.',
        features: ['Publicar artigos', 'Pagina da empresa', 'Analytics'],
    },
    youtube: {
        desc: 'Gerenciar videos e acompanhar metricas de audiencia.',
        features: ['Upload de videos', 'Gerenciar canal', 'Metricas'],
    },
    tiktok: {
        desc: 'Publicar videos e acompanhar desempenho.',
        features: ['Publicar videos', 'Ver estatisticas', 'Agendar posts'],
    },
    pinterest: {
        desc: 'Publicar pins e gerenciar boards automaticamente.',
        features: ['Criar pins', 'Gerenciar boards', 'Analytics'],
    },
};

const platformIcons: Record<string, string> = {
    instagram: 'M12 2c2.717 0 3.056.01 4.122.06 1.065.05 1.79.217 2.428.465.66.254 1.216.598 1.772 1.153a4.908 4.908 0 0 1 1.153 1.772c.247.637.415 1.363.465 2.428.047 1.066.06 1.405.06 4.122 0 2.717-.01 3.056-.06 4.122-.05 1.065-.218 1.79-.465 2.428a4.883 4.883 0 0 1-1.153 1.772 4.915 4.915 0 0 1-1.772 1.153c-.637.247-1.363.415-2.428.465-1.066.047-1.405.06-4.122.06-2.717 0-3.056-.01-4.122-.06-1.065-.05-1.79-.218-2.428-.465a4.89 4.89 0 0 1-1.772-1.153 4.904 4.904 0 0 1-1.153-1.772c-.248-.637-.415-1.363-.465-2.428C2.013 15.056 2 14.717 2 12c0-2.717.01-3.056.06-4.122.05-1.066.217-1.79.465-2.428a4.88 4.88 0 0 1 1.153-1.772A4.897 4.897 0 0 1 5.45 2.525c.638-.248 1.362-.415 2.428-.465C8.944 2.013 9.283 2 12 2zm0 5a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm6.5-.25a1.25 1.25 0 0 0-2.5 0 1.25 1.25 0 0 0 2.5 0zM12 9a3 3 0 1 1 0 6 3 3 0 0 1 0-6z',
    facebook: 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z',
    linkedin: 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z',
    tiktok: 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z',
    youtube: 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z',
    pinterest: 'M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 0 1 .083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.988C24.007 5.367 18.641.001 12.017.001z',
};

function formatNumber(num: number | null | undefined): string {
    if (!num) return '0';
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toString();
}
</script>

<template>
    <Head title="Social - Contas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-white">Contas Conectadas</h1>
                    <p class="text-sm text-gray-500 mt-0.5" v-if="accounts.length > 0">
                        {{ totalActive }} ativa(s)
                        <span v-if="totalInactive > 0"> · {{ totalInactive }} inativa(s)</span>
                        <span v-if="totalExpired > 0" class="text-red-400"> · {{ totalExpired }} com token expirado</span>
                    </p>
                </div>
                <button @click="openManualModal()" class="rounded-xl bg-gray-800 border border-gray-700 px-4 py-2 text-sm text-gray-300 hover:text-white hover:bg-gray-700 transition">
                    + Adicionar manual
                </button>
            </div>
        </template>

        <!-- Toast notification -->
        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-300" enter-from-class="translate-y-2 opacity-0" enter-to-class="translate-y-0 opacity-100" leave-active-class="transition ease-in duration-200" leave-from-class="translate-y-0 opacity-100" leave-to-class="translate-y-2 opacity-0">
                <div v-if="toast" class="fixed bottom-6 right-6 z-[100] max-w-sm">
                    <div :class="[
                        'rounded-xl border px-5 py-3.5 shadow-2xl backdrop-blur-sm flex items-center gap-3',
                        toast.type === 'success' ? 'bg-emerald-600/90 border-emerald-500/50 text-white' : '',
                        toast.type === 'error' ? 'bg-red-600/90 border-red-500/50 text-white' : '',
                        toast.type === 'info' ? 'bg-indigo-600/90 border-indigo-500/50 text-white' : '',
                    ]">
                        <span class="text-lg">{{ toast.type === 'success' ? '✓' : toast.type === 'error' ? '✕' : 'ℹ' }}</span>
                        <p class="text-sm font-medium">{{ toast.message }}</p>
                        <button @click="toast = null" class="ml-2 opacity-60 hover:opacity-100 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                        </button>
                    </div>
                </div>
            </Transition>
        </Teleport>

            <!-- Contas conectadas agrupadas por plataforma -->
            <div v-if="accounts.length > 0" class="mb-8 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-medium text-gray-400 uppercase tracking-wide">Minhas contas</h2>
                    <span class="text-xs text-gray-600">{{ accounts.length }} conta(s) no total</span>
                </div>

                <div v-for="plat in connectedPlatforms" :key="plat.value" class="rounded-2xl bg-gray-900 border border-gray-800 overflow-hidden">
                    <!-- Platform header -->
                    <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-800/50" :style="{ borderLeftWidth: '3px', borderLeftColor: plat.color }">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white shrink-0" :style="{ backgroundColor: plat.color }">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path :d="platformIcons[plat.value] || ''" /></svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-sm font-semibold text-white">{{ plat.label }}</h3>
                            <p class="text-[10px] text-gray-500">{{ (accountsByPlatform[plat.value] || []).length }} conta(s)</p>
                        </div>
                        <button @click="connectOAuth(plat.value)" v-if="oauthConfigured[plat.value]"
                            :disabled="connectingPlatform === plat.value"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium text-indigo-400 hover:bg-indigo-600/10 border border-indigo-500/30 transition disabled:opacity-50">
                            <span v-if="connectingPlatform === plat.value" class="inline-flex items-center gap-1">
                                <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                Aguardando...
                            </span>
                            <span v-else>+ Adicionar conta</span>
                        </button>
                    </div>

                    <!-- Account rows -->
                    <div class="divide-y divide-gray-800/50">
                        <div v-for="account in accountsByPlatform[plat.value]" :key="account.id">
                            <div class="flex items-center gap-4 px-5 py-3.5 hover:bg-gray-800/30 transition">
                                <!-- Avatar -->
                                <div class="relative shrink-0">
                                    <img v-if="account.avatar_url" :src="account.avatar_url" :alt="account.display_name || account.username" class="w-10 h-10 rounded-xl object-cover" />
                                    <div v-else class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-sm font-bold" :style="{ backgroundColor: account.platform_color }">
                                        {{ (account.display_name || account.username || 'A')[0].toUpperCase() }}
                                    </div>
                                </div>

                                <!-- Info -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="font-medium text-white text-sm truncate">{{ account.display_name || account.username }}</p>
                                        <span :class="['rounded-full border px-2 py-0.5 text-[10px] font-medium', tokenStatusLabels[account.token_status]?.bg, tokenStatusLabels[account.token_status]?.color]">
                                            {{ tokenStatusLabels[account.token_status]?.icon }} {{ tokenStatusLabels[account.token_status]?.label }}
                                        </span>
                                        <span v-if="!account.is_active" class="rounded-full bg-gray-700 px-2 py-0.5 text-[10px] text-gray-400">Inativa</span>
                                    </div>
                                    <div class="flex items-center gap-3 mt-0.5">
                                        <p class="text-xs text-gray-500">@{{ account.username }}</p>
                                        <span v-if="account.insights?.followers_count" class="text-[10px] text-gray-500 font-medium">
                                            {{ formatNumber(account.insights.followers_count) }} seguidores
                                            <span v-if="account.insights.followers_variation !== null" :class="account.insights.followers_variation >= 0 ? 'text-emerald-500' : 'text-red-500'">
                                                ({{ account.insights.followers_variation >= 0 ? '+' : '' }}{{ account.insights.followers_variation }}%)
                                            </span>
                                        </span>
                                        <span v-else-if="account.metadata?.followers_count" class="text-[10px] text-gray-600">{{ formatNumber(account.metadata.followers_count) }} seguidores</span>
                                        <span v-if="account.metadata?.fan_count && !account.insights?.followers_count" class="text-[10px] text-gray-600">{{ formatNumber(account.metadata.fan_count) }} fas</span>
                                        <span v-if="account.metadata?.subscriber_count && !account.insights?.followers_count" class="text-[10px] text-gray-600">{{ formatNumber(account.metadata.subscriber_count) }} inscritos</span>
                                        <span v-if="account.metadata?.type" class="text-[10px] text-gray-600 bg-gray-800 rounded px-1.5 py-0.5">{{ account.metadata.type }}</span>
                                        <span class="text-[10px] text-gray-700">{{ account.created_at }}</span>
                                    </div>
                                </div>

                                <!-- Brand link -->
                                <div class="shrink-0 flex items-center gap-1" v-if="brands && brands.length > 0">
                                    <select
                                        :value="account.brand_id ? String(account.brand_id) : 'global'"
                                        @change="linkBrandToAccount(account.id, ($event.target as HTMLSelectElement).value)"
                                        :disabled="linkingBrandAccount === account.id"
                                        class="rounded-lg bg-gray-800 border-gray-700 text-[11px] text-gray-300 py-1 pl-2 pr-6 focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-50"
                                        title="Vincular a marca">
                                        <option value="global">Global</option>
                                        <option v-for="b in brands" :key="b.id" :value="String(b.id)">{{ b.name }}</option>
                                    </select>
                                    <svg v-if="linkingBrandAccount === account.id" class="w-3 h-3 text-indigo-400 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                    </svg>
                                </div>

                                <!-- Actions -->
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <button v-if="account.token_status === 'expirado' || account.token_status === 'renovar'" @click="reconnectOAuth(account)"
                                        class="rounded-lg bg-indigo-600/20 border border-indigo-500/30 px-2.5 py-1 text-[11px] font-medium text-indigo-400 hover:bg-indigo-600/30 transition" title="Reconectar OAuth">
                                        ↻ Reconectar
                                    </button>
                                    <button @click="expandedAccount = expandedAccount === account.id ? null : account.id"
                                        class="rounded-lg px-2.5 py-1 text-[11px] font-medium text-gray-400 hover:bg-gray-700/50 border border-gray-700 transition" title="Ver insights">
                                        {{ expandedAccount === account.id ? '▲ Fechar' : '▼ Insights' }}
                                    </button>
                                    <button @click="toggleAccount(account.id)"
                                        :class="['rounded-lg px-2.5 py-1 text-[11px] font-medium transition border', account.is_active ? 'text-amber-400 hover:bg-amber-500/10 border-amber-500/30' : 'text-emerald-400 hover:bg-emerald-500/10 border-emerald-500/30']"
                                        :title="account.is_active ? 'Desativar conta' : 'Ativar conta'">
                                        {{ account.is_active ? 'Desativar' : 'Ativar' }}
                                    </button>
                                    <button @click="removeAccount(account.id)" class="rounded-lg px-2.5 py-1 text-[11px] font-medium text-red-400 hover:bg-red-500/10 border border-red-500/30 transition" title="Remover conta">
                                        Remover
                                    </button>
                                </div>
                            </div>

                            <!-- Insights expandidos -->
                            <div v-if="expandedAccount === account.id && account.insights" class="px-5 pb-4">
                                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-2">
                                    <div v-if="account.insights.followers_count !== null" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider">Seguidores</p>
                                        <p class="text-lg font-bold text-white">{{ formatNumber(account.insights.followers_count) }}</p>
                                        <p v-if="account.insights.net_followers !== null" :class="['text-[10px] font-medium', account.insights.net_followers >= 0 ? 'text-emerald-400' : 'text-red-400']">
                                            {{ account.insights.net_followers >= 0 ? '+' : '' }}{{ formatNumber(account.insights.net_followers) }} hoje
                                        </p>
                                    </div>
                                    <div v-if="account.insights.reach !== null" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider">Alcance</p>
                                        <p class="text-lg font-bold text-white">{{ formatNumber(account.insights.reach) }}</p>
                                    </div>
                                    <div v-if="account.insights.impressions !== null" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider">Impressoes</p>
                                        <p class="text-lg font-bold text-white">{{ formatNumber(account.insights.impressions) }}</p>
                                    </div>
                                    <div v-if="account.insights.engagement !== null" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider">Engajamento</p>
                                        <p class="text-lg font-bold text-white">{{ formatNumber(account.insights.engagement) }}</p>
                                        <p v-if="account.insights.engagement_rate" class="text-[10px] text-indigo-400">{{ account.insights.engagement_rate }}%</p>
                                    </div>
                                    <div v-if="account.insights.likes !== null" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider">Curtidas</p>
                                        <p class="text-lg font-bold text-white">{{ formatNumber(account.insights.likes) }}</p>
                                    </div>
                                    <div v-if="account.insights.comments !== null" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider">Comentarios</p>
                                        <p class="text-lg font-bold text-white">{{ formatNumber(account.insights.comments) }}</p>
                                    </div>
                                    <div v-if="account.insights.shares !== null" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider">Compartilhamentos</p>
                                        <p class="text-lg font-bold text-white">{{ formatNumber(account.insights.shares) }}</p>
                                    </div>
                                    <div v-if="account.insights.saves !== null" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider">Salvamentos</p>
                                        <p class="text-lg font-bold text-white">{{ formatNumber(account.insights.saves) }}</p>
                                    </div>
                                    <div v-if="account.insights.clicks !== null" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider">Cliques</p>
                                        <p class="text-lg font-bold text-white">{{ formatNumber(account.insights.clicks) }}</p>
                                    </div>
                                    <div v-if="account.insights.video_views !== null" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider">Views Video</p>
                                        <p class="text-lg font-bold text-white">{{ formatNumber(account.insights.video_views) }}</p>
                                    </div>
                                    <div v-if="account.insights.posts_count !== null" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider">Posts</p>
                                        <p class="text-lg font-bold text-white">{{ formatNumber(account.insights.posts_count) }}</p>
                                    </div>
                                </div>

                                <!-- Audiencia (se disponivel) -->
                                <div v-if="account.insights.audience_gender || account.insights.audience_cities" class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div v-if="account.insights.audience_gender" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-2">Genero da Audiencia</p>
                                        <div class="space-y-1.5">
                                            <div v-for="(pct, gender) in account.insights.audience_gender" :key="gender" class="flex items-center gap-2">
                                                <span class="text-xs text-gray-400 w-16">{{ gender === 'male' ? 'Masc.' : gender === 'female' ? 'Fem.' : 'Outro' }}</span>
                                                <div class="flex-1 h-2 bg-gray-700 rounded-full overflow-hidden">
                                                    <div class="h-full rounded-full" :class="gender === 'male' ? 'bg-blue-500' : gender === 'female' ? 'bg-pink-500' : 'bg-gray-500'" :style="{ width: pct + '%' }"></div>
                                                </div>
                                                <span class="text-xs text-gray-400 w-10 text-right">{{ pct }}%</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-if="account.insights.audience_cities" class="rounded-xl bg-gray-800/50 border border-gray-700/50 p-3">
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-2">Top Cidades</p>
                                        <div class="space-y-1">
                                            <div v-for="(pct, city) in account.insights.audience_cities" :key="city" class="flex items-center justify-between">
                                                <span class="text-xs text-gray-300 truncate">{{ city }}</span>
                                                <span class="text-xs text-gray-500">{{ pct }}%</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <p class="text-[10px] text-gray-600 mt-2">Ultima atualizacao: {{ account.insights.date }}</p>
                            </div>

                            <!-- Sem insights -->
                            <div v-else-if="expandedAccount === account.id && !account.insights" class="px-5 pb-4">
                                <div class="rounded-xl bg-gray-800/30 border border-gray-700/50 p-4 text-center">
                                    <p class="text-sm text-gray-400">Nenhum insight disponivel ainda.</p>
                                    <p class="text-xs text-gray-500 mt-1">Os insights serao coletados automaticamente 2x ao dia.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mensagem quando nao tem contas -->
            <div v-else class="rounded-2xl bg-gray-900 border border-gray-800 border-dashed p-10 text-center mb-8">
                <div class="w-16 h-16 mx-auto rounded-2xl bg-gray-800 flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m9.86-2.06a4.5 4.5 0 00-6.364-6.364L4.757 8.757" /></svg>
                </div>
                <h3 class="text-lg font-medium text-gray-300">Nenhuma conta conectada</h3>
                <p class="mt-2 text-sm text-gray-500 max-w-md mx-auto">Conecte suas redes sociais para gerenciar publicacoes, agendar conteudo e acompanhar metricas - tudo em um so lugar.</p>
            </div>

            <!-- Conectar novas plataformas -->
            <div>
                <h2 class="text-sm font-medium text-gray-400 uppercase tracking-wide mb-3">{{ accounts.length > 0 ? 'Conectar mais plataformas' : 'Escolha uma plataforma para conectar' }}</h2>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="platform in platforms" :key="platform.value"
                        class="rounded-2xl bg-gray-900 border border-gray-800 p-5 hover:border-gray-700 transition group">
                        <div class="flex items-start gap-3 mb-3">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0" :style="{ backgroundColor: platform.color }">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path :d="platformIcons[platform.value] || ''" /></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-semibold text-white">{{ platform.label }}</h3>
                                    <span v-if="accountsByPlatform[platform.value]" class="text-[10px] text-emerald-400 bg-emerald-500/10 rounded-full px-2 py-0.5">
                                        {{ accountsByPlatform[platform.value].length }} conectada(s)
                                    </span>
                                </div>
                                <p class="text-[11px] text-gray-500 mt-0.5">{{ platformInfo[platform.value]?.desc || '' }}</p>
                            </div>
                        </div>

                        <!-- Features -->
                        <div v-if="platformInfo[platform.value]?.features" class="flex flex-wrap gap-1 mb-3">
                            <span v-for="feat in platformInfo[platform.value].features" :key="feat" class="rounded-md bg-gray-800 px-1.5 py-0.5 text-[10px] text-gray-500">
                                {{ feat }}
                            </span>
                        </div>

                        <!-- Botoes -->
                        <div class="flex gap-2">
                            <button
                                v-if="oauthConfigured[platform.value]"
                                @click="connectOAuth(platform.value)"
                                :disabled="connectingPlatform === platform.value"
                                class="flex-1 rounded-xl py-2 text-sm font-semibold text-white transition disabled:opacity-70"
                                :style="connectingPlatform !== platform.value ? { backgroundColor: platform.color } : {}"
                                :class="connectingPlatform === platform.value ? 'bg-gray-800 border border-indigo-500/30 !text-indigo-400' : ''"
                            >
                                <span v-if="connectingPlatform === platform.value" class="inline-flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                    Aguardando login...
                                </span>
                                <span v-else>Conectar conta</span>
                            </button>
                            <button
                                v-if="!oauthConfigured[platform.value]"
                                @click="openManualModal(platform.value)"
                                class="flex-1 rounded-xl border border-gray-700 py-2 text-sm font-medium text-gray-400 hover:text-white hover:border-gray-600 transition"
                            >
                                Adicionar manual
                            </button>
                            <button
                                v-if="oauthConfigured[platform.value]"
                                @click="openManualModal(platform.value)"
                                class="rounded-xl border border-gray-700 px-3 py-2 text-xs text-gray-500 hover:text-white hover:border-gray-600 transition"
                                title="Adicionar manualmente"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                            </button>
                        </div>

                        <!-- Not configured warning -->
                        <div v-if="!oauthConfigured[platform.value]" class="mt-2">
                            <Link :href="route('settings.index') + '?tab=oauth'" class="text-[10px] text-amber-400 hover:text-amber-300 underline underline-offset-2 transition">
                                Configurar OAuth em Configuracoes para habilitar conexao automatica
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

        <!-- Modal: Contas descobertas via OAuth -->
        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="showDiscoveryModal" class="fixed inset-0 z-[60] flex items-center justify-center">
                    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeDiscoveryModal" />
                    <div class="relative w-full max-w-lg rounded-2xl bg-gray-900 border border-gray-700 p-6 shadow-2xl mx-4 max-h-[80vh] overflow-y-auto">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-lg font-semibold text-white">Contas encontradas</h3>
                            <button @click="closeDiscoveryModal" class="text-gray-500 hover:text-white transition">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                            </button>
                        </div>
                        <p class="text-sm text-gray-400 mb-4">Selecione as contas que deseja conectar a esta marca:</p>

                        <div class="space-y-2 mb-6">
                            <button
                                v-for="(account, index) in activeDiscoveredAccounts"
                                :key="index"
                                @click="toggleDiscoveredAccount(index)"
                                :class="[
                                    'w-full rounded-xl border p-4 text-left transition flex items-center gap-3',
                                    selectedDiscovered.includes(index) ? 'border-indigo-500 bg-indigo-600/10' : 'border-gray-700 bg-gray-800/50 hover:border-gray-600',
                                ]"
                            >
                                <div :class="['w-5 h-5 rounded-md border-2 flex items-center justify-center shrink-0 transition', selectedDiscovered.includes(index) ? 'border-indigo-500 bg-indigo-600' : 'border-gray-600']">
                                    <svg v-if="selectedDiscovered.includes(index)" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><polyline points="20 6 9 17 4 12" /></svg>
                                </div>
                                <img v-if="account.avatar_url" :src="account.avatar_url" class="w-10 h-10 rounded-lg object-cover shrink-0" />
                                <div v-else class="w-10 h-10 rounded-lg bg-gray-700 flex items-center justify-center text-gray-400 font-bold shrink-0">
                                    {{ (account.display_name || account.username || '?')[0].toUpperCase() }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-white truncate">{{ account.display_name || account.username }}</p>
                                    <p class="text-xs text-gray-500">@{{ account.username }} · {{ account.type }}</p>
                                    <div v-if="account.metadata" class="flex items-center gap-2 mt-0.5">
                                        <span v-if="account.metadata.followers_count" class="text-[10px] text-gray-500">{{ formatNumber(account.metadata.followers_count) }} seguidores</span>
                                        <span v-if="account.metadata.fan_count" class="text-[10px] text-gray-500">{{ formatNumber(account.metadata.fan_count) }} fas</span>
                                    </div>
                                </div>
                            </button>
                        </div>

                        <div class="flex items-center justify-between border-t border-gray-800 pt-4">
                            <button @click="selectedDiscovered = selectedDiscovered.length === activeDiscoveredAccounts.length ? [] : activeDiscoveredAccounts.map((_, i) => i)" class="text-xs text-gray-400 hover:text-white transition">
                                {{ selectedDiscovered.length === activeDiscoveredAccounts.length ? 'Desmarcar todas' : 'Selecionar todas' }}
                            </button>
                            <div class="flex gap-2">
                                <button @click="closeDiscoveryModal" class="rounded-xl px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                                <button @click="saveDiscoveredAccounts" :disabled="selectedDiscovered.length === 0 || savingAccounts" class="rounded-xl bg-indigo-600 px-6 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                                    <span v-if="savingAccounts" class="inline-flex items-center gap-1.5">
                                        <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                        Salvando...
                                    </span>
                                    <span v-else>Conectar {{ selectedDiscovered.length }} conta(s)</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Modal: Adicionar manualmente -->
        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="showManualModal" class="fixed inset-0 z-[60] flex items-center justify-center">
                    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showManualModal = false" />
                    <div class="relative w-full max-w-md rounded-2xl bg-gray-900 border border-gray-700 p-6 shadow-2xl mx-4">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-semibold text-white">Adicionar manualmente</h3>
                            <button @click="showManualModal = false" class="text-gray-500 hover:text-white transition">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                            </button>
                        </div>

                        <div class="rounded-xl bg-amber-500/10 border border-amber-500/20 p-3 mb-4">
                            <p class="text-xs text-amber-400">Use essa opcao apenas se o OAuth nao estiver disponivel. Voce precisara obter o Access Token diretamente do painel de desenvolvedores da plataforma.</p>
                        </div>

                        <form @submit.prevent="submitManual" class="space-y-4">
                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Plataforma *</label>
                                <select v-model="manualForm.platform" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                                    <option value="">Selecione...</option>
                                    <option v-for="p in platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
                                </select>
                                <InputError :message="manualForm.errors.platform" class="mt-1" />
                            </div>
                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Nome de usuario *</label>
                                <input v-model="manualForm.username" type="text" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="@usuario" required />
                                <InputError :message="manualForm.errors.username" class="mt-1" />
                            </div>
                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Nome de exibicao</label>
                                <input v-model="manualForm.display_name" type="text" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Nome da pagina/perfil" />
                            </div>
                            <div>
                                <label class="text-sm text-gray-400 mb-1 block">Access Token</label>
                                <input v-model="manualForm.access_token" type="password" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Token de acesso" />
                            </div>
                            <div class="flex items-center justify-end gap-3 pt-2">
                                <button type="button" @click="showManualModal = false" class="rounded-xl px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancelar</button>
                                <button type="submit" :disabled="manualForm.processing" class="rounded-xl bg-indigo-600 px-6 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                                    {{ manualForm.processing ? 'Adicionando...' : 'Adicionar' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AuthenticatedLayout>
</template>
