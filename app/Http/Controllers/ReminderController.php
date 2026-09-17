<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReminderController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('reminders.view'), 403);

        $user = $request->user();
        $manage = $user->can('reminders.manage');

        // El calendario es global: todos con permiso ven los mismos recordatorios.
        // `recipients`/`assigned_to` solo definen A QUIÉNES les llega el aviso.
        $reminders = Reminder::query()
            ->with(['assignee:id,name', 'creator:id,name', 'recipients:id,name'])
            ->orderBy('scheduled_at')
            ->limit(500)
            ->get()
            ->map(fn (Reminder $reminder): array => [
                'id' => $reminder->id,
                'title' => $reminder->title,
                'description' => $reminder->description,
                'scheduled_at' => $reminder->scheduled_at->toISOString(),
                'remind_before_minutes' => (int) ($reminder->remind_before_minutes ?? 0),
                'notify_at' => $reminder->effectiveNotifyAt()->toISOString(),
                'status' => $reminder->status,
                'notified_at' => $reminder->notified_at?->toISOString(),
                'telegram_sent' => $reminder->telegram_sent,
                'assignee' => $reminder->assignee,
                'recipients' => $reminder->recipients,
                'creator' => $reminder->creator,
            ]);

        return Inertia::render('Reminders', [
            'reminders' => $reminders,
            'users' => $manage
                ? User::query()->orderBy('name')->get(['id', 'name'])
                : [['id' => $user->id, 'name' => $user->name]],
            'canManage' => $manage,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('reminders.manage'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scheduled_at' => ['required', 'date'],
            'remind_before_minutes' => ['nullable', 'integer', 'min:0', 'max:43200'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'notify_user_ids' => ['nullable', 'array'],
            'notify_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $recipientIds = self::recipientIds($validated);
        if ($recipientIds === []) {
            return back()->withErrors(['notify_user_ids' => 'Selecciona al menos un usuario a notificar.']);
        }

        $validated['scheduled_at'] = self::parseAsLocal($validated['scheduled_at']);
        $validated['remind_before_minutes'] = (int) ($validated['remind_before_minutes'] ?? 0);
        $validated['notify_at'] = $validated['scheduled_at']->copy()->subMinutes($validated['remind_before_minutes']);
        // Compatibilidad: assigned_to = primer destinatario (pantalla/respaldo).
        $validated['assigned_to'] = $recipientIds[0];
        unset($validated['notify_user_ids']);

        $reminder = Reminder::create(array_merge($validated, [
            'created_by' => $request->user()->id,
            'status' => Reminder::STATUS_PENDING,
        ]));
        $reminder->recipients()->sync($recipientIds);

        return back()->with('success', 'Recordatorio creado.');
    }

    public function update(Request $request, Reminder $reminder): RedirectResponse
    {
        $user = $request->user();
        $manager = $user->can('reminders.manage');
        abort_unless($manager || $reminder->isRecipient($user), 403);

        if ($manager) {
            $validated = $request->validate([
                'title' => ['sometimes', 'required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
                'scheduled_at' => ['sometimes', 'required', 'date'],
                'remind_before_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:43200'],
                'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
                'notify_user_ids' => ['sometimes', 'nullable', 'array'],
                'notify_user_ids.*' => ['integer', 'exists:users,id'],
                'status' => ['sometimes', 'required', 'in:pending,done'],
            ]);

            if (isset($validated['scheduled_at'])) {
                $validated['scheduled_at'] = self::parseAsLocal($validated['scheduled_at']);
            }

            $recipientsChanged = array_key_exists('notify_user_ids', $validated);
            if ($recipientsChanged) {
                $recipientIds = self::recipientIds($validated, $reminder);
                if ($recipientIds === []) {
                    return back()->withErrors(['notify_user_ids' => 'Selecciona al menos un usuario a notificar.']);
                }
                $validated['assigned_to'] = $recipientIds[0];
                unset($validated['notify_user_ids']);
            }

            $rescheduled = isset($validated['scheduled_at']) || array_key_exists('remind_before_minutes', $validated);

            if ($rescheduled) {
                $scheduled = $validated['scheduled_at'] ?? $reminder->scheduled_at;
                $before = array_key_exists('remind_before_minutes', $validated)
                    ? (int) ($validated['remind_before_minutes'] ?? 0)
                    : (int) ($reminder->remind_before_minutes ?? 0);
                $validated['remind_before_minutes'] = $before;
                $validated['notify_at'] = $scheduled->copy()->subMinutes($before);
            }

            $reminder->fill($validated);

            // Si se reprograma (o cambia el pre-aviso) a futuro tras haber avisado, se rearma el aviso.
            if ($rescheduled && $reminder->effectiveNotifyAt()->isFuture() && $reminder->notified_at) {
                $reminder->forceFill(['notified_at' => null, 'telegram_sent' => false]);
            }

            $reminder->save();

            if ($recipientsChanged) {
                $reminder->recipients()->sync($recipientIds);
            }
        } else {
            $validated = $request->validate([
                'status' => ['required', 'in:pending,done'],
            ]);
            $reminder->update($validated);
        }

        return back()->with('success', 'Recordatorio actualizado.');
    }

    public function destroy(Request $request, Reminder $reminder): RedirectResponse
    {
        abort_unless($request->user()->can('reminders.manage'), 403);
        $reminder->delete();

        return back()->with('success', 'Recordatorio eliminado.');
    }

    /**
     * Interpreta la fecha recibida del frontend como hora local de Ecuador
     * (America/Guayaquil) cuando viene sin zona horaria ("2026-09-11T14:00:00").
     * Si ya trae offset o "Z", se respeta el instante y se normaliza a la
     * timezone de la app para guardar la hora de pared correcta.
     */
    private static function parseAsLocal(string $value): Carbon
    {
        $tz = (string) config('app.timezone', 'America/Guayaquil');

        return Carbon::parse($value, $tz)->setTimezone($tz);
    }

    /**
     * Normaliza los destinatarios del aviso: `notify_user_ids` (multi) o el
     * `assigned_to` clásico de respaldo. Devuelve IDs únicos y ordenados.
     */
    private static function recipientIds(array $validated, ?Reminder $reminder = null): array
    {
        if (array_key_exists('notify_user_ids', $validated)) {
            $ids = $validated['notify_user_ids'] ?? [];
        } elseif (isset($validated['assigned_to'])) {
            $ids = [$validated['assigned_to']];
        } elseif ($reminder) {
            $ids = $reminder->recipients()->pluck('users.id')->all();
            if ($ids === [] && $reminder->assigned_to) {
                $ids = [$reminder->assigned_to];
            }
        } else {
            $ids = [];
        }

        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all();

        return $ids;
    }
}
