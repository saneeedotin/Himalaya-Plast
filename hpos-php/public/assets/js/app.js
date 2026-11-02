(function () {
  // mobile menu: the dock's Menu button opens the sidebar drawer; a tap outside closes it
  var toggle = document.querySelector('.menu-toggle');
  var sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    var setOpen = function (open) {
      sidebar.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    toggle.addEventListener('click', function () { setOpen(!sidebar.classList.contains('open')); });
    document.addEventListener('click', function (e) {
      if (sidebar.classList.contains('open') && !sidebar.contains(e.target) && !toggle.contains(e.target)) { setOpen(false); }
    });
  }

  // light / dark theme, remembered in this browser (theme.js applies it before first paint)
  var applyTheme = function (next) {
    document.documentElement.setAttribute('data-theme', next);
    try { window.localStorage.setItem('hpos-theme', next); } catch (e) { /* storage blocked */ }
    Array.prototype.forEach.call(document.querySelectorAll('[data-set-theme]'), function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-set-theme') === next ? 'true' : 'false');
    });
  };
  var themeToggle = document.querySelector('[data-theme-toggle]');
  if (themeToggle) {
    themeToggle.addEventListener('click', function () {
      applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    });
  }
  // the profile page's Light / Dark buttons
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-set-theme]');
    if (b) { applyTheme(b.getAttribute('data-set-theme')); }
  });
  var current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  Array.prototype.forEach.call(document.querySelectorAll('[data-set-theme]'), function (b) {
    b.setAttribute('aria-pressed', b.getAttribute('data-set-theme') === current ? 'true' : 'false');
  });

  // header search-as-you-type: a dropdown of matches from /search/suggest. Without JavaScript the box still submits to /search.
  var searchForm = document.querySelector('.topsearch');
  var searchInput = searchForm && searchForm.querySelector('input[type=search]');
  if (searchInput && window.fetch) {
    var suggestUrl = searchForm.getAttribute('action').replace(/\/$/, '') + '/suggest';
    var panel = document.createElement('div');
    panel.className = 'suggest';
    panel.id = 'suggest-panel';
    panel.hidden = true;
    searchForm.appendChild(panel);
    searchInput.setAttribute('role', 'combobox');
    searchInput.setAttribute('aria-autocomplete', 'list');
    searchInput.setAttribute('aria-controls', 'suggest-panel');
    searchInput.setAttribute('aria-expanded', 'false');
    searchInput.setAttribute('autocomplete', 'off');

    var timer = null, controller = null, sequence = 0, active = -1;
    var links = function () { return panel.querySelectorAll('a'); };
    var closePanel = function () {
      panel.hidden = true; active = -1;
      searchInput.setAttribute('aria-expanded', 'false');
      searchInput.removeAttribute('aria-activedescendant');
    };
    var setActive = function (i) {
      var all = links();
      if (!all.length) { return; }
      active = (i + all.length) % all.length;
      Array.prototype.forEach.call(all, function (a, n) { a.classList.toggle('is-active', n === active); a.setAttribute('aria-selected', n === active ? 'true' : 'false'); });
      all[active].scrollIntoView({ block: 'nearest' });
    };
    var el = function (tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; };
    var showPanel = function (data, q) {
      panel.textContent = '';
      var found = 0;
      (data.groups || []).forEach(function (g) {
        panel.appendChild(el('div', 'suggest-group', g.label));
        g.items.forEach(function (it) {
          var a = el('a', 'suggest-item');
          a.href = it.url;
          a.setAttribute('role', 'option');
          a.appendChild(el('span', 'suggest-title', it.title));
          if (it.subtitle) { a.appendChild(el('span', 'suggest-sub', it.subtitle)); }
          panel.appendChild(a);
          found++;
        });
      });
      if (!found) { panel.appendChild(el('div', 'suggest-empty', data.message ? data.message : 'No matches for “' + q + '”')); }
      var all = el('a', 'suggest-all', found ? 'See all results for “' + q + '”' : 'Open the search page');
      all.href = searchForm.getAttribute('action') + '?q=' + encodeURIComponent(q);
      panel.appendChild(all);
      active = -1;
      panel.hidden = false;
      searchInput.setAttribute('aria-expanded', 'true');
    };
    var run = function () {
      var q = searchInput.value.trim();
      if (q.length < 2) { closePanel(); return; }
      if (controller) { controller.abort(); }
      controller = window.AbortController ? new AbortController() : null;
      var mine = ++sequence;
      fetch(suggestUrl + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller ? controller.signal : undefined })
        .then(function (r) { return r.ok || r.status === 403 ? r.json() : Promise.reject(r.status); })
        .then(function (data) { if (mine === sequence) { showPanel(data, q); } })
        .catch(function () { /* aborted or offline: leave the box as it is; Enter still opens the full page */ });
    };
    searchInput.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 180); });
    searchInput.addEventListener('focus', function () { if (searchInput.value.trim().length >= 2 && panel.childNodes.length) { panel.hidden = false; searchInput.setAttribute('aria-expanded', 'true'); } });
    searchInput.addEventListener('keydown', function (e) {
      if (panel.hidden) { return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (e.key === 'Escape') { e.preventDefault(); closePanel(); }   // keep the typed text; a search field would clear itself
      else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); window.location.href = links()[active].href; }
    });
    document.addEventListener('click', function (e) { if (!searchForm.contains(e.target)) { closePanel(); } });
  }

  // ---- live notifications: the bell, pop-ups, and (when allowed) browser notifications -----------------------------------
  var page = document.body;
  var pushOn = false;   // set below once this browser is subscribed to Web Push, so a pop-up is not shown twice
  if (page.getAttribute('data-poll') && window.fetch) {
    var pollUrl = page.getAttribute('data-poll');
    var after = null, seen = {};
    var bellCount = document.querySelector('[data-bell-count]');
    var baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, '');
    var setUnread = function (n) {
      if (bellCount) { bellCount.textContent = n > 99 ? '99+' : String(n); bellCount.hidden = n <= 0; }
      document.title = n > 0 ? '(' + n + ') ' + baseTitle : baseTitle;
    };
    var toasts = null;
    var toast = function (n) {
      if (!toasts) {
        toasts = document.createElement('div');
        toasts.className = 'toasts';
        toasts.setAttribute('aria-live', 'polite');
        toasts.setAttribute('role', 'status');
        document.body.appendChild(toasts);
      }
      var box = document.createElement('div');
      box.className = 'toast toast-' + String(n.priority || 'UPDATE').toLowerCase().replace(/_/g, '-');
      var a = document.createElement('a');
      a.href = n.url;
      var t = document.createElement('strong'); t.textContent = n.title;
      var m = document.createElement('span'); m.textContent = n.message;
      a.appendChild(t); a.appendChild(m);
      var x = document.createElement('button');
      x.type = 'button'; x.className = 'toast-close'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×';
      var gone = function () { if (box.parentNode) { box.parentNode.removeChild(box); } };
      x.addEventListener('click', gone);
      box.appendChild(a); box.appendChild(x);
      toasts.appendChild(box);
      setTimeout(gone, n.priority === 'CRITICAL' ? 20000 : 9000);
    };
    var browserNotify = function (n) {
      if (pushOn || !document.hidden || !window.Notification || Notification.permission !== 'granted') { return; }
      try {
        var note = new Notification(n.title, { body: n.message, tag: n.id });
        note.onclick = function () { window.focus(); window.location.href = n.url; note.close(); };
      } catch (e) { /* some browsers only allow notifications from a service worker */ }
    };
    var poll = function () {
      fetch(pollUrl + (after ? '?after=' + encodeURIComponent(after) : ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d) { return; }
          setUnread(d.unread);
          (d.items || []).forEach(function (n) {
            if (seen[n.id]) { return; }
            seen[n.id] = true;
            toast(n);
            browserNotify(n);
          });
          after = d.items && d.items.length ? d.items[d.items.length - 1].created_at : (after || d.now);
        })
        .catch(function () { /* offline or signed out: try again next time */ });
    };
    poll();
    setInterval(poll, 20000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
  }

  // ---- Web Push: the card on the Notifications page -------------------------------------------------------------------
  var card = document.querySelector('[data-push-card]');
  if (card) {
    var say = card.querySelector('[data-push-state]');
    var bOn = card.querySelector('[data-push-enable]');
    var bOff = card.querySelector('[data-push-disable]');
    var bTest = card.querySelector('[data-push-test]');
    var csrf = page.getAttribute('data-csrf');
    var toBytes = function (s) {
      var pad = '='.repeat((4 - s.length % 4) % 4);
      var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
      var out = new Uint8Array(raw.length);
      for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
      return out;
    };
    var toB64u = function (buf) {
      var s = '', b = new Uint8Array(buf);
      for (var i = 0; i < b.length; i++) { s += String.fromCharCode(b[i]); }
      return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    };
    var post = function (url, fields) {
      var f = new FormData();
      f.append('_csrf', csrf);
      Object.keys(fields || {}).forEach(function (k) { f.append(k, fields[k]); });
      return fetch(url, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { j.httpStatus = r.status; return j; }); });
    };
    var show = function (text, mode) {
      say.textContent = text;
      bOn.hidden = mode !== 'off';
      bOff.hidden = mode !== 'on';
      bTest.hidden = mode !== 'on';
    };
    var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    var register = function () { return navigator.serviceWorker.register(page.getAttribute('data-sw'), { scope: page.getAttribute('data-base') + '/' }).then(function () { return navigator.serviceWorker.ready; }); };
    var current = function () { return register().then(function (reg) { return reg.pushManager.getSubscription(); }); };
    var refresh = function () {
      if (!supported) { show('This browser does not support push notifications.', 'none'); return; }
      if (!window.isSecureContext) { show('Push notifications need a secure page (HTTPS, or localhost). Open the app over HTTPS to turn them on.', 'none'); return; }
      if (Notification.permission === 'denied') { show('Notifications are blocked for this site in the browser settings. Allow them there, then reload this page.', 'none'); return; }
      current().then(function (sub) {
        return post(page.getAttribute('data-push-status'), { endpoint: sub ? sub.endpoint : '' }).then(function (f) {
          if (!f.available) { show('Push is not set up on this server yet (PHP openssl is needed). Ask whoever runs the server.', 'none'); return; }
          card.setAttribute('data-push-key', f.key);
          if (sub && f.thisBrowser) { pushOn = true; show('On for this browser. You get alerts for your role even when HPOS is closed.', 'on'); }
          else { show('Off for this browser. Turn it on to get alerts for your role even when HPOS is closed.', 'off'); }
        });
      }).catch(function (e) { show('Could not check push notifications: ' + (e && e.message ? e.message : e), 'none'); });
    };
    bOn.addEventListener('click', function () {
      bOn.disabled = true;
      Notification.requestPermission().then(function (perm) {
        if (perm !== 'granted') { show('Permission was not given, so push stays off.', 'off'); return null; }
        return register().then(function (reg) {
          return post(page.getAttribute('data-push-status'), { endpoint: '' }).then(function (f) {
            return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toBytes(f.key) });
          });
        }).then(function (sub) {
          if (!sub) { return null; }
          return post(page.getAttribute('data-push-subscribe'), { endpoint: sub.endpoint, p256dh: toB64u(sub.getKey('p256dh')), auth: toB64u(sub.getKey('auth')) })
            .then(function (r) { if (!r.ok) { throw new Error(r.error || 'The server refused the subscription.'); } });
        });
      }).then(function () { refresh(); })
        .catch(function (e) { show('Could not turn push on: ' + (e && e.message ? e.message : e), 'off'); })
        .then(function () { bOn.disabled = false; });
    });
    bOff.addEventListener('click', function () {
      current().then(function (sub) {
        if (!sub) { return null; }
        var endpoint = sub.endpoint;
        return sub.unsubscribe().then(function () { return post(page.getAttribute('data-push-unsubscribe'), { endpoint: endpoint }); });
      }).then(function () { pushOn = false; refresh(); });
    });
    bTest.addEventListener('click', function () {
      bTest.disabled = true;
      post(page.getAttribute('data-push-test'), {}).then(function (r) {
        var line = r.ok ? 'Test sent to ' + r.accepted + ' of ' + r.total + ' browser(s). It should appear in a moment.' : (r.error || 'The push service did not accept the test.');
        if (!r.ok && r.results && r.results.length) { line += ' (' + r.results.map(function (x) { return x.status ? 'HTTP ' + x.status : x.error; }).join('; ') + ')'; }
        card.querySelector('[data-push-result]').textContent = line;
      }).then(function () { bTest.disabled = false; });
    });
    refresh();
  } else if ('serviceWorker' in navigator && window.Notification && Notification.permission === 'granted' && page.getAttribute('data-sw')) {
    // already allowed on this browser: keep the worker registered, and skip duplicate page notifications
    navigator.serviceWorker.register(page.getAttribute('data-sw'), { scope: page.getAttribute('data-base') + '/' })
      .then(function () { return navigator.serviceWorker.ready; })
      .then(function (reg) { return reg.pushManager.getSubscription(); })
      .then(function (sub) { pushOn = !!sub; })
      .catch(function () { /* push is optional */ });
  }

  // <form data-confirm="Are you sure?"> asks before submitting
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); }
  });

  // a scanner presses Enter after each code: <input data-enter-next="otherId"> moves on instead of submitting
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') { return; }
    var next = e.target.getAttribute && e.target.getAttribute('data-enter-next');
    if (next) {
      e.preventDefault();
      var el = document.getElementById(next);
      if (el) { el.focus(); }
    }
  });

  // <button data-print> opens the print dialog (inline onclick is blocked by the page's CSP)
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('[data-print]')) { window.print(); }
  });

  // payment form: show only the customers or only the suppliers, to match "this is money from / to"
  var partyType = document.getElementById('f_party_type');
  var partySelect = document.getElementById('f_party_id');
  if (partyType && partySelect) {
    var syncParty = function () {
      Array.prototype.forEach.call(partySelect.querySelectorAll('optgroup[data-party]'), function (g) {
        var on = g.getAttribute('data-party') === partyType.value;
        g.disabled = !on;
        g.hidden = !on;
      });
      var chosen = partySelect.options[partySelect.selectedIndex];
      if (chosen && chosen.parentElement.disabled) { partySelect.value = ''; }
      var label = document.querySelector('label[for="f_party_id"]');
      if (label) { label.textContent = partyType.value === 'CUSTOMER' ? 'Customer' : 'Supplier'; }
    };
    partyType.addEventListener('change', syncParty);
    syncParty();

    // "settle an invoice": only the chosen party's unpaid invoices, and picking one fills in what is owed
    var invoiceSelect = document.getElementById('f_invoice_ref');
    var amountInput = document.getElementById('f_amount');
    if (invoiceSelect) {
      var syncInvoices = function () {
        Array.prototype.forEach.call(invoiceSelect.options, function (o) {
          if (!o.value) { return; }
          var on = o.getAttribute('data-party') === partySelect.value;
          o.hidden = !on;
          o.disabled = !on;
        });
        if (invoiceSelect.value && invoiceSelect.options[invoiceSelect.selectedIndex].disabled) { invoiceSelect.value = ''; }
      };
      partySelect.addEventListener('change', syncInvoices);
      partyType.addEventListener('change', syncInvoices);
      invoiceSelect.addEventListener('change', function () {
        var o = invoiceSelect.options[invoiceSelect.selectedIndex];
        if (o && o.value && amountInput) { amountInput.value = o.getAttribute('data-outstanding'); }
      });
      syncInvoices();
    }
  }

  // credit and debit notes: offer only the chosen customer's or supplier's invoices
  var noteParty = document.getElementById('f_cn_party');
  var noteInvoice = document.getElementById('f_cn_invoice');
  if (noteParty && noteInvoice) {
    var syncNote = function () {
      Array.prototype.forEach.call(noteInvoice.options, function (o) {
        if (!o.value) { return; }
        var on = o.getAttribute('data-party') === noteParty.value;
        o.hidden = !on;
        o.disabled = !on;
        if (!on && o.selected) { noteInvoice.value = ''; }
      });
    };
    noteParty.addEventListener('change', syncNote);
    syncNote();
  }

  // journal and opening-balance forms: running debit, credit and difference
  var journal = document.querySelector('form[data-journal]');
  if (journal) {
    var total = function (name) {
      var sum = 0;
      Array.prototype.forEach.call(journal.querySelectorAll('input[name="' + name + '[]"]'), function (i) { sum += parseFloat(i.value) || 0; });
      return sum;
    };
    var showTotals = function () {
      var dr = total('debit'), cr = total('credit');
      var set = function (k, v) { var el = journal.querySelector('[data-total="' + k + '"]'); if (el) { el.textContent = v.toFixed(2); } };
      set('debit', dr); set('credit', cr); set('diff', Math.abs(dr - cr));
      var box = journal.querySelector('.journal-total');
      if (box) { box.classList.toggle('off', Math.abs(dr - cr) >= 0.005); }
    };
    journal.addEventListener('input', showTotals);
    journal.addEventListener('click', function () { window.setTimeout(showTotals, 0); });
    showTotals();
  }

  // repeatable rows: <button data-add-row="templateId" data-target="containerId">, <button data-remove-row>
  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add-row]');
    if (add) {
      var tpl = document.getElementById(add.getAttribute('data-add-row'));
      var target = document.getElementById(add.getAttribute('data-target'));
      if (tpl && target) { target.appendChild(tpl.content.cloneNode(true)); }
      return;
    }
    var rm = e.target.closest('[data-remove-row]');
    if (rm) {
      var row = rm.parentElement;
      var holder = row.parentElement;
      if (holder.children.length > 1) { row.remove(); }
    }
  });
})();
