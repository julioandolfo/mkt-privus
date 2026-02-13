<script setup>
import { ref, computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    suggestions: Object,
    currentFilter: String | null,
});

const page = usePage();
const flash = page.props.flash || {};
const generating = ref(false);

const items = computed(() => props.suggestions?.data ?? []);

const filterTabs = [
    { value: null, label: 'Todas' },
    { value: 'pending', label: 'Pendentes' },
    { value: 'accepted', label: 'Aceitas' },
    { value: 'rejected', label: 'Rejeitadas' },
    { value: 'used', label: 'Usadas' },
];

const contentTypeBadges = {
    newsletter: 'bg-emerald-900/40 text-emerald-400 border-emerald-500/30',
    promotional: 'bg-amber-900/40 text-amber-400 border-amber-500/30',
    educational: 'bg-blue-900/40 text-blue-400 border-blue-500/30',
    seasonal: 'bg-purple-900/40 text-purple-400 border-purple-500/30',
    engagement: 'bg-pink-900/40 text-pink-400 border-pink-500/30',
};

const statusBadges = {
    pending: 'bg-amber-900/40 text-amber-400 border-amber-500/30',
    accepted: 'bg-green-900/40 text-green-400 border-green-500/30',
    rejected: 'bg-red-900/40 text-red-400 border-red-500/30',
    used: 'bg-indigo-900/40 text-indigo-400 border-indigo-500/30',
};

const statusLabels = {
    pending: 'Pendente',
    accepted: 'Aceita',
    rejected: 'Rejeitada',
    used: 'Usada',
};

const contentTypeLabels = {
    newsletter: 'Newsletter',
    promotional: 'Promocional',
    educational: 'Educacional',
    seasonal: 'Sazonal',
    engagement: 'Engajamento',
};

function getContentTypeBadge(type) {
    return contentTypeBadges[type] || contentTypeBadges.newsletter;
}

function getStatusBadge(status) {
    return statusBadges[status] || statusBadges.pending;
}

function filterUrl(status) {
    const base = route('email.ai-suggestions.index');
    return status ? `${base}?status=${status}` : base;
}

async function generateSuggestions() {
    generating.value = true;
    try {
        await router.post(route('email.ai-suggestions.generate'));
    } finally {
        generating.value = false;
    }
}

function acceptSuggestion(suggestion) {
    router.post(route('email.ai-suggestions.accept', suggestion.id));
}

function rejectSuggestion(suggestion) {
    router.post(route('email.ai-suggestions.reject', suggestion.id));
}

function createCampaign(suggestion) {
    router.post(route('email.ai-suggestions.create-campaign', suggestion.id));
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between flex-wrap gap-4">
                <h1 class="text-2xl font-bold text-white">Sugestões de Email Marketing (IA)</h1>
                <button
                    @click="generateSuggestions"
                    :disabled="generating"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition disabled:opacity-60 disabled:cursor-not-allowed"
                >
                    <svg
                        v-if="generating"
                        class="w-4 h-4 animate-spin"
                        fill="none"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <circle
                            class="opacity-25"
                            cx="12"
                            cy="12"
                            r="10"
                            stroke="currentColor"
                            stroke-width="4"
                        />
                        <path
                            class="opacity-75"
                            fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                        />
                    </svg>
                    {{ generating ? 'Gerando...' : 'Gerar Novas Sugestões' }}
                </button>
            </div>
        </template>

        <!-- Flash messages -->
        <div
            v-if="flash.success"
            class="mb-6 px-4 py-3 rounded-xl bg-green-900/30 border border-green-700/50 text-green-300 text-sm"
        >
            {{ flash.success }}
        </div>
        <div
            v-if="flash.error"
            class="mb-6 px-4 py-3 rounded-xl bg-red-900/30 border border-red-700/50 text-red-300 text-sm"
        >
            {{ flash.error }}
        </div>
        <div
            v-if="flash.info"
            class="mb-6 px-4 py-3 rounded-xl bg-indigo-900/30 border border-indigo-700/50 text-indigo-300 text-sm"
        >
            {{ flash.info }}
        </div>

        <!-- Filter tabs -->
        <div class="flex flex-wrap gap-2 mb-6">
            <Link
                v-for="tab in filterTabs"
                :key="tab.value ?? 'all'"
                :href="filterUrl(tab.value)"
                :class="[
                    'px-4 py-2 rounded-lg text-sm font-medium transition',
                    currentFilter === tab.value
                        ? 'bg-indigo-600 text-white'
                        : 'bg-gray-800 text-gray-400 hover:text-white hover:bg-gray-700 border border-gray-700',
                ]"
            >
                {{ tab.label }}
            </Link>
        </div>

        <!-- Empty state -->
        <div
            v-if="!items.length"
            class="bg-gray-900 rounded-xl border border-gray-800 p-12 text-center"
        >
            <svg
                class="w-16 h-16 text-gray-700 mx-auto mb-4"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.5"
                    d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"
                />
            </svg>
            <p class="text-gray-400 mb-4">Nenhuma sugestão encontrada.</p>
            <p class="text-sm text-gray-500 mb-6">Clique em "Gerar Novas Sugestões" para que a IA crie ideias de campanhas com base na sua marca.</p>
            <button
                @click="generateSuggestions"
                :disabled="generating"
                class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition disabled:opacity-60"
            >
                <svg
                    v-if="generating"
                    class="w-4 h-4 animate-spin"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                </svg>
                {{ generating ? 'Gerando...' : 'Gerar Sugestões' }}
            </button>
        </div>

        <!-- Cards grid -->
        <div v-else class="space-y-4">
            <div
                v-for="s in items"
                :key="s.id"
                class="bg-gray-900 rounded-xl border border-gray-800 p-5 hover:border-gray-700 transition"
            >
                <div class="flex items-start justify-between gap-4 mb-3">
                    <h3 class="text-xl font-semibold text-white flex-1">{{ s.title }}</h3>
                    <span
                        class="px-2.5 py-1 text-xs rounded-lg border shrink-0"
                        :class="getStatusBadge(s.status)"
                    >
                        {{ statusLabels[s.status] || s.status }}
                    </span>
                </div>

                <p v-if="s.description" class="text-gray-400 line-clamp-2 mb-4 text-sm">
                    {{ s.description }}
                </p>

                <div
                    v-if="s.suggested_subject"
                    class="mb-4 rounded-lg bg-indigo-900/30 border border-indigo-700/50 px-4 py-3"
                >
                    <p class="text-xs text-indigo-400 font-medium mb-1">Assunto sugerido</p>
                    <p class="text-indigo-200">{{ s.suggested_subject }}</p>
                </div>

                <p v-if="s.suggested_preview" class="text-sm text-gray-500 mb-4 italic">
                    {{ s.suggested_preview }}
                </p>

                <div class="flex flex-wrap gap-2 mb-4">
                    <span
                        v-if="s.target_audience"
                        class="px-2.5 py-0.5 text-xs rounded-lg bg-gray-800 text-gray-300 border border-gray-700"
                    >
                        {{ s.target_audience }}
                    </span>
                    <span
                        v-if="s.content_type"
                        class="px-2.5 py-0.5 text-xs rounded-lg border"
                        :class="getContentTypeBadge(s.content_type)"
                    >
                        {{ contentTypeLabels[s.content_type] || s.content_type }}
                    </span>
                    <span
                        v-if="s.suggested_send_date"
                        class="px-2.5 py-0.5 text-xs rounded-lg bg-gray-800 text-gray-400 border border-gray-700"
                    >
                        Envio: {{ s.suggested_send_date }}
                    </span>
                </div>

                <div class="flex items-center justify-between pt-3 border-t border-gray-800">
                    <span class="text-xs text-gray-500">{{ s.created_at }}</span>
                    <div class="flex items-center gap-2">
                        <template v-if="s.status === 'pending'">
                            <button
                                @click="acceptSuggestion(s)"
                                class="px-3 py-1.5 text-xs bg-green-900/40 text-green-400 rounded-lg hover:bg-green-900/60 transition"
                            >
                                Aceitar
                            </button>
                            <button
                                @click="rejectSuggestion(s)"
                                class="px-3 py-1.5 text-xs bg-red-900/30 text-red-400 rounded-lg hover:bg-red-900/50 transition"
                            >
                                Rejeitar
                            </button>
                            <button
                                @click="createCampaign(s)"
                                class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg hover:bg-indigo-500 transition"
                            >
                                Criar Campanha
                            </button>
                        </template>
                        <template v-else-if="s.status === 'accepted'">
                            <button
                                @click="createCampaign(s)"
                                class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg hover:bg-indigo-500 transition"
                            >
                                Criar Campanha
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div
                v-if="suggestions?.last_page > 1"
                class="flex items-center justify-center gap-2 mt-6"
            >
                <template v-for="link in suggestions.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-sm transition',
                            link.active ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white hover:bg-gray-700',
                        ]"
                        v-html="link.label"
                    />
                    <span v-else class="px-3 py-1.5 text-sm text-gray-600" v-html="link.label" />
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
