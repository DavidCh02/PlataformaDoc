<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gestión centralizada de los bloqueos de edición (exclusión mutua con el
 * Add-in de Word).
 *
 * Un bloqueo es un "arriendo" (lease): caduca transcurrido el TTL sin que el
 * titular lo renueve con un heartbeat. Esto evita que un documento quede
 * bloqueado para siempre cuando el Word se cierra de golpe, se pierde la
 * conexión o la sesión se abandona.
 */
class LockManager
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Instante desde el cual un bloqueo se considera caducado.
     */
    public function cutoff(): Carbon
    {
        return now()->subMinutes((int) config('addin.lock_ttl_minutes'));
    }

    public function isStale(Document $document): bool
    {
        return $document->is_locked
            && $document->locked_at !== null
            && $document->locked_at->lessThanOrEqualTo($this->cutoff());
    }

    /**
     * Asegura que el documento queda bloqueado para el usuario actual (misma
     * política que el Add-in de Word):
     *  - Ya es del usuario actual o está libre → se asigna y audita.
     *  - Es de otro usuario y está vigente → HTTP 423 (con el titular).
     *  - Es de otro usuario pero caducó (TTL superado) → se libera y se toma.
     *
     * Centraliza la adquisición para no duplicar reglas entre el Add-in
     * (`ensureLockAvailable`) y el flujo "Modificar" del Explorador web.
     */
    public function acquire(Document $document, User $user, ?Request $request = null, string $action = 'document.lock'): void
    {
        $request ??= request();

        if ($document->is_locked && $document->locked_by_id === $user->getKey()) {
            return;
        }

        $stale = $this->isStale($document);

        if ($document->is_locked && ! $stale) {
            throw new HttpResponseException(response()->json([
                'message' => 'El documento está siendo editado en Word por '.($document->lockedBy?->name ?? 'otro usuario').'.',
                'locked_by' => $document->lockedBy?->name,
                'locked_by_id' => $document->locked_by_id,
                'locked_at' => $document->locked_at?->toISOString(),
            ], 423));
        }

        if ($stale) {
            $this->auditLogger->log('document.lock.stale_released', $document, [
                'document_id' => $document->id,
                'taken_over_by' => $user->getKey(),
            ], $request);
        }

        Document::query()->whereKey($document->getKey())->update([
            'is_locked' => true,
            'locked_by_id' => $user->getKey(),
            'locked_at' => now(),
        ]);

        $this->auditLogger->log($action, $document, ['document_id' => $document->id], $request);
    }

    /**
     * Libera el bloqueo de un documento (con auditoría).
     */
    public function release(Document $document, ?Request $request = null, string $action = 'addin.document.unlock', array $metadata = []): void
    {
        $request ??= request();

        if (! $document->is_locked) {
            return;
        }

        $holderId = $document->locked_by_id;

        Document::query()->whereKey($document->getKey())->update([
            'is_locked' => false,
            'locked_by_id' => null,
            'locked_at' => null,
        ]);

        $this->auditLogger->log($action, $document, array_merge(
            ['document_id' => $document->id, 'lock_holder_id' => $holderId],
            $metadata,
        ), $request);
    }

    /**
     * Libera los bloqueos caducados (opcionalmente solo los de una carpeta) y
     * devuelve cuántos expiraron. Se invoca en el polling del Explorador y del
     * Add-in, así ningún documento queda bloqueado para siempre sin necesidad
     * de un cron.
     */
    public function expireStale(?int $folderId = null, ?Request $request = null): int
    {
        $request ??= request();

        $query = Document::query()
            ->where('is_locked', true)
            ->whereNotNull('locked_at')
            ->where('locked_at', '<=', $this->cutoff());

        if ($folderId !== null) {
            $query->where('folder_id', $folderId);
        }

        return $query->select(['id', 'title', 'locked_by_id', 'is_locked', 'locked_at'])
            ->get()
            ->reduce(function (int $count, Document $document) use ($request): int {
                $this->release($document, $request, 'addin.lock.expired_released');
                Log::info('Bloqueo caducado liberado automáticamente.', ['document_id' => $document->id]);

                return $count + 1;
            }, 0);
    }

    /**
     * Libera todos los bloqueos de un usuario (cierre de sesión, panel cerrado).
     */
    public function releaseForUser(int $userId, ?Request $request = null): int
    {
        $request ??= request();

        $locked = Document::query()
            ->where('is_locked', true)
            ->where('locked_by_id', $userId)
            ->get(['id', 'title', 'locked_by_id', 'is_locked', 'locked_at']);

        $locked->each(fn (Document $document) => $this->release($document, $request, 'addin.lock.user_released'));

        return $locked->count();
    }

    /**
     * Un bloqueo se puede liberar manualmente si el peticionario es su titular
     * o tiene permiso de edición en tiempo real (editor/admin).
     */
    public function canForceUnlock(Document $document, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($document->locked_by_id === $user->getKey()) {
            return true;
        }

        return $user->can('docs.edit_realtime');
    }
}