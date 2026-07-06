(function () {
  'use strict';
  var refreshMs = 3000; // overridden by the refresh_ms of the first response
  var timer = null;

  function el(id) { return document.getElementById(id); }

  function setText(id, text) {
    var e = el(id);
    if (e) e.textContent = text;
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
    if ('load' in data) {
      setText('btop-load', data.load
        ? 'Load: ' + data.load.map(function (v) { return v.toFixed(2); }).join(' ')
        : 'Load: n/a');
    }
    if ('cpu_temp' in data) {
      setText('btop-temp', data.cpu_temp === null ? '' : ' · CPU ' + data.cpu_temp + '°C');
    }
    if ('uptime' in data || 'nprocs' in data) {
      var bits = [];
      if (data.uptime != null) bits.push('Up ' + fmtUptime(data.uptime));
      if (data.nprocs != null) bits.push(data.nprocs + ' procs');
      setText('btop-uptime', bits.length ? ' · ' + bits.join(' · ') : '');
    }
    if ('diskio' in data || 'net' in data) {
      var rates = [];
      if ('diskio' in data) {
        rates.push(data.diskio
          ? 'Disk R ' + fmtRate(data.diskio.read_bps) + ' · W ' + fmtRate(data.diskio.write_bps)
          : 'Disk n/a');
      }
      if ('net' in data) {
        rates.push(data.net
          ? 'Net ↓ ' + fmtRate(data.net.rx_bps) + ' · ↑ ' + fmtRate(data.net.tx_bps)
          : 'Net n/a');
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
    setText('btop-procs', 'Stats unavailable');
    setText('btop-rates', 'Stats unavailable');
  }

  function showSessionError(msg) {
    var target = el('btop-procs') || el('btop-rates') || el('btop-load');
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

  function openFullscreen(e) {
    e.preventDefault();
    fetch('/plugins/btop/include/start_session.php', { method: 'POST' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok || res.status === 'busy') {
          window.open('/logterminal/btop/', 'btop', 'width=1280,height=800');
        } else {
          showSessionError(res.error || 'btop unavailable');
        }
      })
      .catch(function () { showSessionError('btop unavailable'); });
  }

  function init() {
    var fs = el('btop-fullscreen');
    if (!fs) return;
    fs.addEventListener('click', openFullscreen);
    tick();
    startTimer();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
