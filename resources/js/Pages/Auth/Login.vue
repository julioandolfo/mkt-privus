<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

const inputClass =
    'w-full rounded-xl border border-white/10 bg-white/5 text-white placeholder-gray-500 focus:border-violet-500 focus:ring-violet-500';
</script>

<template>
    <Head title="Entrar" />

    <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-[#0a0a0f] p-4">
        <!-- Grid de fundo -->
        <div
            class="pointer-events-none absolute inset-0 opacity-[0.07]"
            style="background-image: linear-gradient(to right, #fff 1px, transparent 1px), linear-gradient(to bottom, #fff 1px, transparent 1px); background-size: 44px 44px;"
        />
        <!-- Brilho -->
        <div class="pointer-events-none absolute -top-40 left-1/2 h-80 w-80 -translate-x-1/2 rounded-full bg-violet-600/20 blur-3xl" />

        <div class="relative w-full max-w-md">
            <div class="mb-8 flex flex-col items-center">
                <div class="mb-4 flex items-center gap-2.5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-fuchsia-600 text-base font-bold text-white">M</div>
                    <span class="text-xl font-bold text-white">MKT <span class="text-violet-400">Privus</span></span>
                </div>
                <h1 class="text-2xl font-bold text-white">Bem-vindo de volta</h1>
                <p class="mt-1 text-sm text-gray-400">Entre para acessar seu painel.</p>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/[0.03] p-6 shadow-2xl backdrop-blur-sm sm:p-8">
                <div v-if="status" class="mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-2.5 text-sm font-medium text-emerald-400">
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-300">E-mail</label>
                        <input v-model="form.email" type="email" required autofocus autocomplete="username" :class="inputClass" placeholder="seu@email.com" />
                        <InputError class="mt-1" :message="form.errors.email" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-300">Senha</label>
                        <input v-model="form.password" type="password" required autocomplete="current-password" :class="inputClass" placeholder="••••••••" />
                        <InputError class="mt-1" :message="form.errors.password" />
                    </div>

                    <div class="flex items-center justify-between">
                        <label class="flex items-center">
                            <Checkbox name="remember" v-model:checked="form.remember" />
                            <span class="ms-2 text-sm text-gray-400">Lembrar de mim</span>
                        </label>
                        <Link
                            v-if="canResetPassword"
                            :href="route('password.request')"
                            class="text-sm text-violet-400 hover:text-violet-300"
                        >
                            Esqueceu a senha?
                        </Link>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-violet-900/30 transition hover:from-violet-500 hover:to-fuchsia-500 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Entrando...' : 'Entrar' }}
                    </button>
                </form>

                <div class="mt-6 border-t border-white/10 pt-6 text-center">
                    <span class="text-sm text-gray-400">Não tem uma conta?</span>
                    <Link :href="route('register')" class="ms-1 text-sm font-semibold text-violet-400 hover:text-violet-300">
                        Criar conta grátis
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
