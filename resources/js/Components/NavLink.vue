<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
});

const classes = computed(() =>
    props.active
        ? 'inline-flex items-center rounded-full bg-sky-100/80 px-4 py-2 text-sm font-semibold text-sky-800 shadow-sm ring-1 ring-sky-200/70 transition duration-150 ease-in-out dark:bg-sky-900/50 dark:text-sky-300 dark:ring-sky-800'
        : 'inline-flex items-center rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition duration-150 ease-in-out hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
