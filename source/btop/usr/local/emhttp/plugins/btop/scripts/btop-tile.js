(function () {
  'use strict';
  var L = window.btopTileText || {}; // translated labels injected by btop-dashboard.page
  function t(key, fallback) { return L[key] || fallback; }

  var refreshMs = 3000; // overridden by the refresh_ms of the first response
  var timer = null;
  var failures = 0;     // consecutive stats.php failures
  var fsBusy = false;   // a fullscreen session request is in flight

  // Order matters: error messages land in the first section that exists
  var DATA_IDS = ['btop-rates', 'btop-procs', 'btop-load', 'btop-uptime', 'btop-temp'];

  function el(id) { return document.getElementById(id); }

  function setText(id, text) {
    var e = el(id);
    if (e) e.textContent = text;
  }

  function firstDataEl() {
    for (var i = 0; i < DATA_IDS.length; i++) {
      var e = el(DATA_IDS[i]);
      if (e) return e;
    }
    return null;
  }

  function markStale(stale) {
    for (var i = 0; i < DATA_IDS.length; i++) {
      var e = el(DATA_IDS[i]);
      if (e) e.style.opacity = stale ? '0.55' : '';
    }
  }

  function fmtMem(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GiB';
    if (bytes >= 1048576) return Math.round(bytes / 1048576) + ' MiB';
    return Math.round(bytes / 1024) + ' KiB';
  }

  function fmtRate(bps) {
    return fmtMem(Math.max(0, bps)) + '/s';
  }

  function fmtUptime(sec) {
    var d = Math.floor(sec / 86400);
    var h = Math.floor((sec % 86400) / 3600);
    var m = Math.floor((sec % 3600) / 60);
    if (d > 0) return d + 'd ' + h + 'h';
    if (h > 0) return h + 'h ' + m + 'm';
    return m + 'm';
  }

  function row(name, cpu, mem) {
    var line = document.createElement('div');
    var w44 = document.createElement('span'); w44.className = 'w44';
    var c18 = document.createElement('span'); c18.className = 'w18';
    var m18 = document.createElement('span'); m18.className = 'w18';
    w44.textContent = name;            // textContent: process names are untrusted
    c18.textContent = cpu.toFixed(1) + '%';
    m18.textContent = fmtMem(mem);
    line.appendChild(w44); line.appendChild(c18); line.appendChild(m18);
    return line;
  }

  function render(data) {
    failures = 0;
    markStale(false);
    if ('load' in data) {
      setText('btop-load', t('load', 'Load') + ': ' + (data.load
        ? data.load.map(function (v) { return v.toFixed(2); }).join(' ')
        : t('na', 'n/a')));
    }
    if ('cpu_temp' in data) {
      setText('btop-temp', data.cpu_temp === null
        ? ''
        : ' · CPU ' + data.cpu_temp + '°' + (data.temp_unit || 'C'));
    }
    if ('uptime' in data || 'nprocs' in data) {
      var bits = [];
      if (data.uptime != null) bits.push(t('up', 'Up') + ' ' + fmtUptime(data.uptime));
      if (data.nprocs != null) bits.push(data.nprocs + ' ' + t('procs', 'procs'));
      setText('btop-uptime', bits.length ? ' · ' + bits.join(' · ') : '');
    }
    if ('diskio' in data || 'net' in data) {
      var rates = [];
      if ('diskio' in data) {
        rates.push(data.diskio
          ? t('disk', 'Disk') + ' R ' + fmtRate(data.diskio.read_bps) + ' · W ' + fmtRate(data.diskio.write_bps)
          : t('disk', 'Disk') + ' ' + t('na', 'n/a'));
      }
      if ('net' in data) {
        rates.push(data.net
          ? t('net', 'Net') + ' ↓ ' + fmtRate(data.net.rx_bps) + ' · ↑ ' + fmtRate(data.net.tx_bps)
          : t('net', 'Net') + ' ' + t('na', 'n/a'));
      }
      setText('btop-rates', rates.join('   '));
    }
    if ('procs' in data) {
      var box = el('btop-procs');
      if (box) {
        box.replaceChildren.apply(box, data.procs.map(function (p) { return row(p.name, p.cpu, p.mem); }));
      }
    }
    if (data.refresh_ms && data.refresh_ms !== refreshMs) {
      refreshMs = data.refresh_ms;
      startTimer();
    }
  }

  function showError() {
    failures++;
    markStale(true);          // last values stay visible but dimmed
    if (failures < 2) return; // a single miss may be transient: dim only
    var target = firstDataEl();
    if (target) target.textContent = t('statsUnavailable', 'Stats unavailable');
  }

  function showSessionError(msg) {
    var target = firstDataEl();
    if (target) target.textContent = msg;
  }

  function tick() {
    if (document.hidden || !el('tblBtopDash')) return;
    fetch('/plugins/btop/include/stats.php')
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(render)
      .catch(showError);
  }

  function startTimer() {
    if (timer) clearInterval(timer);
    timer = setInterval(tick, refreshMs);
  }

  function setFsIcon(spinning) {
    var link = el('btop-fullscreen');
    var icon = link ? link.querySelector('i') : null;
    if (!icon) return;
    icon.classList.toggle('fa-expand', !spinning);
    icon.classList.toggle('fa-circle-o-notch', spinning);
    icon.classList.toggle('fa-spin', spinning);
  }

  function navigateTerminal(win) {
    // Reuse the window if it already shows the terminal: reloading it would
    // disconnect the single-client ttyd session (-m 1) and terminate it (-o)
    var showing = false;
    try { showing = win.location.pathname.indexOf('/logterminal/btop') === 0; } catch (err) { /* navigate below */ }
    if (showing) win.focus();
    else win.location = '/logterminal/btop/';
  }

  function openFullscreen(e) {
    e.preventDefault();
    if (fsBusy) return;
    fsBusy = true;
    // Open the window synchronously on the click: calling window.open() from
    // the fetch callback loses the user activation and gets popup-blocked
    // (always on Safari, elsewhere when the server takes a few seconds)
    var win = window.open('', 'btop', 'width=1280,height=800');
    if (win) {
      try {
        if (win.location.href === 'about:blank') {
          win.document.title = 'btop';
          win.document.body.textContent = t('starting', 'Starting btop') + '…';
          win.document.body.style.cssText = 'background:#1b1d1e;color:#ddd;font-family:sans-serif;padding:1em';
        }
      } catch (err) { /* placeholder is cosmetic */ }
    }
    setFsIcon(true);

    function fail(msg) {
      if (win) { try { win.close(); } catch (err) { /* already closed */ } }
      showSessionError(msg);
    }

    fetch('/plugins/btop/include/start_session.php', { method: 'POST' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok || res.status === 'busy') {
          if (win) navigateTerminal(win);
          else fail(t('popupBlocked', 'Popup blocked - allow popups for this server'));
        } else {
          fail(res.error || t('btopUnavailable', 'btop unavailable'));
        }
      })
      .catch(function () { fail(t('btopUnavailable', 'btop unavailable')); })
      .then(function () { setFsIcon(false); fsBusy = false; });
  }

  function init() {
    var fs = el('btop-fullscreen');
    if (!fs) return;
    fs.addEventListener('click', openFullscreen);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) tick(); // refresh right away when the tab comes back
    });
    tick();
    startTimer();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
