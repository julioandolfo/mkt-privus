<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

interface Plan {
    id: number;
    name: string;
    description: string | null;
    price: string;
    currency: string;
    features: string[] | null;
}

defineProps<{
    billingEnabled: boolean;
    plans: Plan[];
}>();

const features = [
    {
        title: 'Redes Sociais com IA',
        desc: 'Gere posts, legendas e imagens no piloto automático. Agende e publique em todas as redes a partir de um painel só.',
        icon: 'share',
        tint: 'text-violet-300 bg-violet-500/10',
        chips: [
            { t: 'Geração por IA', c: 'text-violet-300 bg-violet-500/10' },
            { t: 'Agendamento', c: 'text-cyan-300 bg-cyan-500/10' },
            { t: 'Multiplataforma', c: 'text-emerald-300 bg-emerald-500/10' },
        ],
    },
    {
        title: 'E-mail Marketing',
        desc: 'Editor visual, listas segmentadas, automações e métricas de abertura e clique em tempo real.',
        icon: 'mail',
        tint: 'text-cyan-300 bg-cyan-500/10',
        chips: [
            { t: 'Editor visual', c: 'text-cyan-300 bg-cyan-500/10' },
            { t: 'Automações', c: 'text-violet-300 bg-violet-500/10' },
            { t: 'Tracking', c: 'text-orange-300 bg-orange-500/10' },
        ],
    },
    {
        title: 'SMS Marketing',
        desc: 'Dispare campanhas de SMS com cálculo de segmentos e custo, e acompanhe a entrega.',
        icon: 'smartphone',
        tint: 'text-emerald-300 bg-emerald-500/10',
        chips: [
            { t: 'Campanhas', c: 'text-emerald-300 bg-emerald-500/10' },
            { t: 'Custo estimado', c: 'text-fuchsia-300 bg-fuchsia-500/10' },
        ],
    },
    {
        title: 'Blog & SEO',
        desc: 'Autopilot de conteúdo: gere e publique artigos otimizados direto no seu WordPress.',
        icon: 'file-text',
        tint: 'text-orange-300 bg-orange-500/10',
        chips: [
            { t: 'Autopilot', c: 'text-orange-300 bg-orange-500/10' },
            { t: 'WordPress', c: 'text-cyan-300 bg-cyan-500/10' },
            { t: 'SEO', c: 'text-emerald-300 bg-emerald-500/10' },
        ],
    },
    {
        title: 'Analytics',
        desc: 'Conecte Google Analytics, Ads, Search Console e Meta. Métricas e investimentos num só lugar.',
        icon: 'bar-chart',
        tint: 'text-fuchsia-300 bg-fuchsia-500/10',
        chips: [
            { t: 'GA4 & Ads', c: 'text-fuchsia-300 bg-fuchsia-500/10' },
            { t: 'Meta', c: 'text-violet-300 bg-violet-500/10' },
        ],
    },
    {
        title: 'Várias marcas',
        desc: 'Gerencie múltiplas marcas com dados isolados, equipe por papéis e troca de contexto num clique.',
        icon: 'briefcase',
        tint: 'text-violet-300 bg-violet-500/10',
        chips: [
            { t: 'Multimarcas', c: 'text-violet-300 bg-violet-500/10' },
            { t: 'Equipe', c: 'text-cyan-300 bg-cyan-500/10' },
        ],
    },
];

function formatPrice(plan: Plan): string {
    return Number(plan.price).toLocaleString('pt-BR', { style: 'currency', currency: plan.currency || 'BRL' });
}
</script>

<template>
    <Head title="MKT Privus — Marketing com IA" />

    <div class="min-h-screen bg-[#0a0a0f] text-gray-200">
        <!-- Grid de fundo -->
        <div
            class="pointer-events-none fixed inset-0 opacity-[0.06]"
            style="background-image: linear-gradient(to right, #fff 1px, transparent 1px), linear-gradient(to bottom, #fff 1px, transparent 1px); background-size: 44px 44px;"
        />

        <div class="relative">
            <!-- Navbar -->
            <header class="sticky top-0 z-40 border-b border-white/5 bg-[#0a0a0f]/80 backdrop-blur-md">
                <nav class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-fuchsia-600 text-sm font-bold text-white">M</div>
                        <span class="text-lg font-bold text-white">MKT <span class="text-violet-400">Privus</span></span>
                    </div>
                    <div class="hidden items-center gap-8 text-sm text-gray-400 md:flex">
                        <a href="#funcionalidades" class="transition hover:text-white">Funcionalidades</a>
                        <a v-if="billingEnabled && plans.length" href="#planos" class="transition hover:text-white">Planos</a>
                        <Link :href="route('login')" class="transition hover:text-white">Entrar</Link>
                    </div>
                    <Link
                        :href="route('register')"
                        class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-violet-900/30 transition hover:from-violet-500 hover:to-fuchsia-500"
                    >
                        Criar conta grátis
                    </Link>
                </nav>
            </header>

            <!-- Hero -->
            <section class="mx-auto grid max-w-7xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24 lg:px-8">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3.5 py-1.5 text-xs font-medium text-gray-300">
                        <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                        Plataforma de Marketing com IA
                    </div>
                    <h1 class="mt-6 text-4xl font-extrabold leading-[1.05] tracking-tight text-white sm:text-5xl lg:text-6xl">
                        Todo o seu<br />
                        <span class="bg-gradient-to-r from-violet-400 via-fuchsia-400 to-cyan-400 bg-clip-text text-transparent">marketing</span><br />
                        em um só lugar
                    </h1>
                    <p class="mt-6 max-w-md text-lg leading-relaxed text-gray-400">
                        Campanhas de e-mail e SMS, redes sociais, blog e análises com inteligência artificial. Do conteúdo ao resultado, num painel inteligente.
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <Link
                            :href="route('register')"
                            class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-violet-900/30 transition hover:from-violet-500 hover:to-fuchsia-500"
                        >
                            Começar gratuitamente →
                        </Link>
                        <a
                            href="#funcionalidades"
                            class="rounded-xl border border-white/10 bg-white/5 px-6 py-3 text-sm font-semibold text-white transition hover:bg-white/10"
                        >
                            Ver funcionalidades
                        </a>
                    </div>
                    <div class="mt-8 flex items-center gap-3 text-sm">
                        <div class="flex -space-x-2">
                            <div v-for="n in 4" :key="n" class="h-7 w-7 rounded-full border-2 border-[#0a0a0f] bg-gradient-to-br from-violet-500 to-fuchsia-600"></div>
                        </div>
                        <span class="font-semibold text-white">+500 empresas</span>
                        <span class="text-amber-400">★★★★★</span>
                        <span class="text-gray-500">4.9/5</span>
                    </div>
                </div>

                <!-- Mock de painel -->
                <div class="relative">
                    <div class="absolute -inset-4 rounded-3xl bg-gradient-to-br from-violet-600/20 to-fuchsia-600/10 blur-2xl"></div>
                    <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-[#101018] shadow-2xl">
                        <div class="flex items-center gap-1.5 border-b border-white/5 px-4 py-3">
                            <span class="h-3 w-3 rounded-full bg-red-400/80"></span>
                            <span class="h-3 w-3 rounded-full bg-amber-400/80"></span>
                            <span class="h-3 w-3 rounded-full bg-emerald-400/80"></span>
                            <span class="ml-3 text-xs text-gray-500">app.mktprivus.com/dashboard</span>
                        </div>
                        <div class="space-y-4 p-4">
                            <div class="grid grid-cols-3 gap-3">
                                <div class="rounded-xl border border-white/5 bg-white/5 p-3">
                                    <p class="text-[10px] text-gray-500">Enviados</p>
                                    <p class="text-lg font-bold text-white">48.7k</p>
                                    <p class="text-[10px] text-emerald-400">+12%</p>
                                </div>
                                <div class="rounded-xl border border-white/5 bg-white/5 p-3">
                                    <p class="text-[10px] text-gray-500">Aberturas</p>
                                    <p class="text-lg font-bold text-white">31%</p>
                                    <p class="text-[10px] text-emerald-400">+8%</p>
                                </div>
                                <div class="rounded-xl border border-white/5 bg-white/5 p-3">
                                    <p class="text-[10px] text-gray-500">Posts</p>
                                    <p class="text-lg font-bold text-white">3.942</p>
                                    <p class="text-[10px] text-cyan-400">+3%</p>
                                </div>
                            </div>
                            <div class="rounded-xl border border-white/5 bg-white/5 p-4">
                                <div class="flex h-28 items-end gap-2">
                                    <div v-for="(h, i) in [40, 65, 50, 80, 55, 90, 70, 100, 60, 85]" :key="i"
                                        class="flex-1 rounded-t bg-gradient-to-t from-violet-600 to-fuchsia-500"
                                        :style="{ height: h + '%' }" />
                                </div>
                            </div>
                            <div class="space-y-2">
                                <div v-for="(c, i) in ['Campanha Black Friday', 'Lançamento Coleção', 'Newsletter Semanal']" :key="i"
                                    class="flex items-center justify-between rounded-lg border border-white/5 bg-white/5 px-3 py-2">
                                    <span class="text-xs text-gray-300">{{ c }}</span>
                                    <span class="rounded bg-emerald-500/15 px-1.5 py-0.5 text-[10px] text-emerald-400">enviada</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Funcionalidades -->
            <section id="funcionalidades" class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold text-white sm:text-4xl">Tudo que seu marketing precisa</h2>
                    <p class="mt-3 text-gray-400">Do conteúdo à análise, a MKT Privus centraliza sua operação em um painel poderoso e intuitivo.</p>
                </div>
                <div class="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="f in features"
                        :key="f.title"
                        class="rounded-2xl border border-white/10 bg-white/[0.03] p-6 transition hover:border-white/20 hover:bg-white/[0.05]"
                    >
                        <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl" :class="f.tint">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <template v-if="f.icon === 'share'"><circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><line x1="8.59" y1="13.51" x2="15.42" y2="17.49" /><line x1="15.41" y1="6.51" x2="8.59" y2="10.49" /></template>
                                <template v-else-if="f.icon === 'mail'"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" /><polyline points="22,6 12,13 2,6" /></template>
                                <template v-else-if="f.icon === 'smartphone'"><rect x="5" y="2" width="14" height="20" rx="2" ry="2" /><line x1="12" y1="18" x2="12.01" y2="18" /></template>
                                <template v-else-if="f.icon === 'file-text'"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" /></template>
                                <template v-else-if="f.icon === 'bar-chart'"><line x1="12" y1="20" x2="12" y2="10" /><line x1="18" y1="20" x2="18" y2="4" /><line x1="6" y1="20" x2="6" y2="16" /></template>
                                <template v-else-if="f.icon === 'briefcase'"><rect x="2" y="7" width="20" height="14" rx="2" ry="2" /><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" /></template>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white">{{ f.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-gray-400">{{ f.desc }}</p>
                        <div class="mt-4 flex flex-wrap gap-1.5">
                            <span v-for="chip in f.chips" :key="chip.t" class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="chip.c">{{ chip.t }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Planos -->
            <section v-if="billingEnabled && plans.length" id="planos" class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold text-white sm:text-4xl">Planos para cada fase</h2>
                    <p class="mt-3 text-gray-400">Comece com teste grátis. Sem cartão de crédito.</p>
                </div>
                <div class="mt-12 grid gap-6 md:grid-cols-3">
                    <div
                        v-for="(plan, i) in plans"
                        :key="plan.id"
                        class="relative flex flex-col rounded-2xl border p-6"
                        :class="i === 1 ? 'border-violet-500/50 bg-violet-500/5' : 'border-white/10 bg-white/[0.03]'"
                    >
                        <div v-if="i === 1" class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-gradient-to-r from-violet-600 to-fuchsia-600 px-3 py-0.5 text-xs font-semibold text-white">
                            Mais popular
                        </div>
                        <h3 class="text-lg font-semibold text-white">{{ plan.name }}</h3>
                        <p class="mt-1 text-sm text-gray-400">{{ plan.description }}</p>
                        <p class="mt-4 text-3xl font-bold text-white">
                            {{ formatPrice(plan) }}<span class="text-sm font-normal text-gray-500">/mês</span>
                        </p>
                        <ul class="mt-6 flex-1 space-y-2.5 text-sm text-gray-300">
                            <li v-for="feat in plan.features ?? []" :key="feat" class="flex items-start gap-2">
                                <span class="mt-0.5 text-emerald-400">✓</span><span>{{ feat }}</span>
                            </li>
                        </ul>
                        <Link
                            :href="route('register')"
                            class="mt-6 rounded-xl px-4 py-2.5 text-center text-sm font-semibold transition"
                            :class="i === 1
                                ? 'bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white hover:from-violet-500 hover:to-fuchsia-500'
                                : 'border border-white/10 bg-white/5 text-white hover:bg-white/10'"
                        >
                            Começar agora
                        </Link>
                    </div>
                </div>
            </section>

            <!-- CTA -->
            <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                <div class="relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-br from-violet-700/30 via-fuchsia-700/20 to-transparent p-10 text-center sm:p-16">
                    <h2 class="text-3xl font-bold text-white sm:text-4xl">Pronto para crescer?</h2>
                    <p class="mx-auto mt-3 max-w-lg text-gray-300">Crie sua conta grátis e leve seu marketing para o próximo nível com inteligência artificial.</p>
                    <Link
                        :href="route('register')"
                        class="mt-8 inline-block rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-8 py-3 text-sm font-semibold text-white shadow-lg shadow-violet-900/30 transition hover:from-violet-500 hover:to-fuchsia-500"
                    >
                        Começar gratuitamente →
                    </Link>
                </div>
            </section>

            <!-- Footer -->
            <footer class="border-t border-white/5">
                <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 px-4 py-8 sm:flex-row sm:px-6 lg:px-8">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-violet-500 to-fuchsia-600 text-xs font-bold text-white">M</div>
                        <span class="font-bold text-white">MKT <span class="text-violet-400">Privus</span></span>
                    </div>
                    <div class="flex items-center gap-6 text-sm text-gray-500">
                        <Link :href="route('terms')" class="transition hover:text-white">Termos</Link>
                        <Link :href="route('privacy')" class="transition hover:text-white">Privacidade</Link>
                        <Link :href="route('login')" class="transition hover:text-white">Entrar</Link>
                    </div>
                    <p class="text-xs text-gray-600">© {{ new Date().getFullYear() }} MKT Privus</p>
                </div>
            </footer>
        </div>
    </div>
</template>
