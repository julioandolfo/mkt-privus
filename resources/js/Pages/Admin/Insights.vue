<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

interface Dist {
    label: string;
    count: number;
}

const props = defineProps<{
    totals: { brands: number; accounts: number; with_cnpj: number };
    bySegment: Dist[];
    byCompanySize: Dist[];
    byState: Dist[];
    byGoal: Dist[];
}>();

function maxOf(list: Dist[]): number {
    return list.reduce((m, d) => Math.max(m, d.count), 0) || 1;
}

function pct(list: Dist[], count: number): number {
    return Math.round((count / maxOf(list)) * 100);
}
</script>

<template>
    <Head title="Insights (Admin)" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Insights de onboarding
                </h2>
                <Link :href="route('admin.accounts.index')" class="rounded-xl bg-gray-800 border border-gray-700 px-4 py-2 text-sm font-medium text-gray-300 hover:bg-gray-700 transition">
                    ← Contas
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Totais -->
                <div class="grid grid-cols-3 gap-4">
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                        <p class="text-sm text-gray-400">Contas</p>
                        <p class="mt-1 text-2xl font-bold text-white">{{ totals.accounts.toLocaleString('pt-BR') }}</p>
                    </div>
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                        <p class="text-sm text-gray-400">Marcas</p>
                        <p class="mt-1 text-2xl font-bold text-white">{{ totals.brands.toLocaleString('pt-BR') }}</p>
                    </div>
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-5">
                        <p class="text-sm text-gray-400">Com CNPJ</p>
                        <p class="mt-1 text-2xl font-bold text-emerald-400">{{ totals.with_cnpj.toLocaleString('pt-BR') }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <!-- Por segmento -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                        <h3 class="mb-4 text-base font-semibold text-white">Por segmento</h3>
                        <div v-if="bySegment.length" class="space-y-3">
                            <div v-for="d in bySegment" :key="d.label">
                                <div class="mb-1 flex justify-between text-sm">
                                    <span class="text-gray-300">{{ d.label }}</span>
                                    <span class="text-gray-500">{{ d.count }}</span>
                                </div>
                                <div class="h-2 w-full rounded-full bg-gray-800">
                                    <div class="h-2 rounded-full bg-indigo-500" :style="{ width: pct(bySegment, d.count) + '%' }" />
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-gray-500">Sem dados.</p>
                    </div>

                    <!-- Por objetivo (desejos) -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                        <h3 class="mb-4 text-base font-semibold text-white">O que buscam</h3>
                        <div v-if="byGoal.length" class="space-y-3">
                            <div v-for="d in byGoal" :key="d.label">
                                <div class="mb-1 flex justify-between text-sm">
                                    <span class="text-gray-300">{{ d.label }}</span>
                                    <span class="text-gray-500">{{ d.count }}</span>
                                </div>
                                <div class="h-2 w-full rounded-full bg-gray-800">
                                    <div class="h-2 rounded-full bg-emerald-500" :style="{ width: pct(byGoal, d.count) + '%' }" />
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-gray-500">Sem dados.</p>
                    </div>

                    <!-- Por porte -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                        <h3 class="mb-4 text-base font-semibold text-white">Por porte</h3>
                        <div v-if="byCompanySize.length" class="space-y-3">
                            <div v-for="d in byCompanySize" :key="d.label">
                                <div class="mb-1 flex justify-between text-sm">
                                    <span class="text-gray-300">{{ d.label }}</span>
                                    <span class="text-gray-500">{{ d.count }}</span>
                                </div>
                                <div class="h-2 w-full rounded-full bg-gray-800">
                                    <div class="h-2 rounded-full bg-purple-500" :style="{ width: pct(byCompanySize, d.count) + '%' }" />
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-gray-500">Sem dados.</p>
                    </div>

                    <!-- Por UF -->
                    <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6">
                        <h3 class="mb-4 text-base font-semibold text-white">Por estado (UF)</h3>
                        <div v-if="byState.length" class="space-y-3">
                            <div v-for="d in byState" :key="d.label">
                                <div class="mb-1 flex justify-between text-sm">
                                    <span class="text-gray-300">{{ d.label }}</span>
                                    <span class="text-gray-500">{{ d.count }}</span>
                                </div>
                                <div class="h-2 w-full rounded-full bg-gray-800">
                                    <div class="h-2 rounded-full bg-amber-500" :style="{ width: pct(byState, d.count) + '%' }" />
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-gray-500">Sem dados.</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
