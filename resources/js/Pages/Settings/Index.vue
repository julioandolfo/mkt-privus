<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuideBox from '@/Components/GuideBox.vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';

interface ProviderInfo {
    value: string;
    label: string;
    env_key: string;
}

interface ModelInfo {
    value: string;
    label: string;
    provider: string;
    provider_key: string;
    max_tokens: number;
}

interface ApiKeyStatus {
    configured: boolean;
    source: string;
    masked: string;
}

interface AiUsageStats {
    total_requests: number;
    total_tokens: number;
    estimated_cost: number;
    by_provider: Record<string, { count: number; tokens: number; cost: number }>;
    by_feature: Record<string, number>;
}

interface PushInfo {
    vapid_public_key: string;
    vapid_private_key_set: boolean;
    vapid_subject: string;
    subscriptions_count: number;
    user_subscribed: boolean;
}

const props = defineProps<{
    tab: string;
    general: Record<string, any>;
    ai: Record<string, any>;
    apiKeys: Record<string, ApiKeyStatus>;
    social: Record<string, any>;
    notifications: Record<string, any>;
    email: Record<string, any>;
    emailConfigured: boolean;
    emailSource: string;
    emailPasswordSet: boolean;
    pushInfo: PushInfo;
    oauthCredentials: Record<string, any>;
    availableModels: ModelInfo[];
    providers: ProviderInfo[];
    aiUsageStats: AiUsageStats;
    timezones: Record<string, string>;
}>();

const page = usePage();
const activeTab = ref(props.tab || 'general');
const testingProvider = ref<string | null>(null);
const testResults = ref<Record<string, { success: boolean; message: string }>>({});
const testingEmail = ref(false);
const emailTestResult = ref<{ success: boolean; message: string } | null>(null);
const generatingVapid = ref(false);
const vapidResult = ref<{ success: boolean; message: string; public_key?: string } | null>(null);
const testingPush = ref(false);
const pushTestResult = ref<{ success: boolean; message: string } | null>(null);
const subscribingPush = ref(false);
const pushSubscribeResult = ref<{ success: boolean; message: string } | null>(null);

// Flash message feedback
const saveMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null);
let saveMessageTimeout: ReturnType<typeof setTimeout> | null = null;

function showSaveMessage(type: 'success' | 'error', text: string) {
    if (saveMessageTimeout) clearTimeout(saveMessageTimeout);
    saveMessage.value = { type, text };
    saveMessageTimeout = setTimeout(() => { saveMessage.value = null; }, 5000);
}

// Observar flash messages do Inertia
watch(() => (page.props as any).flash, (flash: any) => {
    if (flash?.success) showSaveMessage('success', flash.success);
    else if (flash?.error) showSaveMessage('error', flash.error);
}, { deep: true, immediate: true });

const tabs = [
    { id: 'general', name: 'Geral', icon: 'sliders' },
    { id: 'users', name: 'Usuarios', icon: 'users' },
    { id: 'ai', name: 'Inteligencia Artificial', icon: 'cpu' },
    { id: 'email', name: 'Email / SMTP', icon: 'mail' },
    { id: 'social', name: 'Social Media', icon: 'share' },
    { id: 'oauth', name: 'Integracoes OAuth', icon: 'link' },
    { id: 'notifications', name: 'Notificacoes', icon: 'bell' },
];

// Forms
const generalForm = useForm({
    app_name: props.general.app_name,
    timezone: props.general.timezone,
    locale: props.general.locale,
    date_format: props.general.date_format,
    time_format: props.general.time_format,
    posts_per_page: props.general.posts_per_page,
});

const aiForm = useForm({
    default_chat_model: props.ai.default_chat_model,
    default_generation_model: props.ai.default_generation_model,
    default_temperature: props.ai.default_temperature,
    default_max_tokens: props.ai.default_max_tokens,
    content_engine_model: props.ai.content_engine_model,
    smart_suggestions_count: props.ai.smart_suggestions_count,
    auto_generate_hashtags: props.ai.auto_generate_hashtags ?? true,
    inject_brand_context: props.ai.inject_brand_context ?? true,
});

const apiKeysForm = useForm({
    openai_api_key: '',
    anthropic_api_key: '',
    gemini_api_key: '',
});

const emailForm = useForm({
    mailer: props.email.mailer || 'smtp',
    host: props.email.host || '',
    port: props.email.port || 587,
    encryption: props.email.encryption || 'tls',
    username: props.email.username || '',
    password: '',
    from_address: props.email.from_address || '',
    from_name: props.email.from_name || '',
});

const pushForm = useForm({
    vapid_public_key: '',
    vapid_private_key: '',
    vapid_subject: props.pushInfo.vapid_subject || '',
});

const socialForm = useForm({
    default_platforms: props.social.default_platforms || ['instagram', 'facebook'],
    autopilot_enabled: props.social.autopilot_enabled ?? true,
    autopilot_max_retries: props.social.autopilot_max_retries || 3,
    autopilot_retry_interval: props.social.autopilot_retry_interval || 15,
    publish_confirmation: props.social.publish_confirmation ?? false,
    default_post_type: props.social.default_post_type || 'feed',
    watermark_enabled: props.social.watermark_enabled ?? false,
});

const notificationsForm = useForm({
    notify_publish_success: props.notifications.notify_publish_success ?? true,
    notify_publish_failure: props.notifications.notify_publish_failure ?? true,
    notify_content_generated: props.notifications.notify_content_generated ?? false,
    notify_token_expiring: props.notifications.notify_token_expiring ?? true,
    email_notifications: props.notifications.email_notifications ?? false,
    email_digest: props.notifications.email_digest || 'none',
    push_enabled: props.notifications.push_enabled ?? false,
});

function changeTab(tab: string) {
    activeTab.value = tab;
    if (tab === 'users' && users.value.length === 0) loadUsers();
    router.get(route('settings.index'), { tab }, { preserveState: true, preserveScroll: true });
}

function saveGeneral() {
    generalForm.put(route('settings.general'), {
        preserveScroll: true,
        onError: () => showSaveMessage('error', 'Erro ao salvar configuracoes gerais. Verifique os campos.'),
    });
}

function saveAI() {
    aiForm.put(route('settings.ai'), {
        preserveScroll: true,
        onError: () => showSaveMessage('error', 'Erro ao salvar preferencias de IA. Verifique os campos.'),
    });
}

function saveApiKeys() {
    apiKeysForm.put(route('settings.api-keys'), {
        preserveScroll: true,
        onSuccess: () => {
            apiKeysForm.reset();
        },
        onError: () => showSaveMessage('error', 'Erro ao salvar chaves de API. Verifique os campos.'),
    });
}

function saveEmail() {
    emailForm.put(route('settings.email'), {
        preserveScroll: true,
        onSuccess: () => {
            emailForm.password = '';
        },
        onError: () => showSaveMessage('error', 'Erro ao salvar configuracoes de email. Verifique os campos.'),
    });
}

function savePush() {
    pushForm.put(route('settings.push'), {
        preserveScroll: true,
        onSuccess: () => {
            pushForm.vapid_private_key = '';
        },
        onError: () => showSaveMessage('error', 'Erro ao salvar configuracoes de Push. Verifique os campos.'),
    });
}

function saveSocial() {
    socialForm.put(route('settings.social'), {
        preserveScroll: true,
        onError: () => showSaveMessage('error', 'Erro ao salvar configuracoes de Social Media. Verifique os campos.'),
    });
}

function saveNotifications() {
    notificationsForm.put(route('settings.notifications'), {
        preserveScroll: true,
        onError: () => showSaveMessage('error', 'Erro ao salvar configuracoes de notificacoes. Verifique os campos.'),
    });
}

async function testConnection(provider: string) {
    testingProvider.value = provider;
    try {
        const response = await axios.post(route('settings.test-ai'), { provider });
        testResults.value[provider] = response.data;
    } catch (error: any) {
        testResults.value[provider] = { success: false, message: 'Erro ao testar conexao.' };
    } finally {
        testingProvider.value = null;
    }
}

async function testEmail() {
    testingEmail.value = true;
    emailTestResult.value = null;
    try {
        const response = await axios.post(route('settings.test-email'));
        emailTestResult.value = response.data;
    } catch (error: any) {
        emailTestResult.value = { success: false, message: error.response?.data?.message || 'Erro ao enviar email de teste.' };
    } finally {
        testingEmail.value = false;
    }
}

async function generateVapidKeys() {
    generatingVapid.value = true;
    vapidResult.value = null;
    try {
        const response = await axios.post(route('settings.push.generate-vapid'));
        vapidResult.value = response.data;
        if (response.data.public_key) {
            pushForm.vapid_public_key = response.data.public_key;
        }
    } catch (error: any) {
        vapidResult.value = { success: false, message: 'Erro ao gerar chaves VAPID.' };
    } finally {
        generatingVapid.value = false;
    }
}

async function subscribePush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        pushSubscribeResult.value = { success: false, message: 'Seu navegador nao suporta Push Notifications.' };
        return;
    }

    subscribingPush.value = true;
    pushSubscribeResult.value = null;

    try {
        const registration = await navigator.serviceWorker.register('/sw.js');
        await navigator.serviceWorker.ready;

        const vapidKey = props.pushInfo.vapid_public_key;
        if (!vapidKey) {
            pushSubscribeResult.value = { success: false, message: 'Chave VAPID publica nao configurada. Gere as chaves primeiro.' };
            subscribingPush.value = false;
            return;
        }

        // Converter base64url para Uint8Array
        const padding = '='.repeat((4 - (vapidKey.length % 4)) % 4);
        const base64 = (vapidKey + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const applicationServerKey = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            applicationServerKey[i] = rawData.charCodeAt(i);
        }

        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey,
        });

        const subJson = subscription.toJSON();

        const response = await axios.post(route('settings.push.subscribe'), {
            endpoint: subJson.endpoint,
            keys: subJson.keys,
            contentEncoding: (PushManager.supportedContentEncodings || ['aesgcm'])[0],
        });

        pushSubscribeResult.value = response.data;
    } catch (error: any) {
        if (error.name === 'NotAllowedError') {
            pushSubscribeResult.value = { success: false, message: 'Permissao de notificacao negada. Habilite nas configuracoes do navegador.' };
        } else {
            pushSubscribeResult.value = { success: false, message: 'Erro: ' + (error.message || 'Falha ao ativar push.') };
        }
    } finally {
        subscribingPush.value = false;
    }
}

async function unsubscribePush() {
    subscribingPush.value = true;
    try {
        if ('serviceWorker' in navigator) {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
                await subscription.unsubscribe();
            }
        }
        const response = await axios.post(route('settings.push.unsubscribe'));
        pushSubscribeResult.value = response.data;
    } catch (error: any) {
        pushSubscribeResult.value = { success: false, message: 'Erro ao desativar push.' };
    } finally {
        subscribingPush.value = false;
    }
}

async function testPush() {
    testingPush.value = true;
    pushTestResult.value = null;
    try {
        const response = await axios.post(route('settings.push.test'));
        pushTestResult.value = response.data;
    } catch (error: any) {
        pushTestResult.value = { success: false, message: 'Erro ao enviar push de teste.' };
    } finally {
        testingPush.value = false;
    }
}

function clearCache() {
    router.post(route('settings.clear-cache'));
}

function toggleSocialPlatform(platform: string) {
    const idx = socialForm.default_platforms.indexOf(platform);
    if (idx >= 0) {
        if (socialForm.default_platforms.length > 1) socialForm.default_platforms.splice(idx, 1);
    } else {
        socialForm.default_platforms.push(platform);
    }
}

const platformOptions = [
    { value: 'instagram', label: 'Instagram', color: '#E4405F' },
    { value: 'facebook', label: 'Facebook', color: '#1877F2' },
    { value: 'linkedin', label: 'LinkedIn', color: '#0A66C2' },
    { value: 'tiktok', label: 'TikTok', color: '#000000' },
    { value: 'youtube', label: 'YouTube', color: '#FF0000' },
    { value: 'pinterest', label: 'Pinterest', color: '#BD081C' },
];

const postTypeOptions = [
    { value: 'feed', label: 'Post Feed' },
    { value: 'carousel', label: 'Carrossel' },
    { value: 'story', label: 'Story' },
    { value: 'reel', label: 'Reel / TikTok' },
    { value: 'video', label: 'Video' },
    { value: 'pin', label: 'Pin' },
];

const dateFormatOptions = [
    { value: 'd/m/Y', label: 'DD/MM/AAAA (31/12/2026)' },
    { value: 'Y-m-d', label: 'AAAA-MM-DD (2026-12-31)' },
    { value: 'm/d/Y', label: 'MM/DD/AAAA (12/31/2026)' },
    { value: 'd M Y', label: 'DD Mes AAAA (31 Dez 2026)' },
];

const mailerOptions = [
    { value: 'smtp', label: 'SMTP' },
    { value: 'sendmail', label: 'Sendmail' },
    { value: 'ses', label: 'Amazon SES' },
    { value: 'postmark', label: 'Postmark' },
    { value: 'resend', label: 'Resend' },
    { value: 'log', label: 'Log (apenas teste)' },
];

const encryptionOptions = [
    { value: 'tls', label: 'TLS (porta 587)' },
    { value: 'ssl', label: 'SSL (porta 465)' },
    { value: 'none', label: 'Nenhuma (porta 25)' },
];

const smtpPresets = [
    { name: 'Gmail', host: 'smtp.gmail.com', port: 587, encryption: 'tls' },
    { name: 'Outlook/Office 365', host: 'smtp.office365.com', port: 587, encryption: 'tls' },
    { name: 'Yahoo', host: 'smtp.mail.yahoo.com', port: 587, encryption: 'tls' },
    { name: 'Zoho Mail', host: 'smtp.zoho.com', port: 587, encryption: 'tls' },
    { name: 'SendGrid', host: 'smtp.sendgrid.net', port: 587, encryption: 'tls' },
    { name: 'Mailgun', host: 'smtp.mailgun.org', port: 587, encryption: 'tls' },
    { name: 'Amazon SES', host: 'email-smtp.us-east-1.amazonaws.com', port: 587, encryption: 'tls' },
    { name: 'Brevo (Sendinblue)', host: 'smtp-relay.brevo.com', port: 587, encryption: 'tls' },
];

function applySmtpPreset(preset: typeof smtpPresets[0]) {
    emailForm.host = preset.host;
    emailForm.port = preset.port;
    emailForm.encryption = preset.encryption;
}

const featureLabels: Record<string, string> = {
    chat: 'Chat IA',
    post_generation: 'Geracao de Posts',
    content_engine: 'Content Engine',
    smart_suggestions: 'Sugestoes Inteligentes',
};

const providerColors: Record<string, string> = {
    openai: 'text-emerald-400',
    anthropic: 'text-orange-400',
    google: 'text-blue-400',
};

const providerBgColors: Record<string, string> = {
    openai: 'bg-emerald-500/20 border-emerald-500/30',
    anthropic: 'bg-orange-500/20 border-orange-500/30',
    google: 'bg-blue-500/20 border-blue-500/30',
};

// Users
const users = ref<any[]>([]);
const loadingUsers = ref(false);
const showUserForm = ref(false);
const editingUser = ref<any>(null);
const userSearch = ref('');
const userStatusFilter = ref('all');
const deletingUserId = ref<number | null>(null);

const userForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    is_active: true,
});

async function loadUsers() {
    loadingUsers.value = true;
    try {
        const params = new URLSearchParams();
        if (userSearch.value) params.set('search', userSearch.value);
        if (userStatusFilter.value !== 'all') params.set('status', userStatusFilter.value);
        const response = await axios.get(route('settings.users.index') + '?' + params.toString());
        users.value = response.data;
    } catch (e) {
        console.error('Erro ao carregar usuarios', e);
    } finally {
        loadingUsers.value = false;
    }
}

function openCreateUser() {
    editingUser.value = null;
    userForm.reset();
    userForm.is_active = true;
    showUserForm.value = true;
}

function openEditUser(user: any) {
    editingUser.value = user;
    userForm.name = user.name;
    userForm.email = user.email;
    userForm.password = '';
    userForm.password_confirmation = '';
    userForm.is_active = user.is_active;
    showUserForm.value = true;
}

function submitUserForm() {
    if (editingUser.value) {
        userForm.put(route('settings.users.update', editingUser.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                showUserForm.value = false;
                editingUser.value = null;
                userForm.reset();
                loadUsers();
            },
        });
    } else {
        userForm.post(route('settings.users.store'), {
            preserveScroll: true,
            onSuccess: () => {
                showUserForm.value = false;
                userForm.reset();
                loadUsers();
            },
        });
    }
}

function toggleUser(userId: number) {
    router.post(route('settings.users.toggle', userId), {}, {
        preserveScroll: true,
        onFinish: () => loadUsers(),
    });
}

function deleteUser(user: any) {
    if (!confirm(`Excluir o usuario "${user.name}"? Esta acao e irreversivel.`)) return;
    deletingUserId.value = user.id;
    router.delete(route('settings.users.destroy', user.id), {
        preserveScroll: true,
        onFinish: () => {
            deletingUserId.value = null;
            loadUsers();
        },
    });
}

// OAuth Form
const oauthForm = useForm({
    meta_app_id: props.oauthCredentials?.meta_app_id || '',
    meta_app_secret: '',
    linkedin_client_id: props.oauthCredentials?.linkedin_client_id || '',
    linkedin_client_secret: '',
    google_client_id: props.oauthCredentials?.google_client_id || '',
    google_client_secret: '',
    google_ads_developer_token: '',
    tiktok_client_key: props.oauthCredentials?.tiktok_client_key || '',
    tiktok_client_secret: '',
    pinterest_app_id: props.oauthCredentials?.pinterest_app_id || '',
    pinterest_app_secret: '',
});

function saveOAuth() {
    oauthForm.put(route('settings.oauth'), {
        preserveScroll: true,
        onSuccess: () => {
            // Limpar campos de secret após salvar
            oauthForm.meta_app_secret = '';
            oauthForm.linkedin_client_secret = '';
            oauthForm.google_client_secret = '';
            oauthForm.google_ads_developer_token = '';
            oauthForm.tiktok_client_secret = '';
            oauthForm.pinterest_app_secret = '';
        },
        onError: () => showSaveMessage('error', 'Erro ao salvar credenciais OAuth. Verifique os campos.'),
    });
}

const oauthProviders = [
    {
        key: 'meta',
        label: 'Meta (Facebook, Instagram, Meta Ads)',
        color: '#1877F2',
        fields: [
            { key: 'meta_app_id', label: 'App ID', type: 'text' },
            { key: 'meta_app_secret', label: 'App Secret', type: 'password', isSet: props.oauthCredentials?.meta_app_secret_set },
        ],
        helpUrl: 'https://developers.facebook.com/apps/',
        helpText: 'Crie um app em developers.facebook.com com permissoes de Pages, Instagram e Marketing API (Ads).',
    },
    {
        key: 'linkedin',
        label: 'LinkedIn',
        color: '#0A66C2',
        fields: [
            { key: 'linkedin_client_id', label: 'Client ID', type: 'text' },
            { key: 'linkedin_client_secret', label: 'Client Secret', type: 'password', isSet: props.oauthCredentials?.linkedin_client_secret_set },
        ],
        helpUrl: 'https://www.linkedin.com/developers/apps/',
        helpText: 'Crie um app no LinkedIn Developers com Sign In with LinkedIn e Community Management API.',
    },
    {
        key: 'google',
        label: 'Google (YouTube, Analytics, Ads, Search Console)',
        color: '#4285F4',
        fields: [
            { key: 'google_client_id', label: 'Client ID', type: 'text' },
            { key: 'google_client_secret', label: 'Client Secret', type: 'password', isSet: props.oauthCredentials?.google_client_secret_set },
            { key: 'google_ads_developer_token', label: 'Google Ads Developer Token', type: 'password', isSet: props.oauthCredentials?.google_ads_developer_token_set, hint: 'Obtido em ads.google.com → Ferramentas → Centro de API' },
        ],
        helpUrl: 'https://console.cloud.google.com/apis/credentials',
        helpText: 'Crie credenciais OAuth no Google Cloud Console. Habilite YouTube Data API, Analytics Data API, Google Ads API e Search Console API. O Developer Token do Google Ads é obtido em ads.google.com → Ferramentas → Centro de API.',
    },
    {
        key: 'tiktok',
        label: 'TikTok',
        color: '#000000',
        fields: [
            { key: 'tiktok_client_key', label: 'Client Key', type: 'text' },
            { key: 'tiktok_client_secret', label: 'Client Secret', type: 'password', isSet: props.oauthCredentials?.tiktok_client_secret_set },
        ],
        helpUrl: 'https://developers.tiktok.com/',
        helpText: 'Registre um app no TikTok for Developers com Login Kit e Content Posting API.',
    },
    {
        key: 'pinterest',
        label: 'Pinterest',
        color: '#BD081C',
        fields: [
            { key: 'pinterest_app_id', label: 'App ID', type: 'text' },
            { key: 'pinterest_app_secret', label: 'App Secret', type: 'password', isSet: props.oauthCredentials?.pinterest_app_secret_set },
        ],
        helpUrl: 'https://developers.pinterest.com/',
        helpText: 'Crie um app no Pinterest Developers com Pins e Boards API.',
    },
];

const pushSupported = ref(false);

onMounted(() => {
    pushSupported.value = 'serviceWorker' in navigator && 'PushManager' in window;
    if (activeTab.value === 'users') loadUsers();
});
</script>

<template>
    <Head title="Configuracoes" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold text-white">Configuracoes</h1>
            </div>
        </template>

        <!-- Flash message de save -->
        <Transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-2"
        >
            <div v-if="saveMessage" :class="['mb-4 rounded-xl px-5 py-3.5 text-sm font-medium flex items-center justify-between',
                saveMessage.type === 'success' ? 'bg-emerald-900/30 border border-emerald-700/40 text-emerald-300' : 'bg-red-900/30 border border-red-700/40 text-red-300']">
                <div class="flex items-center gap-2.5">
                    <svg v-if="saveMessage.type === 'success'" class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <svg v-else class="w-5 h-5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                    <span>{{ saveMessage.text }}</span>
                </div>
                <button @click="saveMessage = null" class="text-gray-400 hover:text-white ml-4 shrink-0">&times;</button>
            </div>
        </Transition>

        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Sidebar de tabs -->
            <div class="lg:w-56 shrink-0">
                <nav class="flex lg:flex-col gap-1 overflow-x-auto lg:overflow-visible pb-2 lg:pb-0">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        @click="changeTab(tab.id)"
                        :class="[
                            'flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition whitespace-nowrap',
                            activeTab === tab.id
                                ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/30'
                                : 'text-gray-400 hover:text-white hover:bg-gray-800',
                        ]"
                    >
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <template v-if="tab.icon === 'sliders'">
                                <line x1="4" y1="21" x2="4" y2="14" /><line x1="4" y1="10" x2="4" y2="3" /><line x1="12" y1="21" x2="12" y2="12" /><line x1="12" y1="8" x2="12" y2="3" /><line x1="20" y1="21" x2="20" y2="16" /><line x1="20" y1="12" x2="20" y2="3" /><line x1="1" y1="14" x2="7" y2="14" /><line x1="9" y1="8" x2="15" y2="8" /><line x1="17" y1="16" x2="23" y2="16" />
                            </template>
                            <template v-else-if="tab.icon === 'cpu'">
                                <rect x="4" y="4" width="16" height="16" rx="2" ry="2" /><rect x="9" y="9" width="6" height="6" /><line x1="9" y1="1" x2="9" y2="4" /><line x1="15" y1="1" x2="15" y2="4" /><line x1="9" y1="20" x2="9" y2="23" /><line x1="15" y1="20" x2="15" y2="23" /><line x1="20" y1="9" x2="23" y2="9" /><line x1="20" y1="14" x2="23" y2="14" /><line x1="1" y1="9" x2="4" y2="9" /><line x1="1" y1="14" x2="4" y2="14" />
                            </template>
                            <template v-else-if="tab.icon === 'mail'">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" /><polyline points="22,6 12,13 2,6" />
                            </template>
                            <template v-else-if="tab.icon === 'share'">
                                <circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><line x1="8.59" y1="13.51" x2="15.42" y2="17.49" /><line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
                            </template>
                            <template v-else-if="tab.icon === 'users'">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" />
                            </template>
                            <template v-else-if="tab.icon === 'link'">
                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" /><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                            </template>
                            <template v-else-if="tab.icon === 'bell'">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.73 21a2 2 0 0 1-3.46 0" />
                            </template>
                        </svg>
                        {{ tab.name }}
                    </button>
                </nav>
            </div>

            <!-- Conteudo -->
            <div class="flex-1 min-w-0">
                <!-- TAB: GERAL -->
                <div v-if="activeTab === 'general'">
                    <form @submit.prevent="saveGeneral" class="space-y-6">
                        <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                            <h2 class="text-lg font-semibold text-white mb-6">Configuracoes Gerais</h2>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Nome do Sistema</label>
                                    <input v-model="generalForm.app_name" type="text" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Fuso Horario</label>
                                    <select v-model="generalForm.timezone" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                        <option v-for="(label, value) in timezones" :key="value" :value="value">{{ label }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Idioma</label>
                                    <select v-model="generalForm.locale" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="pt_BR">Portugues (Brasil)</option>
                                        <option value="en">English</option>
                                        <option value="es">Espanol</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Formato de Data</label>
                                    <select v-model="generalForm.date_format" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                        <option v-for="opt in dateFormatOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Posts por Pagina</label>
                                    <input v-model.number="generalForm.posts_per_page" type="number" min="6" max="48" step="3" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
                            </div>
                        </div>

                        <!-- Manutencao -->
                        <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                            <h2 class="text-lg font-semibold text-white mb-4">Manutencao</h2>
                            <div class="flex items-center gap-4">
                                <button type="button" @click="clearCache" class="rounded-xl bg-gray-800 border border-gray-700 px-4 py-2.5 text-sm font-medium text-gray-300 hover:bg-gray-700 hover:text-white transition">
                                    Limpar Cache
                                </button>
                                <span class="text-xs text-gray-500">Limpa o cache do sistema, incluindo configuracoes e dados temporarios.</span>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" :disabled="generalForm.processing" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                                {{ generalForm.processing ? 'Salvando...' : 'Salvar Configuracoes' }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- TAB: USUARIOS -->
                <div v-if="activeTab === 'users'" class="space-y-6">
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h2 class="text-lg font-semibold text-white">Gerenciamento de Usuarios</h2>
                                <p class="text-sm text-gray-500 mt-1">Crie, edite e gerencie os usuarios com acesso a plataforma.</p>
                            </div>
                            <button @click="openCreateUser" class="flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
                                Novo Usuario
                            </button>
                        </div>

                        <!-- Filtros -->
                        <div class="flex flex-wrap items-center gap-3 mb-5">
                            <div class="flex-1 min-w-[200px]">
                                <input
                                    v-model="userSearch"
                                    @input="loadUsers"
                                    type="text"
                                    placeholder="Buscar por nome ou email..."
                                    class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500 pl-10"
                                />
                            </div>
                            <select
                                v-model="userStatusFilter"
                                @change="loadUsers"
                                class="rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="all">Todos</option>
                                <option value="active">Ativos</option>
                                <option value="inactive">Inativos</option>
                            </select>
                        </div>

                        <!-- Loading -->
                        <div v-if="loadingUsers" class="flex items-center justify-center py-12">
                            <svg class="w-6 h-6 text-indigo-400 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                        </div>

                        <!-- Lista de Usuarios -->
                        <div v-else-if="users.length > 0" class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead>
                                    <tr class="text-[10px] text-gray-500 uppercase tracking-wider border-b border-gray-800">
                                        <th class="pb-3 pr-4">Usuario</th>
                                        <th class="pb-3 pr-4">Status</th>
                                        <th class="pb-3 pr-4">Marcas</th>
                                        <th class="pb-3 pr-4">Posts</th>
                                        <th class="pb-3 pr-4">Ultimo Login</th>
                                        <th class="pb-3 pr-4">Cadastrado em</th>
                                        <th class="pb-3 text-right">Acoes</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-800/50">
                                    <tr v-for="user in users" :key="user.id" class="hover:bg-gray-800/30 transition">
                                        <td class="py-3 pr-4">
                                            <div class="flex items-center gap-3">
                                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                                                    {{ user.name.charAt(0).toUpperCase() }}
                                                </div>
                                                <div>
                                                    <p class="text-sm font-medium text-white">
                                                        {{ user.name }}
                                                        <span v-if="user.is_current" class="text-[10px] text-indigo-400 ml-1">(voce)</span>
                                                    </p>
                                                    <p class="text-[11px] text-gray-500">{{ user.email }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <span :class="[
                                                'inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-medium',
                                                user.is_active ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400'
                                            ]">
                                                <span :class="['w-1.5 h-1.5 rounded-full', user.is_active ? 'bg-emerald-400' : 'bg-red-400']"/>
                                                {{ user.is_active ? 'Ativo' : 'Inativo' }}
                                            </span>
                                        </td>
                                        <td class="py-3 pr-4 text-xs text-gray-400 tabular-nums">{{ user.brands_count }}</td>
                                        <td class="py-3 pr-4 text-xs text-gray-400 tabular-nums">{{ user.posts_count }}</td>
                                        <td class="py-3 pr-4">
                                            <div v-if="user.last_login_at">
                                                <p class="text-xs text-gray-400">{{ user.last_login_at }}</p>
                                                <p v-if="user.last_login_ip" class="text-[10px] text-gray-600">{{ user.last_login_ip }}</p>
                                            </div>
                                            <span v-else class="text-xs text-gray-600">Nunca</span>
                                        </td>
                                        <td class="py-3 pr-4 text-xs text-gray-500">{{ user.created_at }}</td>
                                        <td class="py-3 text-right">
                                            <div class="flex items-center justify-end gap-1">
                                                <button
                                                    @click="openEditUser(user)"
                                                    class="p-1.5 text-gray-400 hover:text-indigo-400 rounded-lg hover:bg-gray-800 transition"
                                                    title="Editar"
                                                >
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                        <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                                    </svg>
                                                </button>
                                                <button
                                                    v-if="!user.is_current"
                                                    @click="toggleUser(user.id)"
                                                    class="p-1.5 rounded-lg hover:bg-gray-800 transition"
                                                    :class="user.is_active ? 'text-emerald-400 hover:text-amber-400' : 'text-gray-600 hover:text-emerald-400'"
                                                    :title="user.is_active ? 'Desativar' : 'Ativar'"
                                                >
                                                    <svg v-if="user.is_active" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                </button>
                                                <button
                                                    v-if="!user.is_current"
                                                    @click="deleteUser(user)"
                                                    :disabled="deletingUserId === user.id"
                                                    class="p-1.5 text-gray-400 hover:text-red-400 rounded-lg hover:bg-gray-800 transition disabled:opacity-40"
                                                    title="Excluir"
                                                >
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Vazio -->
                        <div v-else class="text-center py-12">
                            <svg class="w-12 h-12 text-gray-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>
                            </svg>
                            <p class="text-sm text-gray-500">Nenhum usuario encontrado.</p>
                        </div>
                    </div>

                    <!-- Modal de Criar/Editar Usuario -->
                    <div v-if="showUserForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showUserForm = false">
                        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 w-full max-w-md mx-4">
                            <div class="flex items-center justify-between mb-5">
                                <h3 class="text-lg font-semibold text-white">
                                    {{ editingUser ? 'Editar Usuario' : 'Novo Usuario' }}
                                </h3>
                                <button @click="showUserForm = false" class="text-gray-400 hover:text-white transition">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>

                            <form @submit.prevent="submitUserForm" class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Nome</label>
                                    <input v-model="userForm.name" type="text" required class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Nome completo" />
                                    <p v-if="userForm.errors.name" class="text-xs text-red-400 mt-1">{{ userForm.errors.name }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Email</label>
                                    <input v-model="userForm.email" type="email" required class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="usuario@email.com" />
                                    <p v-if="userForm.errors.email" class="text-xs text-red-400 mt-1">{{ userForm.errors.email }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">
                                        Senha
                                        <span v-if="editingUser" class="text-[10px] text-gray-500 ml-1">(deixe vazio para manter)</span>
                                    </label>
                                    <input v-model="userForm.password" type="password" :required="!editingUser" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="••••••••" />
                                    <p v-if="userForm.errors.password" class="text-xs text-red-400 mt-1">{{ userForm.errors.password }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Confirmar Senha</label>
                                    <input v-model="userForm.password_confirmation" type="password" :required="!!userForm.password" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="••••••••" />
                                </div>

                                <label class="flex items-center gap-3 cursor-pointer pt-1">
                                    <input v-model="userForm.is_active" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                                    <div>
                                        <span class="text-sm text-gray-300">Usuario ativo</span>
                                        <p class="text-[11px] text-gray-600">Usuarios inativos nao conseguem fazer login.</p>
                                    </div>
                                </label>

                                <div class="flex gap-3 pt-2">
                                    <button type="button" @click="showUserForm = false" class="flex-1 px-4 py-2.5 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl text-sm transition border border-gray-700">
                                        Cancelar
                                    </button>
                                    <button type="submit" :disabled="userForm.processing" class="flex-1 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-xl text-sm font-semibold transition">
                                        {{ userForm.processing ? 'Salvando...' : (editingUser ? 'Salvar Alteracoes' : 'Criar Usuario') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- TAB: IA -->
                <div v-if="activeTab === 'ai'" class="space-y-6">
                    <!-- Chaves de API -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                        <h2 class="text-lg font-semibold text-white mb-2">Chaves de API</h2>
                        <p class="text-sm text-gray-500 mb-6">Configure as chaves de acesso para cada provedor de IA. Chaves sao armazenadas de forma criptografada.</p>

                        <div class="space-y-5">
                            <div v-for="provider in providers" :key="provider.value" class="rounded-xl bg-gray-800/50 border border-gray-700 p-4">
                                <div class="flex items-center justify-between mb-3">
                                    <div class="flex items-center gap-3">
                                        <span :class="['text-sm font-semibold', providerColors[provider.value] || 'text-gray-300']">{{ provider.label }}</span>
                                        <span v-if="apiKeys[provider.value]?.configured" :class="['rounded-full border px-2 py-0.5 text-[10px] font-medium', providerBgColors[provider.value]]">
                                            {{ apiKeys[provider.value].source === 'database' ? 'Banco de Dados' : '.env' }}
                                        </span>
                                        <span v-else class="rounded-full bg-red-500/10 border border-red-500/30 px-2 py-0.5 text-[10px] font-medium text-red-400">
                                            Nao configurada
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        @click="testConnection(provider.value)"
                                        :disabled="testingProvider === provider.value || !apiKeys[provider.value]?.configured"
                                        class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-400 hover:text-white hover:bg-gray-700 border border-gray-600 transition disabled:opacity-40"
                                    >
                                        {{ testingProvider === provider.value ? 'Testando...' : 'Testar Conexao' }}
                                    </button>
                                </div>

                                <div v-if="apiKeys[provider.value]?.configured" class="mb-3">
                                    <p class="text-xs text-gray-500 mb-1">Chave atual:</p>
                                    <code class="text-xs text-gray-400 font-mono bg-gray-900 rounded px-2 py-1">{{ apiKeys[provider.value].masked }}</code>
                                </div>

                                <div>
                                    <label class="text-xs text-gray-500 mb-1 block">{{ apiKeys[provider.value]?.configured ? 'Substituir chave:' : 'Adicionar chave:' }}</label>
                                    <input
                                        v-model="apiKeysForm[`${provider.value}_api_key` as keyof typeof apiKeysForm]"
                                        type="password"
                                        class="w-full rounded-lg bg-gray-900 border-gray-600 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        :placeholder="`Cole sua ${provider.label} API Key aqui...`"
                                    />
                                </div>

                                <div v-if="testResults[provider.value]" class="mt-3">
                                    <div :class="['rounded-lg p-2.5 text-xs', testResults[provider.value].success ? 'bg-green-500/10 border border-green-500/30 text-green-400' : 'bg-red-500/10 border border-red-500/30 text-red-400']">
                                        {{ testResults[provider.value].message }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end mt-6">
                            <button @click="saveApiKeys" :disabled="apiKeysForm.processing" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                                {{ apiKeysForm.processing ? 'Salvando...' : 'Salvar Chaves' }}
                            </button>
                        </div>
                    </div>

                    <!-- Modelos e Preferencias -->
                    <form @submit.prevent="saveAI">
                        <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                            <h2 class="text-lg font-semibold text-white mb-6">Modelos e Preferencias</h2>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Modelo padrao (Chat)</label>
                                    <select v-model="aiForm.default_chat_model" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                        <option v-for="m in availableModels" :key="m.value" :value="m.value">{{ m.label }} ({{ m.provider }})</option>
                                    </select>
                                    <p class="text-[11px] text-gray-600 mt-1">Modelo usado nas conversas do Chat IA.</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Modelo padrao (Geracao de Posts)</label>
                                    <select v-model="aiForm.default_generation_model" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                        <option v-for="m in availableModels" :key="m.value" :value="m.value">{{ m.label }} ({{ m.provider }})</option>
                                    </select>
                                    <p class="text-[11px] text-gray-600 mt-1">Modelo usado ao gerar legendas e hashtags.</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Modelo (Content Engine)</label>
                                    <select v-model="aiForm.content_engine_model" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                        <option v-for="m in availableModels" :key="m.value" :value="m.value">{{ m.label }} ({{ m.provider }})</option>
                                    </select>
                                    <p class="text-[11px] text-gray-600 mt-1">Modelo para pautas e sugestoes automaticas.</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Sugestoes inteligentes (quantidade)</label>
                                    <input v-model.number="aiForm.smart_suggestions_count" type="number" min="1" max="10" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                    <p class="text-[11px] text-gray-600 mt-1">Quantas sugestoes gerar por vez.</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Temperatura (Criatividade)</label>
                                    <div class="flex items-center gap-3">
                                        <input v-model.number="aiForm.default_temperature" type="range" min="0" max="2" step="0.1" class="flex-1 accent-indigo-600" />
                                        <span class="text-sm text-gray-400 w-10 text-right">{{ aiForm.default_temperature }}</span>
                                    </div>
                                    <p class="text-[11px] text-gray-600 mt-1">0 = deterministico, 1 = balanceado, 2 = muito criativo.</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Max Tokens (resposta)</label>
                                    <input v-model.number="aiForm.default_max_tokens" type="number" min="256" max="32768" step="256" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                    <p class="text-[11px] text-gray-600 mt-1">Limite de tokens na resposta da IA.</p>
                                </div>

                                <div class="sm:col-span-2 flex flex-col gap-4">
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input v-model="aiForm.inject_brand_context" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                                        <div>
                                            <span class="text-sm text-gray-300">Injetar contexto da marca</span>
                                            <p class="text-[11px] text-gray-600">Envia automaticamente dados da marca ativa como contexto para a IA.</p>
                                        </div>
                                    </label>
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input v-model="aiForm.auto_generate_hashtags" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                                        <div>
                                            <span class="text-sm text-gray-300">Gerar hashtags automaticamente</span>
                                            <p class="text-[11px] text-gray-600">Ao gerar legenda com IA, incluir hashtags sugeridas.</p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end mt-6">
                            <button type="submit" :disabled="aiForm.processing" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                                {{ aiForm.processing ? 'Salvando...' : 'Salvar Preferencias IA' }}
                            </button>
                        </div>
                    </form>

                    <!-- Uso de IA este mes -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                        <h2 class="text-lg font-semibold text-white mb-4">Uso de IA - Este Mes</h2>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
                            <div class="rounded-xl bg-gray-800 p-4">
                                <p class="text-2xl font-bold text-indigo-400">{{ aiUsageStats.total_requests }}</p>
                                <p class="text-xs text-gray-500 mt-1">Requisicoes</p>
                            </div>
                            <div class="rounded-xl bg-gray-800 p-4">
                                <p class="text-2xl font-bold text-purple-400">{{ (aiUsageStats.total_tokens / 1000).toFixed(1) }}k</p>
                                <p class="text-xs text-gray-500 mt-1">Tokens consumidos</p>
                            </div>
                            <div class="rounded-xl bg-gray-800 p-4">
                                <p class="text-2xl font-bold text-emerald-400">$ {{ aiUsageStats.estimated_cost.toFixed(4) }}</p>
                                <p class="text-xs text-gray-500 mt-1">Custo estimado (USD)</p>
                            </div>
                        </div>

                        <div v-if="Object.keys(aiUsageStats.by_provider).length > 0">
                            <h3 class="text-sm font-medium text-gray-400 mb-3">Por Provedor</h3>
                            <div class="space-y-2">
                                <div v-for="(stats, provider) in aiUsageStats.by_provider" :key="provider" class="flex items-center gap-3 rounded-lg bg-gray-800/50 p-3">
                                    <span :class="['text-sm font-medium w-24', providerColors[provider] || 'text-gray-300']">{{ provider }}</span>
                                    <span class="text-xs text-gray-500">{{ stats.count }} req</span>
                                    <span class="text-xs text-gray-500">{{ (stats.tokens / 1000).toFixed(1) }}k tokens</span>
                                    <span class="text-xs text-gray-500 ml-auto">${{ stats.cost.toFixed(4) }}</span>
                                </div>
                            </div>
                        </div>

                        <div v-if="Object.keys(aiUsageStats.by_feature).length > 0" class="mt-4">
                            <h3 class="text-sm font-medium text-gray-400 mb-3">Por Funcionalidade</h3>
                            <div class="flex flex-wrap gap-2">
                                <span v-for="(count, feature) in aiUsageStats.by_feature" :key="feature" class="rounded-lg bg-gray-800 px-3 py-1.5 text-xs text-gray-400">
                                    {{ featureLabels[feature as string] || feature }}: <span class="text-white font-medium">{{ count }}</span>
                                </span>
                            </div>
                        </div>

                        <div v-if="aiUsageStats.total_requests === 0" class="text-center py-6 text-gray-500 text-sm">
                            Nenhum uso de IA registrado neste mes.
                        </div>
                    </div>
                </div>

                <!-- TAB: EMAIL / SMTP -->
                <div v-if="activeTab === 'email'" class="space-y-6">
                    <!-- Status -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h2 class="text-lg font-semibold text-white">Servidor de Email (SMTP)</h2>
                                <p class="text-sm text-gray-500 mt-1">Configure o servidor de email para envio de notificacoes, alertas e resumos.</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span v-if="emailConfigured" class="rounded-full bg-emerald-500/10 border border-emerald-500/30 px-3 py-1 text-xs font-medium text-emerald-400">
                                    Configurado ({{ emailSource === 'database' ? 'Banco de Dados' : '.env' }})
                                </span>
                                <span v-else class="rounded-full bg-red-500/10 border border-red-500/30 px-3 py-1 text-xs font-medium text-red-400">
                                    Nao configurado
                                </span>
                            </div>
                        </div>

                        <!-- Presets SMTP -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Presets rapidos</label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="preset in smtpPresets"
                                    :key="preset.name"
                                    type="button"
                                    @click="applySmtpPreset(preset)"
                                    class="rounded-lg bg-gray-800 border border-gray-700 px-3 py-1.5 text-xs text-gray-400 hover:text-white hover:border-indigo-500/50 transition"
                                >
                                    {{ preset.name }}
                                </button>
                            </div>
                        </div>

                        <form @submit.prevent="saveEmail">
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Metodo de envio</label>
                                    <select v-model="emailForm.mailer" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                        <option v-for="opt in mailerOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Criptografia</label>
                                    <select v-model="emailForm.encryption" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                        <option v-for="opt in encryptionOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Servidor SMTP</label>
                                    <input v-model="emailForm.host" type="text" placeholder="smtp.gmail.com" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Porta</label>
                                    <input v-model.number="emailForm.port" type="number" min="1" max="65535" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Usuario</label>
                                    <input v-model="emailForm.username" type="text" placeholder="seu@email.com" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">
                                        Senha
                                        <span v-if="emailPasswordSet" class="text-emerald-500 text-[10px] ml-1">(definida)</span>
                                    </label>
                                    <input v-model="emailForm.password" type="password" :placeholder="emailPasswordSet ? 'Deixe vazio para manter a atual' : 'Senha SMTP'" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>

                                <div class="sm:col-span-2 border-t border-gray-800 pt-5 mt-1">
                                    <h3 class="text-sm font-medium text-gray-300 mb-4">Remetente padrao</h3>
                                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-300 mb-1">Email do remetente</label>
                                            <input v-model="emailForm.from_address" type="email" placeholder="noreply@suaempresa.com" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-300 mb-1">Nome do remetente</label>
                                            <input v-model="emailForm.from_name" type="text" placeholder="MKT Privus" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center justify-between mt-6 pt-4 border-t border-gray-800">
                                <button
                                    type="button"
                                    @click="testEmail"
                                    :disabled="testingEmail || !emailConfigured"
                                    class="rounded-xl bg-gray-800 border border-gray-700 px-4 py-2.5 text-sm font-medium text-gray-300 hover:bg-gray-700 hover:text-white transition disabled:opacity-40"
                                >
                                    {{ testingEmail ? 'Enviando...' : 'Enviar Email de Teste' }}
                                </button>

                                <button type="submit" :disabled="emailForm.processing" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                                    {{ emailForm.processing ? 'Salvando...' : 'Salvar Configuracoes de Email' }}
                                </button>
                            </div>

                            <!-- Resultado do teste -->
                            <div v-if="emailTestResult" class="mt-4">
                                <div :class="['rounded-xl p-3 text-sm', emailTestResult.success ? 'bg-green-500/10 border border-green-500/30 text-green-400' : 'bg-red-500/10 border border-red-500/30 text-red-400']">
                                    {{ emailTestResult.message }}
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- TAB: SOCIAL -->
                <div v-if="activeTab === 'social'">
                    <form @submit.prevent="saveSocial" class="space-y-6">
                        <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                            <h2 class="text-lg font-semibold text-white mb-6">Preferencias de Social Media</h2>

                            <div class="space-y-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-3">Plataformas padrao (novos posts)</label>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-for="p in platformOptions"
                                            :key="p.value"
                                            type="button"
                                            @click="toggleSocialPlatform(p.value)"
                                            :class="[
                                                'rounded-lg border px-3 py-2 text-sm font-medium transition',
                                                socialForm.default_platforms.includes(p.value)
                                                    ? 'text-white border-current'
                                                    : 'text-gray-500 border-gray-700 hover:border-gray-600',
                                            ]"
                                            :style="socialForm.default_platforms.includes(p.value) ? { borderColor: p.color, color: p.color } : {}"
                                        >
                                            {{ p.label }}
                                        </button>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-1">Tipo de post padrao</label>
                                        <select v-model="socialForm.default_post_type" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                            <option v-for="opt in postTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Autopilot -->
                        <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                            <h2 class="text-lg font-semibold text-white mb-6">Autopilot</h2>

                            <div class="space-y-4">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input v-model="socialForm.autopilot_enabled" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                                    <div>
                                        <span class="text-sm text-gray-300">Ativar Autopilot</span>
                                        <p class="text-[11px] text-gray-600">Publicacao automatica de posts agendados.</p>
                                    </div>
                                </label>

                                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-1">Maximo de re-tentativas</label>
                                        <input v-model.number="socialForm.autopilot_max_retries" type="number" min="1" max="10" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-1">Intervalo entre re-tentativas (min)</label>
                                        <input v-model.number="socialForm.autopilot_retry_interval" type="number" min="5" max="60" step="5" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                                    </div>
                                </div>

                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input v-model="socialForm.publish_confirmation" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                                    <div>
                                        <span class="text-sm text-gray-300">Exigir confirmacao antes de publicar</span>
                                        <p class="text-[11px] text-gray-600">Posts agendados precisarao de aprovacao manual antes da publicacao.</p>
                                    </div>
                                </label>

                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input v-model="socialForm.watermark_enabled" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                                    <div>
                                        <span class="text-sm text-gray-300">Aplicar marca d'agua nas imagens</span>
                                        <p class="text-[11px] text-gray-600">Adiciona logotipo da marca como marca d'agua em imagens publicadas.</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" :disabled="socialForm.processing" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                                {{ socialForm.processing ? 'Salvando...' : 'Salvar Configuracoes Social' }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- TAB: OAUTH SOCIAL -->
                <div v-if="activeTab === 'oauth'" class="space-y-6">
                    <form @submit.prevent="saveOAuth">
                        <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6 mb-6">
                            <h3 class="text-lg font-semibold text-white mb-2">Credenciais OAuth</h3>
                            <p class="text-sm text-gray-400 mb-2">Configure as credenciais de cada plataforma para habilitar a conexao automatica via OAuth.</p>
                            <p class="text-xs text-indigo-400/70 mb-6">Estas credenciais sao compartilhadas entre Social Media e Analytics. Google OAuth cobre YouTube, Analytics, Ads e Search Console. Meta OAuth cobre Facebook, Instagram e Meta Ads.</p>

                            <div class="space-y-6">
                                <div v-for="provider in oauthProviders" :key="provider.key" class="rounded-xl border border-gray-800 p-5">
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-xs font-bold" :style="{ backgroundColor: provider.color }">
                                                {{ provider.label[0] }}
                                            </div>
                                            <div>
                                                <h4 class="text-sm font-semibold text-white">{{ provider.label }}</h4>
                                                <p class="text-[10px] text-gray-500">{{ provider.helpText }}</p>
                                            </div>
                                        </div>
                                        <a :href="provider.helpUrl" target="_blank" class="text-[10px] text-indigo-400 hover:text-indigo-300 underline">Obter credenciais</a>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div v-for="field in provider.fields" :key="field.key">
                                            <label class="block text-xs text-gray-400 mb-1">{{ field.label }}</label>
                                            <input
                                                v-model="(oauthForm as any)[field.key]"
                                                :type="field.type"
                                                class="w-full rounded-lg bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                :placeholder="field.type === 'password' && field.isSet ? '••••••• (configurado)' : ''"
                                            />
                                            <p v-if="field.hint" class="text-[10px] text-gray-500 mt-0.5">{{ field.hint }}</p>
                                            <p v-if="field.type === 'password' && field.isSet" class="text-[10px] text-emerald-500 mt-0.5">Configurado. Deixe vazio para manter.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6 mb-6">
                            <h3 class="text-sm font-semibold text-white mb-3">URLs de Callback (Redirect URI)</h3>
                            <p class="text-xs text-gray-500 mb-4">Cadastre estas URLs no painel de desenvolvedores de cada plataforma:</p>

                            <h4 class="text-xs font-medium text-gray-400 mb-2 mt-3">Social Media</h4>
                            <div class="space-y-2 mb-4">
                                <div class="flex items-center gap-2 rounded-lg bg-gray-800 px-3 py-2">
                                    <span class="text-[10px] text-gray-500 w-20 shrink-0">Meta:</span>
                                    <code class="text-xs text-emerald-400 break-all">{{ $page.props.appUrl || '' }}/social/oauth/callback/facebook</code>
                                </div>
                                <div class="flex items-center gap-2 rounded-lg bg-gray-800 px-3 py-2">
                                    <span class="text-[10px] text-gray-500 w-20 shrink-0">LinkedIn:</span>
                                    <code class="text-xs text-emerald-400 break-all">{{ $page.props.appUrl || '' }}/social/oauth/callback/linkedin</code>
                                </div>
                                <div class="flex items-center gap-2 rounded-lg bg-gray-800 px-3 py-2">
                                    <span class="text-[10px] text-gray-500 w-20 shrink-0">YouTube:</span>
                                    <code class="text-xs text-emerald-400 break-all">{{ $page.props.appUrl || '' }}/social/oauth/callback/youtube</code>
                                </div>
                                <div class="flex items-center gap-2 rounded-lg bg-gray-800 px-3 py-2">
                                    <span class="text-[10px] text-gray-500 w-20 shrink-0">TikTok:</span>
                                    <code class="text-xs text-emerald-400 break-all">{{ $page.props.appUrl || '' }}/social/oauth/callback/tiktok</code>
                                </div>
                                <div class="flex items-center gap-2 rounded-lg bg-gray-800 px-3 py-2">
                                    <span class="text-[10px] text-gray-500 w-20 shrink-0">Pinterest:</span>
                                    <code class="text-xs text-emerald-400 break-all">{{ $page.props.appUrl || '' }}/social/oauth/callback/pinterest</code>
                                </div>
                            </div>

                            <h4 class="text-xs font-medium text-gray-400 mb-2">Analytics</h4>
                            <div class="space-y-2">
                                <div class="flex items-center gap-2 rounded-lg bg-gray-800 px-3 py-2">
                                    <span class="text-[10px] text-gray-500 w-20 shrink-0">GA4:</span>
                                    <code class="text-xs text-blue-400 break-all">{{ $page.props.appUrl || '' }}/analytics/oauth/callback/google_analytics</code>
                                </div>
                                <div class="flex items-center gap-2 rounded-lg bg-gray-800 px-3 py-2">
                                    <span class="text-[10px] text-gray-500 w-20 shrink-0">Google Ads:</span>
                                    <code class="text-xs text-blue-400 break-all">{{ $page.props.appUrl || '' }}/analytics/oauth/callback/google_ads</code>
                                </div>
                                <div class="flex items-center gap-2 rounded-lg bg-gray-800 px-3 py-2">
                                    <span class="text-[10px] text-gray-500 w-20 shrink-0">Search C.:</span>
                                    <code class="text-xs text-blue-400 break-all">{{ $page.props.appUrl || '' }}/analytics/oauth/callback/google_search_console</code>
                                </div>
                                <div class="flex items-center gap-2 rounded-lg bg-gray-800 px-3 py-2">
                                    <span class="text-[10px] text-gray-500 w-20 shrink-0">Meta Ads:</span>
                                    <code class="text-xs text-blue-400 break-all">{{ $page.props.appUrl || '' }}/analytics/oauth/callback/meta_ads</code>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" :disabled="oauthForm.processing" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                                {{ oauthForm.processing ? 'Salvando...' : 'Salvar Credenciais' }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- TAB: NOTIFICACOES -->
                <div v-if="activeTab === 'notifications'" class="space-y-6">
                    <form @submit.prevent="saveNotifications">
                        <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                            <h2 class="text-lg font-semibold text-white mb-6">Eventos de Notificacao</h2>
                            <p class="text-sm text-gray-500 mb-4">Escolha quais eventos geram notificacoes no sistema.</p>

                            <div class="space-y-4">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input v-model="notificationsForm.notify_publish_success" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                                    <div>
                                        <span class="text-sm text-gray-300">Publicacao bem-sucedida</span>
                                        <p class="text-[11px] text-gray-600">Notificar quando um post for publicado com sucesso.</p>
                                    </div>
                                </label>

                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input v-model="notificationsForm.notify_publish_failure" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                                    <div>
                                        <span class="text-sm text-gray-300">Falha na publicacao</span>
                                        <p class="text-[11px] text-gray-600">Notificar quando a publicacao de um post falhar.</p>
                                    </div>
                                </label>

                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input v-model="notificationsForm.notify_content_generated" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                                    <div>
                                        <span class="text-sm text-gray-300">Conteudo gerado</span>
                                        <p class="text-[11px] text-gray-600">Notificar quando o Content Engine gerar novas sugestoes.</p>
                                    </div>
                                </label>

                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input v-model="notificationsForm.notify_token_expiring" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                                    <div>
                                        <span class="text-sm text-gray-300">Token expirando</span>
                                        <p class="text-[11px] text-gray-600">Alerta quando tokens de redes sociais estiverem perto de expirar.</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Canais de entrega -->
                        <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                            <h2 class="text-lg font-semibold text-white mb-6">Canais de Entrega</h2>

                            <div class="space-y-5">
                                <!-- Email -->
                                <div class="rounded-xl bg-gray-800/50 border border-gray-700 p-4">
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input v-model="notificationsForm.email_notifications" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2">
                                                <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" /><polyline points="22,6 12,13 2,6" />
                                                </svg>
                                                <span class="text-sm font-medium text-gray-300">Notificacoes por Email</span>
                                                <span v-if="!emailConfigured" class="text-[10px] text-amber-400 bg-amber-500/10 border border-amber-500/30 rounded px-1.5 py-0.5">
                                                    SMTP nao configurado
                                                </span>
                                            </div>
                                            <p class="text-[11px] text-gray-600 mt-0.5">Envia as notificacoes ativas por email.</p>
                                        </div>
                                    </label>

                                    <div v-if="notificationsForm.email_notifications" class="mt-3 ml-7">
                                        <label class="block text-xs font-medium text-gray-400 mb-1">Resumo por email</label>
                                        <select v-model="notificationsForm.email_digest" class="rounded-lg bg-gray-900 border-gray-600 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500 max-w-xs">
                                            <option value="none">Enviar em tempo real</option>
                                            <option value="daily">Resumo diario</option>
                                            <option value="weekly">Resumo semanal</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Push -->
                                <div class="rounded-xl bg-gray-800/50 border border-gray-700 p-4">
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input v-model="notificationsForm.push_enabled" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2">
                                                <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.73 21a2 2 0 0 1-3.46 0" /><line x1="12" y1="2" x2="12" y2="4" />
                                                </svg>
                                                <span class="text-sm font-medium text-gray-300">Push Notifications</span>
                                                <span v-if="!pushSupported" class="text-[10px] text-red-400 bg-red-500/10 border border-red-500/30 rounded px-1.5 py-0.5">
                                                    Nao suportado
                                                </span>
                                            </div>
                                            <p class="text-[11px] text-gray-600 mt-0.5">Receba notificacoes diretamente no navegador, mesmo com a aba fechada.</p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" :disabled="notificationsForm.processing" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                                {{ notificationsForm.processing ? 'Salvando...' : 'Salvar Notificacoes' }}
                            </button>
                        </div>
                    </form>

                    <!-- Push Notifications Config -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                        <h2 class="text-lg font-semibold text-white mb-2">Push Notifications - Configuracao</h2>
                        <p class="text-sm text-gray-500 mb-6">Configure as chaves VAPID para Web Push e gerencie as inscricoes nos dispositivos.</p>

                        <!-- Status -->
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
                            <div class="rounded-xl bg-gray-800 p-4">
                                <p class="text-2xl font-bold" :class="pushInfo.vapid_public_key ? 'text-emerald-400' : 'text-red-400'">
                                    {{ pushInfo.vapid_public_key ? 'OK' : '--' }}
                                </p>
                                <p class="text-xs text-gray-500 mt-1">Chave VAPID</p>
                            </div>
                            <div class="rounded-xl bg-gray-800 p-4">
                                <p class="text-2xl font-bold text-purple-400">{{ pushInfo.subscriptions_count }}</p>
                                <p class="text-xs text-gray-500 mt-1">Dispositivos inscritos</p>
                            </div>
                            <div class="rounded-xl bg-gray-800 p-4">
                                <p class="text-2xl font-bold" :class="pushInfo.user_subscribed ? 'text-emerald-400' : 'text-gray-600'">
                                    {{ pushInfo.user_subscribed ? 'Ativo' : 'Inativo' }}
                                </p>
                                <p class="text-xs text-gray-500 mt-1">Este dispositivo</p>
                            </div>
                        </div>

                        <!-- Gerar chaves VAPID -->
                        <div class="rounded-xl bg-gray-800/50 border border-gray-700 p-4 mb-4">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <p class="text-sm font-medium text-gray-300">Chaves VAPID</p>
                                    <p class="text-[11px] text-gray-600">Necessarias para autenticar o servidor com os servicos de push.</p>
                                </div>
                                <button
                                    type="button"
                                    @click="generateVapidKeys"
                                    :disabled="generatingVapid"
                                    class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-400 hover:text-white hover:bg-gray-700 border border-gray-600 transition disabled:opacity-40"
                                >
                                    {{ generatingVapid ? 'Gerando...' : (pushInfo.vapid_public_key ? 'Regerar Chaves' : 'Gerar Chaves VAPID') }}
                                </button>
                            </div>

                            <div v-if="pushInfo.vapid_public_key" class="mt-2">
                                <p class="text-xs text-gray-500 mb-1">Chave publica:</p>
                                <code class="text-[11px] text-gray-400 font-mono bg-gray-900 rounded px-2 py-1 break-all block">{{ pushInfo.vapid_public_key }}</code>
                            </div>

                            <div v-if="pushInfo.vapid_private_key_set" class="mt-2">
                                <p class="text-xs text-emerald-500">Chave privada configurada (criptografada)</p>
                            </div>

                            <div v-if="vapidResult" class="mt-3">
                                <div :class="['rounded-lg p-2.5 text-xs', vapidResult.success ? 'bg-green-500/10 border border-green-500/30 text-green-400' : 'bg-red-500/10 border border-red-500/30 text-red-400']">
                                    {{ vapidResult.message }}
                                </div>
                            </div>
                        </div>

                        <!-- Ativar/Desativar push neste dispositivo -->
                        <div class="rounded-xl bg-gray-800/50 border border-gray-700 p-4 mb-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-300">Este dispositivo</p>
                                    <p class="text-[11px] text-gray-600">Ative para receber notificacoes push neste navegador.</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        v-if="!pushInfo.user_subscribed"
                                        type="button"
                                        @click="subscribePush"
                                        :disabled="subscribingPush || !pushInfo.vapid_public_key || !pushSupported"
                                        class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-medium text-white hover:bg-indigo-700 transition disabled:opacity-40"
                                    >
                                        {{ subscribingPush ? 'Ativando...' : 'Ativar Push' }}
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        @click="unsubscribePush"
                                        :disabled="subscribingPush"
                                        class="rounded-lg bg-red-600/20 border border-red-500/30 px-4 py-2 text-xs font-medium text-red-400 hover:bg-red-600/30 transition disabled:opacity-40"
                                    >
                                        {{ subscribingPush ? 'Desativando...' : 'Desativar Push' }}
                                    </button>

                                    <button
                                        type="button"
                                        @click="testPush"
                                        :disabled="testingPush || !pushInfo.user_subscribed"
                                        class="rounded-lg px-3 py-2 text-xs font-medium text-gray-400 hover:text-white hover:bg-gray-700 border border-gray-600 transition disabled:opacity-40"
                                    >
                                        {{ testingPush ? 'Enviando...' : 'Testar Push' }}
                                    </button>
                                </div>
                            </div>

                            <div v-if="pushSubscribeResult" class="mt-3">
                                <div :class="['rounded-lg p-2.5 text-xs', pushSubscribeResult.success ? 'bg-green-500/10 border border-green-500/30 text-green-400' : 'bg-red-500/10 border border-red-500/30 text-red-400']">
                                    {{ pushSubscribeResult.message }}
                                </div>
                            </div>

                            <div v-if="pushTestResult" class="mt-3">
                                <div :class="['rounded-lg p-2.5 text-xs', pushTestResult.success ? 'bg-green-500/10 border border-green-500/30 text-green-400' : 'bg-red-500/10 border border-red-500/30 text-red-400']">
                                    {{ pushTestResult.message }}
                                </div>
                            </div>

                            <div v-if="!pushSupported" class="mt-3">
                                <div class="rounded-lg p-2.5 text-xs bg-amber-500/10 border border-amber-500/30 text-amber-400">
                                    Seu navegador nao suporta Push Notifications. Use Chrome, Firefox ou Edge para esta funcionalidade.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Guias contextuais -->
                <GuideBox
                    v-if="activeTab === 'general'"
                    title="Sobre as configuracoes gerais"
                    color="indigo"
                    storage-key="settings-general-guide"
                    class="mt-6"
                    :tips="[
                        'O nome do sistema aparece no titulo do navegador e em notificacoes por email.',
                        'O fuso horario afeta agendamentos de posts e exibicao de datas em todo o sistema.',
                        'Alterar o formato de data nao afeta dados ja registrados, apenas a exibicao.',
                        'Limpar cache pode resolver problemas de dados desatualizados no sistema.',
                    ]"
                />

                <GuideBox
                    v-if="activeTab === 'ai'"
                    title="Sobre a configuracao de IA"
                    color="purple"
                    storage-key="settings-ai-guide"
                    class="mt-6"
                    :tips="[
                        'Chaves de API configuradas no banco de dados tem prioridade sobre as do arquivo .env.',
                        'Use o botao Testar Conexao para verificar se sua chave esta funcionando corretamente.',
                        'GPT-4o Mini e Gemini Flash sao os modelos com melhor custo-beneficio para geracao de conteudo.',
                        'Temperatura baixa (0.3) gera textos mais consistentes. Alta (1.5+) gera mais variedade.',
                        'O custo estimado e uma aproximacao baseada nos precos oficiais de cada provedor.',
                    ]"
                />

                <GuideBox
                    v-if="activeTab === 'email'"
                    title="Sobre a configuracao de email"
                    color="emerald"
                    storage-key="settings-email-guide"
                    class="mt-6"
                    :steps="[
                        { title: 'Escolha o provedor', description: 'Use os presets rapidos para preencher automaticamente host, porta e criptografia do seu provedor.' },
                        { title: 'Preencha as credenciais', description: 'Informe usuario e senha do SMTP. Para Gmail, use uma Senha de App (nao sua senha pessoal).' },
                        { title: 'Configure o remetente', description: 'Defina o email e nome que aparecera como remetente nas notificacoes enviadas.' },
                        { title: 'Teste o envio', description: 'Clique em Enviar Email de Teste para validar que tudo esta funcionando. O email vai para seu endereco cadastrado.' },
                    ]"
                    :tips="[
                        'Para Gmail: ative a Verificacao em 2 etapas e gere uma Senha de App em myaccount.google.com/apppasswords.',
                        'Para Office 365: use seu email completo como usuario e habilite SMTP Authentication no admin.',
                        'SendGrid e Mailgun oferecem planos gratuitos com limite de envios, ideais para comecar.',
                        'A senha SMTP e armazenada de forma criptografada no banco de dados.',
                        'Configuracoes feitas aqui tem prioridade sobre as definidas no arquivo .env.',
                    ]"
                />

                <GuideBox
                    v-if="activeTab === 'oauth'"
                    title="Sobre as integracoes OAuth"
                    color="blue"
                    storage-key="settings-oauth-guide"
                    class="mt-6"
                    :steps="[
                        { title: 'Crie as credenciais', description: 'Acesse o painel de desenvolvedores de cada plataforma e crie um app OAuth.' },
                        { title: 'Cadastre os callbacks', description: 'Copie as URLs de callback listadas abaixo e cadastre-as no painel de cada plataforma.' },
                        { title: 'Preencha os campos', description: 'Insira o Client ID e Secret de cada plataforma e salve.' },
                        { title: 'Conecte nas paginas', description: 'Va em Social > Contas ou Analytics > Conexoes e clique em Conectar via OAuth.' },
                    ]"
                    :tips="[
                        'Google OAuth e compartilhado entre YouTube, Google Analytics, Google Ads e Search Console.',
                        'Meta OAuth cobre Facebook, Instagram e Meta Ads.',
                        'As credenciais sao armazenadas de forma criptografada no banco de dados.',
                        'Voce pode configurar tambem via variaveis de ambiente (.env), mas as do banco tem prioridade.',
                    ]"
                />

                <GuideBox
                    v-if="activeTab === 'social'"
                    title="Sobre configuracoes de Social Media"
                    color="blue"
                    storage-key="settings-social-guide"
                    class="mt-6"
                    :tips="[
                        'Plataformas padrao sao pre-selecionadas ao criar novos posts, economizando cliques.',
                        'O Autopilot pode ser desativado temporariamente sem afetar posts ja agendados.',
                        'A confirmacao de publicacao adiciona uma etapa de seguranca antes do envio automatico.',
                        'A marca d agua utiliza o logotipo principal cadastrado na marca ativa.',
                    ]"
                />

                <GuideBox
                    v-if="activeTab === 'notifications'"
                    title="Sobre notificacoes e push"
                    color="amber"
                    storage-key="settings-notifications-guide"
                    class="mt-6"
                    :steps="[
                        { title: 'Escolha os eventos', description: 'Selecione quais acoes do sistema devem gerar notificacoes.' },
                        { title: 'Ative os canais', description: 'Habilite email e/ou push como canais de entrega das notificacoes.' },
                        { title: 'Configure o SMTP', description: 'Para emails, va na aba Email/SMTP e configure seu servidor.' },
                        { title: 'Ative o Push', description: 'Gere as chaves VAPID, depois clique em Ativar Push para este dispositivo.' },
                    ]"
                    :tips="[
                        'Push Notifications funcionam mesmo com a aba do navegador fechada.',
                        'Cada navegador/dispositivo precisa ser ativado individualmente.',
                        'As chaves VAPID precisam ser geradas apenas uma vez e servem para todos os usuarios.',
                        'O resumo diario/semanal compila todas as notificacoes em um unico email.',
                        'Chrome, Firefox e Edge suportam Push Notifications. Safari tem suporte limitado.',
                    ]"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
