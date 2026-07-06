(function () {
  'use strict';
  var REFRESH_MS = 3000;

  function el(id) { return document.getElementById(id); }

  function fmtMem(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GiB';
    if (bytes >= 1048576) return Math.round(bytes / 1048576) + ' MiB';
    return Math.round(bytes / 1024) + ' KiB';
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
    el('btop-load').textContent = data.load
      ? 'Load: ' + data.load.map(function (v) { return v.toFixed(2); }).join(' ')
      : 'Load: n/a';
    el('btop-temp').textContent = data.cpu_temp === null ? '' : ' · CPU ' + data.cpu_temp + '°C';
    var box = el('btop-procs');
    box.replaceChildren.apply(box, data.procs.map(function (p) { return row(p.name, p.cpu, p.mem); }));
  }

  function tick() {
    if (document.hidden || !el('btop-procs')) return;
    fetch('/plugins/btop/include/stats.php')
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(render)
      .catch(function () { el('btop-procs').textContent = 'Stats unavailable'; });
  }

  function openFullscreen(e) {
    e.preventDefault();
    fetch('/plugins/btop/include/start_session.php', { method: 'POST' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok || res.status === 'busy') {
          window.open('/logterminal/btop/', 'btop', 'width=1280,height=800');
        } else {
          el('btop-procs').textContent = res.error || 'btop unavailable';
        }
      })
      .catch(function () { el('btop-procs').textContent = 'btop unavailable'; });
  }

  function init() {
    var fs = el('btop-fullscreen');
    if (!fs) return;
    fs.addEventListener('click', openFullscreen);
    tick();
    setInterval(tick, REFRESH_MS);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
