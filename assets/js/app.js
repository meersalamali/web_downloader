/* ============================================================
   SiteGrabber - front end
   Work runs in short server-side "ticks"; a separate lightweight
   status poll streams progress so the UI stays live between them.
   ============================================================ */
(function () {
  'use strict';

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  var RING = 2 * Math.PI * 52;   // circumference of the progress circle

  var PRESETS = {
    quick:    { max_pages: 30,   max_depth: 3,  max_assets: 400,   concurrency: 5, delay_ms: 150 },
    standard: { max_pages: 150,  max_depth: 6,  max_assets: 1500,  concurrency: 5, delay_ms: 150 },
    deep:     { max_pages: 600,  max_depth: 10, max_assets: 5000,  concurrency: 7, delay_ms: 100 },
    max:      { max_pages: 5000, max_depth: 20, max_assets: 20000, concurrency: 8, delay_ms: 60 }
  };

  var PHASES = {
    init:    'Preparing',
    crawl:   'Downloading',
    db:      'Exporting database',
    rewrite: 'Rewriting links',
    package: 'Building ZIP',
    done:    'Finished'
  };

  var App = {
    id: null,
    logAt: 0,
    done: false,
    stopped: false,
    localLoaded: false,

    /* ---------------------------------------------------------- setup */
    init: function () {
      var self = this;

      $$('#tabs .tab').forEach(function (b) {
        b.addEventListener('click', function () { self.tab(b.dataset.tab); });
      });
      $$('[data-goto]').forEach(function (b) {
        b.addEventListener('click', function () { self.tab(b.dataset.goto); });
      });

      $$('#presets .chip').forEach(function (c) {
        c.addEventListener('click', function () {
          $$('#presets .chip').forEach(function (x) { x.classList.remove('on'); });
          c.classList.add('on');
          self.applyPreset(c.dataset.preset);
        });
      });

      $('#startForm').addEventListener('submit', function (e) {
        e.preventDefault();
        self.startRemote();
      });
      $('#localForm').addEventListener('submit', function (e) {
        e.preventDefault();
        self.startLocal();
      });
      $('#cancelBtn').addEventListener('click', function () { self.cancel(); });
      $('#backBtn').addEventListener('click', function () {
        self.id = null;
        $('#tabRun').hidden = true;
        self.tab('remote');
      });

      $('#url').focus();
      this.ringTo(0);
    },

    tab: function (name) {
      $$('#tabs .tab').forEach(function (b) { b.classList.toggle('on', b.dataset.tab === name); });
      $$('.panel').forEach(function (p) { p.classList.remove('on'); });
      var panel = $('#tab-' + name);
      if (panel) { panel.classList.add('on'); }
      if (name === 'history') { this.loadHistory(); }
      if (name === 'local' && !this.localLoaded) { this.loadLocal(); }
    },

    applyPreset: function (name) {
      var p = PRESETS[name];
      if (!p) { return; }
      Object.keys(p).forEach(function (k) {
        var el = $('#o_' + k);
        if (el) { el.value = p[k]; }
      });
    },

    /* ---------------------------------------------------------- transport */
    api: function (path, payload) {
      return fetch('api/' + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload || {})
      }).then(function (res) {
        return res.text().then(function (text) {
          var data;
          try {
            data = JSON.parse(text);
          } catch (e) {
            throw new Error('The server returned something unexpected:\n' + text.slice(0, 400));
          }
          if (!res.ok || data.ok === false) {
            throw new Error(data.error || ('Request failed (' + res.status + ')'));
          }
          return data;
        });
      });
    },

    toast: function (msg, bad) {
      var t = $('#toast');
      t.textContent = msg;
      t.className = 'toast on' + (bad ? ' bad' : '');
      clearTimeout(this._tt);
      this._tt = setTimeout(function () { t.className = 'toast'; }, bad ? 6500 : 3400);
    },

    /* ---------------------------------------------------------- starting a job */
    formValues: function (form) {
      var out = {};
      $$('input', form).forEach(function (el) {
        if (!el.name) { return; }
        out[el.name] = el.type === 'checkbox' ? el.checked : el.value;
      });
      return out;
    },

    startRemote: function () {
      var self = this;
      var btn = $('#startBtn');
      var payload = this.formValues($('#startForm'));

      if (!String(payload.url || '').trim()) {
        this.toast('Please type a website address first.', true);
        $('#url').focus();
        return;
      }

      btn.disabled = true;
      btn.classList.add('loading');
      $('.label', btn).textContent = 'Starting...';

      this.api('start.php', payload)
        .then(function (d) { self.beginRun(d.id, d.snapshot); })
        .catch(function (e) { self.toast(e.message, true); })
        .then(function () {
          btn.disabled = false;
          btn.classList.remove('loading');
          $('.label', btn).textContent = 'Download site';
        });
    },

    startLocal: function () {
      var self = this;
      var btn = $('#localBtn');
      var payload = this.formValues($('#localForm'));
      payload.databases = $$('#dbList input[type=checkbox]')
        .filter(function (c) { return c.checked; })
        .map(function (c) { return c.value; });

      if (!String(payload.path || '').trim()) {
        this.toast('Pick a project folder first.', true);
        return;
      }

      btn.disabled = true;
      $('.label', btn).textContent = 'Starting...';

      this.api('local_start.php', payload)
        .then(function (d) { self.beginRun(d.id, d.snapshot); })
        .catch(function (e) { self.toast(e.message, true); })
        .then(function () {
          btn.disabled = false;
          $('.label', btn).textContent = 'Export source & database';
        });
    },

    beginRun: function (id, snapshot) {
      this.id = id;
      this.logAt = 0;
      this.done = false;
      this.stopped = false;

      $('#log').innerHTML = '';
      $('#result').hidden = true;
      $('#notes').hidden = true;
      $('#cancelBtn').hidden = false;
      $('#backBtn').hidden = true;
      $('#ring').className = 'ring';
      $('#phaseLabel').className = 'phase';
      $('#tabRun').hidden = false;

      this.tab('run');
      this.render(snapshot);
      this.driveTicks();
      this.pollStatus();
    },

    /* ---------------------------------------------------------- the two loops */

    /** Drives the actual work. One tick does a few seconds of downloading. */
    driveTicks: function () {
      var self = this;
      if (this.done || this.stopped || !this.id) { return; }

      this.api('tick.php', { id: this.id, log_at: -1 })
        .then(function (d) {
          var s = d.snapshot;
          if (s.done) {
            self.finish(s);
            return;
          }
          setTimeout(function () { self.driveTicks(); }, s.busy ? 600 : 30);
        })
        .catch(function (e) {
          if (self.done || self.stopped) { return; }
          // A dropped tick is usually a timeout; wait a moment and carry on.
          self.appendLog([{ l: 'warn', m: 'Hiccup: ' + e.message + ' - retrying' }]);
          setTimeout(function () { self.driveTicks(); }, 2500);
        });
    },

    /** Cheap read-only poll so numbers and the log move while a tick is busy. */
    pollStatus: function () {
      var self = this;
      if (this.done || this.stopped || !this.id) { return; }

      this.api('status.php', { id: this.id, log_at: this.logAt })
        .then(function (d) { self.render(d.snapshot); })
        .catch(function () { /* a missed poll is harmless */ })
        .then(function () {
          if (!self.done && !self.stopped) {
            setTimeout(function () { self.pollStatus(); }, 850);
          }
        });
    },

    cancel: function () {
      var self = this;
      if (!this.id) { return; }
      this.stopped = true;
      this.api('cancel.php', { id: this.id, log_at: this.logAt })
        .then(function (d) { self.finish(d.snapshot); })
        .catch(function (e) { self.toast(e.message, true); });
    },

    finish: function (snapshot) {
      var self = this;
      this.done = true;
      // One last read so the tail of the log is not lost.
      this.api('status.php', { id: this.id, log_at: this.logAt })
        .then(function (d) { self.render(d.snapshot, true); })
        .catch(function () { self.render(snapshot, true); });
    },

    /* ---------------------------------------------------------- rendering */
    render: function (s, isFinal) {
      if (!s) { return; }

      var c = s.counters || {};
      this.ringTo(s.progress || 0);
      $('#pct').textContent = s.progress || 0;
      $('#runTitle').textContent = s.title || '';
      $('#runUrl').textContent = s.root_url || '';

      $('#s_pages').textContent = c.pages_done || 0;
      $('#s_files').textContent = c.assets_done || 0;
      $('#s_size').textContent  = s.bytes_h || '0 B';
      $('#s_queue').textContent = s.queue || 0;
      $('#s_fail').textContent  = (c.pages_failed || 0) + (c.assets_failed || 0);
      $('#s_time').textContent  = this.hms(s.elapsed || 0);

      if (s.type === 'local') {
        $('.sl', $('#s_pages').parentNode).textContent = 'Files added';
        $('.sl', $('#s_files').parentNode).textContent = 'Tables';
      }

      var label = $('#phaseLabel');
      if (s.done) {
        label.className = 'phase static';
        label.textContent = s.status === 'done' ? 'Finished'
          : (s.status === 'cancelled' ? 'Stopped' : 'Failed');
      } else {
        label.className = 'phase';
        label.textContent = PHASES[s.phase] || s.phase || 'Working';
      }

      if (s.log_at >= 0 && s.log && s.log.length) {
        this.appendLog(s.log);
        this.logAt = s.log_at;
      }

      this.renderNotes(s);

      if (s.done) {
        this.done = true;
        $('#cancelBtn').hidden = true;
        $('#backBtn').hidden = false;
        $('#ring').className = 'ring ' + (s.status === 'done' ? 'done' : 'failed');
        this.renderResult(s);
        if (isFinal) { this.loadHistory(); }
      }
    },

    ringTo: function (pct) {
      var bar = $('#ringBar');
      bar.style.strokeDasharray = RING.toFixed(1);
      bar.style.strokeDashoffset = (RING * (1 - Math.max(0, Math.min(100, pct)) / 100)).toFixed(1);
    },

    renderNotes: function (s) {
      var box = $('#notes');
      if (!s.notes || !s.notes.length) { box.hidden = true; return; }
      box.hidden = false;
      box.innerHTML = '<div class="alert warn"><strong>Worth knowing</strong><ul>'
        + s.notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('')
        + '</ul></div>';
    },

    renderResult: function (s) {
      var box = $('#result');
      box.hidden = false;

      if (s.status === 'done' && s.zip) {
        var preview = s.entry
          ? '<a class="btn ghost" href="storage/jobs/' + encodeURIComponent(s.id) + '/site/'
            + s.entry.split('/').map(encodeURIComponent).join('/') + '" target="_blank" rel="noopener">Open the copy</a>'
          : '';
        var report = s.entry
          ? '<a class="btn ghost" href="storage/jobs/' + encodeURIComponent(s.id)
            + '/site/_sitegrabber/report.html" target="_blank" rel="noopener">View report</a>'
          : '';
        box.innerHTML = '<div class="rcard">'
          + '<h3>Your archive is ready</h3>'
          + '<p>' + (s.type === 'local'
              ? 'The project source and database dump are packed and waiting.'
              : 'Every page has been saved and its links rewritten, so the copy works with no internet connection.')
          + '</p>'
          + '<div class="rbtns">'
          + '<a class="btn success big" href="api/download.php?id=' + encodeURIComponent(s.id) + '">'
          + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">'
          + '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/>'
          + '<line x1="12" y1="15" x2="12" y2="3"/></svg>Download ZIP</a>'
          + preview + report
          + '</div>'
          + '<div class="zipmeta">' + esc(s.zip.name) + ' &middot; ' + esc(s.zip.size_h)
          + ' &middot; ' + s.zip.files + ' files</div>'
          + '</div>';
      } else if (s.status === 'cancelled') {
        box.innerHTML = '<div class="rcard bad"><h3>Stopped</h3>'
          + '<p>You stopped this download, so no archive was built. '
          + 'Whatever had already been fetched is still in storage.</p></div>';
      } else {
        box.innerHTML = '<div class="rcard bad"><h3>It did not finish</h3>'
          + '<p>' + esc(s.error || 'Unknown error.') + '</p></div>';
      }
    },

    appendLog: function (lines) {
      var box = $('#log');
      var follow = $('#autoscroll').checked;
      var frag = document.createDocumentFragment();

      lines.forEach(function (row) {
        var level = row.l || 'info';
        var div = document.createElement('div');
        div.className = 'ln l-' + level;
        var tag = document.createElement('span');
        tag.className = 'tag';
        tag.textContent = ({
          ok: 'page', asset: 'file', error: 'fail', warn: 'warn',
          info: 'info', muted: '', done: 'done'
        })[level] || level;
        var msg = document.createElement('span');
        msg.textContent = row.m || '';
        div.appendChild(tag);
        div.appendChild(msg);
        frag.appendChild(div);
      });

      box.appendChild(frag);
      while (box.childElementCount > 1200) { box.removeChild(box.firstChild); }
      if (follow) { box.scrollTop = box.scrollHeight; }
    },

    hms: function (sec) {
      sec = Math.max(0, Math.round(sec));
      if (sec < 60) { return sec + 's'; }
      var m = Math.floor(sec / 60), s = sec % 60;
      if (m < 60) { return m + 'm ' + s + 's'; }
      return Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
    },

    /* ---------------------------------------------------------- history */
    loadHistory: function () {
      var self = this;
      var box = $('#historyList');
      this.api('jobs.php', {})
        .then(function (d) {
          if (!d.jobs.length) {
            box.innerHTML = '<div class="empty">Nothing downloaded yet.</div>';
            return;
          }
          box.innerHTML = d.jobs.map(function (j) {
            var zip = j.zip && j.status === 'done';
            return '<div class="hrow" data-id="' + esc(j.id) + '">'
              + '<div class="hico">' + (j.type === 'local' ? '&#128193;' : '&#127760;') + '</div>'
              + '<div class="hmain"><div class="ht">' + esc(j.title || j.url)
              + ' <span class="badge ' + esc(j.status) + '">' + esc(j.status) + '</span></div>'
              + '<div class="hu">' + esc(j.url) + '</div></div>'
              + '<div class="hmeta">'
              + '<span><b>' + j.pages + '</b> pages</span>'
              + '<span><b>' + j.files + '</b> files</span>'
              + '<span><b>' + esc(j.bytes_h) + '</b></span>'
              + '<span>' + esc(j.when_h) + '</span>'
              + '</div>'
              + '<div class="hbtns">'
              + (zip ? '<a class="btn success" href="api/download.php?id=' + encodeURIComponent(j.id) + '">ZIP</a>' : '')
              + '<button type="button" class="btn ghost" data-del="' + esc(j.id) + '">Delete</button>'
              + '</div></div>';
          }).join('');

          $$('[data-del]', box).forEach(function (b) {
            b.addEventListener('click', function () { self.del(b.dataset.del, b); });
          });
        })
        .catch(function (e) {
          box.innerHTML = '<div class="alert">' + esc(e.message) + '</div>';
        });
    },

    del: function (id, btn) {
      var self = this;
      if (btn.dataset.sure !== '1') {
        btn.dataset.sure = '1';
        btn.textContent = 'Really delete?';
        setTimeout(function () {
          if (btn.dataset.sure === '1') { btn.dataset.sure = '0'; btn.textContent = 'Delete'; }
        }, 4000);
        return;
      }
      this.api('delete.php', { id: id })
        .then(function () {
          self.toast('Deleted.');
          self.loadHistory();
        })
        .catch(function (e) { self.toast(e.message, true); });
    },

    /* ---------------------------------------------------------- local project tab */
    loadLocal: function () {
      var self = this;
      this.localLoaded = true;

      this.api('local_info.php', {})
        .then(function (d) {
          $('#rootsHint').textContent = 'Must be inside: ' + (d.roots || []).join(' or ');

          var pl = $('#projList');
          if (!d.projects.length) {
            pl.innerHTML = '<div class="loading">No project folders found.</div>';
          } else {
            pl.innerHTML = d.projects.map(function (p) {
              return '<button type="button" class="proj" data-path="' + esc(p.path) + '">'
                + '<span class="pico">&#128193;</span>'
                + '<span style="min-width:0"><span class="pn">' + esc(p.name) + '</span>'
                + '<span class="pe">' + esc(p.entry || '') + '</span></span></button>';
            }).join('');
            $$('.proj', pl).forEach(function (b) {
              b.addEventListener('click', function () {
                $$('.proj', pl).forEach(function (x) { x.classList.remove('on'); });
                b.classList.add('on');
                $('#localPath').value = b.dataset.path;
              });
            });
          }

          var dl = $('#dbList');
          if (d.db_error) {
            dl.innerHTML = '<div class="alert">Could not reach MySQL: ' + esc(d.db_error)
              + '<br>Start MySQL in the XAMPP control panel, or set the login in <code>config.php</code>.</div>';
          } else if (!d.databases.length) {
            dl.innerHTML = '<div class="loading">No databases found.</div>';
          } else {
            dl.innerHTML = d.databases.map(function (db) {
              return '<label class="dbitem"><input type="checkbox" value="' + esc(db.name) + '" style="accent-color:#5b7cfa">'
                + '<span><span class="dn">' + esc(db.name) + '</span><br>'
                + '<span class="dt">' + db.tables + ' table' + (db.tables === 1 ? '' : 's') + '</span></span></label>';
            }).join('');
          }
        })
        .catch(function (e) {
          $('#projList').innerHTML = '<div class="alert">' + esc(e.message) + '</div>';
          $('#dbList').innerHTML = '';
        });
    }
  };

  function esc(s) {
    return String(s === undefined || s === null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  document.addEventListener('DOMContentLoaded', function () { App.init(); });
})();
