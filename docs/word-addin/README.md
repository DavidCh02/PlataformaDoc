# Add-in de Microsoft Word para PlataformaDoc

Sustituye la integración con OnlyOffice. El editor de Word se abre **en el propio
Word** (escritorio, Mac o Web) mediante un complemento de Office que descarga el
binario actual, permite editarlo y vuelve a subir la nueva versión, registrando
cada guardado en el historial de versiones con una nota obligatoria de cambios.

## Arquitectura

- El panel del Add-in vive en `public/word-addin/index.html` (estático, Office.js CDN).
- La API REST está en `routes/api.php` (grupo `/api/addin/*`), protegida con **Laravel
  Sanctum** (Bearer token).
- Cada documento Word (`documents` + `files` con `document_id`) lleva una cadena de
  versiones en `document_versions`, con binario en `storage/app/private` (disco `local`).
- La versión activa apunta a `documents.current_version_id`. Las anotaciones/comentarios
  de una versión viven en `document_annotations`.
- El bloqueo para edición en Word usa `documents.is_locked`, `locked_by_id` y `locked_at`.
  Mientras está bloqueado, solo el titular puede descargar/subir/desbloquear (recovery:
  cualquier `docs.create` puede forzar el desbloqueo).

## Seguridad

- Login: `POST /api/addin/login` con `email` + `password` → `{ token, user }`.
  Rate limiting `throttle:5,1`; revoca tokens antiguos del usuario.
- Todas las demás rutas requieren `Authorization: Bearer <token>`.
- Permisos exigidos por la API: `files.view` (listar, descargar), `docs.create`
  (forzar desbloqueo), y el resto de operaciones son del titular del bloqueo.
- El upload valida: archivo `.docx` (máx `ADDIN_MAX_FILE_SIZE`, por defecto 15 MB) y
  `change_notes` obligatorias (máx 1000 caracteres).

## Endpoints

| Método | Ruta | Descripción |
| --- | --- | --- |
| POST | `/api/addin/login` | Login, devuelve token Bearer. |
| GET | `/api/addin/documents` | Documentos Word con versión actual, estado de bloqueo y titular. |
| POST | `/api/addin/documents/{document}/lock` | Bloquea (idempotente). 423 si lo tiene otro. |
| GET | `/api/addin/documents/{document}/download` | Descarga el binario actual. 423 si no tienes el bloqueo. |
| POST | `/api/addin/documents/{document}/upload` | Sube la nueva versión (`file` + `change_notes`). 423 sin bloqueo; 422 si faltan notas. |
| POST | `/api/addin/documents/{document}/unlock` | Libera el bloqueo (solo titular; `docs.create` fuerza). |
| GET | `/api/addin/documents/{document}/history` | Historial de versiones + autor + anotaciones. |

### Versionado

- `v1` se crea con `DocumentController::createWord()`/`linkWord()`.
- Cada `upload` registra `v{n+1}` (con `change_summary = change_notes`), actualiza
  `current_version_id` y sincroniza el `File` vinculado (nuevo `storage_path` +
  `file_version` espejo `source=upload`).

## Instalación del panel

1. Sirve la plataforma por HTTPS y publica el panel: `public/word-addin/index.html`.
2. Crea un manifesto (ver `manifest-word-addin.xml` en esta carpeta) apuntando a
   `https://<dominio>/word-addin/index.html`, súbelo o side-load it en Word:
   - Word Web/365: *Insertar* → *Complementos* → *Mis complementos* → *Subir mi complemento*.
   - Word escritorio Windows: *Insertar* → *Mis complementos* → *Subir mi complemento*.
3. Configura `ADDIN_MAX_FILE_SIZE` en `.env` si necesitas otro límite.

## Flujo del panel

1. El usuario hace login (email/password); se guarda el token localmente.
2. Se lista `documents`; cada elemento muestra versión actual y si está bloqueado.
3. "Abrir/Bloquear" → `lock` → `download` → se abre el `.docx` en Word.
4. Al guardar ("Guardar cambios en plataforma"), se pide una **nota de cambios**,
   se lee el documento abierto con `Office.context.document.getFileAsync`
   (`fileType: 'compressed'`) y se hace `upload` (multipart).
5. "Terminar/Finalizar" → `unlock`.
6. "Historial" muestra la línea de tiempo (versiones, autor, notas y anotaciones).

## Solución de problemas

- **El panel no hace login en Word Web**: revisa que esté servido por HTTPS y que la
  página de origen esté permitida en `config/sanctum.php` (rutas SPA) si usas cookies.
  El Add-in usa Bearer token, así que normalmente no hace falta.
- **`423 Locked`**: el documento está bloqueado por otro usuario; pídele que lo cierre
  o usa *Forzar desbloqueo* (requiere `docs.create`).
- **`422` al guardar**: falta `change_notes` o el archivo supera `ADDIN_MAX_FILE_SIZE`.
- **`404/desaparece el binario`**: revisa que `filesystems.default` apunte al disco
  donde se guardaron los binarios (`storage` en local) y que el respaldo incluya
  `storage/app/private`.
- **Regenerar índices de versiones**: solo reindexa si la tabla quedó a medias por un
  fallo; no edites `document_versions` a mano si hay versiones publicadas.