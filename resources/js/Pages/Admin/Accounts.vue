<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface SubscriptionInfo {
    id: number;
    plan_name: string | null;
    status: string;
    trial_ends_at: string | null;
    current_period_end: string | null;
    is_valid: boolean;
}

interface Company {
    name: string;
    legal_name: string | null;
    cnpj: string | null;
    segment: string | null;
    company_size: string | null;
    phone: string | null;
    city: string | null;
    state: string | null;
    goals: string[];
    objective: string | null;
}

interface Account {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_active: boolean;
    created_at: string | null;
    last_login_at: string | null;
    brands_count: number;
    company: Company | null;
    subscription: SubscriptionInfo | null;
    usage: { emails: number; ai_tokens: number };
}

interface PlanOption {
    id: number;
    name: string;
    price: string;
}

const props = defineProps<{
    accounts: {
        data: Account[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    stats: {
        total_accounts: number;
        active_subscriptions: number;
        trialing: number;
        mrr: number;
    };
    plans: PlanOption[];
    filters: { q: string };
}>();

const page = usePage();
const flashError = computed(() => (page.props as any).flash?.error ?? null);
const flashSuccess = computed(() => (page.props as any).flash?.success ?? null);

const search = ref(props.filters.q ?? '');
const selectedPlan = ref<Record<number, number | ''>>({});

const statusLabels: Record<string, string> = {
    pending: 'Pendente',
    trialing: 'Trial',
    active: 'Ativa',
    paused: 'Pausada',
    past_due: 'Em atraso',
    cancelled: 'Cancelada',
};

function doSearch() {
    router.get(route('admin.accounts.index'), { q: search.value }, { preserveState: true, preserveScroll: true });
}

// ===== Detalhes da conta =====
const detailAccount = ref<Account | null>(null);

// ===== Nova conta de cliente =====
const showCreate = ref(false);
const createForm = useForm({
    name: '',
    email: '',
    brand_name: '',
    password: '',
    password_confirmation: '',
    subscription: 'trial',
    plan_id: '' as number | '',
    trial_days: 14,
});

function openCreate() {
    createForm.reset();
    createForm.subscription = 'trial';
    createForm.trial_days = 14;
    showCreate.value = true;
}

function submitCreate() {
    createForm.post(route('admin.accounts.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
            createForm.reset();
        },
    });
}

function post(routeName: string, account: Account, data: Record<string, unknown> = {}) {
    router.post(route(routeName, account.id), data, { preserveScroll: true });
}

function toggleActive(account: Account) {
    const action = account.is_active ? 'Desativar' : 'Ativar';
    if (confirm(`${action} a conta de ${account.email}?`)) {
        post('admin.accounts.toggle-active', account);
    }
}

function toggleAdmin(account: Account) {
    const action = account.is_admin ? 'Remover acesso de administrador de' : 'Promover a administrador';
    if (confirm(`${action} ${account.email}?`)) {
        post('admin.accounts.toggle-admin', account);
    }
}

function extendTrial(account: Account) {
    const days = prompt('Estender trial por quantos dias?', '14');
    if (days && Number(days) > 0) {
        post('admin.accounts.extend-trial', account, { days: Number(days) });
    }
}

function grantPlan(account: Account) {
    const planId = selectedPlan.value[account.id];
    if (!planId) return;
    const plan = props.plans.find((p) => p.id === planId);
    const months = prompt(
        `Conceder o plano ${plan?.name} a ${account.email} por quantos meses? (vazio = sem expiração)`,
        '',
    );
    if (months === null) return;
    post('admin.accounts.grant-plan', account, {
        plan_id: planId,
        months: months.trim() === '' ? null : Number(months),
    });
    selectedPlan.value[account.id] = '';
}

function cancelSubscription(account: Account) {
    if (confirm(`Cancelar a assinatura vigente de ${account.email}?`)) {
        post('admin.accounts.cancel-subscription', account);
    }
}

function formatMoney(value: number): string {
    return value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function formatNumber(value: number): string {
    return value.toLocaleString('pt-BR');
}
</script>

<template>
    <Head title="Contas (Admin)" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Contas do SaaS
                </h2>
                <div class="flex items-center gap-2">
                    <Link :href="route('admin.insights')" class="rounded-xl bg-gray-800 border border-gray-700 px-4 py-2 text-sm font-medium text-gray-300 hover:bg-gray-700 transition">
                        Insights
                    </Link>
                    <button
                        type="button"
                        @click="openCreate"
                        class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition"
                    >
                        + Nova conta
                    </button>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="flashSuccess" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-400">
                    {{ flashSuccess }}
                </div>
                <div v-if="flashError" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
                    {{ flashError }}
                </div>

                <!-- Indicadores -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                        <p class="text-sm text-gray-400">Contas</p>
                        <p class="mt-1 text-2xl font-bold text-white">{{ formatNumber(stats.total_accounts) }}</p>
                    </div>
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                        <p class="text-sm text-gray-400">Assinaturas ativas</p>
                        <p class="mt-1 text-2xl font-bold text-emerald-400">{{ formatNumber(stats.active_subscriptions) }}</p>
                    </div>
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                        <p class="text-sm text-gray-400">Em trial</p>
                        <p class="mt-1 text-2xl font-bold text-blue-400">{{ formatNumber(stats.trialing) }}</p>
                    </div>
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                        <p class="text-sm text-gray-400">MRR</p>
                        <p class="mt-1 text-2xl font-bold text-white">{{ formatMoney(stats.mrr) }}</p>
                    </div>
                </div>

                <!-- Busca -->
                <form @submit.prevent="doSearch" class="flex gap-3">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Buscar por nome ou e-mail..."
                        class="w-full max-w-md rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                        Buscar
                    </button>
                </form>

                <!-- Tabela de contas -->
                <div class="overflow-x-auto rounded-2xl bg-gray-900 border border-gray-800">
                    <table class="min-w-full divide-y divide-gray-800 text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                                <th class="px-4 py-3">Conta</th>
                                <th class="px-4 py-3">Marcas</th>
                                <th class="px-4 py-3">Assinatura</th>
                                <th class="px-4 py-3">Uso no mês</th>
                                <th class="px-4 py-3">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <tr v-for="account in accounts.data" :key="account.id" class="align-top">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-white">
                                        {{ account.name }}
                                        <span v-if="account.is_admin" class="ml-1 rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-medium text-amber-400">admin</span>
                                        <span v-if="!account.is_active" class="ml-1 rounded bg-red-500/15 px-1.5 py-0.5 text-[10px] font-medium text-red-400">inativa</span>
                                    </p>
                                    <p class="text-xs text-gray-400">{{ account.email }}</p>
                                    <p class="mt-0.5 text-[11px] text-gray-600">
                                        desde {{ account.created_at }}
                                        <span v-if="account.last_login_at"> · último acesso {{ account.last_login_at }}</span>
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-gray-300">{{ account.brands_count }}</td>
                                <td class="px-4 py-3">
                                    <template v-if="account.subscription">
                                        <p class="text-gray-200">{{ account.subscription.plan_name ?? '—' }}</p>
                                        <span
                                            class="mt-1 inline-block rounded px-1.5 py-0.5 text-[10px] font-medium"
                                            :class="{
                                                'bg-emerald-500/15 text-emerald-400': account.subscription.status === 'active',
                                                'bg-blue-500/15 text-blue-400': account.subscription.status === 'trialing',
                                                'bg-yellow-500/15 text-yellow-400': ['pending', 'past_due', 'paused'].includes(account.subscription.status),
                                                'bg-red-500/15 text-red-400': account.subscription.status === 'cancelled',
                                            }"
                                        >
                                            {{ statusLabels[account.subscription.status] ?? account.subscription.status }}
                                        </span>
                                        <p class="mt-0.5 text-[11px] text-gray-500">
                                            <template v-if="account.subscription.status === 'trialing' && account.subscription.trial_ends_at">
                                                trial até {{ account.subscription.trial_ends_at }}
                                            </template>
                                            <template v-else-if="account.subscription.current_period_end">
                                                renova {{ account.subscription.current_period_end }}
                                            </template>
                                        </p>
                                    </template>
                                    <span v-else class="text-gray-600">Sem assinatura</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-400">
                                    <p>{{ formatNumber(account.usage.emails) }} e-mails</p>
                                    <p>{{ formatNumber(account.usage.ai_tokens) }} tokens IA</p>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <button @click="extendTrial(account)" class="rounded-lg bg-gray-800 border border-gray-700 px-2 py-1 text-[11px] text-gray-300 hover:bg-gray-700 transition">
                                            + Trial
                                        </button>
                                        <select
                                            v-model="selectedPlan[account.id]"
                                            class="rounded-lg border-gray-700 bg-gray-800 py-1 text-[11px] text-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option value="">Conceder plano...</option>
                                            <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                                        </select>
                                        <button
                                            v-if="selectedPlan[account.id]"
                                            @click="grantPlan(account)"
                                            class="rounded-lg bg-indigo-600 px-2 py-1 text-[11px] font-medium text-white hover:bg-indigo-700 transition"
                                        >
                                            OK
                                        </button>
                                        <button
                                            v-if="account.subscription?.is_valid"
                                            @click="cancelSubscription(account)"
                                            class="rounded-lg bg-gray-800 border border-gray-700 px-2 py-1 text-[11px] text-red-400 hover:bg-red-500/10 transition"
                                        >
                                            Cancelar assin.
                                        </button>
                                        <button @click="toggleActive(account)" class="rounded-lg bg-gray-800 border border-gray-700 px-2 py-1 text-[11px] text-gray-300 hover:bg-gray-700 transition">
                                            {{ account.is_active ? 'Desativar' : 'Ativar' }}
                                        </button>
                                        <button @click="toggleAdmin(account)" class="rounded-lg bg-gray-800 border border-gray-700 px-2 py-1 text-[11px] text-amber-400/80 hover:bg-gray-700 transition">
                                            {{ account.is_admin ? 'Tirar admin' : 'Tornar admin' }}
                                        </button>
                                        <button @click="detailAccount = account" class="rounded-lg bg-gray-800 border border-gray-700 px-2 py-1 text-[11px] text-indigo-300 hover:bg-gray-700 transition">
                                            Detalhes
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!accounts.data.length">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">Nenhuma conta encontrada.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Paginação -->
                <div v-if="accounts.links.length > 3" class="flex flex-wrap gap-1.5">
                    <template v-for="(link, index) in accounts.links" :key="index">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="rounded-lg px-3 py-1.5 text-xs transition"
                            :class="link.active ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'"
                            v-html="link.label"
                        />
                        <span v-else class="rounded-lg px-3 py-1.5 text-xs text-gray-600" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>
        <!-- Modal: nova conta de cliente -->
        <div
            v-if="showCreate"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            @click.self="showCreate = false"
        >
            <div class="w-full max-w-lg rounded-2xl bg-gray-900 border border-gray-800 p-6 shadow-xl">
                <h3 class="mb-1 text-lg font-semibold text-white">Nova conta de cliente</h3>
                <p class="mb-5 text-sm text-gray-400">
                    Cria um cliente com a própria marca, isolado das demais contas.
                </p>

                <form @submit.prevent="submitCreate" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Nome</label>
                            <input v-model="createForm.name" type="text" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                            <p v-if="createForm.errors.name" class="mt-1 text-xs text-red-400">{{ createForm.errors.name }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">E-mail</label>
                            <input v-model="createForm.email" type="email" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                            <p v-if="createForm.errors.email" class="mt-1 text-xs text-red-400">{{ createForm.errors.email }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Nome da marca/empresa</label>
                        <input v-model="createForm.brand_name" type="text" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                        <p v-if="createForm.errors.brand_name" class="mt-1 text-xs text-red-400">{{ createForm.errors.brand_name }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">
                            Senha <span class="text-gray-500">(opcional — gerada se vazia)</span>
                        </label>
                        <input v-model="createForm.password" type="password" autocomplete="new-password" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                        <p v-if="createForm.errors.password" class="mt-1 text-xs text-red-400">{{ createForm.errors.password }}</p>
                    </div>
                    <div v-if="createForm.password">
                        <label class="block text-sm font-medium text-gray-300 mb-1">Confirmar senha</label>
                        <input v-model="createForm.password_confirmation" type="password" autocomplete="new-password" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Assinatura</label>
                            <select v-model="createForm.subscription" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="trial">Trial (teste grátis)</option>
                                <option value="plan">Plano cortesia (ativo)</option>
                                <option value="none">Nenhuma</option>
                            </select>
                        </div>
                        <div v-if="createForm.subscription !== 'none'">
                            <label class="block text-sm font-medium text-gray-300 mb-1">Plano</label>
                            <select v-model="createForm.plan_id" class="w-full rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ createForm.subscription === 'trial' ? 'Primeiro plano ativo' : 'Selecione...' }}</option>
                                <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                            </select>
                            <p v-if="createForm.errors.plan_id" class="mt-1 text-xs text-red-400">{{ createForm.errors.plan_id }}</p>
                        </div>
                    </div>

                    <div v-if="createForm.subscription === 'trial'">
                        <label class="block text-sm font-medium text-gray-300 mb-1">Dias de trial</label>
                        <input v-model.number="createForm.trial_days" type="number" min="1" max="90" class="w-32 rounded-xl bg-gray-800 border-gray-700 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="showCreate = false" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-400 hover:text-white transition">
                            Cancelar
                        </button>
                        <button type="submit" :disabled="createForm.processing" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition disabled:opacity-50">
                            {{ createForm.processing ? 'Criando...' : 'Criar conta' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <!-- Modal: detalhes da conta -->
        <div
            v-if="detailAccount"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            @click.self="detailAccount = null"
        >
            <div class="w-full max-w-lg rounded-2xl bg-gray-900 border border-gray-800 p-6 shadow-xl">
                <div class="mb-4 flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-white">{{ detailAccount.name }}</h3>
                        <p class="text-sm text-gray-400">{{ detailAccount.email }}</p>
                    </div>
                    <button @click="detailAccount = null" class="text-gray-500 hover:text-white">✕</button>
                </div>

                <div v-if="detailAccount.company" class="space-y-3 text-sm">
                    <div class="grid grid-cols-2 gap-3">
                        <div><p class="text-gray-500">Empresa</p><p class="text-gray-200">{{ detailAccount.company.name || '—' }}</p></div>
                        <div><p class="text-gray-500">Razão social</p><p class="text-gray-200">{{ detailAccount.company.legal_name || '—' }}</p></div>
                        <div><p class="text-gray-500">CNPJ</p><p class="text-gray-200">{{ detailAccount.company.cnpj || '—' }}</p></div>
                        <div><p class="text-gray-500">Telefone</p><p class="text-gray-200">{{ detailAccount.company.phone || '—' }}</p></div>
                        <div><p class="text-gray-500">Segmento</p><p class="text-gray-200">{{ detailAccount.company.segment || '—' }}</p></div>
                        <div><p class="text-gray-500">Porte</p><p class="text-gray-200">{{ detailAccount.company.company_size || '—' }}</p></div>
                        <div class="col-span-2"><p class="text-gray-500">Cidade / UF</p><p class="text-gray-200">{{ [detailAccount.company.city, detailAccount.company.state].filter(Boolean).join(' / ') || '—' }}</p></div>
                    </div>

                    <div v-if="detailAccount.company.goals.length">
                        <p class="text-gray-500">O que busca</p>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            <span v-for="g in detailAccount.company.goals" :key="g" class="rounded-full bg-indigo-600/20 px-2.5 py-0.5 text-xs text-indigo-200">{{ g }}</span>
                        </div>
                    </div>

                    <div v-if="detailAccount.company.objective">
                        <p class="text-gray-500">Objetivo</p>
                        <p class="mt-1 rounded-xl bg-gray-800 p-3 text-gray-200">{{ detailAccount.company.objective }}</p>
                    </div>
                </div>
                <p v-else class="text-sm text-gray-500">Esta conta não tem dados de empresa preenchidos.</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
