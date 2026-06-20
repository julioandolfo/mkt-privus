<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';

interface Plan {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    price: string;
    currency: string;
    interval: string;
    features: string[] | null;
}

interface Subscription {
    id: number;
    status: string;
    trial_ends_at: string | null;
    current_period_end: string | null;
    plan: Plan | null;
}

interface UsageMetric {
    used: number;
    limit: number | null;
}

const props = defineProps<{
    billingEnabled: boolean;
    plans: Plan[];
    subscription: Subscription | null;
    usage: Record<string, UsageMetric>;
}>();

const statusLabels: Record<string, string> = {
    pending: 'Aguardando pagamento',
    trialing: 'Período de teste',
    active: 'Ativa',
    paused: 'Pausada',
    past_due: 'Pagamento pendente',
    cancelled: 'Cancelada',
};

const usageLabels: Record<string, string> = {
    brands: 'Marcas',
    users: 'Usuários',
    monthly_emails: 'E-mails este mês',
    monthly_sms: 'SMS este mês',
    monthly_ai_tokens: 'Tokens de IA este mês',
};

function formatPrice(plan: Plan): string {
    return Number(plan.price).toLocaleString('pt-BR', {
        style: 'currency',
        currency: plan.currency || 'BRL',
    });
}

function formatNumber(value: number): string {
    return value.toLocaleString('pt-BR');
}

function usagePercent(metric: UsageMetric): number {
    if (!metric.limit) return 0;
    return Math.min(100, Math.round((metric.used / metric.limit) * 100));
}

function subscribe(plan: Plan) {
    router.post(route('billing.subscribe', plan.id));
}

function cancelSubscription() {
    if (confirm('Tem certeza que deseja cancelar sua assinatura?')) {
        router.post(route('billing.cancel'));
    }
}

function isCurrentPlan(plan: Plan): boolean {
    return (
        !!props.subscription &&
        ['active', 'trialing'].includes(props.subscription.status) &&
        props.subscription.plan?.id === plan.id
    );
}
</script>

<template>
    <Head title="Assinatura" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Assinatura
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-8 px-4 sm:px-6 lg:px-8">
                <!-- Billing desabilitado -->
                <div
                    v-if="!billingEnabled"
                    class="rounded-lg bg-yellow-50 p-4 text-sm text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-200"
                >
                    O módulo de assinaturas está desabilitado nesta instalação (BILLING_ENABLED=false).
                    Nenhum limite de plano está sendo aplicado.
                </div>

                <!-- Assinatura atual -->
                <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                    <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                        Sua assinatura
                    </h3>

                    <div v-if="subscription" class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p class="text-gray-900 dark:text-gray-100">
                                <span class="font-medium">{{ subscription.plan?.name ?? '—' }}</span>
                                <span
                                    class="ml-2 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="{
                                        'bg-green-100 text-green-800': subscription.status === 'active',
                                        'bg-blue-100 text-blue-800': subscription.status === 'trialing',
                                        'bg-yellow-100 text-yellow-800': ['pending', 'past_due', 'paused'].includes(subscription.status),
                                        'bg-red-100 text-red-800': subscription.status === 'cancelled',
                                    }"
                                >
                                    {{ statusLabels[subscription.status] ?? subscription.status }}
                                </span>
                            </p>
                            <p v-if="subscription.status === 'trialing' && subscription.trial_ends_at" class="mt-1 text-sm text-gray-500">
                                Teste grátis até {{ new Date(subscription.trial_ends_at).toLocaleDateString('pt-BR') }}
                            </p>
                            <p v-else-if="subscription.current_period_end" class="mt-1 text-sm text-gray-500">
                                Próxima renovação: {{ new Date(subscription.current_period_end).toLocaleDateString('pt-BR') }}
                            </p>
                        </div>

                        <button
                            v-if="['active', 'trialing'].includes(subscription.status)"
                            class="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-900/30"
                            @click="cancelSubscription"
                        >
                            Cancelar assinatura
                        </button>
                    </div>

                    <p v-else class="text-gray-500 dark:text-gray-400">
                        Você ainda não possui uma assinatura. Escolha um plano abaixo.
                    </p>
                </div>

                <!-- Uso -->
                <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                    <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                        Uso do plano
                    </h3>
                    <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        <div v-for="(metric, key) in usage" :key="key">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ usageLabels[key] ?? key }}
                            </p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                                {{ formatNumber(metric.used) }}
                                <span class="text-sm font-normal text-gray-500">
                                    / {{ metric.limit === null ? 'ilimitado' : formatNumber(metric.limit) }}
                                </span>
                            </p>
                            <div v-if="metric.limit" class="mt-2 h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                <div
                                    class="h-2 rounded-full"
                                    :class="usagePercent(metric) >= 90 ? 'bg-red-500' : 'bg-gradient-to-r from-violet-500 to-fuchsia-500'"
                                    :style="{ width: usagePercent(metric) + '%' }"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Planos -->
                <div>
                    <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                        Planos
                    </h3>
                    <div class="grid gap-6 md:grid-cols-3">
                        <div
                            v-for="plan in plans"
                            :key="plan.id"
                            class="flex flex-col rounded-lg bg-white p-6 shadow dark:bg-gray-800"
                            :class="{ 'ring-2 ring-violet-500': isCurrentPlan(plan) }"
                        >
                            <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                {{ plan.name }}
                            </h4>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ plan.description }}
                            </p>
                            <p class="mt-4 text-3xl font-bold text-gray-900 dark:text-gray-100">
                                {{ formatPrice(plan) }}
                                <span class="text-sm font-normal text-gray-500">/mês</span>
                            </p>
                            <ul class="mt-4 flex-1 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                                <li v-for="feature in plan.features ?? []" :key="feature" class="flex items-start gap-2">
                                    <span class="text-green-500">✓</span>
                                    <span>{{ feature }}</span>
                                </li>
                            </ul>
                            <button
                                class="mt-6 w-full rounded-md px-4 py-2 text-sm font-medium"
                                :class="isCurrentPlan(plan)
                                    ? 'cursor-default bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'
                                    : 'bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white hover:from-violet-500 hover:to-fuchsia-500'"
                                :disabled="isCurrentPlan(plan) || !billingEnabled"
                                @click="subscribe(plan)"
                            >
                                {{ isCurrentPlan(plan) ? 'Plano atual' : 'Assinar' }}
                            </button>
                        </div>
                    </div>
                    <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                        Pagamento processado com segurança pelo Mercado Pago. Você será redirecionado para concluir a assinatura.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
