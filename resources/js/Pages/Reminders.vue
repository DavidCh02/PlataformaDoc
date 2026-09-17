<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlarmClock, AlertTriangle, BellPlus, BellRing, CalendarCheck, CalendarDays, CalendarX,
    Check, CheckCircle2, ChevronLeft, ChevronRight, Clock, Copy, Hourglass, Plus, Search, Trash2, User, X,
} from 'lucide-vue-next';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';

const props = defineProps({
    reminders: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const page = usePage();
const currentUserId = Number(page.props.auth.user?.id);
const isRecipient = (reminder, id) => (reminder.recipients || []).some(u => Number(u.id) === Number(id)) || (reminder.assignee && Number(reminder.assignee.id) === Number(id));
const canEditReminder = reminder => props.canManage || isRecipient(reminder, currentUserId);
const recipientNames = reminder => (reminder.recipients?.length ? reminder.recipients.map(u => u.name) : (reminder.assignee ? [reminder.assignee.name] : []));
const recipientLabel = reminder => {
    const names = recipientNames(reminder);
    if (!names.length) return '';
    return names.length === 1 ? names[0] : `${names[0]} +${names.length - 1}`;
};

// Toda la app trabaja en hora de Ecuador (America/Guayaquil, UTC-5 sin DST).
const APP_TZ = 'America/Guayaquil';
const ECUADOR_OFFSET = '-05:00';
const parseISO = iso => new Date(iso);
const toKey = date => new Intl.DateTimeFormat('en-CA', { timeZone: APP_TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
const todayKey = toKey(new Date());
const toTimeInEcuador = date => {
    const parts = new Intl.DateTimeFormat('es-EC', { timeZone: APP_TZ, hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(date);
    let h = parts.find(p => p.type === 'hour')?.value ?? '09';
    const m = parts.find(p => p.type === 'minute')?.value ?? '00';
    if (h === '24') h = '00';
    return `${h.padStart(2, '0')}:${m.padStart(2, '0')}`;
};

const cursor = ref(new Date());
const cursorYear = computed(() => cursor.value.getFullYear());
const cursorMonth = computed(() => cursor.value.getMonth());
const monthLabel = computed(() => cursor.value.toLocaleDateString('es-EC', { timeZone: APP_TZ, month: 'long', year: 'numeric' }));

// Celdas del mes (semana inicia en lunes).
const cells = computed(() => {
    const first = new Date(cursorYear.value, cursorMonth.value, 1);
    const leading = (first.getDay() + 6) % 7;
    const daysInMonth = new Date(cursorYear.value, cursorMonth.value + 1, 0).getDate();
    const list = [];
    for (let i = 0; i < leading; i++) list.push(null);
    for (let day = 1; day <= daysInMonth; day++) list.push(new Date(cursorYear.value, cursorMonth.value, day));
    return list;
});

const prevMonth = () => { cursor.value = new Date(cursorYear.value, cursorMonth.value - 1, 1); };
const nextMonth = () => { cursor.value = new Date(cursorYear.value, cursorMonth.value + 1, 1); };
const goToday = () => { cursor.value = new Date(); selectedDay.value = new Date(); };

// Día seleccionado para la agenda.
const selectedDay = ref(new Date());
const selectedKey = computed(() => toKey(selectedDay.value));
const selectedLabel = computed(() => selectedDay.value.toLocaleDateString('es-EC', { timeZone: APP_TZ, weekday: 'long', day: 'numeric', month: 'long' }));
const selectDay = day => { if (day) selectedDay.value = day; };

// Buscador + filtros.
const search = ref('');
const filter = ref('all'); // all | pending | overdue | today | done
const filters = [
    ['all', 'Todos'],
    ['pending', 'Pendientes'],
    ['overdue', 'Vencidos'],
    ['today', 'Hoy'],
    ['done', 'Hechos'],
];
const matchesSearch = reminder => {
    const q = search.value.trim().toLowerCase();
    if (!q) return true;
    return `${reminder.title} ${reminder.description || ''} ${recipientNames(reminder).join(' ')}`.toLowerCase().includes(q);
};
const matchesFilter = reminder => {
    const d = parseISO(reminder.scheduled_at);
    const now = new Date();
    switch (filter.value) {
        case 'pending': return reminder.status === 'pending' && d >= now;
        case 'overdue': return reminder.status === 'pending' && d < now;
        case 'today': return toKey(d) === todayKey && reminder.status === 'pending';
        case 'done': return reminder.status === 'done';
        default: return true;
    }
};

const remindersForDay = day => {
    const key = toKey(day);
    return props.reminders
        .filter(reminder => toKey(parseISO(reminder.scheduled_at)) === key)
        .sort((a, b) => parseISO(a.scheduled_at) - parseISO(b.scheduled_at));
};
const dayHasOverdue = day => remindersForDay(day).some(r => r.status === 'pending' && parseISO(r.scheduled_at) < new Date());
const isOverdue = reminder => reminder.status === 'pending' && parseISO(reminder.scheduled_at) < new Date();
const fmtTime = iso => parseISO(iso).toLocaleTimeString('es-EC', { timeZone: APP_TZ, hour: '2-digit', minute: '2-digit' });
const fmtDateTime = iso => parseISO(iso).toLocaleString('es-EC', { timeZone: APP_TZ, day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
const beforeLabel = min => {
    min = Number(min) || 0;
    if (min <= 0) return 'a la hora';
    if (min < 60) return `${min} min antes`;
    if (min === 60) return '1 hora antes';
    if (min < 1440) return `${Math.round(min / 60 * 10) / 10} h antes`;
    return `${Math.round(min / 1440 * 10) / 10} día(s) antes`;
};

const pendingCount = computed(() => props.reminders.filter(r => r.status === 'pending' && parseISO(r.scheduled_at) >= new Date()).length);
const overdueList = computed(() => props.reminders
    .filter(r => r.status === 'pending' && parseISO(r.scheduled_at) < new Date() && matchesSearch(r))
    .sort((a, b) => parseISO(a.scheduled_at) - parseISO(b.scheduled_at)));
const upcoming = computed(() => props.reminders
    .filter(r => matchesSearch(r) && matchesFilter(r) && !(filter.value === 'all' && r.status !== 'pending'))
    .filter(r => filter.value === 'all' ? (r.status === 'pending' && parseISO(r.scheduled_at) >= new Date()) : true)
    .sort((a, b) => parseISO(a.scheduled_at) - parseISO(b.scheduled_at))
    .slice(0, 10));
const agenda = computed(() => remindersForDay(selectedDay.value).filter(matchesSearch));
const doneCount = computed(() => props.reminders.filter(r => r.status === 'done').length);
const todayCount = computed(() => props.reminders.filter(r => toKey(parseISO(r.scheduled_at)) === todayKey && r.status === 'pending').length);

const showModal = ref(false);
const editingId = ref(null);
const confirmDelete = ref(false);
const form = useForm({ title: '', description: '', date: '', time: '09:00', remind_before: 10, remind_custom: '', notify_ids: [] });
const toggleNotifyId = id => {
    id = Number(id);
    form.notify_ids = form.notify_ids.includes(id) ? form.notify_ids.filter(x => Number(x) !== id) : [...form.notify_ids, id];
};
const selectAllNotify = () => { form.notify_ids = props.users.map(u => Number(u.id)); };
const clearNotify = () => { form.notify_ids = []; };
const beforePresets = [
    { label: 'A la hora', value: 0 },
    { label: '10 min', value: 10 },
    { label: '30 min', value: 30 },
    { label: '1 hora', value: 60 },
    { label: '1 día', value: 1440 },
];
const effectiveBefore = computed(() => {
    if (form.remind_custom !== '' && form.remind_custom !== null) return Math.max(0, Number(form.remind_custom) || 0);
    return Number(form.remind_before) || 0;
});

const openCreate = day => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.date = toKey(day || selectedDay.value || new Date());
    form.time = '09:00';
    form.remind_before = 10;
    form.remind_custom = '';
    form.notify_ids = props.users.map(u => Number(u.id));
    showModal.value = true;
};
const openEdit = reminder => {
    editingId.value = reminder.id;
    const date = parseISO(reminder.scheduled_at);
    form.reset();
    form.clearErrors();
    form.title = reminder.title;
    form.description = reminder.description || '';
    form.date = toKey(date);
    form.time = toTimeInEcuador(date);
    const before = Number(reminder.remind_before_minutes ?? 0);
    form.remind_before = beforePresets.some(p => p.value === before) ? before : 'custom';
    form.remind_custom = beforePresets.some(p => p.value === before) ? '' : before;
    const ids = (reminder.recipients?.length ? reminder.recipients.map(u => Number(u.id)) : (reminder.assignee ? [Number(reminder.assignee.id)] : []));
    form.notify_ids = ids.length ? ids : props.users.map(u => Number(u.id));
    showModal.value = true;
};
const quickTimes = [['Mañana', '09:00'], ['Tarde', '15:00'], ['Noche', '19:00']];
const schedulePreview = computed(() => {
    if (!form.date || !form.time) return '';
    const preview = new Date(`${form.date}T${form.time}:00${ECUADOR_OFFSET}`);
    if (isNaN(preview)) return '';
    return `${preview.toLocaleDateString('es-EC', { timeZone: APP_TZ, weekday: 'long', day: 'numeric', month: 'long' })}, ${preview.toLocaleTimeString('es-EC', { timeZone: APP_TZ, hour: '2-digit', minute: '2-digit' })}`;
});
const notifyPreview = computed(() => {
    if (!form.date || !form.time) return '';
    const preview = new Date(`${form.date}T${form.time}:00${ECUADOR_OFFSET}`);
    if (isNaN(preview)) return '';
    const notify = new Date(preview.getTime() - effectiveBefore.value * 60000);
    return notify.toLocaleString('es-EC', { timeZone: APP_TZ, weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
});
const modalToggleDone = () => {
    if (!editingId.value) return;
    router.patch(route('reminders.update', editingId.value), { status: 'done' }, {
        preserveScroll: true,
        onSuccess: () => { showModal.value = false; },
    });
};
const submit = () => {
    if (!form.title.trim() || !form.date || !form.time) return;
    form.transform(() => ({
        title: form.title.trim(),
        description: form.description?.trim() || null,
        scheduled_at: `${form.date}T${form.time}:00${ECUADOR_OFFSET}`,
        remind_before_minutes: effectiveBefore.value,
        notify_user_ids: form.notify_ids.map(Number),
    }));
    const options = { preserveScroll: true, onSuccess: () => { showModal.value = false; } };
    if (editingId.value) form.patch(route('reminders.update', editingId.value), options);
    else form.post(route('reminders.store'), options);
};
const toggleDone = reminder => router.patch(
    route('reminders.update', reminder.id),
    { status: reminder.status === 'done' ? 'pending' : 'done' },
    { preserveScroll: true },
);
const withOffset = date => {
    const p = n => String(n).padStart(2, '0');
    const key = new Intl.DateTimeFormat('en-CA', { timeZone: APP_TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
    const parts = new Intl.DateTimeFormat('es-EC', { timeZone: APP_TZ, hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(date);
    let h = parts.find(x => x.type === 'hour')?.value ?? '09';
    const m = parts.find(x => x.type === 'minute')?.value ?? '00';
    if (h === '24') h = '00';
    return `${key}T${h.padStart(2, '0')}:${m.padStart(2, '0')}:00${ECUADOR_OFFSET}`;
};
const postpone = (reminder, minutes) => {
    const next = new Date(parseISO(reminder.scheduled_at).getTime() + minutes * 60000);
    router.patch(route('reminders.update', reminder.id), { scheduled_at: withOffset(next) }, { preserveScroll: true });
};
const duplicate = reminder => {
    const next = new Date(parseISO(reminder.scheduled_at).getTime() + 24 * 3600000);
    router.post(route('reminders.store'), {
        title: `${reminder.title} (copia)`,
        description: reminder.description,
        scheduled_at: withOffset(next),
        remind_before_minutes: Number(reminder.remind_before_minutes ?? 0),
        notify_user_ids: (reminder.recipients?.length ? reminder.recipients.map(u => Number(u.id)) : (reminder.assignee ? [Number(reminder.assignee.id)] : [])),
    }, { preserveScroll: true });
};
const askDelete = () => { confirmDelete.value = true; };
const doDelete = () => {
    if (!editingId.value) return;
    router.delete(route('reminders.destroy', editingId.value), {
        preserveScroll: true,
        onFinish: () => { confirmDelete.value = false; showModal.value = false; },
    });
};
</script>

<template>
    <Head title="Recordatorios" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-600 text-white shadow-lg shadow-sky-500/30">
                        <BellRing :size="22" />
                    </span>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">PlataformaDoc · hora Ecuador (UTC−5)</p>
                        <h2 class="text-2xl font-extrabold capitalize text-slate-900 dark:text-white">Recordatorios</h2>
                    </div>
                </div>
                <button v-if="canManage" type="button" class="explorer-primary-button !px-4 !py-2.5 !text-sm !shadow-lg !shadow-sky-500/25" @click="openCreate()"><Plus :size="16" />Nuevo recordatorio</button>
            </div>
        </template>

        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <div v-if="$page.props.flash?.success" class="mb-4 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/70 dark:text-emerald-300">
                <CheckCircle2 :size="16" />{{ $page.props.flash.success }}
            </div>

            <!-- Tarjetas resumen -->
            <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-600 dark:bg-sky-950 dark:text-sky-400"><Hourglass :size="18" /></span>
                    <div><p class="text-xl font-extrabold leading-none text-slate-900 dark:text-white">{{ pendingCount }}</p><p class="text-xs text-slate-500 dark:text-slate-400">Pendientes</p></div>
                </div>
                <div class="flex items-center gap-3 rounded-2xl border border-red-200 bg-red-50/70 p-3.5 shadow-sm dark:border-red-900 dark:bg-red-950/30">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400"><AlertTriangle :size="18" /></span>
                    <div><p class="text-xl font-extrabold leading-none text-red-700 dark:text-red-300">{{ overdueList.length }}</p><p class="text-xs text-red-500 dark:text-red-400">Vencidos</p></div>
                </div>
                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600 dark:bg-indigo-950 dark:text-indigo-400"><CalendarCheck :size="18" /></span>
                    <div><p class="text-xl font-extrabold leading-none text-slate-900 dark:text-white">{{ todayCount }}</p><p class="text-xs text-slate-500 dark:text-slate-400">Para hoy</p></div>
                </div>
                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400"><CheckCircle2 :size="18" /></span>
                    <div><p class="text-xl font-extrabold leading-none text-slate-900 dark:text-white">{{ doneCount }}</p><p class="text-xs text-slate-500 dark:text-slate-400">Completados</p></div>
                </div>
            </div>

            <!-- Buscador + filtros -->
            <div class="mb-5 flex flex-col gap-2.5 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:flex-row sm:items-center dark:border-slate-700 dark:bg-slate-800">
                <label class="relative flex-1">
                    <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input v-model="search" type="search" placeholder="Buscar por título, detalle o persona…" class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:bg-white focus:ring-2 focus:ring-sky-100 dark:border-slate-600 dark:bg-slate-700/60 dark:text-slate-100 dark:focus:border-sky-600 dark:focus:ring-sky-950" />
                </label>
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="[value, label] in filters" :key="value" type="button" class="rounded-full px-3 py-1.5 text-xs font-bold transition" :class="filter === value ? 'bg-sky-600 text-white shadow-md shadow-sky-500/30' : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-300 dark:hover:bg-slate-600'" @click="filter = value">{{ label }}</button>
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <!-- Calendario -->
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2 dark:border-slate-700 dark:bg-slate-800">
                    <div class="flex items-center justify-between gap-2 border-b border-slate-100 bg-gradient-to-r from-sky-50/80 to-indigo-50/60 px-4 py-3 dark:border-slate-700 dark:from-sky-950/40 dark:to-indigo-950/30">
                        <div class="flex items-center gap-1">
                            <button type="button" class="editor-tool-button" title="Mes anterior" @click="prevMonth"><ChevronLeft :size="16" /></button>
                            <button type="button" class="explorer-action-button" @click="goToday">Hoy</button>
                            <button type="button" class="editor-tool-button" title="Mes siguiente" @click="nextMonth"><ChevronRight :size="16" /></button>
                        </div>
                        <h3 class="flex items-center gap-2 text-base font-extrabold capitalize text-slate-900 dark:text-white"><CalendarDays :size="17" class="text-sky-600 dark:text-sky-400" />{{ monthLabel }}</h3>
                    </div>
                    <div class="px-4 pt-3">
                        <div class="grid grid-cols-7 gap-1 text-center text-[0.65rem] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                            <span v-for="day in ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom']" :key="day" class="py-1">{{ day }}</span>
                        </div>
                        <div class="grid grid-cols-7 gap-1 pb-2">
                            <div v-for="(day, index) in cells" :key="index"
                                 class="group relative min-h-20 rounded-xl border p-1 transition sm:min-h-24"
                                 :class="!day ? 'border-transparent' : toKey(day) === selectedKey ? 'border-sky-500 bg-sky-50/70 ring-2 ring-sky-200 dark:border-sky-500 dark:bg-sky-950/40 dark:ring-sky-900' : 'border-slate-200 bg-white hover:border-sky-300 hover:bg-sky-50/50 dark:border-slate-700 dark:bg-slate-800 dark:hover:border-sky-700'"
                                 @click="selectDay(day)">
                                <template v-if="day">
                                    <div class="flex items-center justify-between">
                                        <p class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold"
                                           :class="toKey(day) === todayKey ? 'bg-sky-600 text-white shadow-md shadow-sky-500/40' : 'text-slate-600 dark:text-slate-300'">
                                            {{ day.getDate() }}
                                        </p>
                                        <button v-if="canManage" type="button" title="Crear ese día" class="rounded-full p-1 text-sky-500 opacity-0 transition hover:bg-sky-100 group-hover:opacity-100 dark:hover:bg-sky-900" @click.stop="openCreate(day)"><Plus :size="13" /></button>
                                    </div>
                                    <ul class="mt-1 space-y-0.5">
                                        <li v-for="reminder in remindersForDay(day).slice(0, 3)" :key="reminder.id">
                                            <button type="button" class="flex w-full items-center gap-1 truncate rounded-lg px-1.5 py-0.5 text-left text-[0.68rem] font-semibold"
                                                    :class="reminder.status === 'done' ? 'bg-slate-100 text-slate-400 line-through dark:bg-slate-700/60' : isOverdue(reminder) ? 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300' : 'bg-sky-50 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300'"
                                                    :title="`${reminder.title} · ${fmtTime(reminder.scheduled_at)}${Number(reminder.remind_before_minutes) ? ' · ⏰ ' + beforeLabel(reminder.remind_before_minutes) : ''}`"
                                                    @click.stop="openEdit(reminder)">
                                                <span class="shrink-0">{{ fmtTime(reminder.scheduled_at) }}</span>
                                                <span class="truncate">{{ reminder.title }}</span>
                                                <AlarmClock v-if="Number(reminder.remind_before_minutes)" :size="10" class="shrink-0 opacity-70" />
                                            </button>
                                        </li>
                                    </ul>
                                    <p v-if="remindersForDay(day).length > 3" class="px-1 text-[0.65rem] font-bold text-sky-500">+{{ remindersForDay(day).length - 3 }} más</p>
                                    <span v-if="dayHasOverdue(day)" class="absolute bottom-1 right-1 h-1.5 w-1.5 rounded-full bg-red-500"></span>
                                </template>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 px-1 py-2.5 text-[0.7rem] text-slate-400 dark:border-slate-700">
                            <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-sky-500"></span>pendiente</span>
                            <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-red-500"></span>vencido</span>
                            <span class="flex items-center gap-1"><AlarmClock :size="11" />con pre-aviso</span>
                            <span class="ml-auto hidden sm:inline">Clic para ver la agenda · ＋ para crear ese día</span>
                        </div>
                    </div>
                </section>

                <aside class="space-y-5">
                    <!-- Agenda del día -->
                    <section class="overflow-hidden rounded-2xl border border-sky-200 bg-gradient-to-b from-sky-50/80 to-white shadow-sm dark:border-sky-900 dark:from-sky-950/40 dark:to-slate-800">
                        <div class="flex items-center justify-between gap-2 px-4 pt-3.5">
                            <h3 class="flex items-center gap-1.5 text-sm font-extrabold capitalize text-slate-900 dark:text-white"><CalendarCheck :size="15" class="text-sky-600 dark:text-sky-400" />{{ selectedLabel }}</h3>
                            <button v-if="canManage" type="button" class="flex items-center gap-1 rounded-full bg-sky-600 px-2.5 py-1 text-[0.7rem] font-bold text-white shadow-md shadow-sky-500/30 transition hover:bg-sky-700" @click="openCreate(selectedDay)"><Plus :size="12" />Añadir</button>
                        </div>
                        <div class="space-y-2 px-4 py-3">
                            <p v-if="!agenda.length" class="flex items-center gap-2 rounded-xl border border-dashed border-slate-300 px-3 py-4 text-center text-xs text-slate-400 dark:border-slate-600"><CalendarX :size="15" class="shrink-0" />Nada programado este día. ¡Agenda algo con ＋!</p>
                            <div v-for="reminder in agenda" :key="reminder.id" class="flex items-start gap-2 rounded-xl bg-white p-2.5 shadow-sm ring-1 ring-slate-100 dark:bg-slate-700/60 dark:ring-slate-600/50">
                                <button v-if="canEditReminder(reminder)" type="button" class="editor-tool-button mt-0.5" title="Marcar realizado" @click="toggleDone(reminder)"><Check :size="14" /></button>
                                <button type="button" class="min-w-0 flex-1 text-left" @click="openEdit(reminder)">
                                    <span class="block truncate text-sm font-bold text-slate-800 dark:text-slate-100">{{ reminder.title }}</span>
                                    <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                        <span class="flex items-center gap-0.5 font-semibold text-sky-600 dark:text-sky-400"><Clock :size="11" />{{ fmtTime(reminder.scheduled_at) }}</span>
                                        <span v-if="Number(reminder.remind_before_minutes)" class="flex items-center gap-0.5 rounded-full bg-amber-100 px-1.5 py-px text-[0.65rem] font-bold text-amber-700 dark:bg-amber-950 dark:text-amber-300"><AlarmClock :size="10" />{{ beforeLabel(reminder.remind_before_minutes) }}</span>
                                        <span v-if="recipientLabel(reminder)" class="flex items-center gap-0.5" :title="recipientNames(reminder).join(', ')"><User :size="11" />👥 {{ recipientLabel(reminder) }}</span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </section>

                    <!-- Vencidos -->
                    <section v-if="overdueList.length" class="rounded-2xl border border-red-200 bg-red-50/60 p-4 dark:border-red-900 dark:bg-red-950/30">
                        <h3 class="flex items-center gap-1.5 text-sm font-extrabold text-red-700 dark:text-red-300"><AlertTriangle :size="15" />Vencidos ({{ overdueList.length }})</h3>
                        <ul class="mt-2 space-y-2">
                            <li v-for="reminder in overdueList" :key="reminder.id" class="flex items-start gap-2 rounded-xl bg-white/80 p-2 shadow-sm dark:bg-slate-800/70">
                                <button v-if="canEditReminder(reminder)" type="button" class="editor-tool-button mt-0.5" title="Marcar realizado" @click="toggleDone(reminder)"><Check :size="14" /></button>
                                <button type="button" class="min-w-0 flex-1 text-left" @click="openEdit(reminder)">
                                    <span class="block truncate text-sm font-bold text-slate-800 dark:text-slate-100">{{ reminder.title }}</span>
                                    <span class="flex items-center gap-1 text-xs text-red-600 dark:text-red-400"><Clock :size="11" />{{ fmtDateTime(reminder.scheduled_at) }}</span>
                                </button>
                            </li>
                        </ul>
                    </section>

                    <!-- Próximos / resultados -->
                    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <h3 class="flex items-center gap-1.5 text-sm font-extrabold text-slate-900 dark:text-white"><Hourglass :size="15" class="text-sky-600 dark:text-sky-400" />{{ search || filter !== 'all' ? 'Resultados' : 'Próximos' }}</h3>
                        <p v-if="!upcoming.length" class="mt-2 rounded-xl border border-dashed border-slate-300 px-3 py-4 text-center text-xs text-slate-400 dark:border-slate-600">Sin recordatorios. Prueba otro filtro o crea uno nuevo. ✨</p>
                        <ul v-else class="mt-2 space-y-2">
                            <li v-for="reminder in upcoming" :key="reminder.id" class="group flex items-start gap-2 rounded-xl bg-slate-50 p-2 ring-1 ring-transparent transition hover:ring-sky-200 dark:bg-slate-700/50 dark:hover:ring-sky-800">
                                <button v-if="canEditReminder(reminder)" type="button" class="editor-tool-button mt-0.5" title="Marcar realizado" @click="toggleDone(reminder)"><Check :size="14" /></button>
                                <button type="button" class="min-w-0 flex-1 text-left" @click="openEdit(reminder)">
                                    <span class="block truncate text-sm font-bold text-slate-800 dark:text-slate-100">{{ reminder.title }}</span>
                                    <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                        <span class="flex items-center gap-0.5"><Clock :size="11" />{{ fmtDateTime(reminder.scheduled_at) }}</span>
                                        <span v-if="Number(reminder.remind_before_minutes)" class="flex items-center gap-0.5 rounded-full bg-amber-100 px-1.5 py-px text-[0.65rem] font-bold text-amber-700 dark:bg-amber-950 dark:text-amber-300"><AlarmClock :size="10" />{{ beforeLabel(reminder.remind_before_minutes) }}</span>
                                        <span v-if="recipientLabel(reminder)" class="inline-flex items-center gap-0.5" :title="recipientNames(reminder).join(', ')"><User :size="11" />👥 {{ recipientLabel(reminder) }}</span>
                                    </span>
                                </button>
                                <span v-if="canManage" class="flex shrink-0 gap-1 opacity-0 transition group-hover:opacity-100">
                                    <button type="button" title="Posponer 1 hora" class="rounded-lg p-1.5 text-slate-400 hover:bg-sky-100 hover:text-sky-600 dark:hover:bg-sky-950" @click="postpone(reminder, 60)"><Clock :size="13" /></button>
                                    <button type="button" title="Duplicar mañana" class="rounded-lg p-1.5 text-slate-400 hover:bg-sky-100 hover:text-sky-600 dark:hover:bg-sky-950" @click="duplicate(reminder)"><Copy :size="13" /></button>
                                </span>
                            </li>
                        </ul>
                    </section>
                </aside>
            </div>
        </div>

        <!-- Modal profesional -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/50 px-4 py-6 backdrop-blur-sm" @click.self="showModal = false">
            <form class="w-full max-w-lg overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-slate-900" @submit.prevent="submit">
                <div class="relative bg-gradient-to-r from-sky-600 via-sky-500 to-indigo-600 px-5 pb-5 pt-5 text-white">
                    <span class="absolute -right-6 -top-6 flex h-28 w-28 items-center justify-center rounded-full bg-white/10"><BellRing :size="52" class="opacity-40" /></span>
                    <div class="relative flex items-start gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white/20 backdrop-blur"><BellPlus :size="20" /></span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-lg font-extrabold leading-tight">{{ editingId ? 'Editar recordatorio' : 'Nuevo recordatorio' }}</h3>
                            <p class="text-xs text-sky-100">Te avisamos en la plataforma y por Telegram · hora Ecuador ⏰</p>
                        </div>
                        <button type="button" class="rounded-full p-1.5 text-white/80 transition hover:bg-white/20 hover:text-white" @click="showModal = false"><X :size="17" /></button>
                    </div>
                </div>

                <div class="max-h-[65vh] space-y-4 overflow-y-auto px-5 py-4">
                    <div>
                        <label class="mb-1 flex items-center gap-1.5 text-xs font-extrabold uppercase tracking-wide text-slate-500 dark:text-slate-400" for="reminder-title">📌 Título</label>
                        <input id="reminder-title" v-model="form.title" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-800 outline-none transition placeholder:font-normal placeholder:text-slate-400 focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-sky-500 dark:focus:ring-sky-950" placeholder="p. ej. Audiencia caso García ⚖️" required autofocus :disabled="!canManage" />
                        <p v-if="form.errors.title" class="mt-1 text-xs font-semibold text-red-500">{{ form.errors.title }}</p>
                    </div>
                    <div>
                        <label class="mb-1 flex items-center gap-1.5 text-xs font-extrabold uppercase tracking-wide text-slate-500 dark:text-slate-400" for="reminder-description">📝 Descripción <span class="font-normal normal-case text-slate-400">(opcional)</span></label>
                        <textarea id="reminder-description" v-model="form.description" rows="2" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:focus:border-sky-500 dark:focus:ring-sky-950" placeholder="Detalles, lugar, expediente, links…" :disabled="!canManage"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 flex items-center gap-1.5 text-xs font-extrabold uppercase tracking-wide text-slate-500 dark:text-slate-400" for="reminder-date">📅 Fecha</label>
                            <input id="reminder-date" v-model="form.date" type="date" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-800 outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-sky-500 dark:focus:ring-sky-950" required :disabled="!canManage" />
                        </div>
                        <div>
                            <label class="mb-1 flex items-center gap-1.5 text-xs font-extrabold uppercase tracking-wide text-slate-500 dark:text-slate-400" for="reminder-time">🕑 Hora</label>
                            <input id="reminder-time" v-model="form.time" type="time" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-800 outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-sky-500 dark:focus:ring-sky-950" required :disabled="!canManage" />
                        </div>
                    </div>
                    <div v-if="canManage" class="flex flex-wrap gap-1.5">
                        <button v-for="[label, value] in quickTimes" :key="value" type="button" class="rounded-full bg-slate-100 px-3 py-1 text-[0.72rem] font-bold text-slate-600 transition hover:bg-sky-100 hover:text-sky-700 dark:bg-slate-700 dark:text-slate-300 dark:hover:bg-sky-950 dark:hover:text-sky-300" @click="form.time = value">☀️ {{ label }} {{ value }}</button>
                    </div>
                    <div class="rounded-2xl border border-sky-200 bg-sky-50/80 px-3.5 py-2.5 text-xs dark:border-sky-900 dark:bg-sky-950/40">
                        <p class="font-bold text-sky-800 dark:text-sky-300">📍 Se programará: {{ schedulePreview || '—' }} <span class="font-normal">(hora Ecuador)</span></p>
                        <p v-if="form.errors.scheduled_at" class="mt-1 font-semibold text-red-500">{{ form.errors.scheduled_at }}</p>
                    </div>

                    <div class="rounded-2xl border border-amber-200 bg-amber-50/70 p-3.5 dark:border-amber-900 dark:bg-amber-950/30">
                        <p class="flex items-center gap-1.5 text-xs font-extrabold uppercase tracking-wide text-amber-700 dark:text-amber-300"><AlarmClock :size="14" /> Recordar antes</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <button v-for="preset in beforePresets" :key="preset.value" type="button" class="rounded-full px-3 py-1.5 text-xs font-bold transition" :class="form.remind_custom === '' && Number(form.remind_before) === preset.value ? 'bg-amber-500 text-white shadow-md shadow-amber-500/30' : 'bg-white text-amber-700 ring-1 ring-amber-200 hover:bg-amber-100 dark:bg-slate-800 dark:text-amber-300 dark:ring-amber-800'" :disabled="!canManage" @click="form.remind_before = preset.value; form.remind_custom = ''">{{ preset.label }}</button>
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <label for="reminder-custom-before" class="text-xs font-semibold text-amber-700 dark:text-amber-300">Otro (min):</label>
                            <input id="reminder-custom-before" v-model="form.remind_custom" type="number" min="0" max="43200" placeholder="p. ej. 45" class="w-28 rounded-xl border border-amber-200 bg-white px-2.5 py-1.5 text-xs font-bold text-amber-800 outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-100 dark:border-amber-800 dark:bg-slate-800 dark:text-amber-200" :disabled="!canManage" />
                            <button v-if="form.remind_custom !== ''" type="button" class="text-xs font-bold text-amber-600 underline dark:text-amber-400" @click="form.remind_custom = ''">usar preset</button>
                        </div>
                        <p class="mt-2 flex items-center gap-1 text-xs font-semibold text-amber-800 dark:text-amber-200"><BellRing :size="13" /> Te avisaremos: {{ notifyPreview || '—' }} ({{ beforeLabel(effectiveBefore) }})</p>
                    </div>

                    <template v-if="canManage">
                        <div class="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-3.5 dark:border-indigo-900 dark:bg-indigo-950/30">
                            <div class="flex items-center justify-between gap-2">
                                <p class="flex items-center gap-1.5 text-xs font-extrabold uppercase tracking-wide text-indigo-700 dark:text-indigo-300">👥 Notificar a <span class="rounded-full bg-indigo-600 px-1.5 py-px text-[0.65rem] text-white">{{ form.notify_ids.length }}</span></p>
                                <div class="flex gap-2">
                                    <button type="button" class="text-[0.7rem] font-bold text-indigo-600 underline dark:text-indigo-400" @click="selectAllNotify">Todos</button>
                                    <button type="button" class="text-[0.7rem] font-bold text-indigo-400 underline dark:text-indigo-500" @click="clearNotify">Nadie</button>
                                </div>
                            </div>
                            <p class="mt-1 text-[0.7rem] text-indigo-500 dark:text-indigo-400">El evento se ve en el calendario de todos; el aviso (plataforma + Telegram) solo les llega a los marcados.</p>
                            <div class="mt-2 grid max-h-36 grid-cols-1 gap-1 overflow-y-auto sm:grid-cols-2">
                                <label v-for="user in users" :key="user.id" class="flex cursor-pointer items-center gap-2 rounded-xl bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-indigo-100 transition hover:ring-indigo-300 dark:bg-slate-800 dark:text-slate-200 dark:ring-indigo-900" :class="form.notify_ids.map(Number).includes(Number(user.id)) ? '!ring-2 !ring-indigo-500 bg-indigo-50 dark:bg-indigo-950/50' : ''">
                                    <input type="checkbox" :checked="form.notify_ids.map(Number).includes(Number(user.id))" class="h-3.5 w-3.5 accent-indigo-600" @change="toggleNotifyId(user.id)" />
                                    <span class="truncate">{{ user.name }}</span>
                                </label>
                            </div>
                            <p v-if="!users.length" class="mt-1 text-xs text-slate-400">Sin usuarios disponibles.</p>
                            <p v-if="form.errors.notify_user_ids" class="mt-1 text-xs font-semibold text-red-500">{{ form.errors.notify_user_ids }}</p>
                        </div>
                    </template>
                </div>

                <div class="flex items-center gap-2 border-t border-slate-100 bg-slate-50/70 px-5 py-3.5 dark:border-slate-700 dark:bg-slate-800/60">
                    <button v-if="editingId && canManage" type="button" class="flex items-center gap-1 rounded-xl border border-red-200 px-3 py-2 text-xs font-bold text-red-600 transition hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950" @click="askDelete"><Trash2 :size="14" />Eliminar</button>
                    <span class="flex-1"></span>
                    <button type="button" class="rounded-xl px-3.5 py-2 text-xs font-bold text-slate-500 transition hover:bg-slate-200/70 dark:text-slate-300 dark:hover:bg-slate-700" @click="showModal = false">{{ canManage ? 'Cancelar' : 'Cerrar' }}</button>
                    <button v-if="!canManage && editingId" type="button" class="explorer-primary-button" @click="modalToggleDone"><Check :size="14" />Marcar realizado</button>
                    <button v-if="canManage" type="submit" class="flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-sky-600 to-indigo-600 px-4 py-2 text-xs font-extrabold text-white shadow-lg shadow-sky-500/30 transition hover:brightness-110 disabled:opacity-60" :disabled="form.processing">{{ form.processing ? 'Guardando…' : '✨ Guardar' }}</button>
                </div>
            </form>
        </div>
        <ConfirmModal :show="confirmDelete" danger title="Eliminar recordatorio" message="Se eliminará definitivamente y no se enviará ningún aviso." confirm-label="Eliminar" @confirm="doDelete" @cancel="confirmDelete = false" />
    </AuthenticatedLayout>
</template>
