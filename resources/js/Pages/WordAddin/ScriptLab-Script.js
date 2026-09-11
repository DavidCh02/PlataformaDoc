'use strict';

// ============================================================
//  CONFIGURACION — EDITA ESTA SECCION
// ============================================================
// Punto de entrada de la API REST. Para desarrollo local con
// ngrok usa la URL https de tu tunel (p.ej. https://abc123.ngrok-free.app/api/addin).
// NOTA: Script Lab ejecuta en contexto HTTPS; http://localhost sera bloqueado
// por mixed-content. Usa SIEMPRE https (ngrok, tunnel, o tu servidor HTTPS).
var BASE_URL = 'https://TU-TUNEL.ngrok-free.app/api/addin'; // <-- EDITABLE

// Rutas relativas a BASE_URL (ajustalas si tu backend difiere)
var API = {
    login:     '/login',
    documents: '/documents',
    checkout:  function (id) { return '/documents/' + id + '/checkout'; },
    upload:    function (id) { return '/documents/' + id + '/upload'; }
};

// Claves de persistencia (localStorage)
var LS_TOKEN_KEY = 'sl_plataformadoc_token';
var LS_DOC_KEY   = 'sl_plataformadoc_doc_id';

// Estado global
var authToken    = localStorage.getItem(LS_TOKEN_KEY) || '';
var currentDocId = localStorage.getItem(LS_DOC_KEY) || null;
var activeDoc    = null;
var docs         = [];

// ============================================================
//  INICIALIZACION — TODO el DOM se manipula AQUI dentro
// ============================================================
Office.onReady(function (info) {

    // ---- Verificacion de entorno ----
    if (info.host !== Office.HostType.Word) {
        console.warn('Este snippet esta disenado para Word. Host actual: ' + info.host);
    }

    // ---- Helper DOM seguro (devuelve null si no existe) ----
    function $(id) { return document.getElementById(id); }

    // ---- Verificar que los elementos criticos existen ----
    var statusBox = $('statusBox');
    var screenLogin = $('screen-login');
    var screenDocs = $('screen-docs');
    if (!statusBox || !screenLogin || !screenDocs) {
        console.error('[PlataformaDoc] Elementos del DOM no encontrados. Revisa la pestana HTML.');
        return;
    }

    // ---- Utilidades ----
    function setStatus(message, type) {
        var box = $('statusBox');
        if (!box) return;
        box.textContent = message;
        box.className = 'status show alert-' + (type || 'info');
        clearTimeout(box._timer);
        box._timer = setTimeout(function () { box.classList.remove('show'); }, 7000);
    }

    function setButton(btn, loading) {
        if (!btn) return;
        btn.disabled = loading;
        if (loading) {
            btn.dataset.text = btn.textContent;
            btn.textContent = 'Procesando...';
        } else if (btn.dataset.text) {
            btn.textContent = btn.dataset.text;
        }
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function showScreen(name) {
        var sLogin = $('screen-login');
        var sDocs  = $('screen-docs');
        if (sLogin) sLogin.classList.toggle('hidden', name !== 'login');
        if (sDocs)  sDocs.classList.toggle('hidden', name !== 'docs');
    }

    function showActiveBanner(doc) {
        var title  = $('activeDocTitle');
        var banner = $('activeBanner');
        if (title)  title.textContent = doc ? doc.title : '\u2014';
        if (banner) banner.classList.toggle('hidden', !doc);
    }

    // ---- Cliente API (Accept + ngrok-skip-browser-warning en TODAS) ----
    function api(path, options) {
        options = options || {};
        var headers = {
            'Accept': 'application/json',
            'ngrok-skip-browser-warning': 'true'
        };
        // Fusionar headers personalizados
        if (options.headers) {
            var keys = Object.keys(options.headers);
            for (var i = 0; i < keys.length; i++) { headers[keys[i]] = options.headers[keys[i]]; }
        }
        if (authToken) headers['Authorization'] = 'Bearer ' + authToken;

        if (options.body && !(options.body instanceof FormData) && !headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
        }

        return fetch(BASE_URL + path, {
            method: options.method || 'GET',
            headers: headers,
            body: options.body || undefined
        })
        .then(function (response) {
            var contentType = response.headers.get('content-type') || '';
            var dataPromise = contentType.indexOf('application/json') !== -1
                ? response.json()
                : response.blob();
            return dataPromise.then(function (data) {
                if (!response.ok) {
                    var msg = (data && (data.message || data.error)) || ('Error HTTP ' + response.status);
                    throw new Error(msg);
                }
                return { status: response.status, data: data };
            });
        })
        .catch(function (err) {
            if (err.message === 'unauthorized') throw err;
            throw err;
        });
    }

    // ---- LOGIN ----
    function doLogin() {
        var emailEl = $('loginEmail');
        var passEl  = $('loginPassword');
        var email = emailEl ? emailEl.value.trim() : '';
        var password = passEl ? passEl.value : '';
        if (!email || !password) return setStatus('Introduce tu correo y contrasena.', 'err');

        var btn = $('btnLogin');
        setButton(btn, true);

        return api(API.login, {
            method: 'POST',
            body: JSON.stringify({ email: email, password: password })
        })
        .then(function (res) {
            var data = res.data;
            if (!data.token) throw new Error('La API no devolvio un token.');
            authToken = data.token;
            currentDocId = null;
            activeDoc = null;
            localStorage.setItem(LS_TOKEN_KEY, authToken);
            localStorage.removeItem(LS_DOC_KEY);
            setStatus('Sesion iniciada correctamente.', 'ok');
            if (passEl) passEl.value = '';
            showScreen('docs');
            return loadDocuments();
        })
        .catch(function (err) {
            setStatus('No se pudo iniciar sesion: ' + err.message, 'err');
        })
        .finally(function () {
            setButton(btn, false);
        });
    }

    // ---- LISTA DE DOCUMENTOS ----
    function loadDocuments() {
        var list = $('docsList');
        if (!list) return Promise.resolve();
        list.innerHTML = '<p class="muted">Cargando documentos...</p>';

        return api(API.documents)
        .then(function (res) {
            docs = res.data.documents || res.data.data || [];
            renderDocuments();
        })
        .catch(function (err) {
            if (list) list.innerHTML = '<p class="muted">No se pudieron cargar los documentos.</p>';
            setStatus('Error al listar: ' + err.message, 'err');
        });
    }

    function renderDocuments() {
        var list = $('docsList');
        if (!list) return;
        list.innerHTML = '';
        if (!docs.length) {
            list.innerHTML = '<p class="muted">No hay documentos Word disponibles.</p>';
            return;
        }
        for (var i = 0; i < docs.length; i++) {
            (function (doc) {
                var locked = !!doc.is_locked;
                var el = document.createElement('div');
                el.className = 'doc-item';
                el.innerHTML =
                    '<div class="doc-head">' +
                        '<strong>' + escapeHtml(doc.title) + '</strong>' +
                        '<span class="badge ' + (locked ? 'badge-locked' : 'badge-free') + '">' +
                            (locked ? 'Bloqueado' : 'Disponible') +
                        '</span>' +
                        (doc.current_version
                            ? '<span class="badge badge-version">v' + escapeHtml(doc.current_version) + '</span>'
                            : '') +
                    '</div>' +
                    (doc.locked_by
                        ? '<p class="meta">Bloqueado por: ' + escapeHtml(doc.locked_by.name || doc.locked_by) + '</p>'
                        : '') +
                    '<button class="btn btn-primary btn-block" data-id="' + doc.id + '">Editar en Word</button>';

                var btn = el.querySelector('button');
                if (btn) {
                    btn.addEventListener('click', function () { checkoutDocument(doc); });
                }
                list.appendChild(el);
            })(docs[i]);
        }
    }

    // ---- CHECK-OUT ----
    function checkoutDocument(doc) {
        setStatus('Realizando Check-Out de "' + doc.title + '"...', 'info');

        return api(API.checkout(doc.id), { method: 'POST' })
        .then(function (res) {
            var data = res.data;
            if (!data.file_base64) throw new Error('La respuesta del checkout no incluye file_base64.');

            setStatus('Insertando el documento en Word...', 'info');
            return Word.run(function (context) {
                var body = context.document.body;
                body.clear();
                return context.sync().then(function () {
                    body.insertFileFromBase64(data.file_base64, Word.InsertLocation.replace);
                    return context.sync();
                });
            });
        })
        .then(function () {
            currentDocId = doc.id;
            activeDoc = doc;
            localStorage.setItem(LS_DOC_KEY, String(doc.id));
            showActiveBanner(doc);
            var panel = $('checkinPanel');
            if (panel) panel.classList.remove('hidden');
            setStatus('Documento "' + doc.title + '" en edicion. Ya puedes escribir y luego hacer Check-In.', 'ok');
            return loadDocuments();
        })
        .catch(function (err) {
            setStatus('Check-Out fallido: ' + err.message, 'err');
        });
    }

    // ---- CHECK-IN (getFileAsync + Promise.all slices) ----
    function getDocumentBlob() {
        return new Promise(function (resolve, reject) {
            Office.context.document.getFileAsync(
                Office.FileType.Compressed,
                { sliceSize: 4194304 },
                function (result) {
                    if (result.status !== Office.AsyncResultStatus.Succeeded) {
                        return reject(new Error('No se pudo leer el documento: ' + result.error.message));
                    }
                    var file = result.value;
                    if (!file.sliceCount) {
                        file.closeAsync(function () {});
                        return reject(new Error('El documento abierto esta vacio.'));
                    }
                    var slicePromises = [];
                    for (var i = 0; i < file.sliceCount; i++) {
                        (function (index) {
                            slicePromises.push(new Promise(function (res, rej) {
                                file.getSliceAsync(index, function (sr) {
                                    if (sr.status === Office.AsyncResultStatus.Succeeded) {
                                        res(sr.value.data);
                                    } else {
                                        rej(new Error('No se pudo leer un fragmento: ' + sr.error.message));
                                    }
                                });
                            }));
                        })(i);
                    }
                    Promise.all(slicePromises)
                        .then(function (chunks) {
                            file.closeAsync(function () {});
                            resolve(concatSlices(chunks));
                        })
                        .catch(function (err) {
                            file.closeAsync(function () {});
                            reject(err);
                        });
                }
            );
        });
    }

    function concatSlices(chunks) {
        var arrays = chunks.map(function (chunk) {
            if (chunk instanceof ArrayBuffer) return new Uint8Array(chunk);
            if (ArrayBuffer.isView(chunk)) return new Uint8Array(chunk.buffer, chunk.byteOffset, chunk.byteLength);
            if (Array.isArray(chunk)) return new Uint8Array(chunk);
            if (typeof chunk === 'string') {
                var bin = atob(chunk);
                var out = new Uint8Array(bin.length);
                for (var j = 0; j < bin.length; j++) out[j] = bin.charCodeAt(j);
                return out;
            }
            throw new Error('Formato de bytes inesperado devuelto por Office.js.');
        });
        var total = arrays.reduce(function (s, a) { return s + a.length; }, 0);
        var merged = new Uint8Array(total);
        var offset = 0;
        for (var k = 0; k < arrays.length; k++) { merged.set(arrays[k], offset); offset += arrays[k].length; }
        return new Blob([merged], { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' });
    }

    function doCheckIn() {
        if (!currentDocId) return setStatus('No hay un documento en edicion (primero haz Check-Out).', 'err');
        var notesEl = $('changeNotes');
        var notes = notesEl ? notesEl.value.trim() : '';
        if (!notes) return setStatus('Las notas de cambios son obligatorias.', 'err');

        var btn = $('btnCheckin');
        setButton(btn, true);

        setStatus('Leyendo el documento abierto en Word...', 'info');
        return getDocumentBlob()
        .then(function (blob) {
            var form = new FormData();
            form.append('file', blob, (activeDoc ? activeDoc.title : 'documento') + '.docx');
            form.append('change_notes', notes);
            setStatus('Subiendo nueva version (Check-In)...', 'info');
            return api(API.upload(currentDocId), { method: 'POST', body: form });
        })
        .then(function (res) {
            var data = res.data;
            if (notesEl) notesEl.value = '';
            var version = data.version && data.version.version_number ? data.version.version_number : '';
            setStatus('Version ' + version + ' guardada en la plataforma (Check-In completado).', 'ok');
            return loadDocuments();
        })
        .catch(function (err) {
            setStatus('Check-In fallido: ' + err.message, 'err');
        })
        .finally(function () {
            setButton(btn, false);
        });
    }

    // ---- LOGOUT ----
    function logout() {
        authToken = '';
        currentDocId = null;
        activeDoc = null;
        localStorage.removeItem(LS_TOKEN_KEY);
        localStorage.removeItem(LS_DOC_KEY);
        showActiveBanner(null);
        var panel = $('checkinPanel');
        if (panel) panel.classList.add('hidden');
        showScreen('login');
        setStatus('Sesion cerrada.', 'info');
    }

    // ---- Bind eventos (despues de confirmar DOM) ----
    var btnLogin = $('btnLogin');
    var btnRefresh = $('btnRefresh');
    var btnCheckin = $('btnCheckin');
    var btnLogout = $('btnLogout');
    var loginEmail = $('loginEmail');
    var loginPassword = $('loginPassword');

    if (btnLogin) btnLogin.addEventListener('click', doLogin);
    if (loginEmail) loginEmail.addEventListener('keydown', function (e) { if (e.key === 'Enter') doLogin(); });
    if (loginPassword) loginPassword.addEventListener('keydown', function (e) { if (e.key === 'Enter') doLogin(); });
    if (btnRefresh) btnRefresh.addEventListener('click', function () { loadDocuments(); });
    if (btnCheckin) btnCheckin.addEventListener('click', doCheckIn);
    if (btnLogout) btnLogout.addEventListener('click', logout);

    // ---- Arranque ----
    if (BASE_URL.indexOf('TU-TUNEL') !== -1) {
        setStatus('Configura BASE_URL en el Script con la URL https de tu servidor (p.ej. ngrok).', 'err');
    }

    if (authToken) {
        showScreen('docs');
        loadDocuments().then(function () {
            var savedDocId = localStorage.getItem(LS_DOC_KEY);
            if (savedDocId) {
                var saved = docs.find(function (d) { return String(d.id) === savedDocId; });
                if (saved) {
                    currentDocId = saved.id;
                    activeDoc = saved;
                    showActiveBanner(saved);
                    var panel = $('checkinPanel');
                    if (panel) panel.classList.remove('hidden');
                }
            }
        });
    } else {
        showScreen('login');
    }
});
