# Page de settings + tuile configurable - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Page Settings native Unraid pour configurer la tuile dashboard btop, et nouvelles métriques activables (I/O disque, réseau, uptime + nb processus, tri/nombre de processus, intervalle).

**Architecture:** Un fichier `btop.cfg` sur la flash (écrit par l'endpoint natif `/update.php` depuis `SettingsBtop.page`, lu via `parse_plugin_cfg`) pilote à la fois le rendu du `.page` de la tuile (sections présentes) et la collecte de `stats.php` (sections calculées). Les nouvelles fonctions de collecte vivent dans `stats_lib.php` (racines paramétrées, fixtures). Les débits réutilisent la fenêtre de 500 ms existante.

**Tech Stack:** PHP 8 (lib + endpoints + pages dynamix), JavaScript vanilla, fixtures + harnais `tests/php/run_tests.php` existant.

**Spec:** `docs/superpowers/specs/2026-07-06-btop-settings-design.md`

## Global Constraints

- **Défauts = comportement v1 exact** : `SHOW_LOAD=yes`, `SHOW_TEMP=yes`, `SHOW_UPTIME=no`, `SHOW_DISKIO=no`, `SHOW_NET=no`, `SHOW_PROCS=yes`, `PROC_SORT=cpu`, `PROC_COUNT=5`, `REFRESH_MS=3000`.
- Bornes : `PROC_COUNT` ∈ [3,10] (défaut 5 si non numérique), `REFRESH_MS` ∈ [2000,10000] (défaut 3000 si non numérique), enums invalides → défaut. Validation centralisée dans `btop_validate_cfg()`.
- Disques comptés : nom exactement `sd[a-z]+`, `hd[a-z]+` ou `nvme\d+n\d+` (pas les partitions, ni `md`/`loop`/`dm-`).
- Interfaces comptées : uniquement celles avec une entrée `device` dans `/sys/class/net/<if>/`.
- Aucune valeur de cfg n'atteint un shell ; les endpoints ne prennent toujours aucune entrée utilisateur.
- Page settings : `SettingsBtop.page`, `Menu="Utilities"`, URL `/Settings/SettingsBtop`, formulaire POST vers `/update.php` avec `#file` = `btop/btop.cfg`.
- Libellés UI en anglais avec balises `_( )_` ; commentaires de code en anglais.
- La suite v1 (`php tests/php/run_tests.php`, 13 checks) doit rester verte à chaque tâche.
- Branche de travail : `feat/settings` (créée par le contrôleur).

---

### Task 1: Extensions de `stats_lib.php` (TDD avec fixtures)

**Files:**
- Modify: `source/btop/usr/local/emhttp/plugins/btop/include/stats_lib.php`
- Modify: `tests/php/run_tests.php` (ajouts en fin, avant le bilan)
- Create: fixtures sous `tests/php/fixtures/` (voir Step 1)

**Interfaces:**
- Consumes: harnais `check()` et fixtures existants.
- Produces (consommé par Tasks 2-4) :
  - `btop_read_uptime(string $procRoot): ?int`
  - `btop_sample_diskio(string $procRoot): ?array` - `['read_sectors' => int, 'write_sectors' => int]` ou `null`
  - `btop_sample_net(string $sysRoot): ?array` - `['rx' => int, 'tx' => int]` ou `null`
  - `btop_top_procs(array $s0, array $s1, float $elapsed, int $n = 5, int $clkTck = 100, string $sort = 'cpu'): array`
  - `btop_validate_cfg(array $raw): array` - retourne les 9 clés validées (toggles en string `yes`/`no`, `PROC_SORT` string, `PROC_COUNT` et `REFRESH_MS` en **int**)

- [ ] **Step 1: Créer les fixtures**

`tests/php/fixtures/proc_t0/uptime` :

```
123456.78 654321.00
```

`tests/php/fixtures/proc_t0/diskstats` (physiques : sda + nvme0n1 → read 100000+50000=150000, write 200000+30000=230000 ; sda1/nvme0n1p1/md1/loop0/dm-0 exclus) :

```
   8       0 sda 4520 123 100000 4400 812 44 200000 900 0 5000 5300 0 0 0 0 0 0
   8       1 sda1 4000 100 90000 4000 800 40 190000 890 0 4900 5200 0 0 0 0 0 0
 259       0 nvme0n1 1000 0 50000 100 500 0 30000 50 0 100 150 0 0 0 0 0 0
 259       1 nvme0n1p1 900 0 45000 90 450 0 29000 45 0 90 140 0 0 0 0 0 0
   9       1 md1 100 0 8000 0 100 0 8000 0 0 0 0 0 0 0 0 0 0
   7       0 loop0 10 0 80 0 0 0 0 0 0 0 0 0 0 0 0 0 0
 253       0 dm-0 50 0 400 0 50 0 400 0 0 0 0 0 0 0 0 0 0
```

`tests/php/fixtures/proc_t1/diskstats` (sda +800 read/+1000 write, nvme0n1 +200/+500 → totaux 151000/231500) :

```
   8       0 sda 4530 123 100800 4410 815 44 201000 905 0 5010 5310 0 0 0 0 0 0
   8       1 sda1 4000 100 90000 4000 800 40 190000 890 0 4900 5200 0 0 0 0 0 0
 259       0 nvme0n1 1002 0 50200 101 505 0 30500 52 0 101 151 0 0 0 0 0 0
 259       1 nvme0n1p1 900 0 45000 90 450 0 29000 45 0 90 140 0 0 0 0 0 0
   9       1 md1 100 0 8000 0 100 0 8000 0 0 0 0 0 0 0 0 0 0
   7       0 loop0 10 0 80 0 0 0 0 0 0 0 0 0 0 0 0 0 0
 253       0 dm-0 50 0 400 0 50 0 400 0 0 0 0 0 0 0 0 0 0
```

`tests/php/fixtures/proc_nophys/diskstats` (aucun disque physique → `null`) :

```
   7       0 loop0 10 0 80 0 0 0 0 0 0 0 0 0 0 0 0 0 0
   9       1 md1 100 0 8000 0 100 0 8000 0 0 0 0 0 0 0 0 0 0
```

Interfaces réseau (le fichier vide `device` marque une interface physique) :

```bash
mkdir -p tests/php/fixtures/sys_net/eth0/statistics \
         tests/php/fixtures/sys_net/br0/statistics \
         tests/php/fixtures/sys_net/lo/statistics \
         tests/php/fixtures/sys_net_none/br0/statistics
touch tests/php/fixtures/sys_net/eth0/device
echo 1000000 > tests/php/fixtures/sys_net/eth0/statistics/rx_bytes
echo 500000  > tests/php/fixtures/sys_net/eth0/statistics/tx_bytes
echo 999999  > tests/php/fixtures/sys_net/br0/statistics/rx_bytes
echo 888888  > tests/php/fixtures/sys_net/br0/statistics/tx_bytes
echo 111     > tests/php/fixtures/sys_net/lo/statistics/rx_bytes
echo 222     > tests/php/fixtures/sys_net/lo/statistics/tx_bytes
echo 1       > tests/php/fixtures/sys_net_none/br0/statistics/rx_bytes
echo 2       > tests/php/fixtures/sys_net_none/br0/statistics/tx_bytes
```

- [ ] **Step 2: Ajouter les tests (qui échouent)**

Dans `tests/php/run_tests.php`, insérer AVANT les deux lignes finales (`echo $failures === 0 ...` / `exit(...)`) :

```php
// --- v2: uptime ---
check('uptime parsed (rounded)', 123457, btop_read_uptime("$fx/proc_t0"));
check('uptime missing file', null, btop_read_uptime("$fx/does_not_exist"));

// --- v2: aggregated disk I/O ---
$d0 = btop_sample_diskio("$fx/proc_t0");
check('diskio t0 read sectors (physical disks only)', 150000, $d0['read_sectors']);
check('diskio t0 write sectors (physical disks only)', 230000, $d0['write_sectors']);
$d1 = btop_sample_diskio("$fx/proc_t1");
check('diskio t1 read sectors', 151000, $d1['read_sectors']);
check('diskio t1 write sectors', 231500, $d1['write_sectors']);
check('diskio missing file', null, btop_sample_diskio("$fx/does_not_exist"));
check('diskio no physical disk', null, btop_sample_diskio("$fx/proc_nophys"));

// --- v2: aggregated network ---
check('net sums physical interfaces only', ['rx' => 1000000, 'tx' => 500000], btop_sample_net("$fx/sys_net"));
check('net no physical interface', null, btop_sample_net("$fx/sys_net_none"));

// --- v2: process sort ---
$a0 = [1 => ['name' => 'cpuhog', 'ticks' => 0, 'rss' => 4096], 2 => ['name' => 'memhog', 'ticks' => 0, 'rss' => 999424]];
$a1 = [1 => ['name' => 'cpuhog', 'ticks' => 50, 'rss' => 4096], 2 => ['name' => 'memhog', 'ticks' => 1, 'rss' => 999424]];
check('sort cpu (default) unchanged', 'cpuhog', btop_top_procs($a0, $a1, 0.5)[0]['name']);
check('sort mem puts memhog first', 'memhog', btop_top_procs($a0, $a1, 0.5, 5, 100, 'mem')[0]['name']);

// --- v2: config validation ---
check('cfg defaults (empty input)', [
    'SHOW_LOAD' => 'yes', 'SHOW_TEMP' => 'yes', 'SHOW_UPTIME' => 'no',
    'SHOW_DISKIO' => 'no', 'SHOW_NET' => 'no', 'SHOW_PROCS' => 'yes',
    'PROC_SORT' => 'cpu', 'PROC_COUNT' => 5, 'REFRESH_MS' => 3000,
], btop_validate_cfg([]));
check('cfg invalid bool falls back to default', 'yes', btop_validate_cfg(['SHOW_LOAD' => 'maybe'])['SHOW_LOAD']);
check('cfg valid toggle kept', 'yes', btop_validate_cfg(['SHOW_DISKIO' => 'yes'])['SHOW_DISKIO']);
check('cfg invalid sort falls back to cpu', 'cpu', btop_validate_cfg(['PROC_SORT' => 'disk'])['PROC_SORT']);
check('cfg count clamped high', 10, btop_validate_cfg(['PROC_COUNT' => '99'])['PROC_COUNT']);
check('cfg count clamped low', 3, btop_validate_cfg(['PROC_COUNT' => '1'])['PROC_COUNT']);
check('cfg count non-numeric -> default', 5, btop_validate_cfg(['PROC_COUNT' => 'abc'])['PROC_COUNT']);
check('cfg refresh clamped', 2000, btop_validate_cfg(['REFRESH_MS' => '500'])['REFRESH_MS']);
```

- [ ] **Step 3: Lancer → échec attendu**

Run: `php tests/php/run_tests.php`
Expected: erreur fatale `Call to undefined function btop_read_uptime()`.

- [ ] **Step 4: Implémenter dans `stats_lib.php`**

Ajouter à la fin du fichier :

```php
function btop_read_uptime(string $procRoot): ?int {
    $raw = @file_get_contents("$procRoot/uptime");
    if ($raw === false) return null;
    $parts = preg_split('/\s+/', trim($raw));
    if (!isset($parts[0]) || !is_numeric($parts[0])) return null;
    return (int)round((float)$parts[0]);
}

function btop_sample_diskio(string $procRoot): ?array {
    $raw = @file_get_contents("$procRoot/diskstats");
    if ($raw === false) return null;
    $read = $write = 0;
    $found = false;
    foreach (explode("\n", trim($raw)) as $line) {
        $f = preg_split('/\s+/', trim($line));
        // Whole physical disks only: partitions and md/loop/dm devices would
        // double-count the traffic already accounted on the underlying disks
        if (count($f) < 10 || !preg_match('/^(sd[a-z]+|hd[a-z]+|nvme\d+n\d+)$/', $f[2])) continue;
        $found = true;
        $read += (int)$f[5];   // sectors read
        $write += (int)$f[9];  // sectors written
    }
    return $found ? ['read_sectors' => $read, 'write_sectors' => $write] : null;
}

function btop_sample_net(string $sysRoot): ?array {
    $rx = $tx = 0;
    $found = false;
    foreach (glob("$sysRoot/*", GLOB_ONLYDIR) ?: [] as $dir) {
        // Physical interfaces only: bridges/bonds aggregate the same traffic
        if (!file_exists("$dir/device")) continue;
        $r = @file_get_contents("$dir/statistics/rx_bytes");
        $t = @file_get_contents("$dir/statistics/tx_bytes");
        if ($r === false || $t === false) continue;
        $found = true;
        $rx += (int)trim($r);
        $tx += (int)trim($t);
    }
    return $found ? ['rx' => $rx, 'tx' => $tx] : null;
}

function btop_validate_cfg(array $raw): array {
    $bool = fn($v, string $def) => in_array($v, ['yes', 'no'], true) ? $v : $def;
    $int = function ($v, int $def, int $min, int $max) {
        if (!is_numeric($v)) return $def;
        return max($min, min($max, (int)$v));
    };
    return [
        'SHOW_LOAD'   => $bool($raw['SHOW_LOAD']   ?? null, 'yes'),
        'SHOW_TEMP'   => $bool($raw['SHOW_TEMP']   ?? null, 'yes'),
        'SHOW_UPTIME' => $bool($raw['SHOW_UPTIME'] ?? null, 'no'),
        'SHOW_DISKIO' => $bool($raw['SHOW_DISKIO'] ?? null, 'no'),
        'SHOW_NET'    => $bool($raw['SHOW_NET']    ?? null, 'no'),
        'SHOW_PROCS'  => $bool($raw['SHOW_PROCS']  ?? null, 'yes'),
        'PROC_SORT'   => in_array($raw['PROC_SORT'] ?? null, ['cpu', 'mem'], true) ? $raw['PROC_SORT'] : 'cpu',
        'PROC_COUNT'  => $int($raw['PROC_COUNT'] ?? null, 5, 3, 10),
        'REFRESH_MS'  => $int($raw['REFRESH_MS'] ?? null, 3000, 2000, 10000),
    ];
}
```

Et remplacer la fonction `btop_top_procs` existante par :

```php
function btop_top_procs(array $s0, array $s1, float $elapsed, int $n = 5, int $clkTck = 100, string $sort = 'cpu'): array {
    $out = [];
    foreach ($s1 as $pid => $proc) {
        if (!isset($s0[$pid])) continue; // appeared between samples
        $delta = $proc['ticks'] - $s0[$pid]['ticks'];
        $cpu = $elapsed > 0 ? round($delta / $clkTck / $elapsed * 100, 1) : 0.0;
        $out[] = ['name' => $proc['name'], 'cpu' => $cpu, 'mem' => $proc['rss']];
    }
    if ($sort === 'mem') {
        usort($out, fn($a, $b) => $b['mem'] <=> $a['mem'] ?: $b['cpu'] <=> $a['cpu']);
    } else {
        usort($out, fn($a, $b) => $b['cpu'] <=> $a['cpu'] ?: $b['mem'] <=> $a['mem']);
    }
    return array_slice($out, 0, $n);
}
```

- [ ] **Step 5: Lancer → succès attendu**

Run: `php tests/php/run_tests.php`
Expected: `ALL TESTS PASSED` (13 checks v1 + 21 checks v2), exit 0.

- [ ] **Step 6: Commit**

```bash
git add source/btop/usr/local/emhttp/plugins/btop/include/stats_lib.php tests/
git commit -m "feat: lib v2 - uptime, diskio/net agrégés, tri procs, validation cfg (fixtures)"
```

---

### Task 2: `default.cfg` + `stats.php` conditionnel

**Files:**
- Create: `source/btop/usr/local/emhttp/plugins/btop/default.cfg`
- Modify: `source/btop/usr/local/emhttp/plugins/btop/include/stats.php` (remplacement complet)

**Interfaces:**
- Consumes: toutes les fonctions de la Task 1 ; `parse_plugin_cfg('btop', true)` (dynamix, présent sur le serveur uniquement - garde `is_file` pour l'exécution locale).
- Produces (consommé par le JS, Task 4) : JSON avec `refresh_ms` (toujours, int) et, seulement si activés : `load`, `cpu_temp`, `uptime`, `nprocs`, `diskio` (`{read_bps, write_bps}` ou `null`), `net` (`{rx_bps, tx_bps}` ou `null`), `procs`.

- [ ] **Step 1: Créer `default.cfg`**

```
SHOW_LOAD="yes"
SHOW_TEMP="yes"
SHOW_UPTIME="no"
SHOW_DISKIO="no"
SHOW_NET="no"
SHOW_PROCS="yes"
PROC_SORT="cpu"
PROC_COUNT="5"
REFRESH_MS="3000"
```

- [ ] **Step 2: Remplacer `stats.php`**

```php
<?php
// Dashboard tile endpoint. Collects only the sections enabled in the plugin
// config; instantaneous rates need two samples 500 ms apart.
require_once __DIR__ . '/stats_lib.php';

$raw = [];
if (is_file('/usr/local/emhttp/plugins/dynamix/include/Wrappers.php')) {
    require_once '/usr/local/emhttp/plugins/dynamix/include/Wrappers.php';
    $raw = parse_plugin_cfg('btop', true);
}
$cfg = btop_validate_cfg($raw);

header('Content-Type: application/json');
header('Cache-Control: no-store');

$out = ['refresh_ms' => $cfg['REFRESH_MS']];

if ($cfg['SHOW_LOAD'] === 'yes') $out['load'] = btop_read_loadavg('/proc');
if ($cfg['SHOW_TEMP'] === 'yes') $out['cpu_temp'] = btop_read_cpu_temp('/sys/class/hwmon');
if ($cfg['SHOW_UPTIME'] === 'yes') $out['uptime'] = btop_read_uptime('/proc');

$wantProcs = $cfg['SHOW_PROCS'] === 'yes';
$wantDisk = $cfg['SHOW_DISKIO'] === 'yes';
$wantNet = $cfg['SHOW_NET'] === 'yes';
$wantUptime = $cfg['SHOW_UPTIME'] === 'yes';

$procs1 = null;
if ($wantProcs || $wantDisk || $wantNet) {
    $procs0 = $wantProcs ? btop_sample_procs('/proc') : null;
    $disk0 = $wantDisk ? btop_sample_diskio('/proc') : null;
    $net0 = $wantNet ? btop_sample_net('/sys/class/net') : null;
    usleep(500000);
    $procs1 = ($wantProcs || $wantUptime) ? btop_sample_procs('/proc') : null;
    $disk1 = $wantDisk ? btop_sample_diskio('/proc') : null;
    $net1 = $wantNet ? btop_sample_net('/sys/class/net') : null;

    if ($wantProcs) {
        $out['procs'] = btop_top_procs($procs0, $procs1, 0.5, $cfg['PROC_COUNT'], 100, $cfg['PROC_SORT']);
    }
    if ($wantDisk) {
        $out['diskio'] = ($disk0 !== null && $disk1 !== null) ? [
            'read_bps'  => (int)(($disk1['read_sectors'] - $disk0['read_sectors']) * 512 / 0.5),
            'write_bps' => (int)(($disk1['write_sectors'] - $disk0['write_sectors']) * 512 / 0.5),
        ] : null;
    }
    if ($wantNet) {
        $out['net'] = ($net0 !== null && $net1 !== null) ? [
            'rx_bps' => (int)(($net1['rx'] - $net0['rx']) / 0.5),
            'tx_bps' => (int)(($net1['tx'] - $net0['tx']) / 0.5),
        ] : null;
    }
} elseif ($wantUptime) {
    $procs1 = btop_sample_procs('/proc');
}

if ($wantUptime) $out['nprocs'] = $procs1 !== null ? count($procs1) : null;

echo json_encode($out);
```

- [ ] **Step 3: Vérifier**

Run: `bash scripts/test_php_syntax.sh && php tests/php/run_tests.php`
Expected: `PHP syntax OK` puis `ALL TESTS PASSED`.

Run (smoke local, sans dynamix → défauts) : `php source/btop/usr/local/emhttp/plugins/btop/include/stats.php`
Expected: JSON avec `refresh_ms`, `load`, `cpu_temp` (probablement `null` sur macOS), `procs` - et SANS `uptime`/`diskio`/`net` (défauts `no`).

- [ ] **Step 4: Commit**

```bash
git add source/btop/usr/local/emhttp/plugins/btop/default.cfg source/btop/usr/local/emhttp/plugins/btop/include/stats.php
git commit -m "feat: stats.php piloté par la config (collecte conditionnelle, débits, tri)"
```

---

### Task 3: Page de settings (`SettingsBtop.page`)

**Files:**
- Create: `source/btop/usr/local/emhttp/plugins/btop/SettingsBtop.page`

**Interfaces:**
- Consumes: `btop_validate_cfg()` (Task 1), `parse_plugin_cfg` (dynamix, disponible dans le contexte des pages).
- Produces: page à l'URL `/Settings/SettingsBtop` (utilisée par le lien engrenage de la Task 4) ; écrit `btop/btop.cfg` via `/update.php`.

- [ ] **Step 1: Écrire la page**

```
Menu="Utilities"
Title="btop"
Icon="btop.png"
---
<?php
/* btop tile settings. Values are written to /boot/config/plugins/btop/btop.cfg
   by Unraid's native /update.php endpoint (session-authenticated). */
require_once '/usr/local/emhttp/plugins/btop/include/stats_lib.php';
$btop_cfg = btop_validate_cfg(parse_plugin_cfg('btop', true));

function btop_sel($current, $value): string {
    return $current === $value ? ' selected' : '';
}
?>
<form markdown="1" method="POST" action="/update.php" target="progressFrame">
<input type="hidden" name="#file" value="btop/btop.cfg">

_(Show load average)_:
: <select name="SHOW_LOAD"><option value="yes"<?=btop_sel($btop_cfg['SHOW_LOAD'], 'yes')?>>_(Yes)_</option><option value="no"<?=btop_sel($btop_cfg['SHOW_LOAD'], 'no')?>>_(No)_</option></select>

_(Show CPU temperature)_:
: <select name="SHOW_TEMP"><option value="yes"<?=btop_sel($btop_cfg['SHOW_TEMP'], 'yes')?>>_(Yes)_</option><option value="no"<?=btop_sel($btop_cfg['SHOW_TEMP'], 'no')?>>_(No)_</option></select>

_(Show uptime and process count)_:
: <select name="SHOW_UPTIME"><option value="yes"<?=btop_sel($btop_cfg['SHOW_UPTIME'], 'yes')?>>_(Yes)_</option><option value="no"<?=btop_sel($btop_cfg['SHOW_UPTIME'], 'no')?>>_(No)_</option></select>

_(Show aggregated disk I/O rates)_:
: <select name="SHOW_DISKIO"><option value="yes"<?=btop_sel($btop_cfg['SHOW_DISKIO'], 'yes')?>>_(Yes)_</option><option value="no"<?=btop_sel($btop_cfg['SHOW_DISKIO'], 'no')?>>_(No)_</option></select>

_(Show aggregated network rates)_:
: <select name="SHOW_NET"><option value="yes"<?=btop_sel($btop_cfg['SHOW_NET'], 'yes')?>>_(Yes)_</option><option value="no"<?=btop_sel($btop_cfg['SHOW_NET'], 'no')?>>_(No)_</option></select>

_(Show process table)_:
: <select name="SHOW_PROCS"><option value="yes"<?=btop_sel($btop_cfg['SHOW_PROCS'], 'yes')?>>_(Yes)_</option><option value="no"<?=btop_sel($btop_cfg['SHOW_PROCS'], 'no')?>>_(No)_</option></select>

_(Sort processes by)_:
: <select name="PROC_SORT"><option value="cpu"<?=btop_sel($btop_cfg['PROC_SORT'], 'cpu')?>>_(CPU)_</option><option value="mem"<?=btop_sel($btop_cfg['PROC_SORT'], 'mem')?>>_(Memory)_</option></select>

_(Number of processes shown)_:
: <select name="PROC_COUNT"><?php for ($i = 3; $i <= 10; $i++) echo '<option value="' . $i . '"' . btop_sel($btop_cfg['PROC_COUNT'], $i) . '>' . $i . '</option>'; ?></select>

_(Tile refresh interval)_:
: <select name="REFRESH_MS"><?php foreach ([2000 => '2 s', 3000 => '3 s', 5000 => '5 s', 10000 => '10 s'] as $ms => $label) echo '<option value="' . $ms . '"' . btop_sel($btop_cfg['REFRESH_MS'], $ms) . '>' . $label . '</option>'; ?></select>

&nbsp;
: <input type="submit" name="#apply" value="_(Apply)_"><input type="button" value="_(Done)_" onclick="done()">
</form>
```

- [ ] **Step 2: Vérifier la syntaxe**

Run: `bash scripts/test_php_syntax.sh`
Expected: `PHP syntax OK` (le script valide la partie PHP après `---`).

- [ ] **Step 3: Commit**

```bash
git add source/btop/usr/local/emhttp/plugins/btop/SettingsBtop.page
git commit -m "feat: page de settings (Settings > User Utilities > btop)"
```

---

### Task 4: Tuile conditionnelle (`btop-dashboard.page` + `btop-tile.js`)

**Files:**
- Modify: `source/btop/usr/local/emhttp/plugins/btop/btop-dashboard.page` (remplacement complet)
- Modify: `source/btop/usr/local/emhttp/plugins/btop/scripts/btop-tile.js` (remplacement complet)

**Interfaces:**
- Consumes: `btop_validate_cfg()` (Task 1), JSON de `stats.php` (Task 2), page `/Settings/SettingsBtop` (Task 3), `POST include/start_session.php` (v1, inchangé).
- Produces: DOM ids `#btop-load`, `#btop-temp`, `#btop-uptime`, `#btop-rates`, `#btop-procs`, `#btop-fullscreen`, tbody `#tblBtopDash` - chacun présent seulement si sa section est activée (sauf fullscreen/tbody, toujours là).

- [ ] **Step 1: Remplacer `btop-dashboard.page`**

```
Menu="Dashboard:0"
Icon="btop.png"
Cond="version_compare(parse_ini_file('/etc/unraid-version')['version'],'6.12.0','>=')"
---
<?php
/* btop dashboard tile. Sections are enabled/disabled from the settings page
   (Settings > User Utilities > btop). Content is rendered client-side by
   scripts/btop-tile.js polling include/stats.php. */
require_once '/usr/local/emhttp/plugins/btop/include/stats_lib.php';
$btop_cfg = btop_validate_cfg(parse_plugin_cfg('btop', true));

$btop_header = '';
if ($btop_cfg['SHOW_LOAD'] === 'yes') $btop_header .= '<span id="btop-load">_(Loading)_&hellip;</span>';
if ($btop_cfg['SHOW_TEMP'] === 'yes') $btop_header .= '<span id="btop-temp"></span>';
if ($btop_cfg['SHOW_UPTIME'] === 'yes') $btop_header .= '<span id="btop-uptime"></span>';

$btop_rates = ($btop_cfg['SHOW_DISKIO'] === 'yes' || $btop_cfg['SHOW_NET'] === 'yes')
    ? '<tr><td><div id="btop-rates"></div></td></tr>' : '';

$btop_procs = $btop_cfg['SHOW_PROCS'] === 'yes'
    ? '<tr class="header"><td><span class="w44">_(Process)_</span><span class="w18">_(CPU)_</span><span class="w18">_(Memory)_</span></td></tr><tr><td><div id="btop-procs"></div></td></tr>'
    : '';

$mytiles['btop']['column1'] = <<<EOT
<tbody id="tblBtopDash" title="_(btop system monitor)_">
<tr>
  <td>
    <span class="tile-header">
      <span class="tile-header-left">
        <img src="/plugins/btop/images/btop.png" style="width:32px;height:32px">
        <div class="section">_(btop)_<br>
          $btop_header
        </div>
      </span>
      <span class="tile-header-right">
        <span class="tile-header-right-controls">
          <a href="/Settings/SettingsBtop" title="_(btop tile settings)_">
            <i class="fa fa-fw fa-cog control"></i>
          </a>
          <a href="#" id="btop-fullscreen" title="_(Open btop fullscreen)_">
            <i class="fa fa-fw fa-expand control"></i>
          </a>
        </span>
      </span>
    </span>
  </td>
</tr>
$btop_rates
$btop_procs
</tbody>
EOT;
?>
<script src="/plugins/btop/scripts/btop-tile.js"></script>
```

(Attention : heredoc `<<<EOT` interpolé - pas le nowdoc `<<<'EOT'` de la v1 - car `$btop_header`/`$btop_rates`/`$btop_procs` doivent s'insérer. Le `EOT;` final reste en colonne 0.)

- [ ] **Step 2: Remplacer `btop-tile.js`**

```js
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
          showError();
        }
      })
      .catch(showError);
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
```

- [ ] **Step 3: Vérifier**

Run: `bash scripts/test_php_syntax.sh && php tests/php/run_tests.php && bash scripts/test_build.sh`
Expected: `PHP syntax OK`, `ALL TESTS PASSED`, `OK: package contents verified` (le test générique embarque les nouveaux fichiers `default.cfg` et `SettingsBtop.page` automatiquement).

Run (optionnel si node présent) : `node --check source/btop/usr/local/emhttp/plugins/btop/scripts/btop-tile.js`
Expected: exit 0.

- [ ] **Step 4: Commit**

```bash
git add source/btop/usr/local/emhttp/plugins/btop/btop-dashboard.page source/btop/usr/local/emhttp/plugins/btop/scripts/btop-tile.js
git commit -m "feat: tuile pilotée par la config (uptime, débits disque/réseau, engrenage settings)"
```

---

### Task 5: Validation serveur, merge et release

Exécutée par le contrôleur (accès `ssh unraid`) avec l'utilisateur pour la partie visuelle.

**Files:** aucun nouveau - déploiement et vérification.

- [ ] **Step 1: Déployer les fichiers en live sur le serveur (test avant merge)**

```bash
rsync -r --delete source/btop/usr/local/emhttp/plugins/btop/ unraid:/usr/local/emhttp/plugins/btop/
```

- [ ] **Step 2: Vérifier côté serveur**

```bash
ssh unraid 'php /usr/local/emhttp/plugins/btop/include/stats.php; echo'
```
Expected: JSON avec `refresh_ms`, `load`, `cpu_temp`, `procs` (défauts v1 - pas encore de btop.cfg).

```bash
ssh unraid 'printf "SHOW_DISKIO=\"yes\"\nSHOW_NET=\"yes\"\nSHOW_UPTIME=\"yes\"\nPROC_SORT=\"mem\"\nPROC_COUNT=\"8\"\n" > /boot/config/plugins/btop/btop.cfg && php /usr/local/emhttp/plugins/btop/include/stats.php; echo'
```
Expected: JSON avec en plus `uptime`, `nprocs`, `diskio` (`read_bps`/`write_bps` ≥ 0), `net` (`rx_bps`/`tx_bps` ≥ 0), `procs` de 8 entrées triées par `mem` décroissant. Comparer l'ordre de grandeur des débits avec `iostat`/les compteurs d'interface si doute.

- [ ] **Step 3: Vérification visuelle (utilisateur)**

Dashboard : la tuile montre uptime/nb procs, la ligne de débits, 8 processus triés par mémoire ; Settings → User Utilities → btop : la page existe, Apply modifie `btop.cfg`, refresh du dashboard applique ; remettre les réglages voulus à la fin (`rm /boot/config/plugins/btop/btop.cfg` pour revenir aux défauts).

- [ ] **Step 4: Merge, release, mise à jour du serveur**

```bash
git checkout main && git pull && git merge --no-ff feat/settings -m "merge: page de settings + tuile configurable"
php tests/php/run_tests.php && bash scripts/test_php_syntax.sh && bash scripts/test_build.sh
git push && git branch -d feat/settings
gh workflow run release.yml -R Greite/unraid-btop
gh run watch "$(gh run list -R Greite/unraid-btop --workflow=release.yml --limit 1 --json databaseId --jq '.[0].databaseId')" -R Greite/unraid-btop --exit-status
```
Expected: run vert, release datée du jour (suffixe `a` si même jour que la précédente), `.plg` bumpé.

```bash
ssh unraid 'plugin check btop.plg && plugin update btop.plg' 
```
Expected: mise à jour installée depuis GitHub (chemin réel utilisateur) ; la tuile et la page settings fonctionnent après refresh.
