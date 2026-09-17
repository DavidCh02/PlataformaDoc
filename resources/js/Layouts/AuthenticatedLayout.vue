<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { AlarmClock, AlertTriangle, Bell, BellRing, CheckCheck, Clock, Volume2, VolumeX } from 'lucide-vue-next';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);
const page = usePage();
const APP_TZ = 'America/Guayaquil';

const readNotification = notification => {
    if (!notification.read_at) router.post(route('notifications.read', notification.id), {}, { preserveScroll: true });
    router.visit(route('reminders.index'));
};
const readAllNotifications = () => {
    if (!unreadCount.value) return;
    router.post(route('notifications.read-all'), {}, { preserveScroll: true });
};
const formatNotificationDate = iso => iso ? new Date(iso).toLocaleString('es-EC', { timeZone: APP_TZ, day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
const formatFullDate = iso => iso ? new Date(iso).toLocaleString('es-EC', { timeZone: APP_TZ, weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';

// --- Sonido de aviso (WebAudio, sin archivos) con opción de silenciar ---
const muted = ref(typeof localStorage !== 'undefined' && localStorage.getItem('pd_notif_muted') === '1');
const toggleMute = () => {
    muted.value = !muted.value;
    try { localStorage.setItem('pd_notif_muted', muted.value ? '1' : '0'); } catch { /* ignorar */ }
};
let audioCtx = null;
const playChime = () => {
    if (muted.value) return;
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        audioCtx = audioCtx || new Ctx();
        if (audioCtx.state === 'suspended') audioCtx.resume();
        const now = audioCtx.currentTime;
        [[880, 0], [660, 0.16]].forEach(([freq, offset]) => {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, now + offset);
            gain.gain.exponentialRampToValueAtTime(0.25, now + offset + 0.03);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.3);
            osc.connect(gain).connect(audioCtx.destination);
            osc.start(now + offset);
            osc.stop(now + offset + 0.35);
        });
    } catch { /* ignorar */ }
};

// --- Avisos en vivo de recordatorios (polling + toast) ---
const toasts = ref([]);
const knownIds = ref(new Set());
const unreadCount = computed(() => page.props.notifications?.unread_count || 0);
const recent = computed(() => page.props.notifications?.recent || []);
let pollTimer = null;
let toastSeq = 0;

const notifKind = n => n.data?.kind || (n.data?.late ? 'late' : 'due');
const notifTitle = n => n.data?.title || n.data?.message || 'Aviso';
const notifDesc = n => n.data?.description || '';
const notifWhen = n => n.data?.scheduled_at || null;

const pushToast = notification => {
    const id = `toast-${++toastSeq}`;
    const title = notification.data?.message || notification.data?.title || 'Recordatorio';
    const desc = notification.data?.description || '';
    toasts.value.push({ id, title, desc, kind: notifKind(notification), notificationId: notification.id });
    playChime();
    // Nativa del navegador si ya hay permiso (no se pide solo).
    try {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('PlataformaDoc · Recordatorio', { body: title });
        }
    } catch { /* ignorar */ }
    setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id); }, 9000);
};
const openToast = toast => {
    const found = recent.value.find(n => n.id === toast.notificationId);
    if (found) readNotification(found);
    else router.visit(route('reminders.index'));
    toasts.value = toasts.value.filter(t => t.id !== toast.id);
};
let initialized = false;
const isFresh = n => {
    if (!n.created_at) return true;
    return Date.now() - new Date(n.created_at).getTime() < 2 * 60 * 1000;
};
const syncKnown = () => {
    for (const n of recent.value) {
        if (knownIds.value.has(n.id)) continue;
        knownIds.value.add(n.id);
        // En la primera carga solo avisar lo recién creado; después, todo lo nuevo no leído.
        if (!n.read_at && (!initialized ? isFresh(n) : true)) pushToast(n);
    }
    initialized = true;
};

// Al abrir el panel, el punto rojo desaparece: se marcan como vistas.
let openTimer = null;
const onNotifOpened = () => {
    clearTimeout(openTimer);
    openTimer = setTimeout(() => {
        if (unreadCount.value > 0) readAllNotifications();
    }, 1500);
};

onMounted(() => {
    syncKnown();
    // Desbloquear audio con el primer clic (política de autoplay).
    const unlock = () => { try { playChimeMutedUnlock(); } catch { /* */ } window.removeEventListener('click', unlock); };
    window.addEventListener('click', unlock);
    // Refresca solo las notificaciones cada 30 s. El backend (share) genera
    // el aviso aunque el scheduler/cron no esté corriendo.
    pollTimer = setInterval(() => {
        if (!page.props.auth?.user) return;
        if (document.hidden) return;
        router.reload({
            only: ['notifications'],
            onSuccess: () => syncKnown(),
        });
    }, 30000);
});
const playChimeMutedUnlock = () => {
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        audioCtx = audioCtx || new Ctx();
        if (audioCtx.state === 'suspended') audioCtx.resume();
    } catch { /* ignorar */ }
};
onUnmounted(() => { if (pollTimer) clearInterval(pollTimer); clearTimeout(openTimer); });
watch(recent, () => syncKnown());
</script>

<template>
    <div>
        <div class="min-h-screen bg-slate-100 dark:bg-[#0b1424]">
            <nav
                class="sticky top-0 z-40 border-b border-slate-200/70 bg-gradient-to-r from-white via-slate-50 to-white/90 shadow-sm backdrop-blur dark:border-slate-700/70 dark:from-slate-900 dark:via-slate-900 dark:to-slate-900/90"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex items-center gap-6">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center gap-2">
                                <Link :href="route('dashboard')">
                                    <ApplicationLogo
                                        class="block h-9 w-auto fill-current text-sky-700"
                                    />
                                </Link>
                                <Link :href="route('dashboard')" class="hidden text-lg font-bold tracking-tight text-slate-900 sm:block dark:text-white">
                                    Plataforma<span class="text-sky-600 dark:text-sky-400">Doc</span>
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    Explorador
                                </NavLink>
                                <NavLink
                                    v-if="$page.props.auth.can.includes('reminders.view')"
                                    :href="route('reminders.index')"
                                    :active="route().current('reminders.*')"
                                >
                                    Recordatorios
                                </NavLink>
                                <NavLink
                                    v-if="$page.props.auth.can.includes('users.manage')"
                                    :href="route('admin.users.index')"
                                    :active="route().current('admin.users.*') || route().current('admin.audit-logs.*')"
                                >
                                    Administración
                                </NavLink>
                                <NavLink
                                    v-if="$page.props.auth.can.includes('users.manage')"
                                    :href="route('admin.permissions.index')"
                                    :active="route().current('admin.permissions.*')"
                                >
                                    Permisos
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden space-x-2 sm:ms-6 sm:flex sm:items-center">
                            <Dropdown align="right" width="96" @open="onNotifOpened">
                                <template #trigger>
                                    <button
                                        type="button"
                                        title="Notificaciones"
                                        class="relative inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-sky-300 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-sky-400 dark:hover:text-white"
                                    >
                                        <Bell :size="17" />
                                        <span
                                            v-if="$page.props.notifications?.unread_count"
                                            class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 animate-pulse items-center justify-center rounded-full bg-red-500 px-1 text-[0.6rem] font-bold text-white"
                                        >{{ $page.props.notifications.unread_count > 9 ? '9+' : $page.props.notifications.unread_count }}</span>
                                    </button>
                                </template>

                                <template #content>
                                    <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-700">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="flex items-center gap-1.5 text-sm font-bold text-slate-800 dark:text-slate-100"><BellRing :size="15" class="text-sky-600 dark:text-sky-400" />Notificaciones</span>
                                            <div class="flex items-center gap-1">
                                                <button type="button" :title="muted ? 'Activar sonido' : 'Silenciar sonido'" class="rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-700 dark:hover:text-slate-200" @click.stop="toggleMute">
                                                    <VolumeX v-if="muted" :size="15" />
                                                    <Volume2 v-else :size="15" />
                                                </button>
                                                <button
                                                    v-if="$page.props.notifications?.unread_count"
                                                    type="button"
                                                    class="flex items-center gap-1 rounded-full px-2 py-1 text-xs font-semibold text-sky-600 transition hover:bg-sky-50 dark:text-sky-400 dark:hover:bg-sky-950"
                                                    @click="readAllNotifications"
                                                >
                                                    <CheckCheck :size="14" />Marcar todas
                                                </button>
                                            </div>
                                        </div>
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                            <template v-if="unreadCount">{{ unreadCount }} pendiente{{ unreadCount === 1 ? '' : 's' }} por ver</template>
                                            <template v-else>Al día, sin pendientes 🎉</template>
                                            <template v-if="muted"> · 🔇 sonido off</template>
                                        </p>
                                    </div>
                                    <div class="max-h-96 overflow-y-auto">
                                        <div
                                            v-if="!($page.props.notifications?.recent?.length)"
                                            class="px-4 py-8 text-center"
                                        >
                                            <Bell :size="28" class="mx-auto text-slate-300 dark:text-slate-600" />
                                            <p class="mt-2 text-sm font-semibold text-slate-600 dark:text-slate-300">Sin notificaciones</p>
                                            <p class="text-xs text-slate-400">Cuando llegue la hora de un recordatorio aparecerá aquí.</p>
                                        </div>
                                        <button
                                            v-for="notification in $page.props.notifications?.recent || []"
                                            :key="notification.id"
                                            type="button"
                                            class="flex w-full items-start gap-2.5 border-b border-slate-50 px-4 py-3 text-left transition last:border-0 hover:bg-slate-50 dark:border-slate-700/50 dark:hover:bg-slate-700/50"
                                            :class="notification.read_at ? 'opacity-70' : 'bg-sky-50/60 dark:bg-sky-950/30'"
                                            @click="readNotification(notification)"
                                        >
                                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full" :class="notifKind(notification) === 'late' ? 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400' : notifKind(notification) === 'pre' ? 'bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400' : 'bg-sky-100 text-sky-600 dark:bg-sky-950 dark:text-sky-400'">
                                                <AlertTriangle v-if="notifKind(notification) === 'late'" :size="15" />
                                                <AlarmClock v-else-if="notifKind(notification) === 'pre'" :size="15" />
                                                <BellRing v-else :size="15" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="flex items-center gap-1.5">
                                                    <span class="truncate text-sm font-bold text-slate-800 dark:text-slate-100">{{ notifTitle(notification) }}</span>
                                                    <span v-if="!notification.read_at" class="h-2 w-2 shrink-0 rounded-full bg-sky-500"></span>
                                                </span>
                                                <span v-if="notifDesc(notification)" class="mt-0.5 line-clamp-2 block text-xs leading-snug text-slate-500 dark:text-slate-400">{{ notifDesc(notification) }}</span>
                                                <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[0.7rem] text-slate-400">
                                                    <span v-if="notifKind(notification) === 'pre' && notification.data?.remind_before_minutes" class="rounded-full bg-amber-100 px-1.5 py-0.5 font-bold text-amber-700 dark:bg-amber-950 dark:text-amber-300">⏰ {{ notification.data.remind_before_minutes }} min antes</span>
                                                    <span v-if="notifKind(notification) === 'late'" class="rounded-full bg-red-100 px-1.5 py-0.5 font-bold text-red-700 dark:bg-red-950 dark:text-red-300">vencido</span>
                                                    <span class="flex items-center gap-0.5"><Clock :size="10" />{{ formatFullDate(notifWhen(notification)) }}</span>
                                                    <span v-if="(notification.data?.recipients_count || 0) > 1" :title="(notification.data?.recipients || []).join(', ')">· 👥 {{ notification.data.recipients?.[0] }} +{{ notification.data.recipients_count - 1 }}</span>
                                                    <span v-else-if="notification.data?.assignee_name">· {{ notification.data.assignee_name }}</span>
                                                </span>
                                            </span>
                                        </button>
                                    </div>
                                    <Link :href="route('reminders.index')" class="block border-t border-slate-100 bg-slate-50/60 px-4 py-2.5 text-center text-xs font-bold text-sky-600 hover:underline dark:border-slate-700 dark:bg-slate-800/60 dark:text-sky-400">
                                        Ver recordatorios
                                    </Link>
                                </template>
                            </Dropdown>
                            <ThemeToggle />
                            <!-- Settings Dropdown -->
                            <div class="relative ms-1">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium leading-4 text-slate-600 shadow-sm transition duration-150 ease-in-out hover:border-sky-300 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-sky-400 dark:hover:text-white"
                                            >
                                                {{ $page.props.auth.user.name }}

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
                                            Mi perfil
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            Cerrar sesión
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center gap-1 sm:hidden">
                            <ThemeToggle />
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none dark:hover:bg-slate-800 dark:hover:text-slate-300"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            :href="route('dashboard')"
                            :active="route().current('dashboard')"
                        >
                            Explorador
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="$page.props.auth.can.includes('reminders.view')"
                            :href="route('reminders.index')"
                            :active="route().current('reminders.*')"
                        >
                            Recordatorios<span v-if="$page.props.notifications?.unread_count" class="ml-1 rounded-full bg-red-500 px-1.5 text-[0.65rem] font-bold text-white">{{ $page.props.notifications.unread_count }}</span>
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="$page.props.auth.can.includes('users.manage')"
                            :href="route('admin.users.index')"
                            :active="route().current('admin.users.*') || route().current('admin.audit-logs.*')"
                        >
                            Administración
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="$page.props.auth.can.includes('users.manage')"
                            :href="route('admin.permissions.index')"
                            :active="route().current('admin.permissions.*')"
                        >
                            Permisos
                        </ResponsiveNavLink>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div
                        class="border-t border-gray-200 pb-1 pt-4 dark:border-slate-700"
                    >
                        <div class="px-4">
                            <div
                                class="text-base font-medium text-gray-800 dark:text-slate-100"
                            >
                                {{ $page.props.auth.user.name }}
                            </div>
                            <div class="text-sm font-medium text-gray-500 dark:text-slate-400">
                                {{ $page.props.auth.user.email }}
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                Mi perfil
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                Cerrar sesión
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header
                class="border-b border-slate-200/70 bg-white/70 shadow-sm backdrop-blur dark:border-slate-700/70 dark:bg-slate-900/70"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>

            <!-- Toasts de recordatorios -->
            <div class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-80 flex-col gap-2">
                <div
                    v-for="toast in toasts"
                    :key="toast.id"
                    class="pointer-events-auto flex cursor-pointer items-start gap-2.5 rounded-2xl border border-amber-200 bg-white/95 p-3 shadow-xl backdrop-blur dark:border-amber-800 dark:bg-slate-900/95"
                    @click="openToast(toast)"
                >
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" :class="toast.kind === 'late' ? 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400' : toast.kind === 'pre' ? 'bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400' : 'bg-sky-100 text-sky-600 dark:bg-sky-950 dark:text-sky-400'">
                        <AlertTriangle v-if="toast.kind === 'late'" :size="17" />
                        <AlarmClock v-else-if="toast.kind === 'pre'" :size="17" />
                        <BellRing v-else :size="17" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold uppercase tracking-wide text-amber-600 dark:text-amber-400">{{ toast.kind === 'pre' ? '🔔 Pre-aviso' : toast.kind === 'late' ? '⏰ Vencido' : '⏰ Recordatorio' }}{{ muted ? ' · 🔇' : ' · 🔊' }}</p>
                        <p class="truncate text-sm font-bold text-slate-800 dark:text-slate-100">{{ toast.title }}</p>
                        <p v-if="toast.desc" class="line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{{ toast.desc }}</p>
                        <p class="mt-0.5 text-xs font-semibold text-sky-600 underline dark:text-sky-400">Ver recordatorio</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>