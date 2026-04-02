<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps<{
    config: {
        enabled: boolean;
        posts_per_week: number;
        connection_id: number | null;
        category_id: number | null;
        require_approval: boolean;
        auto_approve: boolean;
        tone: string;
        instructions: string;
        cover_width: number;
        cover_height: number;
    };
    connections: { id: number; name: string; platform_label: string; site_url: string }[];
    categories: { id: number; name: string }[];
}>();

const form = ref({ ...props.config });
const saving = ref(false);
const running = ref(false);
const result = ref<{ type: 'success' | 'error'; message: string } | null>(null);

async function save() {
    saving.value = true;
    result.value = null;
    try {
        const { data } = await axios.post(route('blog.autopilot.save'), form.value);
        result.value = { type: data.success ? 'success' : 'error', message: data.message || data.error };
    } catch (e: any) {
        result.value = { type: 'error', message: e.response?.data?.message || 'Erro ao salvar.' };
    } finally {
        saving.value = false;
    }
}

async function runNow() {
    if (!confirm('Disparar o autopilot agora? Isso vai gerar pautas para a próxima semana.')) return;
    running.value = true;
    result.value = null;
    try {
        const { data } = await axios.post(route('blog.autopilot.run'));
        result.value = { type: data.success ? 'success' : 'error', message: data.message || data.error };
    } catch (e: any) {
        result.value = { type: 'error', message: e.response?.data?.message || 'Erro ao disparar.' };
    } finally {
        running.value = false;
    }
}

const selectedConnection = computed(() => props.connections.find(c => c.id === form.value.connection_id));
const selectedCategory = computed(() => props.categories.find(c => c.id === form.value.category_id));

const summary = computed(() => {
    if (!form.value.enabled) return 'Autopilot desativado';
    const conn = selectedConnection.value?.name ?? 'sem destino WordPress';
    const cat  = selectedCategory.value?.name ?? 'sem categoria';
    const aprov = form.value.require_approval ? 'aguardando sua aprovação' : 'publicação automática';
    return `Toda segunda-feira às 7h: gerar ${form.value.posts_per_week} pauta(s)/semana → ${conn} → categoria "${cat}" → ${aprov}`;
});
</script>

<template>
    <Head title="Blog - Autopilot" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-white">Autopilot de Blog</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Configure a IA para gerar artigos automaticamente toda semana</p>
                </div>
                <div class="flex items-center gap-2">
                    <Link :href="route('blog.index')"
                        class="rounded-xl border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:text-white transition">
                        ← Blog
                    </Link>
                </div>
            </div>
        </template>

        <div class="max-w-2xl mx-auto space-y-5">

            <!-- Status banner -->
            <div :class="form.enabled
                ? 'bg-emerald-900/20 border-emerald-500/30 text-emerald-300'
                : 'bg-gray-900 border-gray-700 text-gray-400'"
                class="rounded-2xl border p-4 flex items-start gap-3">
                <div :class="form.enabled ? 'bg-emerald-500' : 'bg-gray-600'"
                    class="w-2 h-2 rounded-full mt-1.5 shrink-0"></div>
                <div>
                    <p class="text-sm font-medium">{{ form.enabled ? 'Autopilot Ativo' : 'Autopilot Inativo' }}</p>
                    <p class="text-xs mt-0.5 opacity-70">{{ summary }}</p>
                </div>
            </div>

            <!-- Config card -->
            <div class="rounded-2xl bg-gray-900 border border-gray-800 p-6 space-y-5">
                <h2 class="text-base font-semibold text-white">Configurações</h2>

                <!-- Ativar -->
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-white">Ativar autopilot</p>
                        <p class="text-xs text-gray-500 mt-0.5">Toda segunda-feira às 7h o sistema gera as pautas da semana automaticamente</p>
                    </div>
                    <button @click="form.enabled = !form.enabled" type="button"
                        :class="form.enabled ? 'bg-indigo-600' : 'bg-gray-700'"
                        class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors duration-200 focus:outline-none">
                        <span :class="form.enabled ? 'translate-x-5' : 'translate-x-0.5'"
                            class="inline-block h-5 w-5 mt-0.5 rounded-full bg-white shadow transform transition-transform duration-200" />
                    </button>
                </div>

                <div class="border-t border-gray-800"></div>

                <!-- Posts por semana -->
                <div>
                    <label class="text-sm text-gray-400 mb-2 block">Artigos por semana: <span class="text-white font-medium">{{ form.posts_per_week }}</span></label>
                    <input v-model.number="form.posts_per_week" type="range" min="1" max="7" step="1" class="w-full accent-indigo-500" />
                    <div class="flex justify-between text-[10px] text-gray-600 mt-1"><span>1</span><span>7</span></div>
                </div>

                <!-- Destino WordPress -->
                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Destino WordPress</label>
                    <select v-model="form.connection_id"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option :value="null">Nenhum (apenas rascunho local)</option>
                        <option v-for="c in connections" :key="c.id" :value="c.id">
                            {{ c.name }} ({{ c.platform_label }}) — {{ c.site_url }}
                        </option>
                    </select>
                    <p v-if="!connections.length" class="text-[10px] text-yellow-400 mt-1">
                        Nenhuma conexão WordPress cadastrada.
                        <Link :href="route('blog.categories')" class="underline">Adicionar em Categorias</Link>
                    </p>
                </div>

                <!-- Categoria padrão -->
                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Categoria padrão</label>
                    <select v-model="form.category_id"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option :value="null">Sem categoria (IA sugere automaticamente)</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <p class="text-[10px] text-gray-600 mt-1">Se deixar em branco, a IA vai sugerir a categoria mais adequada para cada artigo.</p>
                </div>

                <!-- Modo aprovação -->
                <div class="rounded-xl bg-gray-800/50 border border-gray-700 p-4 space-y-3">
                    <p class="text-sm font-medium text-white">Modo de publicação</p>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="radio" :value="true" v-model="form.require_approval" class="mt-0.5 accent-indigo-500" />
                        <div>
                            <p class="text-sm text-gray-200">Gerar pautas e aguardar aprovação</p>
                            <p class="text-xs text-gray-500">A IA gera as pautas toda semana. Você revisa no Calendário e clica "Gerar Artigo" quando quiser.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="radio" :value="false" v-model="form.require_approval" class="mt-0.5 accent-indigo-500" />
                        <div>
                            <p class="text-sm text-gray-200">Geração e publicação totalmente automática</p>
                            <p class="text-xs text-gray-500">A IA gera pautas <strong>e</strong> artigos completos automaticamente. Os artigos ficam em "Pendente de revisão" — você só aprova antes de publicar.</p>
                        </div>
                    </label>
                </div>

                <!-- Auto-aprovação -->
                <div class="rounded-xl bg-gray-800/50 border border-gray-700 p-4">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" v-model="form.auto_approve" class="mt-0.5 rounded border-gray-600 text-indigo-600 focus:ring-indigo-500" />
                        <div>
                            <p class="text-sm font-medium text-white">Auto-aprovar artigos completos</p>
                            <p class="text-xs text-gray-500">Quando ativado, artigos que tiverem todos os elementos (texto, imagem de capa, SEO) serão aprovados automaticamente. Artigos incompletos (sem capa, sem meta description, etc.) continuam como "Pendente de revisão" para sua conferência.</p>
                        </div>
                    </label>
                </div>

                <!-- Tom de voz -->
                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Tom de voz (opcional)</label>
                    <select v-model="form.tone"
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Padrão da marca</option>
                        <option value="profissional">Profissional</option>
                        <option value="informal">Informal</option>
                        <option value="educativo">Educativo</option>
                        <option value="persuasivo">Persuasivo</option>
                        <option value="técnico">Técnico</option>
                    </select>
                </div>

                <!-- Instruções -->
                <div>
                    <label class="text-sm text-gray-400 mb-1 block">Instruções permanentes para a IA</label>
                    <textarea v-model="form.instructions" rows="3"
                        placeholder="Ex: Sempre mencionar os benefícios do produto X. Focar em SEO. Incluir estatísticas."
                        class="w-full rounded-xl bg-gray-800 border-gray-700 text-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <p class="text-[10px] text-gray-600 mt-1">Essas instruções são aplicadas em toda geração automática.</p>
                </div>

                <!-- Dimensões da capa -->
                <div>
                    <label class="text-sm text-gray-400 mb-2 block">Dimensões da imagem de capa (px)</label>
                    <div class="flex items-center gap-2">
                        <input v-model.number="form.cover_width" type="number" min="100" max="4000" placeholder="Largura"
                            class="w-24 rounded-xl bg-gray-800 border-gray-700 text-white text-sm text-center focus:border-indigo-500 focus:ring-indigo-500" />
                        <span class="text-gray-500 text-sm">×</span>
                        <input v-model.number="form.cover_height" type="number" min="100" max="4000" placeholder="Altura"
                            class="w-24 rounded-xl bg-gray-800 border-gray-700 text-white text-sm text-center focus:border-indigo-500 focus:ring-indigo-500" />
                        <span class="text-xs text-gray-600">px (padrão: 1750 × 650)</span>
                    </div>
                </div>

                <!-- Resultado -->
                <div v-if="result" class="px-3 py-2 rounded-xl text-sm"
                    :class="result.type === 'success' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20'">
                    {{ result.message }}
                </div>

                <!-- Botões -->
                <div class="flex gap-3 pt-2 border-t border-gray-800">
                    <button @click="save" :disabled="saving"
                        class="flex-1 rounded-xl bg-indigo-600 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                        {{ saving ? 'Salvando...' : 'Salvar Configurações' }}
                    </button>
                    <button @click="runNow" :disabled="running"
                        class="rounded-xl border border-gray-600 px-5 py-2.5 text-sm text-gray-300 hover:text-white hover:border-gray-500 disabled:opacity-50 transition">
                        {{ running ? 'Disparando...' : '▶ Executar Agora' }}
                    </button>
                </div>
            </div>

            <!-- Info -->
            <div class="rounded-xl bg-indigo-950/30 border border-indigo-500/20 p-4">
                <p class="text-xs text-indigo-300 font-medium mb-2">Como funciona o Autopilot:</p>
                <ul class="text-[11px] text-indigo-400/70 space-y-1.5">
                    <li>• <strong>Toda segunda-feira às 7h</strong>: a IA analisa sua marca e gera pautas para os próximos 7 dias</li>
                    <li>• <strong>Com aprovação</strong>: pautas aparecem no Calendário Editorial → você aprova → depois clica "Gerar Artigo"</li>
                    <li>• <strong>Sem aprovação</strong>: artigos completos são gerados automaticamente e ficam como "Pendente de revisão"</li>
                    <li>• <strong>Publicação</strong>: artigos aprovados são enviados ao WordPress automaticamente na data agendada</li>
                    <li>• <strong>Evita repetição</strong>: a IA verifica artigos já publicados para não repetir temas</li>
                </ul>
            </div>

            <!-- Quick links -->
            <div class="grid grid-cols-3 gap-3">
                <Link :href="route('blog.calendar')"
                    class="rounded-xl bg-gray-900 border border-gray-800 p-3 text-center hover:border-gray-600 transition">
                    <p class="text-sm font-medium text-white">Calendário</p>
                    <p class="text-[11px] text-gray-500 mt-0.5">Ver pautas geradas</p>
                </Link>
                <Link :href="route('blog.index')"
                    class="rounded-xl bg-gray-900 border border-gray-800 p-3 text-center hover:border-gray-600 transition">
                    <p class="text-sm font-medium text-white">Artigos</p>
                    <p class="text-[11px] text-gray-500 mt-0.5">Revisar e aprovar</p>
                </Link>
                <Link :href="route('blog.categories')"
                    class="rounded-xl bg-gray-900 border border-gray-800 p-3 text-center hover:border-gray-600 transition">
                    <p class="text-sm font-medium text-white">Categorias</p>
                    <p class="text-[11px] text-gray-500 mt-0.5">Gerenciar conexões</p>
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
