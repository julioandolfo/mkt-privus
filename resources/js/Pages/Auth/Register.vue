<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    brand_name: '',
    cnpj: '',
    legal_name: '',
    phone: '',
    company_size: '',
    segment: '',
    cep: '',
    address_street: '',
    address_number: '',
    address_complement: '',
    address_neighborhood: '',
    address_city: '',
    address_state: '',
    goals: [] as string[],
    objective: '',
});

const steps = [
    { id: 1, title: 'Conta', subtitle: 'Seus dados de acesso' },
    { id: 2, title: 'Empresa', subtitle: 'Sobre o seu negócio' },
    { id: 3, title: 'Endereço', subtitle: 'Onde você está' },
    { id: 4, title: 'Objetivos', subtitle: 'O que você busca' },
];
const step = ref(1);
const progress = computed(() => (step.value / steps.length) * 100);

const segments = [
    'E-commerce', 'Varejo', 'Serviços', 'Saúde', 'Educação', 'Alimentação',
    'Moda e Beleza', 'Tecnologia', 'Imobiliário', 'Indústria',
    'Agência de Marketing', 'Turismo', 'Financeiro', 'Outro',
];
const companySizes = ['MEI', 'Microempresa', 'Pequena', 'Média', 'Grande'];
const goalOptions = [
    'Aumentar vendas', 'Gerar leads', 'Automatizar redes sociais',
    'E-mail marketing', 'Marketing por SMS', 'Criar conteúdo com IA',
    'Gerenciar várias marcas', 'Blog / SEO', 'Análise de métricas',
    'Agendamento de posts',
];

const lookingUpCnpj = ref(false);
const cnpjError = ref<string | null>(null);
const lookingUpCep = ref(false);
const cepError = ref<string | null>(null);

function maskCnpj() {
    let v = form.cnpj.replace(/\D/g, '').slice(0, 14);
    v = v.replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\d{4})(\d)/, '$1-$2');
    form.cnpj = v;
}

function maskCep() {
    form.cep = form.cep.replace(/\D/g, '').slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2');
}

async function lookupCnpj() {
    const digits = form.cnpj.replace(/\D/g, '');
    if (digits.length !== 14) return;
    lookingUpCnpj.value = true;
    cnpjError.value = null;
    try {
        const { data } = await axios.get(`/onboarding/cnpj/${digits}`);
        form.legal_name = data.legal_name || form.legal_name;
        if (!form.brand_name) form.brand_name = data.trade_name || data.legal_name || '';
        if (!form.segment && data.segment) {
            const match = segments.find((s) => data.segment.toLowerCase().includes(s.toLowerCase()));
            if (match) form.segment = match;
        }
        form.phone = data.phone || form.phone;
        form.cep = data.cep || form.cep;
        form.address_street = data.address_street || form.address_street;
        form.address_number = data.address_number || form.address_number;
        form.address_complement = data.address_complement || form.address_complement;
        form.address_neighborhood = data.address_neighborhood || form.address_neighborhood;
        form.address_city = data.address_city || form.address_city;
        form.address_state = data.address_state || form.address_state;
    } catch (e: any) {
        cnpjError.value = e.response?.data?.error || 'Não foi possível consultar o CNPJ.';
    } finally {
        lookingUpCnpj.value = false;
    }
}

async function lookupCep() {
    const digits = form.cep.replace(/\D/g, '');
    if (digits.length !== 8) return;
    lookingUpCep.value = true;
    cepError.value = null;
    try {
        const { data } = await axios.get(`/onboarding/cep/${digits}`);
        form.address_street = data.address_street || form.address_street;
        form.address_neighborhood = data.address_neighborhood || form.address_neighborhood;
        form.address_city = data.address_city || form.address_city;
        form.address_state = data.address_state || form.address_state;
    } catch (e: any) {
        cepError.value = e.response?.data?.error || 'CEP não encontrado.';
    } finally {
        lookingUpCep.value = false;
    }
}

function toggleGoal(goal: string) {
    const i = form.goals.indexOf(goal);
    if (i === -1) form.goals.push(goal);
    else form.goals.splice(i, 1);
}

const canAdvance = computed(() => {
    if (step.value === 1) {
        return form.name && form.email && form.password.length >= 8
            && form.password === form.password_confirmation;
    }
    if (step.value === 2) {
        return !!form.brand_name;
    }
    return true;
});

function next() {
    if (step.value < steps.length && canAdvance.value) step.value++;
}
function back() {
    if (step.value > 1) step.value--;
}

const submit = () => {
    form.post(route('register'), {
        onError: (errors) => {
            const stepOf: Record<string, number> = {
                name: 1, email: 1, password: 1,
                brand_name: 2, cnpj: 2, legal_name: 2, segment: 2, company_size: 2, phone: 2,
                cep: 3, address_street: 3, address_city: 3, address_state: 3,
            };
            const first = Object.keys(errors)[0];
            if (first && stepOf[first]) step.value = stepOf[first];
        },
    });
};

// Classes reutilizáveis no estilo VendexHub
const inputClass =
    'w-full rounded-xl border border-white/10 bg-white/5 text-white placeholder-gray-500 focus:border-violet-500 focus:ring-violet-500';
const featureChips = [
    { label: 'E-mail & SMS com IA', color: 'text-violet-300 border-violet-500/30 bg-violet-500/10' },
    { label: 'Redes no piloto automático', color: 'text-cyan-300 border-cyan-500/30 bg-cyan-500/10' },
    { label: 'Blog & SEO', color: 'text-emerald-300 border-emerald-500/30 bg-emerald-500/10' },
    { label: 'Analytics', color: 'text-orange-300 border-orange-500/30 bg-orange-500/10' },
    { label: 'Multimarcas', color: 'text-fuchsia-300 border-fuchsia-500/30 bg-fuchsia-500/10' },
];
</script>

<template>
    <Head title="Criar conta" />

    <div class="relative grid min-h-screen bg-[#0a0a0f] lg:grid-cols-2">
        <!-- Grid de fundo -->
        <div
            class="pointer-events-none absolute inset-0 opacity-[0.07]"
            style="background-image: linear-gradient(to right, #fff 1px, transparent 1px), linear-gradient(to bottom, #fff 1px, transparent 1px); background-size: 44px 44px;"
        />

        <!-- Painel de marketing (esquerda) -->
        <div class="relative hidden flex-col justify-between p-12 lg:flex">
            <!-- Logo -->
            <div class="flex items-center gap-2.5">
                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-fuchsia-600 text-sm font-bold text-white">M</div>
                <span class="text-xl font-bold text-white">MKT <span class="text-violet-400">Privus</span></span>
            </div>

            <!-- Headline -->
            <div class="space-y-7">
                <div class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3.5 py-1.5 text-xs font-medium text-gray-300">
                    <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                    Plataforma de Marketing com IA
                </div>

                <h2 class="text-4xl font-extrabold leading-[1.1] tracking-tight text-white">
                    Todo o seu<br />
                    <span class="bg-gradient-to-r from-violet-400 via-fuchsia-400 to-cyan-400 bg-clip-text text-transparent">marketing</span><br />
                    em um só lugar
                </h2>

                <p class="max-w-md text-base leading-relaxed text-gray-400">
                    Campanhas, redes sociais, blog e análises com inteligência artificial — do conteúdo ao resultado, num painel só.
                </p>

                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="chip in featureChips"
                        :key="chip.label"
                        class="rounded-full border px-3 py-1 text-xs font-medium"
                        :class="chip.color"
                    >
                        {{ chip.label }}
                    </span>
                </div>
            </div>

            <!-- Prova social -->
            <div class="flex items-center gap-3 text-sm">
                <div class="flex -space-x-2">
                    <div v-for="n in 4" :key="n" class="h-7 w-7 rounded-full border-2 border-[#0a0a0f] bg-gradient-to-br from-violet-500 to-fuchsia-600"></div>
                </div>
                <span class="font-semibold text-white">+500 empresas</span>
                <span class="text-amber-400">★★★★★</span>
                <span class="text-gray-500">4.9/5</span>
            </div>
        </div>

        <!-- Formulário (direita) -->
        <div class="relative flex items-center justify-center overflow-y-auto p-4 py-8 sm:p-8">
            <div class="w-full max-w-xl">
                <div class="mb-8">
                    <div class="mb-6 flex items-center gap-2.5 lg:hidden">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-fuchsia-600 text-sm font-bold text-white">M</div>
                        <span class="text-lg font-bold text-white">MKT <span class="text-violet-400">Privus</span></span>
                    </div>
                    <h1 class="text-2xl font-bold text-white">Crie sua conta</h1>
                    <p class="mt-1 text-sm text-gray-400">Comece grátis — leva menos de 2 minutos.</p>
                </div>

                <div class="rounded-3xl border border-white/10 bg-white/[0.03] p-6 shadow-2xl backdrop-blur-sm sm:p-8">
                    <!-- Progresso -->
                    <div class="mb-8">
                        <div class="mb-3 flex items-center justify-between">
                            <div v-for="s in steps" :key="s.id" class="flex flex-1 flex-col items-center">
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold transition"
                                    :class="step >= s.id ? 'bg-gradient-to-br from-violet-500 to-fuchsia-600 text-white' : 'bg-white/5 text-gray-500'"
                                >
                                    <span v-if="step > s.id">✓</span>
                                    <span v-else>{{ s.id }}</span>
                                </div>
                                <span class="mt-1.5 hidden text-[11px] sm:block" :class="step >= s.id ? 'text-violet-300' : 'text-gray-600'">
                                    {{ s.title }}
                                </span>
                            </div>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-white/5">
                            <div class="h-full rounded-full bg-gradient-to-r from-violet-500 to-fuchsia-500 transition-all duration-300" :style="{ width: progress + '%' }" />
                        </div>
                    </div>

                    <form @submit.prevent="submit">
                        <!-- Passo 1: Conta -->
                        <div v-show="step === 1" class="space-y-4">
                            <h2 class="text-lg font-semibold text-white">{{ steps[0].subtitle }}</h2>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Seu nome</label>
                                <input v-model="form.name" type="text" autocomplete="name" :class="inputClass" placeholder="Nome completo" />
                                <InputError class="mt-1" :message="form.errors.name" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">E-mail</label>
                                <input v-model="form.email" type="email" autocomplete="username" :class="inputClass" placeholder="seu@email.com" />
                                <InputError class="mt-1" :message="form.errors.email" />
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-300">Senha</label>
                                    <input v-model="form.password" type="password" autocomplete="new-password" :class="inputClass" placeholder="Mínimo 8 caracteres" />
                                    <InputError class="mt-1" :message="form.errors.password" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-300">Confirmar senha</label>
                                    <input v-model="form.password_confirmation" type="password" autocomplete="new-password" :class="inputClass" placeholder="Repita a senha" />
                                </div>
                            </div>
                        </div>

                        <!-- Passo 2: Empresa -->
                        <div v-show="step === 2" class="space-y-4">
                            <h2 class="text-lg font-semibold text-white">{{ steps[1].subtitle }}</h2>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">CNPJ <span class="text-gray-500">(preenche os dados automaticamente)</span></label>
                                <div class="flex gap-2">
                                    <input v-model="form.cnpj" @input="maskCnpj" @blur="lookupCnpj" type="text" inputmode="numeric" :class="inputClass" placeholder="00.000.000/0000-00" />
                                    <button type="button" @click="lookupCnpj" :disabled="lookingUpCnpj" class="shrink-0 rounded-xl border border-violet-500/30 bg-violet-500/10 px-4 text-sm font-medium text-violet-300 transition hover:bg-violet-500/20 disabled:opacity-50">
                                        {{ lookingUpCnpj ? 'Buscando...' : 'Buscar' }}
                                    </button>
                                </div>
                                <p v-if="cnpjError" class="mt-1 text-xs text-amber-400">{{ cnpjError }}</p>
                                <InputError class="mt-1" :message="form.errors.cnpj" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Nome da empresa / marca</label>
                                <input v-model="form.brand_name" type="text" :class="inputClass" placeholder="Sua loja ou marca" />
                                <InputError class="mt-1" :message="form.errors.brand_name" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Razão social</label>
                                <input v-model="form.legal_name" type="text" :class="inputClass" placeholder="Razão social (opcional)" />
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-300">Segmento</label>
                                    <select v-model="form.segment" :class="inputClass">
                                        <option value="" class="bg-gray-900">Selecione...</option>
                                        <option v-for="s in segments" :key="s" :value="s" class="bg-gray-900">{{ s }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-300">Porte da empresa</label>
                                    <select v-model="form.company_size" :class="inputClass">
                                        <option value="" class="bg-gray-900">Selecione...</option>
                                        <option v-for="c in companySizes" :key="c" :value="c" class="bg-gray-900">{{ c }}</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Telefone</label>
                                <input v-model="form.phone" type="text" :class="inputClass" placeholder="(00) 00000-0000" />
                            </div>
                        </div>

                        <!-- Passo 3: Endereço -->
                        <div v-show="step === 3" class="space-y-4">
                            <h2 class="text-lg font-semibold text-white">{{ steps[2].subtitle }}</h2>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-300">CEP</label>
                                    <div class="flex gap-2">
                                        <input v-model="form.cep" @input="maskCep" @blur="lookupCep" type="text" inputmode="numeric" :class="inputClass" placeholder="00000-000" />
                                        <button type="button" @click="lookupCep" :disabled="lookingUpCep" class="shrink-0 rounded-xl border border-violet-500/30 bg-violet-500/10 px-3 text-sm font-medium text-violet-300 transition hover:bg-violet-500/20 disabled:opacity-50">
                                            {{ lookingUpCep ? '...' : 'Buscar' }}
                                        </button>
                                    </div>
                                    <p v-if="cepError" class="mt-1 text-xs text-amber-400">{{ cepError }}</p>
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="mb-1 block text-sm font-medium text-gray-300">Rua / Logradouro</label>
                                    <input v-model="form.address_street" type="text" :class="inputClass" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-300">Número</label>
                                    <input v-model="form.address_number" type="text" :class="inputClass" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-300">Complemento</label>
                                    <input v-model="form.address_complement" type="text" :class="inputClass" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-300">Bairro</label>
                                    <input v-model="form.address_neighborhood" type="text" :class="inputClass" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div class="sm:col-span-2">
                                    <label class="mb-1 block text-sm font-medium text-gray-300">Cidade</label>
                                    <input v-model="form.address_city" type="text" :class="inputClass" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-300">UF</label>
                                    <input v-model="form.address_state" type="text" maxlength="2" :class="inputClass + ' uppercase'" />
                                </div>
                            </div>
                        </div>

                        <!-- Passo 4: Objetivos -->
                        <div v-show="step === 4" class="space-y-4">
                            <h2 class="text-lg font-semibold text-white">{{ steps[3].subtitle }}</h2>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-300">O que você busca com o sistema? <span class="text-gray-500">(selecione quantos quiser)</span></label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="goal in goalOptions"
                                        :key="goal"
                                        type="button"
                                        @click="toggleGoal(goal)"
                                        class="rounded-full border px-3.5 py-1.5 text-sm transition"
                                        :class="form.goals.includes(goal)
                                            ? 'border-violet-500 bg-gradient-to-r from-violet-500/20 to-fuchsia-500/20 text-violet-200'
                                            : 'border-white/10 bg-white/5 text-gray-400 hover:border-white/20'"
                                    >
                                        {{ goal }}
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">O que você pretende alcançar?</label>
                                <textarea v-model="form.objective" rows="4" :class="inputClass" placeholder="Conte rapidamente seu objetivo principal (ex.: aumentar vendas online, profissionalizar as redes sociais...)" />
                            </div>
                        </div>

                        <!-- Navegação -->
                        <div class="mt-8 flex items-center justify-between gap-3">
                            <button v-if="step > 1" type="button" @click="back" class="rounded-xl px-5 py-2.5 text-sm font-medium text-gray-400 transition hover:text-white">
                                ← Voltar
                            </button>
                            <Link v-else :href="route('login')" class="rounded-xl px-1 py-2.5 text-sm text-gray-400 transition hover:text-white">
                                Já tem conta? Entrar
                            </Link>

                            <button
                                v-if="step < steps.length"
                                type="button"
                                @click="next"
                                :disabled="!canAdvance"
                                class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-violet-900/30 transition hover:from-violet-500 hover:to-fuchsia-500 disabled:opacity-40"
                            >
                                Próximo →
                            </button>
                            <button
                                v-else
                                type="submit"
                                :disabled="form.processing"
                                class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-violet-900/30 transition hover:from-violet-500 hover:to-fuchsia-500 disabled:opacity-50"
                            >
                                {{ form.processing ? 'Criando conta...' : 'Criar conta grátis' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
