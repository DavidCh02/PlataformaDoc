import { ref } from 'vue';

const STORAGE_KEY = 'pd-theme';

const isDark = ref(false);

const apply = (dark) => {
    isDark.value = dark;
    const root = document.documentElement;
    root.classList.add('theme-transition');
    root.classList.toggle('dark', dark);
    root.style.colorScheme = dark ? 'dark' : 'light';
    window.setTimeout(() => root.classList.remove('theme-transition'), 300);
};

export const initTheme = () => {
    const stored = localStorage.getItem(STORAGE_KEY);
    const prefersDark = typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-color-scheme: dark)').matches;
    apply(stored ? stored === 'dark' : prefersDark);
};

export const toggleTheme = () => {
    apply(!isDark.value);
    localStorage.setItem(STORAGE_KEY, isDark.value ? 'dark' : 'light');
};

export const useTheme = () => ({ isDark });