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
            // tenta casar com a lista; senão deixa o usuário escolher
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

// Validação mínima por passo para liberar o "Próximo"
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
            // Volta ao passo do primeiro erro
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
</script>

<template>
    <Head title="Criar conta" />

    <div class="flex min-h-screen items-center justify-center bg-gray-950 p-4">
        <div class="w-full max-w-2xl">
            <div class="mb-8 text-center">
                <h1 class="text-2xl font-bold text-white">Crie sua conta</h1>
                <p class="mt-1 text-sm text-gray-400">Comece grátis — leva menos de 2 minutos.</p>
            </div>

            <div class="rounded-3xl border border-gray-800 bg-gray-900 p-6 shadow-2xl sm:p-8">
                <!-- Progresso -->
                <div class="mb-8">
                    <div class="mb-3 flex items-center justify-between">
                        <div v-for="s in steps" :key="s.id" class="flex flex-1 flex-col items-center">
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold transition"
                                :class="step >= s.id ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-500'"
                            >
                                <span v-if="step > s.id">✓</span>
                                <span v-else>{{ s.id }}</span>
                            </div>
                            <span class="mt-1.5 hidden text-[11px] sm:block" :class="step >= s.id ? 'text-indigo-300' : 'text-gray-600'">
                                {{ s.title }}
                            </span>
                        </div>
                    </div>
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-800">
                        <div class="h-full rounded-full bg-indigo-600 transition-all duration-300" :style="{ width: progress + '%' }" />
                    </div>
                </div>

                <form @submit.prevent="submit">
                    <!-- Passo 1: Conta -->
                    <div v-show="step === 1" class="space-y-4">
                        <h2 class="text-lg font-semibold text-white">{{ steps[0].subtitle }}</h2>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-300">Seu nome</label>
                            <input v-model="form.name" type="text" autocomplete="name" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="Nome completo" />
                            <InputError class="mt-1" :message="form.errors.name" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-300">E-mail</label>
                            <input v-model="form.email" type="email" autocomplete="username" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="seu@email.com" />
                            <InputError class="mt-1" :message="form.errors.email" />
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Senha</label>
                                <input v-model="form.password" type="password" autocomplete="new-password" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="Mínimo 8 caracteres" />
                                <InputError class="mt-1" :message="form.errors.password" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Confirmar senha</label>
                                <input v-model="form.password_confirmation" type="password" autocomplete="new-password" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="Repita a senha" />
                            </div>
                        </div>
                    </div>

                    <!-- Passo 2: Empresa -->
                    <div v-show="step === 2" class="space-y-4">
                        <h2 class="text-lg font-semibold text-white">{{ steps[1].subtitle }}</h2>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-300">CNPJ <span class="text-gray-500">(preenche os dados automaticamente)</span></label>
                            <div class="flex gap-2">
                                <input v-model="form.cnpj" @input="maskCnpj" @blur="lookupCnpj" type="text" inputmode="numeric" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="00.000.000/0000-00" />
                                <button type="button" @click="lookupCnpj" :disabled="lookingUpCnpj" class="shrink-0 rounded-xl bg-indigo-600/20 px-4 text-sm font-medium text-indigo-300 hover:bg-indigo-600/30 disabled:opacity-50">
                                    {{ lookingUpCnpj ? 'Buscando...' : 'Buscar' }}
                                </button>
                            </div>
                            <p v-if="cnpjError" class="mt-1 text-xs text-amber-400">{{ cnpjError }}</p>
                            <InputError class="mt-1" :message="form.errors.cnpj" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-300">Nome da empresa / marca</label>
                            <input v-model="form.brand_name" type="text" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="Sua loja ou marca" />
                            <InputError class="mt-1" :message="form.errors.brand_name" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-300">Razão social</label>
                            <input v-model="form.legal_name" type="text" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="Razão social (opcional)" />
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Segmento</label>
                                <select v-model="form.segment" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Selecione...</option>
                                    <option v-for="s in segments" :key="s" :value="s">{{ s }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Porte da empresa</label>
                                <select v-model="form.company_size" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Selecione...</option>
                                    <option v-for="c in companySizes" :key="c" :value="c">{{ c }}</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-300">Telefone</label>
                            <input v-model="form.phone" type="text" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="(00) 00000-0000" />
                        </div>
                    </div>

                    <!-- Passo 3: Endereço -->
                    <div v-show="step === 3" class="space-y-4">
                        <h2 class="text-lg font-semibold text-white">{{ steps[2].subtitle }}</h2>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">CEP</label>
                                <div class="flex gap-2">
                                    <input v-model="form.cep" @input="maskCep" @blur="lookupCep" type="text" inputmode="numeric" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="00000-000" />
                                    <button type="button" @click="lookupCep" :disabled="lookingUpCep" class="shrink-0 rounded-xl bg-indigo-600/20 px-3 text-sm font-medium text-indigo-300 hover:bg-indigo-600/30 disabled:opacity-50">
                                        {{ lookingUpCep ? '...' : 'Buscar' }}
                                    </button>
                                </div>
                                <p v-if="cepError" class="mt-1 text-xs text-amber-400">{{ cepError }}</p>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-sm font-medium text-gray-300">Rua / Logradouro</label>
                                <input v-model="form.address_street" type="text" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Número</label>
                                <input v-model="form.address_number" type="text" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Complemento</label>
                                <input v-model="form.address_complement" type="text" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Bairro</label>
                                <input v-model="form.address_neighborhood" type="text" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-sm font-medium text-gray-300">Cidade</label>
                                <input v-model="form.address_city" type="text" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">UF</label>
                                <input v-model="form.address_state" type="text" maxlength="2" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white uppercase focus:border-indigo-500 focus:ring-indigo-500" />
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
                                        ? 'border-indigo-500 bg-indigo-600/20 text-indigo-200'
                                        : 'border-gray-700 bg-gray-800 text-gray-400 hover:border-gray-600'"
                                >
                                    {{ goal }}
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-300">O que você pretende alcançar?</label>
                            <textarea v-model="form.objective" rows="4" class="w-full rounded-xl border-gray-700 bg-gray-800 text-white focus:border-indigo-500 focus:ring-indigo-500" placeholder="Conte rapidamente seu objetivo principal (ex.: aumentar vendas online, profissionalizar as redes sociais...)" />
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
                            class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-40"
                        >
                            Próximo →
                        </button>
                        <button
                            v-else
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50"
                        >
                            {{ form.processing ? 'Criando conta...' : 'Criar conta grátis' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
