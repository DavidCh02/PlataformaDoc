<script setup>
import { Moon, Sun } from 'lucide-vue-next';
import { toggleTheme, useTheme } from '@/composables/useTheme';

const { isDark } = useTheme();
</script>

<template>
    <button
        type="button"
        :title="isDark ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'"
        aria-label="Cambiar tema claro/oscuro"
        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 shadow-sm transition duration-150 ease-in-out hover:border-sky-300 hover:text-sky-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-sky-400 dark:hover:text-sky-300"
        @click="toggleTheme"
    >
        <Sun v-if="isDark" :size="16" />
        <Moon v-else :size="16" />
    </button>
</template>