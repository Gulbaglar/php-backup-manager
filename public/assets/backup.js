/* Backup Manager — admin UI. All texts come from the language files (lang/*.php, keys "ui.*"). */
(function () {
  'use strict';
  var C = window.BK_BOOT;
  var S = { state: null, log: [], poll: null, jobId: null };
  var $ = function (s, r) { return (r || document).querySelector(s); };

  /* i18n: T(key, {name: value}) — keys live in lang/*.php */
  function T(k, p) {
    var s = (C.i18n && C.i18n[k]) || k;
    if (p) for (var n in p) s = s.split('{' + n + '}').join(p[n]);
    return s;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]; }); }
  function size(n) { n = +n || 0; if (n < 1024) return n + ' B'; var u = ['KB', 'MB', 'GB', 'TB'], i = -1; do { n /= 1024; i++; } while (n >= 1024 && i < 3); return n.toFixed(n < 10 ? 2 : 1) + ' ' + u[i]; }
  function date(ts) { if (!ts) return '—'; var d = new Date(ts * 1000); return d.toLocaleDateString(C.lang) + ' ' + d.toLocaleTimeString(C.lang, { hour: '2-digit', minute: '2-digit' }); }
  function typeLabel(t) { return T('ui.type.' + t) === 'ui.type.' + t ? String(t).toUpperCase() : T('ui.type.' + t); }
  function integ(m) {
    if (!m) return '<span class="bk-badge">—</span>';
    if (!m.exists) return '<span class="bk-badge bad">' + T('ui.badge.missing') + '</span>';
    if (m.status === 'verified') return '<span class="bk-badge ok">' + T('ui.badge.verified') + '</span>';
    if (m.status === 'failed') return '<span class="bk-badge bad">' + T('ui.badge.corrupted') + '</span>';
    return '<span class="bk-badge warn">' + T('ui.badge.unverified') + '</span>';
  }

  function api(action, data) {
    return fetch(C.api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': C.csrf }, body: JSON.stringify(Object.assign({ action: action }, data || {})) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: T('ui.err.bad_response') }; }); })
      .then(function (j) { if (!j.ok) throw new Error(j.error || 'Error'); return j; });
  }
  var tt;
  function toast(m, type) { var t = $('.bk-toast'); if (t) t.remove(); t = document.createElement('div'); t.className = 'bk-toast ' + (type || 'ok'); t.textContent = m; document.body.appendChild(t); clearTimeout(tt); tt = setTimeout(function () { t.remove(); }, type === 'err' ? 7000 : 2600); }
  function fail(e) { toast(e && e.message ? e.message : String(e), 'err'); }
  function modal(html) { $('#bkModalBox').innerHTML = html; $('#bkModal').hidden = false; }
  function closeModal() { $('#bkModal').hidden = true; }
  $('#bkModal').addEventListener('mousedown', function (e) { if (e.target.id === 'bkModal') closeModal(); });

  /* ---------------- render ---------------- */
  function renderStatus() {
    var s = S.state, last = s.last, ok = s.last_ok;
    var cards = [
      [T('ui.card.last'), last ? date(last.created_ts) : T('ui.none_yet'), last ? typeLabel(last.type) + ' · ' + T(last.origin === 'auto' ? 'ui.auto' : 'ui.manual') : ''],
      [T('ui.card.last_ok'), ok ? date(ok.created_ts) : '—', ok ? T('ui.badge.verified') + ' · ' + typeLabel(ok.type) : T('ui.no_verified')],
      [T('ui.card.date'), last ? date(last.created_ts) : '—', ''],
      [T('ui.card.type'), last ? typeLabel(last.type) : '—', s.last_full ? T('ui.last_full') + ': ' + date(s.last_full.created_ts) : T('ui.no_full')],
      [T('ui.card.size'), last ? size(last.size) : '—', T('ui.used_total') + ': ' + size(s.storage_used)],
      [T('ui.card.integrity'), last ? integ(last) : '—', s.last_quick ? T('ui.last_quick') + ': ' + date(s.last_quick.created_ts) : ''],
      [T('ui.card.storage'), T('ui.private_storage'), s.storage_path + ' · ' + T('ui.free') + ' ' + size(s.disk_free)],
      [T('ui.card.available'), s.backups.length, s.key_id ? T('ui.key_id') + ': ' + s.key_id : T('ui.no_openssl')]
    ];
    $('#bkStatus').innerHTML = cards.map(function (c) { return '<div class="bk-card"><div class="l">' + esc(c[0]) + '</div><div class="v">' + c[1] + '</div><div class="s">' + esc(c[2]) + '</div></div>'; }).join('');
    var msgs = [];
    if (!s.php_ok) msgs.push(T('ui.warn.php'));
    if (!s.openssl) msgs.push(T('ui.warn.openssl'));
    if (!s.has_database) msgs.push(T('ui.warn.no_database'));
    if (!s.last_full) msgs.push(T('ui.warn.no_full'));
    else if (Date.now() / 1000 - s.last_full.created_ts > 30 * 86400) msgs.push(T('ui.warn.old_full'));
    var w = $('#bkWarn'); w.hidden = !msgs.length; w.innerHTML = msgs.map(esc).join('<br>');
    $('#bKey').href = C.download + '?key=1&t=' + encodeURIComponent(C.csrf);
  }

  function renderTable() {
    var b = S.state.backups;
    if (!b.length) { $('#bkTable').innerHTML = '<tr><td class="bk-empty">' + esc(T('ui.empty')) + '</td></tr>'; return; }
    var h = '<thead><tr><th>' + T('ui.col.date') + '</th><th>' + T('ui.col.type') + '</th><th>' + T('ui.col.size') + '</th><th>' + T('ui.col.integrity') + '</th><th>' + T('ui.col.origin') + '</th><th class="acts">' + T('ui.col.actions') + '</th></tr></thead><tbody>';
    b.forEach(function (m) {
      h += '<tr><td>' + date(m.created_ts) + '<br><span class="muted small">' + esc(m.id) + '</span></td>' +
        '<td><span class="bk-badge type">' + typeLabel(m.type) + '</span>' + (m.protected ? ' <span class="bk-badge warn">' + T('ui.badge.protected') + '</span>' : '') + '</td>' +
        '<td>' + size(m.size) + '</td><td>' + integ(m) + '</td><td>' + T(m.origin === 'auto' ? 'ui.auto' : 'ui.manual') + '</td><td class="acts">' +
        (m.exists ? '<a class="bk-btn bk-sm" href="' + C.download + '?id=' + encodeURIComponent(m.id) + '&t=' + encodeURIComponent(C.csrf) + '">' + T('ui.act.download') + '</a>' : '') +
        '<button class="bk-btn bk-sm" data-a="verify" data-id="' + esc(m.id) + '">' + T('ui.act.verify') + '</button>' +
        '<button class="bk-btn bk-sm" data-a="restore" data-id="' + esc(m.id) + '"' + (m.status === 'failed' || !m.exists ? ' disabled' : '') + '>' + T('ui.act.restore') + '</button>' +
        '<button class="bk-btn bk-sm" data-a="details" data-id="' + esc(m.id) + '">' + T('ui.act.details') + '</button>' +
        '<button class="bk-btn bk-sm" data-a="protect" data-id="' + esc(m.id) + '" data-on="' + (m.protected ? 0 : 1) + '">' + T(m.protected ? 'ui.act.unprotect' : 'ui.act.protect') + '</button>' +
        '<button class="bk-btn bk-sm bk-danger" data-a="delete" data-id="' + esc(m.id) + '"' + (m.protected ? ' disabled' : '') + '>' + T('ui.act.delete') + '</button></td></tr>';
    });
    $('#bkTable').innerHTML = h + '</tbody>';
  }

  function renderAuto() {
    var c = S.state.settings, opts = ['disabled', 'daily', '3days', 'weekly', 'monthly'];
    var sel = function (id, v) { return '<select id="' + id + '">' + opts.map(function (o) { return '<option value="' + o + '"' + (o === v ? ' selected' : '') + '>' + T('ui.auto.opt.' + o) + '</option>'; }).join('') + '</select>'; };
    $('#bkAuto').innerHTML =
      '<label>' + T('ui.auto.full') + sel('aFull', c.auto_full) + '</label>' +
      '<label>' + T('ui.auto.quick') + sel('aQuick', c.auto_quick) + '</label>' +
      '<label>' + T('ui.auto.keep_full') + '<input type="number" min="1" max="100" id="kFull" value="' + c.keep_full + '"></label>' +
      '<label>' + T('ui.auto.keep_quick') + '<input type="number" min="1" max="100" id="kQuick" value="' + c.keep_quick + '"></label>' +
      '<div><button class="bk-btn" id="aSave">' + T('ui.save') + '</button></div>' +
      '<p class="muted small" style="grid-column:1/-1;margin:0">' + esc(T('ui.auto.note')) + ' <code>' + esc(C.cron) + '</code></p>';
  }

  function renderLog() {
    var l = S.log; if (!l.length) { $('#bkLog').innerHTML = '<div>' + esc(T('ui.log.empty')) + '</div>'; return; }
    $('#bkLog').innerHTML = l.map(function (e) {
      var bad = /fail|corrupt|rolled/i.test((e.event || '') + (e.result || '')), good = /success|verified/i.test(e.result || '');
      var bits = [e.event, e.backup_id || e.source, e.type, e.mode, e.user && ('by ' + e.user), e.size && size(e.size), e.duration != null && (e.duration + 's'), e.result, e.error, (e.errors && e.errors.length) ? e.errors[0] : ''].filter(Boolean);
      return '<div class="' + (bad ? 'f' : good ? 'g' : '') + '">' + esc(e.time.replace('T', ' ').slice(0, 19)) + '  ' + esc(bits.join(' · ')) + '</div>';
    }).join('');
  }

  function groupsHtml(g) {
    if (!g) return '';
    return '<div class="bk-checks">' + Object.keys(g).map(function (k) {
      return '<div><span>' + esc(T('ui.group.' + k)) + '</span><b style="color:' + (g[k].state === 'VERIFIED' ? '#3ddc97' : '#ff8b7f') + '">' + (g[k].state === 'VERIFIED' ? T('ui.badge.verified') : T('ui.badge.corrupted')) + '</b><span class="muted">' + g[k].count + ' ' + T('ui.files') + '</span></div>';
    }).join('') + '</div>';
  }
  function errsHtml(e) { return e && e.length ? '<div class="bk-danger-box">' + e.map(esc).join('<br>') + '</div>' : ''; }

  function renderJob(j) {
    var box = $('#bkJob'); if (!j) { box.hidden = true; return; }
    box.hidden = false;
    var running = j.status === 'running' || j.status === 'queued';
    var h = '<h2>' + esc(T('ui.job.' + j.kind)) + (running ? ' …' : '') + '</h2><div class="bk-steps">';
    j.steps.forEach(function (s) {
      var st = s.state === 'done' ? T('ui.state.done') : s.state === 'running' ? (s.pct ? s.pct + '%' : T('ui.state.running')) : s.state === 'failed' ? T('ui.state.failed') : s.state === 'skipped' ? T('ui.state.skipped') : T('ui.state.waiting');
      h += '<div class="bk-step ' + s.state + '"><div>' + esc(T('ui.step.' + s.key)) + '</div><div class="st">' + st + '</div><div><div class="inf">' + esc(s.info || '') + '</div>' +
        (s.state === 'running' ? '<div class="bk-bar"><i style="width:' + (s.pct || 3) + '%"></i></div>' : '') + '</div></div>';
    });
    h += '</div>';
    if (j.status === 'done') {
      var r = j.result || {};
      if (j.kind === 'restore') {
        h += '<div class="bk-result ok">' + T('ui.job.restore_ok') + '</div><div class="bk-checks">' + (r.health ? r.health.checks.map(function (c) { return '<div><span>' + esc(c.name) + '</span><b>' + c.state + '</b><span class="muted">' + esc(c.detail) + '</span></div>'; }).join('') : '') + '</div>' +
          '<p class="muted small">' + T('ui.job.safety') + ' <b>' + esc(r.emergency_backup || '') + '</b> ' + T('ui.job.safety_note') + ' ' + ((r.summary && r.summary.notes || []).map(esc).join(' ')) + '</p>' +
          '<p class="muted small">' + T('ui.job.login_note') + '</p>';
      } else if (j.kind === 'verify') {
        h += '<div class="bk-result ' + (r.ok ? 'ok' : 'bad') + '">' + (r.ok ? T('ui.badge.verified').toUpperCase() : T('ui.job.corrupted')) + '</div>' + groupsHtml(r.groups) +
          (r.hash_ok === false ? '<div class="bk-result bad">' + T('ui.job.hash_bad') + '</div>' : '') + errsHtml(r.errors);
      } else h += '<div class="bk-result ok">' + T('ui.job.created') + ' · ' + size(r.size) + ' · ' + esc(r.id) + '</div>';
      h += '<p><button class="bk-btn bk-sm" id="jobClose">' + T('ui.close') + '</button></p>';
    } else if (j.status === 'failed') {
      h += '<div class="bk-result bad">' + esc(j.error || T('ui.failed')) + '</div><p><button class="bk-btn bk-sm" id="jobClose">' + T('ui.close') + '</button></p>';
    }
    box.innerHTML = h;
  }

  function renderAll() { renderStatus(); renderTable(); renderAuto(); renderLog(); }

  /* ---------------- loading & polling ---------------- */
  function load() {
    return api('state').then(function (j) {
      S.state = j.state; S.log = j.log; renderAll();
      if (j.state.active_job && S.jobId !== j.state.active_job.id) track(j.state.active_job.id);
      busy(!!j.state.active_job);
    }).catch(fail);
  }
  function busy(on) { ['bFull', 'bQuick', 'bRestore'].forEach(function (id) { $('#' + id).disabled = on; }); }

  function track(id) {
    S.jobId = id; clearInterval(S.poll); busy(true);
    var tick = function () {
      api('job', { id: id }).then(function (r) {
        renderJob(r.job);
        if (r.job.status === 'done' || r.job.status === 'failed') {
          clearInterval(S.poll); S.poll = null; busy(false); S.jobId = null;
          toast(r.job.status === 'done' ? T('ui.done') : T('ui.failed'), r.job.status === 'done' ? 'ok' : 'err');
          api('state').then(function (j) { S.state = j.state; S.log = j.log; renderAll(); });
        }
      }).catch(function () {});
    };
    tick(); S.poll = setInterval(tick, 1000);
  }

  function startJob(action, data) {
    busy(true);
    // if the background process could not be spawned the job runs inline: keep polling for progress meanwhile
    var p = api(action, data);
    var early = setInterval(function () { api('state').then(function (j) { if (j.state.active_job && S.jobId !== j.state.active_job.id) { clearInterval(early); track(j.state.active_job.id); } }).catch(function () {}); }, 1500);
    return p.then(function (r) { clearInterval(early); track(r.job); }).catch(function (e) { clearInterval(early); busy(false); fail(e); });
  }

  /* ---------------- events ---------------- */
  $('#bFull').onclick = function () { startJob('start_backup', { type: 'full' }); };
  $('#bQuick').onclick = function () { startJob('start_backup', { type: 'quick' }); };
  $('#bRestore').onclick = function () { restoreSourceDlg(); };
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.id === 'jobClose') { renderJob(null); return; }
    if (t.closest('[data-close]')) { closeModal(); return; }
    if (t.id === 'aSave') {
      api('save_settings', { settings: { auto_full: $('#aFull').value, auto_quick: $('#aQuick').value, keep_full: $('#kFull').value, keep_quick: $('#kQuick').value } }).then(function () { toast(T('ui.saved')); load(); }).catch(fail); return;
    }
    var el = t.closest('[data-a]'); if (!el) return;
    var id = el.dataset.id, a = el.dataset.a;
    if (a === 'verify') startJob('verify', { id: id });
    else if (a === 'delete') { if (confirm(T('ui.confirm.delete', { id: id }))) api('delete', { id: id }).then(function () { toast(T('ui.deleted')); load(); }).catch(fail); }
    else if (a === 'protect') api('protect', { id: id, on: el.dataset.on === '1' }).then(load).catch(fail);
    else if (a === 'details') details(id);
    else if (a === 'restore') restoreFromStored(id);
  });

  /* ---------------- details ---------------- */
  function details(id) {
    api('details', { id: id }).then(function (r) {
      var m = r.meta, man = r.manifest || {}, c = man.counts || {}, ex = man.external || {}, v = m.verify;
      var h = '<h3>' + esc(id) + '</h3><div class="bk-kv">' +
        '<b>' + T('ui.d.type') + '</b><span>' + typeLabel(m.type) + (m.protected ? ' · ' + T('ui.badge.protected') : '') + '</span>' +
        '<b>' + T('ui.d.created') + '</b><span>' + date(m.created_ts) + ' · ' + esc(m.created_by || '') + ' · ' + T(m.origin === 'auto' ? 'ui.auto' : 'ui.manual') + '</span>' +
        '<b>' + T('ui.d.size') + '</b><span>' + size(m.size) + ' (' + T('ui.d.content') + ' ' + size(c.total_bytes) + ')</span>' +
        '<b>' + T('ui.d.sha') + '</b><span style="word-break:break-all">' + esc(m.sha256) + '</span>' +
        '<b>' + T('ui.card.integrity') + '</b><span>' + integ(m) + (m.verified_at ? ' <span class="muted small">' + date(m.verified_at) + '</span>' : '') + '</span>' +
        '<b>' + T('ui.d.components') + '</b><span>' + esc((man.components || m.components || []).join(', ')) + '</span>' +
        '<b>' + T('ui.d.files') + '</b><span>' + (c.files || 0) + ' (' + T('ui.group.website') + ' ' + (c.website_files || 0) + ') · ' + T('ui.group.images') + ' ' + (c.images || 0) + ' · ' + T('ui.group.videos') + ' ' + (c.videos || 0) + ' · ' + T('ui.group.documents') + ' ' + (c.documents || 0) + ' · ' + T('ui.group.other') + ' ' + (c.other || 0) + '</span>' +
        '<b>' + T('ui.group.database') + '</b><span>' + (man.database ? 'sqlite ' + esc(man.database.version) + ' · ' + Object.keys(man.database.tables || {}).length + ' ' + T('ui.d.tables') + ' · ' + size(man.database.dump_size) : T('ui.d.no_db')) + '</span>' +
        '<b>' + T('ui.d.runtime') + '</b><span>PHP ' + esc((man.runtime || {}).php) + ' · ' + esc((man.runtime || {}).os) + ' · ' + esc((man.runtime || {}).environment) + ' · format v' + esc(man.backup_format_version) + '</span>' +
        '<b>' + T('ui.d.encryption') + '</b><span>' + (man.encryption && man.encryption.key_id ? 'AES-256-GCM · ' + T('ui.d.key') + ' ' + esc(man.encryption.key_id) : T('ui.d.no_enc')) + '</span>' +
        '<b>' + T('ui.d.duration') + '</b><span>' + (m.duration || 0) + ' s</span></div>' +
        '<h3>' + T('ui.d.external') + '</h3><div class="bk-kv"><b>' + T('ui.d.local_media') + '</b><span>' + T('ui.d.backed_up') + '</span>' +
        '<b>' + T('ui.d.youtube') + '</b><span>' + T('ui.d.reference_only') + ' (' + (ex.youtube_refs || 0) + ')</span><b>' + T('ui.d.cdn') + '</b><span>' + T('ui.d.not_included') + '</span>' +
        (ex.hosts && Object.keys(ex.hosts).length ? '<b>' + T('ui.d.hosts') + '</b><span class="muted small">' + Object.keys(ex.hosts).map(function (k) { return esc(k) + ' (' + ex.hosts[k] + ')'; }).join(', ') + '</span>' : '') + '</div>';
      if (v) h += '<h3>' + T('ui.d.report') + '</h3>' + groupsHtml(v.groups) + errsHtml(v.errors) + (v.db ? '<p class="muted small">' + T('ui.d.dump_test', { i: esc(v.db.integrity), f: v.db.fk_violations, t: v.db.tables }) + '</p>' : '');
      if (m.warnings && m.warnings.length) h += '<div class="bk-danger-box">' + m.warnings.map(esc).join('<br>') + '</div>';
      modal(h + '<p><button class="bk-btn" data-close>' + T('ui.close') + '</button></p>');
    }).catch(fail);
  }

  /* ---------------- restore ---------------- */
  function restoreSourceDlg() {
    var opts = S.state.backups.filter(function (m) { return m.exists && m.status !== 'failed'; }).map(function (m) { return '<option value="' + esc(m.id) + '">' + date(m.created_ts) + ' · ' + typeLabel(m.type) + ' · ' + size(m.size) + '</option>'; }).join('');
    modal('<h3>' + T('ui.restore.title') + '</h3><p class="muted small">' + esc(T('ui.restore.intro')) + '</p>' +
      '<p><button class="bk-btn bk-primary" id="pickFile">' + T('ui.restore.pick_file') + '</button></p><div id="upProg" class="muted small"></div>' +
      (opts ? '<hr><label class="muted small">' + T('ui.restore.from_server') + '</label><select id="srvSel">' + opts + '</select><p><button class="bk-btn" id="srvGo">' + T('ui.restore.continue') + '</button></p>' : '') +
      '<p><button class="bk-btn" data-close>' + T('ui.cancel') + '</button></p>');
    $('#pickFile').onclick = function () { $('#bkFile').click(); };
    if ($('#srvGo')) $('#srvGo').onclick = function () { restoreFromStored($('#srvSel').value); };
  }
  $('#bkFile').addEventListener('change', function (e) { var f = e.target.files[0]; e.target.value = ''; if (f) upload(f); });
  function upload(f) {
    var prog = $('#upProg'); if (!prog) { restoreSourceDlg(); prog = $('#upProg'); }
    api('upload_init', { name: f.name, size: f.size }).then(function (r) {
      var u = r.upload, off = 0, CH = 4 * 1048576;
      function next() {
        if (off >= f.size) return api('upload_finish', { upload: u });
        var blob = f.slice(off, off + CH), tries = 0;
        var send = function () {
          return fetch(C.api + '?action=upload_chunk&u=' + u + '&offset=' + off, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': C.csrf, 'Content-Type': 'application/octet-stream' }, body: blob })
            .then(function (x) { return x.json(); }).then(function (j) { if (!j.ok) throw new Error(j.error); off = j.received; prog.textContent = T('ui.restore.uploading') + ' ' + Math.floor(off * 100 / f.size) + '% (' + size(off) + ' / ' + size(f.size) + ')'; })
            .catch(function (e) { if (++tries < 3) return send(); throw e; });
        };
        return send().then(next);
      }
      return next();
    }).then(function (info) { restoreDlg({ source: 'upload:' + info.upload, manifest: info.manifest, supported: info.supported, integrity: T('ui.restore.checked_at_restore') }); }).catch(fail);
  }
  function restoreFromStored(id) {
    api('details', { id: id }).then(function (r) {
      if (!r.manifest) throw new Error(T('ui.err.unreadable'));
      restoreDlg({ source: 'backup:' + id, manifest: r.manifest, supported: true, integrity: r.meta.status === 'verified' ? T('ui.badge.verified') : T('ui.restore.unverified_yet') });
    }).catch(fail);
  }
  // [mode, needs-component(s), warning items "label:component"]
  var MODES = [
    ['full', ['database'], ['database', 'uploads', 'website', 'config']],
    ['database', ['database'], ['database']],
    ['media', ['uploads'], ['uploads']],
    ['code', ['website'], ['website']],
    ['admin', ['database'], ['admin']]
  ];
  var ITEM_KEY = { database: 'ui.restore.item.database', uploads: 'ui.restore.item.uploads', website: 'ui.restore.item.website', config: 'ui.restore.item.config', admin: 'ui.restore.item.admin' };
  function restoreDlg(info) {
    var m = info.manifest, c = m.counts || {}, has = m.components || [], adminOk = !!(m.layout && m.layout.admin_tables && m.layout.admin_tables.length);
    var h = '<h3>' + T('ui.restore.title') + '</h3>';
    if (!info.supported) h += '<div class="bk-danger-box">' + esc(T('ui.restore.unsupported', { v: m.backup_format_version })) + '</div>';
    h += '<div class="bk-kv"><b>' + T('ui.restore.date') + '</b><span>' + esc(m.created_at) + '</span><b>' + T('ui.col.type') + '</b><span>' + typeLabel(m.type) + '</span>' +
      '<b>' + T('ui.group.database') + '</b><span>' + (m.database ? 'OK (' + Object.keys(m.database.tables || {}).length + ' ' + T('ui.d.tables') + ')' : '—') + '</span>' +
      '<b>' + T('ui.group.website') + '</b><span>' + (has.indexOf('website') >= 0 ? 'OK (' + (c.website_files || 0) + ')' : '—') + '</span>' +
      '<b>' + T('ui.group.images') + '</b><span>' + (c.images || 0) + '</span><b>' + T('ui.group.videos') + '</b><span>' + (c.videos || 0) + '</span><b>' + T('ui.group.documents') + '</b><span>' + (c.documents || 0) + '</span>' +
      '<b>' + T('ui.card.integrity') + '</b><span>' + esc(info.integrity) + '</span></div><div>';
    MODES.forEach(function (md, i) {
      var okm = md[1].every(function (x) { return has.indexOf(x) >= 0; }) && (md[0] !== 'admin' || adminOk);
      h += '<label class="bk-mode' + (i === 0 ? ' on' : '') + (okm ? '' : ' off') + '"><input type="radio" name="rmode" value="' + md[0] + '"' + (i === 0 ? ' checked' : '') + (okm ? '' : ' disabled') + '> <b>' + T('ui.restore.mode.' + md[0]) + '</b><small>' + esc(T('ui.restore.mode.' + md[0] + '.desc')) + (okm ? '' : ' — ' + esc(T('ui.restore.not_in_backup'))) + '</small></label>';
    });
    h += '</div><div class="bk-danger-box" id="rWarn"></div><p class="muted small">' + esc(T('ui.restore.safety_note')) + '</p>' +
      '<label class="muted small">' + T('ui.restore.password') + '</label><input type="password" id="rPw" autocomplete="current-password">' +
      '<p style="margin-top:14px"><button class="bk-btn bk-restore" id="rGo"' + (info.supported ? '' : ' disabled') + '></button> <button class="bk-btn" data-close>' + T('ui.cancel') + '</button></p>';
    modal(h);
    function upd() {
      var v = $('input[name=rmode]:checked').value, md = MODES.filter(function (x) { return x[0] === v; })[0];
      var items = md[2].filter(function (k) { return k === 'admin' || has.indexOf(k) >= 0; });
      $('#rWarn').innerHTML = '<b>' + T('ui.restore.warning') + '</b> — ' + T('ui.restore.will_replace', { mode: T('ui.restore.mode.' + v) }) + '<ul>' + items.map(function (k) { return '<li>' + T(ITEM_KEY[k]) + '</li>'; }).join('') + '</ul>';
      $('#rGo').textContent = v === 'full' ? T('ui.restore.go_full') : T('ui.restore.go_mode', { mode: T('ui.restore.mode.' + v).toUpperCase() });
      document.querySelectorAll('.bk-mode').forEach(function (l) { l.classList.toggle('on', l.querySelector('input').checked); });
    }
    document.querySelectorAll('input[name=rmode]').forEach(function (r) { r.onchange = upd; }); upd();
    $('#rGo').onclick = function () {
      var mode = $('input[name=rmode]:checked').value, pw = $('#rPw').value;
      if (!pw) return toast(T('ui.restore.password_required'), 'err');
      if (!confirm(T('ui.restore.confirm', { mode: mode }))) return;
      closeModal();
      startJob('start_restore', { source: info.source, mode: mode, password: pw });
    };
  }

  load();
})();
