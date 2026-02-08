<script setup lang="ts">
import { ref } from 'vue';

interface Step {
    title: string;
    description: string;
}

const props = withDefaults(defineProps<{
    title: string;
    description?: string;
    steps?: Step[];
    tips?: string[];
    color?: 'indigo' | 'purple' | 'emerald' | 'amber' | 'blue';
    collapsible?: boolean;
    defaultOpen?: boolean;
    storageKey?: string;
}>(), {
    color: 'indigo',
    collapsible: true,
    defaultOpen: true,
});

const colorClasses: Record<string, { bg: string; border: string; title: string; text: string; icon: string; step: string }> = {
    indigo: { bg: 'bg-indigo-950/30', border: 'border-indigo-500/20', title: 'text-indigo-300', text: 'text-indigo-400/70', icon: 'text-indigo-400', step: 'bg-indigo-600' },
    purple: { bg: 'bg-purple-950/30', border: 'border-purple-500/20', title: 'text-purple-300', text: 'text-purple-400/70', icon: 'text-purple-400', step: 'bg-purple-600' },
    emerald: { bg: 'bg-emerald-950/30', border: 'border-emerald-500/20', title: 'text-emerald-300', text: 'text-emerald-400/70', icon: 'text-emerald-400', step: 'bg-emerald-600' },
    amber: { bg: 'bg-amber-950/30', border: 'border-amber-500/20', title: 'text-amber-300', text: 'text-amber-400/70', icon: 'text-amber-400', step: 'bg-amber-600' },
    blue: { bg: 'bg-blue-950/30', border: 'border-blue-500/20', title: 'text-blue-300', text: 'text-blue-400/70', icon: 'text-blue-400', step: 'bg-blue-600' },
};

const dismissed = ref(false);
const isOpen = ref(props.defaultOpen);

// Verificar localStorage se tem storageKey
if (props.storageKey) {
    const stored = localStorage.getItem(`guide_${props.storageKey}`);
    if (stored === 'dismissed') dismissed.value = true;
    if (stored === 'collapsed') isOpen.value = false;
}

function dismiss() {
    dismissed.value = true;
    if (props.storageKey) localStorage.setItem(`guide_${props.storageKey}`, 'dismissed');
}

function toggle() {
    isOpen.value = !isOpen.value;
    if (props.storageKey && !isOpen.value) localStorage.setItem(`guide_${props.storageKey}`, 'collapsed');
    if (props.storageKey && isOpen.value) localStorage.removeItem(`guide_${props.storageKey}`);
}

const c = colorClasses[props.color] || colorClasses.indigo;
</script>

<template>
    <div v-if="!dismissed" :class="['rounded-2xl border p-5', c.bg, c.border]">
        <div class="flex items-start gap-3">
            <svg :class="['w-5 h-5 mt-0.5 shrink-0', c.icon]" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" />
            </svg>
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between">
                    <button v-if="collapsible" @click="toggle" :class="['text-sm font-medium', c.title]">
                        {{ title }}
                        <svg class="w-3.5 h-3.5 inline ml-1 transition-transform" :class="{ 'rotate-180': isOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <polyline points="6 9 12 15 18 9" />
                        </svg>
                    </button>
                    <p v-else :class="['text-sm font-medium', c.title]">{{ title }}</p>
                    <button @click="dismiss" class="text-gray-600 hover:text-gray-400 transition p-1" title="Fechar guia">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>

                <div v-show="!collapsible || isOpen" class="mt-2">
                    <p v-if="description" :class="['text-xs leading-relaxed', c.text]">{{ description }}</p>

                    <!-- Steps -->
                    <div v-if="steps && steps.length" class="mt-3 space-y-2">
                        <div v-for="(step, idx) in steps" :key="idx" class="flex items-start gap-2.5">
                            <span :class="['flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold text-white shrink-0 mt-0.5', c.step]">
                                {{ idx + 1 }}
                            </span>
                            <div>
                                <p :class="['text-xs font-medium', c.title]">{{ step.title }}</p>
                                <p :class="['text-[11px]', c.text]">{{ step.description }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Tips -->
                    <div v-if="tips && tips.length" class="mt-3">
                        <div v-for="(tip, idx) in tips" :key="idx" class="flex items-start gap-2 mb-1.5 last:mb-0">
                            <span :class="['text-xs mt-0.5', c.icon]">&#x2022;</span>
                            <p :class="['text-[11px]', c.text]">{{ tip }}</p>
                        </div>
                    </div>

                    <!-- Slot for extra content -->
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
