'use strict';

// ============================================================
//  CONFIGURACION — PRODUCCION (Railway)
//  Copia de script.js apuntando al servidor de producción.
//  El archivo local (script.js) queda intacto.
// ============================================================
// Pegar este script en Script Lab (Word) cuando se trabaje contra
// https://plataformadoc-production-ec46.up.railway.app
// NOTA: Script Lab ejecuta en contexto HTTPS; usar SIEMPRE https.
var BASE_URL = "https://plataformadoc-production-ec46.up.railway.app/api/addin";

var API = {
    login:     '/login',
    documents: '/documents',
    contents:  '/folders',
    modify:    function (id) { return '/documents/' + id + '/modify'; },
    session:   function (id) { return '/documents/' + id + '/session'; },
    upload:    function (id) { return '/documents/' + id + '/upload'; },
    unlock:    function (id) { return '/documents/' + id + '/unlock'; },
    heartbeat: function (id) { return '/documents/' + id + '/heartbeat'; },
    logout:    '/logout',
    linkFile:  function (id) { return '/files/' + id + '/link'; }
};

var LS_TOKEN_KEY = 'sl_pd_token';
var LS_USER_KEY  = 'sl_pd_user_id';
var LS_FOLDER_KEY = 'sl_pd_folder_id';
var LS_THEME_KEY = 'sl_pd_theme';

// Nombre de la custom property de Office que vinculamos al documento
// plataforma ↔ .docx real (la inyecta la plataforma en "Modificar").
var META_PROP = 'plataforma_doc_id';

var authToken    = localStorage.getItem(LS_TOKEN_KEY) || '';
var currentUserId = localStorage.getItem(LS_USER_KEY) || null;
var currentFolderId = (function () {
    var v = localStorage.getItem(LS_FOLDER_KEY);
    return v && !isNaN(Number(v)) ? Number(v) : null;
})();
var activeDoc    = null;
var currentDocId = null;
var docs         = [];
var currentFolders = [];
var currentFiles = [];
var currentBreadcrumbs = [];
var listSignature = null;
var pollHandle   = null;
var checkingOut = false;
// Último documento controlado (el del id con metadatos): es el único que
// ofrece la acción "Editar en Word" en el explorador.
var highlightDocId = null;

// ============================================================
//  INICIALIZACIÓN SEGURA
// ============================================================
Office.onReady(function (info) {
    // Retardo diferido para permitir que Script Lab inyecte el HTML por completo
    setTimeout(initAddIn, 100);
});

function initAddIn() {
    function $(id) { return document.getElementById(id); }

    var statusBox = $('statusBox');
    var screenLogin = $('screen-login');
    var screenDocs = $('screen-docs');
    var docsList = $('docsList');
    var busyCheckin = false;
    var heartbeatHandle = null;
    // Vista de sesión: el documento abierto en Word trae `plataforma_doc_id` y
    // coincide con un bloqueo activo del usuario → se oculta la navegación y se
    // muestra SOLO el panel de Guardar versión hasta el Check-In.
    var metadataSessionMode = false;
    // Último `plataforma_doc_id` con estado inválido visto: evita repetir el
    // mismo aviso en cada tic del polling cuando el doc abierto no es editable.
    var lastRejectedProp = null;
    // Evita liberar dos veces el mismo bloqueo si pagehide y beforeunload
    // (o un reintento del DOM) llegan juntos al cerrar Word/apagar.
    var lockReleasedOnClose = false;

    if (!statusBox || !screenLogin || !screenDocs || !docsList) {
        setTimeout(initAddIn, 200);
        return;
    }

    initTheme();

    function setStatus(msg, type) {
        var box = $('statusBox');
        if (!box) return;
        box.textContent = msg;
        box.className = 'status show alert-' + (type || 'info');
        clearTimeout(box._timer);
        box._timer = setTimeout(function () { box.classList.remove('show'); }, 8000);
    }

    function setButton(btn, loading) {
        if (!btn) return;
        if (loading) { btn.dataset.text = btn.textContent; btn.textContent = 'Procesando...'; }
        else if (btn.dataset.text) { btn.textContent = btn.dataset.text; }
    }

    function escapeHtml(v) {
        return String(v || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function showScreen(name) {
        var a = $('screen-login'), b = $('screen-docs');
        if (a) a.classList.toggle('hidden', name !== 'login');
        if (b) b.classList.toggle('hidden', name !== 'docs');
    }

    function showActiveBanner(doc) {
        var t = $('activeDocTitle'), b = $('activeBanner');
        if (t) t.textContent = doc ? doc.title : '\u2014';
        if (b) b.classList.toggle('hidden', !doc);
    }

    // Tema claro/oscuro: se persiste en localStorage y, si no hay preferencia,
    // se respeta el tema del sistema.
    function applyTheme(dark) {
        var root = document.documentElement || document.body;
        root.setAttribute('data-theme', dark ? 'dark' : 'light');
        var t = $('btnTheme');
        if (t) t.textContent = dark ? '\u2600\uFE0F' : '\uD83C\uDF19';
    }
    function initTheme() {
        var stored = localStorage.getItem(LS_THEME_KEY);
        var dark = stored
            ? stored === 'dark'
            : !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        applyTheme(dark);
        var t = $('btnTheme');
        if (t && !t.dataset.hooked) {
            t.dataset.hooked = '1';
            t.addEventListener('click', function () {
                var root = document.documentElement || document.body;
                var next = root.getAttribute('data-theme') !== 'dark';
                applyTheme(next);
                localStorage.setItem(LS_THEME_KEY, next ? 'dark' : 'light');
            });
        }
    }

    function api(path, opts) {
        opts = opts || {};
        var headers = {
            'Accept': 'application/json'
        };
        if (opts.headers) {
            Object.keys(opts.headers).forEach(function (k) { headers[k] = opts.headers[k]; });
        }
        if (authToken) headers['Authorization'] = 'Bearer ' + authToken;
        if (opts.body && !(opts.body instanceof FormData) && !headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
        }
        return fetch(BASE_URL + path, {
            method: opts.method || 'GET',
            headers: headers,
            body: opts.body || undefined
        }).then(function (res) {
            var ct = res.headers.get('content-type') || '';
            var p = ct.indexOf('application/json') !== -1 ? res.json() : res.blob();
            return p.then(function (data) {
                if (res.status === 401 && path !== API.login) {
                    sessionExpired();
                    throw new Error('Tu sesión expiró o ya no está autenticada. Vuelve a iniciar sesión.');
                }
                if (!res.ok) {
                    var err = new Error((data && (data.message || data.error)) || 'Error HTTP ' + res.status);
                    err.status = res.status;
                    throw err;
                }
                return { status: res.status, data: data };
            });
        });
    }

    function doLogin() {
        var emailEl = $('loginEmail'), passEl = $('loginPassword');
        var email = emailEl ? emailEl.value.trim() : '';
        var password = passEl ? passEl.value : '';
        if (!email || !password) return setStatus('Introduce tu correo y contraseña.', 'err');

        var btn = $('btnLogin');
        setButton(btn, true);
        return api(API.login, {
            method: 'POST',
            body: JSON.stringify({ email: email, password: password })
        }).then(function (res) {
            var d = res.data;
            if (!d.token) throw new Error('La API no devolvió un token.');
            authToken = d.token;
            currentUserId = d.user ? String(d.user.id) : null;
            localStorage.setItem(LS_TOKEN_KEY, authToken);
            if (currentUserId) localStorage.setItem(LS_USER_KEY, currentUserId);
            currentFolderId = null;
            localStorage.removeItem(LS_FOLDER_KEY);
            lastRejectedProp = null;
            setStatus('Sesión iniciada.', 'ok');
            if (passEl) passEl.value = '';
            showScreen('docs');
            startPolling();
            return loadDocuments().then(function () {
                return detectActiveEditSession();
            });
        }).catch(function (e) {
            setStatus('Login fallido: ' + e.message, 'err');
        }).finally(function () { setButton(btn, false); });
    }

    function loadDocuments(silent) {
        var list = $('docsList');
        if (!list) return Promise.resolve();
        if (!silent) list.innerHTML = '<p class="muted">Cargando contenido...</p>';
        var url = API.contents + (currentFolderId ? '?folder_id=' + encodeURIComponent(currentFolderId) : '');
        return api(url).then(function (res) {
            docs = res.data.documents || [];
            currentFolders = res.data.folders || [];
            currentFiles = res.data.files || [];
            currentBreadcrumbs = res.data.breadcrumbs || [];
            var sig = contentsSignature();
            if (!silent || sig !== listSignature) {
                listSignature = sig;
                renderContents();
            }
        }).catch(function (e) {
            if (!silent && list) list.innerHTML = '<p class="muted">Error al cargar.</p>';
            if (!silent) setStatus(e.message, 'err');
        });
    }

    function contentsSignature() {
        return JSON.stringify({
            breadcrumbs: currentBreadcrumbs.map(function (b) { return b.id; }),
            folders: currentFolders.map(function (f) { return f.id; }),
            docs: docs.map(function (d) {
                return [d.id, d.current_version, !!d.is_locked, d.locked_by ? d.locked_by.id : null, d.file_name, !!d.editing_cancelled_at];
            }),
            files: currentFiles.map(function (f) { return [f.id, f.file_size, f.original_name]; })
        });
    }

    function wordSafeName(title) {
        return String(title || 'documento')
            .replace(/[\\/:*?"<>|]/g, '-')
            .replace(/\s+/g, ' ')
            .trim() || 'documento';
    }

    function stopHeartbeat() {
        if (heartbeatHandle) { clearInterval(heartbeatHandle); heartbeatHandle = null; }
    }

    // Mientras dura la sesión de edición: renueva el TTL cada 25 s. Si el
    // servidor responde 409/423 (cancelada desde la plataforma, bloqueo
    // liberado o tomado por otro) se abandona la sesión.
    function startHeartbeat() {
        stopHeartbeat();
        lockReleasedOnClose = false;
        heartbeatHandle = setInterval(function () {
            if (!currentDocId || busyCheckin) return;
            api(API.heartbeat(currentDocId), { method: 'POST' }).catch(function (e) {
                if (e && (e.status === 423 || e.status === 409)) {
                    var title = activeDoc ? activeDoc.title : '';
                    var msg = (e.message && /cancelada desde la plataforma/i.test(e.message))
                        ? e.message
                        : 'Perdiste el bloqueo de "' + title + '": caducó o fue liberado por un editor.';
                    exitCheckinView(false, msg, true);
                }
            });
        }, 25000);
    }

    // Actualización automática cada 8 s: sincroniza los estados Disponible /
    // Bloqueado y detecta cambios; si no hay sesión activa, además reintenta
    // detectar el documento abierto por metadatos (ahí entra el auto-lanzar la
    // vista de Guardar versión al abrir un .docx con `plataforma_doc_id`).
    function startPolling() {
        stopPolling();
        pollHandle = setInterval(function () {
            if (busyCheckin || checkingOut) return;
            loadDocuments(true);
            if (!metadataSessionMode) detectActiveEditSession(true);
        }, 8000);
    }

    function stopPolling() {
        if (pollHandle) { clearInterval(pollHandle); pollHandle = null; }
    }

    function openFolder(id) {
        currentFolderId = id ? Number(id) : null;
        if (currentFolderId) localStorage.setItem(LS_FOLDER_KEY, String(currentFolderId));
        else localStorage.removeItem(LS_FOLDER_KEY);
        loadDocuments();
    }

    function renderContents() {
        var list = $('docsList');
        if (!list) return;
        list.innerHTML = '';

        // Migas de pan: Este equipo / carpeta / subcarpeta
        var bc = document.createElement('div');
        bc.className = 'folder-breadcrumb';
        bc.style.cssText = 'display:flex;flex-wrap:wrap;align-items:center;gap:4px;margin:2px 0 8px;font-size:12px;';
        var home = document.createElement('button');
        home.type = 'button';
        home.style.cssText = 'border:0;background:transparent;padding:4px 6px;border-radius:6px;color:var(--accent-text);font-weight:700;cursor:pointer;';
        home.textContent = '\u2302 Este equipo';
        home.addEventListener('click', function () { openFolder(null); });
        bc.appendChild(home);
        currentBreadcrumbs.forEach(function (crumb, index) {
            var sep = document.createElement('span'); sep.textContent = '/'; sep.style.cssText = 'color:var(--muted-2);';
            bc.appendChild(sep);
            var crumbBtn = document.createElement('button');
            crumbBtn.type = 'button';
            crumbBtn.textContent = escapeHtml(crumb.name);
            var isLast = index === currentBreadcrumbs.length - 1;
            crumbBtn.style.cssText = 'border:0;background:transparent;padding:4px 6px;border-radius:6px;' +
                (isLast ? 'color:var(--text);font-weight:700;' : 'color:var(--accent-text);cursor:pointer;');
            if (!isLast) crumbBtn.addEventListener('click', (function (id) { return function () { openFolder(id); }; })(crumb.id));
            bc.appendChild(crumbBtn);
        });
        list.appendChild(bc);

        var total = currentFolders.length + docs.length + currentFiles.length;

        // Subcarpetas (clic para entrar)
        currentFolders.forEach(function (folder) {
            var row = document.createElement('div');
            row.className = 'folder-row';
            row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:10px 2px;border-bottom:1px solid var(--border);cursor:pointer;';
            row.innerHTML = '<span style="color:var(--accent-text);font-size:15px;">&#128449;</span>' +
                '<strong style="color:var(--accent-text);">' + escapeHtml(folder.name) + '</strong>';
            row.addEventListener('click', (function (id) { return function () { openFolder(id); }; })(folder.id));
            list.appendChild(row);
        });

        // Documentos: "Editar en Word" descarga el .docx REAL con los metadatos
        // (fidelidad 100 %). El Check-In NO se ofrece aquí: solo la vista de
        // sesión activada al detectar `plataforma_doc_id` en el archivo abierto.
        docs.forEach(function (doc) {
            var locked = !!doc.is_locked;
            var lockedByName = '';
            if (doc.locked_by) {
                lockedByName = typeof doc.locked_by === 'object' ? (doc.locked_by.name || '') : String(doc.locked_by);
            }
            var lockedByMe = locked && doc.locked_by && currentUserId &&
                String(doc.locked_by.id) === String(currentUserId);
            // Solo el documento que acabamos de controlar (el del id con metadatos)
            // ofrece la acción "Editar en Word"; el resto se muestra como exploración.
            var isTarget = highlightDocId != null && String(doc.id) === String(highlightDocId);

            var el = document.createElement('div');
            el.className = 'doc-item' + (isTarget ? ' doc-item-target' : '');
            var html =
                '<div class="doc-head"><strong>' + escapeHtml(doc.title) + '</strong>' +
                '<span class="badge ' + (locked ? 'badge-locked' : 'badge-free') + '">' +
                (locked ? 'Bloqueado' : 'Disponible') + '</span>' +
                (doc.current_version ? '<span class="badge badge-version">v' + escapeHtml(doc.current_version) + '</span>' : '') +
                '</div>';
            if (lockedByName) html += '<p class="meta">Bloqueado por: ' + escapeHtml(lockedByName) + '</p>';
            if (isTarget) {
                html += '<button class="btn btn-primary btn-block" ' +
                    (locked && !lockedByMe ? 'disabled' : '') + '>' +
                    (locked && !lockedByMe ? 'Ocupado por otro usuario' : 'Editar en Word') + '</button>';
            }
            el.innerHTML = html;

            var btn = el.querySelector('button');
            if (btn) {
                if (locked && !lockedByMe) {
                    btn.addEventListener('click', function () {
                        setStatus('"' + doc.title + '" está bloqueado por ' + (lockedByName || 'otro usuario') + '.', 'err');
                    });
                } else if (lockedByMe) {
                    // Ya lo bloqueaste: verificamos si el documento ABUERTO en
                    // Word (con metadatos) sigue siendo una sesión tuya. Si el
                    // bloqueo caducó o fue cancelado, se re-descarga con "Editar
                    // en Word" (startModify) para renovar la sesión.
                    btn.addEventListener('click', function () {
                        detectActiveEditSession().then(function (sessDoc) {
                            if (!sessDoc) startModify(doc);
                        });
                    });
                } else {
                    btn.addEventListener('click', (function (d) { return function () { startModify(d); }; })(doc));
                }
            }
            list.appendChild(el);
        });

        // Archivos: si es Word y aún no está vinculado a un documento, se puede
        // vincular directamente desde el panel para editarlo con el Add-in.
        currentFiles.forEach(function (file) {
            var size = file.file_size ? (file.file_size / 1024).toFixed(1) + ' KB' : '';
            var row = document.createElement('div');
            row.className = 'file-row';
            row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:10px 2px;border-bottom:1px solid var(--border);';
            row.innerHTML = '<span style="color:var(--muted-2);font-size:15px;">&#128196;</span>' +
                '<div style="min-width:0;"><strong style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(file.original_name) + '</strong>' +
                '<small class="meta">' + escapeHtml(file.mime_type || '') + (size ? ' \u00b7 ' + size : '') + '</small></div>';
            list.appendChild(row);
        });

        if (!total) list.innerHTML += '<p class="muted">Esta carpeta está vacía.</p>';
    }

    // ============================================================
    //  DETECCIÓN DE SESIÓN POR METADATOS
    // ============================================================
    // La plataforma inyecta la custom property `plataforma_doc_id` en el `.docx`
    // real que descarga con "Modificar". Al leer esa propiedad del documento
    // abierto en Word y validarla contra /session, activamos la vista de
    // Guardar versión (y ocultamos la navegación por carpetas).

    function readPlataformaDocId(retriesLeft) {
        retriesLeft = retriesLeft === undefined ? 3 : retriesLeft;
        return new Promise(function (resolve) {
            try {
                Word.run(function (context) {
                    var prop = context.document.properties.customProperties.getItemOrNullObject(META_PROP);
                    return context.sync().then(function () {
                        if (!prop || prop.isNullObject) { return resolve(null); }
                        prop.load('value');
                        return context.sync().then(function () {
                            resolve(prop.value == null ? null : String(prop.value));
                        });
                    });
                }).catch(function (e) {
                    // Word puede estar todavía cargando el documento (p. ej.
                    // justo después de abrirlo): reintentamos un par de veces
                    // antes de rendirnos.
                    if (retriesLeft > 0) {
                        setTimeout(function () {
                            readPlataformaDocId(retriesLeft - 1).then(resolve);
                        }, 700);
                    } else {
                        resolve(null);
                    }
                });
            } catch (e) { resolve(null); }
        });
    }

    function detectActiveEditSession(quiet) {
        if (!authToken) return Promise.resolve(null);
        return readPlataformaDocId().then(function (propId) {
            if (!propId) {
                if (metadataSessionMode) exitCheckinView(false);
                // Documento abierto sin metadato de PlataformaDoc: en llamadas
                // manuales ("Detectar documento") se explica por qué no aparece
                // la vista de Guardar versión.
                if (!quiet && !metadataSessionMode && lastRejectedProp !== '__noMeta__') {
                    lastRejectedProp = '__noMeta__';
                    setStatus('El documento abierto en Word no es de PlataformaDoc. Descárgalo con "Editar en Word" y ábrelo aquí.', 'warn');
                }
                return null;
            }
            lastRejectedProp = null;
            return api(API.session(propId)).then(function (res) {
                var s = res.data.session || {};
                if (s.active) {
                    lastRejectedProp = null;
                    var doc = res.data.document || { id: propId, title: 'Documento ' + propId };
                    // No repetir el aviso ni reiniciar el lugar de latido en cada
                    // tic del polling mientras la sesión ya está activa.
                    if (!metadataSessionMode || String(currentDocId) !== String(doc.id)) enterCheckinView(doc);
                    return doc;
                }
                if (metadataSessionMode) exitCheckinView(false);
                if (!quiet || lastRejectedProp !== propId) {
                    lastRejectedProp = propId;
                    if (s.cancelled) {
                        setStatus('La edición de este documento fue cancelada desde la plataforma. Usa "Editar en Word" para descargarlo de nuevo.', 'err');
                    } else {
                        setStatus('El documento abierto no está en una sesión de edición tuya: no puedes guardar cambios desde aquí.', 'warn');
                    }
                }
                return null;
            }).catch(function (e) {
                if (metadataSessionMode) exitCheckinView(false);
                if (!quiet) {
                    setStatus('No se pudo verificar la sesión: ' + (e.message || 'error desconocido') + '. Verifica la URL de producción y que hayas iniciado sesión.', 'err');
                }
                return null;
            });
        }).catch(function () { return null; });
    }

    // Activa la vista de sesión: oculta la lista y muestra solo Guardar versión.
    function enterCheckinView(doc) {
        metadataSessionMode = true;
        currentDocId = doc.id;
        activeDoc = doc;
        highlightDocId = doc.id;
        currentFolderId = null;
        localStorage.removeItem(LS_FOLDER_KEY);
        showActiveBanner(doc);
        var recBtn = $('btnRecheck'); if (recBtn) recBtn.classList.add('hidden');
        var fldrBtn = $('btnFolderUp'); if (fldrBtn) fldrBtn.classList.add('hidden');
        var list = $('docsList'); if (list) list.style.display = 'none';
        var edit = $('checkinEdit'), saved = $('checkinSaved');
        if (saved) saved.classList.add('hidden');
        if (edit) edit.classList.remove('hidden');
        var badge = $('activeBadge'); if (badge) badge.textContent = 'Editando';
        var p = $('checkinPanel'); if (p) p.classList.remove('hidden');
        startHeartbeat();
        setStatus('Documento "' + doc.title + '" listo: edítalo en Word y pulsa "Guardar versión".', 'ok');
    }

    // Abandona la sesión; `release` true libera el bloqueo (usuario sale sin
    // guardar), false si ya no es nuestro (bloqueo perdido/cancelado).
    function exitCheckinView(release, msg, warnAsErr) {
        var docId = currentDocId;
        metadataSessionMode = false;
        currentDocId = null;
        activeDoc = null;
        showActiveBanner(null);
        stopHeartbeat();
        var recBtn = $('btnRecheck'); if (recBtn) recBtn.classList.remove('hidden');
        var fldrBtn = $('btnFolderUp'); if (fldrBtn) fldrBtn.classList.remove('hidden');
        var edit = $('checkinEdit'), saved = $('checkinSaved');
        if (saved) saved.classList.add('hidden');
        if (edit) edit.classList.remove('hidden');
        var badge = $('activeBadge'); if (badge) badge.textContent = 'Editando';
        var p = $('checkinPanel'); if (p) p.classList.add('hidden');
        var list = $('docsList'); if (list) list.style.display = '';
        if (release && authToken && docId) {
            var headers = { 'Accept': 'application/json' };
            headers['Authorization'] = 'Bearer ' + authToken;
            try { fetch(BASE_URL + API.unlock(docId), { method: 'POST', headers: headers, keepalive: true }); } catch (e) {}
        }
        if (msg) setStatus(msg, warnAsErr ? 'err' : 'info');
        loadDocuments(true);
    }

    // ============================================================
    //  DESCARGA NATIVA DEL .docx CON METADATOS (fidelidad 100 %)
    // ============================================================
    // No se inserta el documento (insertFileFromBase64 distorsiona el formato):
    // se descarga el `.docx` REAL con `plataforma_doc_id` y se pide abrirlo en
    // Word con Archivo > Abrir. Al abrirlo, la detección por metadatos activa
    // la vista de Guardar versión.

    function base64ToBytes(b64) {
        var clean = b64.replace(/\s/g, '');
        var raw = atob(clean);
        var bytes = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
        return bytes;
    }

    function downloadBase64File(b64, name) {
        try {
            var blob = new Blob([base64ToBytes(b64)], {
                type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            });
            if (window.navigator && navigator.msSaveBlob) {
                navigator.msSaveBlob(blob, name);
                return true;
            }
            var url;
            if (window.URL && URL.createObjectURL) {
                url = URL.createObjectURL(blob);
            } else if (window.createObjectURL) {
                url = window.createObjectURL(blob);
            } else {
                return false;
            }
            var a = document.createElement('a');
            a.href = url;
            a.download = name;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            setTimeout(function () {
                document.body.removeChild(a);
                if (window.URL && URL.revokeObjectURL) URL.revokeObjectURL(url);
            }, 5000);
            return true;
        } catch (e) {
            return false;
        }
    }

    function startModify(doc) {
        if (checkingOut) return;
        checkingOut = true;
        setStatus('Preparando "' + doc.title + '" para editar en Word...', 'info');
        return api(API.modify(doc.id), { method: 'POST' }).then(function (res) {
            var d = res.data;
            if (!d.file_base64) throw new Error('Sin file_base64 en la respuesta.');
            var name = d.file_name || (doc.title + '.docx');
            var ok = downloadBase64File(d.file_base64, name);
            setStatus(
                '"' + doc.title + '" bloqueado para ti.' + (ok
                    ? ' Se descargó "' + name + '": ábrelo en Word (Archivo > Abrir). Al abrirlo se activará "Guardar versión".'
                    : ' No se pudo descargar aquí. Usa "Modificar" en la plataforma y ábrelo en Word.'),
                ok ? 'ok' : 'warn'
            );
            return loadDocuments();
        }).catch(function (e) {
            setStatus('No se pudo preparar: ' + e.message, 'err');
            return loadDocuments(true);
        }).finally(function () { checkingOut = false; });
    }

    function linkAndEdit(file) {
        setStatus('Vinculando "' + file.original_name + '"...', 'info');
        return api(API.linkFile(file.id), { method: 'POST' }).then(function (res) {
            var linked = res.data.document;
            return loadDocuments().then(function () {
                var fresh = docs.find(function (d) { return d.id === linked.id; });
                return startModify(fresh || linked);
            });
        }).catch(function (e) {
            setStatus('No se pudo vincular: ' + e.message, 'err');
        });
    }

    // ============================================================
    //  CHECK-IN (GUARDAR VERSIÓN)
    // ============================================================

    function getDocumentBlob() {
        return new Promise(function (resolve, reject) {
            // Timeout de seguridad: jamás dejar el botón colgado en silencio
            var timeoutId = setTimeout(function () {
                reject(new Error('Tiempo agotado al leer el documento (90s). Cierra y vuelve a abrir la tarea y reintenta.'));
            }, 90000);

            function fin(err, blob) {
                clearTimeout(timeoutId);
                if (err) reject(err); else resolve(blob);
            }

            try {
                Office.context.document.getFileAsync(Office.FileType.Compressed, { sliceSize: 4194304 }, function (r) {
                    try {
                        if (r.status !== Office.AsyncResultStatus.Succeeded) {
                            return fin(new Error('No se pudo leer el documento: ' + ((r.error && r.error.message) || r.error || 'desconocido')));
                        }
                        var file = r.value;
                        if (!file || !file.sliceCount || !file.getSliceAsync) {
                            if (file && file.closeAsync) file.closeAsync(function () {});
                            return fin(new Error('El documento de Word está vacío o no se puede leer en este momento.'));
                        }
                        var proms = [];
                        for (var i = 0; i < file.sliceCount; i++) {
                            (function (idx) {
                                proms.push(new Promise(function (ok, fail) {
                                    file.getSliceAsync(idx, function (sr) {
                                        try {
                                            if (sr.status === Office.AsyncResultStatus.Succeeded) ok(sr.value.data);
                                            else fail(new Error((sr.error && sr.error.message) || ('Error leyendo parte ' + idx)));
                                        } catch (err2) { fail(err2); }
                                    });
                                }));
                            })(i);
                        }
                        Promise.all(proms).then(function (chunks) {
                            try { if (file.closeAsync) file.closeAsync(function () {}); } catch (e) {}
                            makeBlob(chunks).then(
                                function (blob) { fin(null, blob); },
                                function (e) { fin(e); }
                            );
                        }).catch(function (e) {
                            try { if (file.closeAsync) file.closeAsync(function () {}); } catch (e2) {}
                            fin(e);
                        });
                    } catch (e) {
                        fin(e);
                    }
                });
            } catch (e) {
                fin(e);
            }
        });
    }

    function toUint8(c) {
        return Promise.resolve().then(function () {
            var tag = Object.prototype.toString.call(c);
            if (tag === '[object ArrayBuffer]') return new Uint8Array(c);
            if (ArrayBuffer.isView(c)) return new Uint8Array(c.buffer, c.byteOffset, c.byteLength);
            if (typeof c === 'string') {
                var b64 = c.replace(/\-/g, '+').replace(/_/g, '/');
                if (b64.length % 4) b64 += '===='.slice(b64.length % 4);
                var b = atob(b64), o = new Uint8Array(b.length);
                for (var j = 0; j < b.length; j++) o[j] = b.charCodeAt(j);
                return o;
            }
            if (tag === '[object Blob]') return c.arrayBuffer();
            if (Array.isArray(c)) return new Uint8Array(c);
            throw new Error('Bytes inesperados de Office.js: ' + tag + '.');
        });
    }

    function makeBlob(chunks) {
        return Promise.all(chunks.map(toUint8)).then(function (arrs) {
            var total = arrs.reduce(function (s, a) { return s + a.length; }, 0);
            var out = new Uint8Array(total), off = 0;
            arrs.forEach(function (a) { out.set(a, off); off += a.length; });
            return new Blob([out], { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' });
        });
    }

    function doCheckIn() {
        if (busyCheckin) return;
        if (checkingOut) return setStatus('Espera a que termine el Check-Out del archivo.', 'info');
        var btn = $('btnCheckin');
        var notesEl = $('changeNotes');
        if (!currentDocId) { setStatus('Sin sesión activa. Usa "Editar en Word" y abre el archivo descargado en Word.', 'err'); return; }
        var notes = notesEl ? notesEl.value.trim() : '';
        if (!notes) { setStatus('Las notas de cambios son obligatorias.', 'err'); return; }
        busyCheckin = true;
        setButton(btn, true);
        setStatus('Leyendo documento en Word...', 'info');
        var nowDocId = currentDocId;
        var savedVersion = '';
        getDocumentBlob().then(function (blob) {
            var form = new FormData();
            form.append('file', blob, wordSafeName(activeDoc ? activeDoc.title : 'documento') + '.docx');
            form.append('change_notes', notes);
            setStatus('Subiendo versión...', 'info');
            return api(API.upload(nowDocId), { method: 'POST', body: form });
        }).then(function (res) {
            if (notesEl) notesEl.value = '';
            savedVersion = res.data.version ? res.data.version.version_number : '';
            setStatus('Versión ' + (savedVersion ? savedVersion + ' ' : '') + 'guardada. Liberando el bloqueo...', 'ok');
            return api(API.unlock(nowDocId), { method: 'POST' }).catch(function () { return null; });
        }).then(function () {
            enterSavedState(savedVersion);
            setStatus('Versión ' + (savedVersion ? savedVersion + ' ' : '') + 'guardada. El documento sigue disponible: vuelve a editarlo cuando quieras.', 'ok');
        }).catch(function (e) {
            setStatus('Check-In fallido: ' + e.message, 'err');
        }).finally(function () {
            setButton(btn, false);
            busyCheckin = false;
        });
    }

    // Tras guardar la versión seguimos mostrando SOLO este documento (no la
    // lista): para seguir editándolo basta con "Volver a editar en Word".
    function enterSavedState(version) {
        stopHeartbeat();
        highlightDocId = currentDocId;
        var edit = $('checkinEdit'), saved = $('checkinSaved');
        if (edit) edit.classList.add('hidden');
        if (saved) saved.classList.remove('hidden');
        var badge = $('activeBadge'); if (badge) badge.textContent = 'Guardado';
        var vEl = $('savedVersion');
        if (vEl) vEl.textContent = version ? 'Versión ' + version + ' guardada.' : 'Versión guardada.';
    }

    // Vuelve al modo de edición tras un nuevo Check-Out (re-bloqueo + descarga).
    function enterEditState() {
        var edit = $('checkinEdit'), saved = $('checkinSaved');
        if (saved) saved.classList.add('hidden');
        if (edit) edit.classList.remove('hidden');
        var badge = $('activeBadge'); if (badge) badge.textContent = 'Editando';
        var notesEl = $('changeNotes'); if (notesEl) notesEl.value = '';
        startHeartbeat();
    }

    // "Volver a editar en Word": vuelve a bloquear y descargar el documento.
    function redoEdit() {
        if (busyCheckin) return;
        if (checkingOut) return setStatus('Espera a que termine la descarga anterior.', 'info');
        if (!activeDoc) { exitCheckinView(false); return; }
        return startModify(activeDoc).then(function () {
            if (!metadataSessionMode) return;
            enterEditState();
            setStatus('"' + activeDoc.title + '" bloqueado y descargado de nuevo. Ábrelo en Word (Archivo > Abrir), edítalo y guarda la nueva versión aquí.', 'ok');
        });
    }

    // Salir sin guardar: libera el bloqueo y vuelve a la lista.
    function doUnlock() {
        if (!currentDocId) return setStatus('Sin sesión activa.', 'err');
        var title = activeDoc ? activeDoc.title : 'documento';
        setStatus('Liberando el bloqueo de "' + title + '" y volviendo a la lista...', 'info');
        exitCheckinView(true, 'Edición "' + title + '" cancelada y bloqueo liberado.');
    }

    // Libera el bloqueo sin esperar respuesta: se usa al cerrar el panel, al
    // cerrar Word o al salir de sesión para que el documento no quede bloqueado.
    function releaseLockQuietly() {
        if (!authToken || !currentDocId || busyCheckin || lockReleasedOnClose) return;
        lockReleasedOnClose = true;
        stopHeartbeat();
        var headers = { 'Accept': 'application/json' };
        if (authToken) headers['Authorization'] = 'Bearer ' + authToken;
        try {
            fetch(BASE_URL + API.unlock(currentDocId), { method: 'POST', headers: headers, keepalive: true });
        } catch (e) {}
    }

    // Sesión caducada o token inválido: limpia el estado y vuelve al login.
    function sessionExpired() {
        stopPolling();
        stopHeartbeat();
        authToken = ''; currentDocId = null; activeDoc = null;
        metadataSessionMode = false;
        localStorage.removeItem(LS_TOKEN_KEY);
        localStorage.removeItem(LS_USER_KEY);
        localStorage.removeItem(LS_FOLDER_KEY);
        currentFolderId = null;
        showActiveBanner(null);
        var recBtn = $('btnRecheck'); if (recBtn) recBtn.classList.remove('hidden');
        var p = $('checkinPanel'); if (p) p.classList.add('hidden');
        var list = $('docsList'); if (list) list.style.display = '';
        showScreen('login');
        setStatus('Tu sesión expiró. Vuelve a iniciar sesión.', 'err');
    }

    function logout() {
        releaseLockQuietly();
        // Avísale al servidor que cerramos sesión para que libere CUALQUIER
        // bloqueo de este usuario (aunque no sea el doc activo del panel).
        if (authToken) {
            var lheaders = { 'Accept': 'application/json' };
            lheaders['Authorization'] = 'Bearer ' + authToken;
            try { fetch(BASE_URL + API.logout, { method: 'POST', headers: lheaders, keepalive: true }); } catch (e) {}
        }
        stopPolling();
        stopHeartbeat();
        authToken = ''; currentDocId = null; activeDoc = null;
        metadataSessionMode = false;
        localStorage.removeItem(LS_TOKEN_KEY);
        localStorage.removeItem(LS_USER_KEY);
        localStorage.removeItem(LS_FOLDER_KEY);
        currentFolderId = null;
        showActiveBanner(null);
        var recBtn = $('btnRecheck'); if (recBtn) recBtn.classList.remove('hidden');
        var p = $('checkinPanel'); if (p) p.classList.add('hidden');
        var list = $('docsList'); if (list) list.style.display = '';
        showScreen('login'); setStatus('Sesión cerrada.', 'info');
    }

    // Cierre del host o apagado: tanto `pagehide` como `beforeunload` se disparan
    // cuando se cierra la ventana/tarea de Word (y en la web al cerrar la
    // pestaña). En cuanto se cierra, se libera el bloqueo al instante con una
    // petición keepalive. NO se usa `visibilitychange` porque Minimizar o
    // Alt+Tab también lo dispara sin cerrar la aplicación. Si el host no emite
    // estos eventos (cierre forzado, apagado, Word que se cuelga), queda el
    // respaldo del TTL corto (3 min) con la purga automática cada 8 s.
    window.addEventListener('pagehide', releaseLockQuietly);
    window.addEventListener('beforeunload', releaseLockQuietly);

    // Eventos
    var el;
    el = $('btnLogin');      if (el) el.addEventListener('click', doLogin);
    el = $('loginEmail');    if (el) el.addEventListener('keydown', function (e) { if (e.key === 'Enter') doLogin(); });
    el = $('loginPassword'); if (el) el.addEventListener('keydown', function (e) { if (e.key === 'Enter') doLogin(); });
    el = $('btnRecheck');    if (el) el.addEventListener('click', function () { detectActiveEditSession(); });
    el = $('btnRefresh');    if (el) el.addEventListener('click', loadDocuments);
    el = $('btnCheckin');    if (el) el.addEventListener('click', doCheckIn);
    el = $('btnUnlock');     if (el) el.addEventListener('click', doUnlock);
    el = $('btnReedit');     if (el) el.addEventListener('click', redoEdit);
    el = $('btnExitSaved');  if (el) el.addEventListener('click', function () { exitCheckinView(false); });
    el = $('btnLogout2');    if (el) el.addEventListener('click', logout);
    el = $('btnLogout');     if (el) el.addEventListener('click', logout);

    // Arranque: partimos del panel principal, SIN restaurar sesiones previas.
    // La detección por metadatos (botón "Detectar documento" y el polling de
    // 8 s) activa la vista de Guardar versión cuando el documento abierto en
    // Word es un .docx con `plataforma_doc_id` de una edición vigente del
    // usuario. Si Word se cerró de golpe, el TTL corto libera el bloqueo.
    if (authToken) {
        showScreen('docs');
        currentDocId = null; activeDoc = null;
        metadataSessionMode = false;
        lastRejectedProp = null;
        startPolling();
        loadDocuments().then(function () {
            // Pequeña demora: verifica si el documento ABUERTO ya trae metadatos.
            setTimeout(function () {
                if (!metadataSessionMode && !checkingOut && !busyCheckin) detectActiveEditSession();
            }, 1200);
        });
    } else {
        showScreen('login');
    }
}
