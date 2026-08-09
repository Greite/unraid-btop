(function () {
  'use strict';
  // Translated labels, inlined by btop-dashboard.page before this script loads
  var L = window.btopTileText;

  var refreshMs = 3000; // overridden by the refresh_ms of the first response
  var timer = null;
  var failures = 0;     // consecutive stats.php failures

  // Order matters: error messages land in the first section that exists
  var DATA_IDS = ['btop-rates', 'btop-procs', 'btop-load', 'btop-uptime', 'btop-temp'];

  var SPARK_SAMPLES = 20;  // 2 samples per braille cell -> 10 cells, ~60 s at 3 s
  var SPARK_FLOOR = 10240; // don't amplify sub-10 KiB/s noise to full height
  var hist = { disk: [], net: [] };

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

  function pushHist(arr, value) {
    arr.push(value);
    if (arr.length > SPARK_SAMPLES) arr.shift();
  }

  // History rendered as braille bars, btop's own visual vernacular. Always
  // SPARK_SAMPLES/2 cells wide (zero-padded) so the line never shifts.
  function sparkline(values) {
    var padded = [];
    var tail = values.slice(-SPARK_SAMPLES);
    for (var i = tail.length; i < SPARK_SAMPLES; i++) padded.push(0);
    padded = padded.concat(tail);
    var max = SPARK_FLOOR;
    for (var j = 0; j < padded.length; j++) if (padded[j] > max) max = padded[j];
    // Braille dot masks for a column filled bottom-up, height 0-4
    var LEFT = [0, 0x40, 0x44, 0x46, 0x47], RIGHT = [0, 0x80, 0xA0, 0xB0, 0xB8];
    function lvl(v) { return v <= 0 ? 0 : Math.max(1, Math.ceil(v / max * 4)); }
    var out = '';
    for (var k = 0; k < padded.length; k += 2) {
      out += String.fromCharCode(0x2800 + LEFT[lvl(padded[k])] + RIGHT[lvl(padded[k + 1])]);
    }
    return out;
  }

  function rateGroup(sparkValues, text) {
    var g = document.createElement('span');
    g.className = 'btop-rate-group';
    if (sparkValues) {
      var s = document.createElement('span');
      s.className = 'btop-spark';
      s.setAttribute('aria-hidden', 'true');
      s.textContent = sparkline(sparkValues);
      g.appendChild(s);
    }
    var x = document.createElement('span');
    x.textContent = text;
    g.appendChild(x);
    return g;
  }

  // btop's load gradient: green (idle) to red (saturated), low alpha so the
  // bar stays readable behind the text on every dynamix theme
  function barBg(pct) {
    var p = Math.max(0, Math.min(100, pct));
    var hue = Math.round(120 - 1.2 * p);
    return 'linear-gradient(90deg,hsla(' + hue + ',70%,45%,.28) ' + p + '%,transparent ' + p + '%)';
  }

  function row(p, memTotal) {
    var line = document.createElement('div');
    var w44 = document.createElement('span'); w44.className = 'w44';
    var c18 = document.createElement('span'); c18.className = 'w18';
    var m18 = document.createElement('span'); m18.className = 'w18';
    // cname = Docker container or VM name, sent when the "container" labelling is on
    w44.textContent = p.cname || p.name; // textContent: names are untrusted
    w44.title = p.cname ? p.cname + ' · ' + p.name : p.name;
    c18.textContent = p.cpu.toFixed(1) + '%';
    c18.style.background = barBg(p.cpu);
    m18.textContent = fmtMem(p.mem);
    if (memTotal > 0) m18.style.background = barBg(p.mem / memTotal * 100);
    line.appendChild(w44); line.appendChild(c18); line.appendChild(m18);
    return line;
  }

  function render(data) {
    failures = 0;
    markStale(false);
    if ('load' in data) {
      setText('btop-load', L.load + ': ' + (data.load
        ? data.load.map(function (v) { return v.toFixed(2); }).join(' ')
        : L.na));
    }
    if ('cpu_temp' in data) {
      var temp = el('btop-temp');
      if (temp) {
        temp.textContent = data.cpu_temp === null
          ? ''
          : ' · CPU ' + data.cpu_temp + '°' + (data.temp_unit || 'C');
        var warn = data.temp_unit === 'F' ? 158 : 70;
        var hot = data.temp_unit === 'F' ? 185 : 85;
        temp.className = data.cpu_temp === null ? ''
          : data.cpu_temp >= hot ? 'btop-temp-hot'
          : data.cpu_temp >= warn ? 'btop-temp-warn' : '';
      }
    }
    if ('uptime' in data || 'nprocs' in data) {
      var bits = [];
      if (data.uptime != null) bits.push(L.up + ' ' + fmtUptime(data.uptime));
      if (data.nprocs != null) bits.push(data.nprocs + ' ' + L.procs);
      setText('btop-uptime', bits.length ? ' · ' + bits.join(' · ') : '');
    }
    if ('diskio' in data || 'net' in data) {
      var box = el('btop-rates');
      if (box) {
        var groups = [];
        if ('diskio' in data) {
          if (data.diskio) {
            pushHist(hist.disk, data.diskio.read_bps + data.diskio.write_bps);
            groups.push(rateGroup(hist.disk, L.disk
              + ' ↓ ' + fmtRate(data.diskio.read_bps) + ' · ↑ ' + fmtRate(data.diskio.write_bps)));
          } else {
            groups.push(rateGroup(null, L.disk + ' ' + L.na));
          }
        }
        if ('net' in data) {
          if (data.net) {
            pushHist(hist.net, data.net.rx_bps + data.net.tx_bps);
            groups.push(rateGroup(hist.net, L.net
              + ' ↓ ' + fmtRate(data.net.rx_bps) + ' · ↑ ' + fmtRate(data.net.tx_bps)));
          } else {
            groups.push(rateGroup(null, L.net + ' ' + L.na));
          }
        }
        box.replaceChildren.apply(box, groups);
      }
    }
    if ('procs' in data) {
      var list = el('btop-procs');
      if (list) {
        var memTotal = data.mem_total || 0;
        list.replaceChildren.apply(list, data.procs.map(function (p) {
          return row(p, memTotal);
        }));
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
    if (target) target.textContent = L.statsUnavailable;
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

  function init() {
    if (!el('tblBtopDash')) return;
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) tick(); // refresh right away when the tab comes back
    });
    // Uniform placeholder until the first response lands
    var rates = el('btop-rates'), procs = el('btop-procs');
    if (rates && !rates.textContent) rates.textContent = '…';
    if (procs && !procs.textContent) procs.textContent = '…';
    tick();
    startTimer();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
