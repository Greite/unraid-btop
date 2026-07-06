# Plugin Unraid btop — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plugin Unraid qui installe le CLI btop et ajoute une tuile dashboard native (load, température CPU, top 5 processus) avec ouverture du vrai btop en plein écran via ttyd.

**Architecture:** Un paquet Slackware `unraid.btop-<version>-x86_64-1.txz` (binaire statique btop officiel + fichiers webgui) installé par un `btop.plg`. La tuile dashboard est un `.page` (`Menu="Dashboard:0"`) dont le JS interroge un endpoint PHP `stats.php` (lecture de `/proc` et `/sys/class/hwmon`) ; le plein écran réutilise l'infra ttyd/nginx d'Unraid (`/logterminal/btop/` → `unix:/var/tmp/btop.sock`). La CI GitHub Actions rebuild et republie automatiquement à chaque release btop upstream.

**Tech Stack:** Bash (build/packaging), PHP 8 (endpoints + lib testée par fixtures), JavaScript vanilla (tuile), XML `.plg`, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-07-06-unraid-btop-plugin-design.md`

## Global Constraints

- Unraid minimum : `6.12.0` (attribut `min` du `.plg` et `Cond` du `.page`).
- Nom du plugin : `btop` ; auteur : `Greite` ; repo : `Greite/unraid-btop`.
- Paquet : `unraid.btop-<version>-x86_64-1.txz` ; version plugin = date UTC `YYYY.MM.DD` (+ suffixe `a`, `b`… si collision de tag).
- Binaire : asset officiel `btop-x86_64-unknown-linux-musl.tar.gz` de `aristocratos/btop` (v1.4.7 au moment du plan) ; structure interne `./btop/bin/btop`, `./btop/themes/*.theme`, `./btop/Img/icon.png`, `./btop/LICENSE`. Upstream ne publie pas de checksums → consigner le SHA256 calculé.
- x86_64 uniquement. Pas de page de settings. Tuile : 5 processus, refresh 3 s (valeurs fixes).
- Endpoints sans entrée utilisateur (aucun paramètre interprété) — pas de surface d'injection.
- Chemins runtime : socket `/var/tmp/btop.sock`, config CLI persistante `/boot/config/plugins/btop/config/` symlinée depuis `/root/.config/btop`.
- Outillage : les scripts doivent tourner sur ubuntu-latest (CI, GNU tar/md5sum) **et** macOS (dev local, bsdtar/md5) — chaque script détecte l'outil disponible.
- Messages utilisateur de la tuile en anglais (langue standard des plugins CA) ; commentaires de code en anglais.

---

### Task 1: Squelette du repo, `build.sh` et test de build

**Files:**
- Create: `BTOP_VERSION`
- Create: `.gitignore`
- Create: `scripts/build.sh`
- Create: `scripts/test_build.sh`
- Create: `source/btop/usr/local/emhttp/plugins/btop/images/btop.png` (extrait de l'archive btop)
- Create: `source/btop/usr/local/emhttp/plugins/btop/README.md`

**Interfaces:**
- Produces: `bash scripts/build.sh <btop-version> [plugin-version]` → génère `dist/unraid.btop-<plugin-version>-x86_64-1.txz`, `dist/unraid.btop-<plugin-version>-x86_64-1.md5`, `dist/btop-<btop-version>.sha256`, et laisse `dist/pkgroot/` en place (utilisé par le smoke test CI).
- Produces: fichier `BTOP_VERSION` (contenu : `1.4.7`) lu par la CI et les tests.
- Produces: `bash scripts/test_build.sh` → exit 0 si le paquet est conforme.

- [ ] **Step 1: Créer `BTOP_VERSION` et `.gitignore`**

`BTOP_VERSION` :

```
1.4.7
```

`.gitignore` :

```
dist/
.DS_Store
```

- [ ] **Step 2: Récupérer l'icône btop et créer le README du plugin**

```bash
mkdir -p source/btop/usr/local/emhttp/plugins/btop/images
cd /tmp && curl -fSL -o btop-icon.tgz 'https://github.com/aristocratos/btop/releases/download/v1.4.7/btop-x86_64-unknown-linux-musl.tar.gz'
tar -xzf btop-icon.tgz ./btop/Img/icon.png
cd - && cp /tmp/btop/Img/icon.png source/btop/usr/local/emhttp/plugins/btop/images/btop.png
```

`source/btop/usr/local/emhttp/plugins/btop/README.md` :

```markdown
**btop**

Installs the btop resource monitor CLI and adds a dashboard tile showing
load average, CPU temperature and the top 5 processes by CPU usage.
Click the expand icon on the tile to open the full interactive btop in
your browser, or run `btop` from any terminal (SSH or web terminal).

Notes:
- Do not install alongside the NerdTools btop package (both ship /usr/bin/btop).
- GPU monitoring is unavailable (static binary cannot load NVML/ROCm).
- Your btop CLI settings persist across reboots (stored on the flash drive).
```

- [ ] **Step 3: Écrire le test de build (qui échoue)**

`scripts/test_build.sh` :

```bash
#!/usr/bin/env bash
# Builds the package with a fixed test version and asserts its contents.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLGVER="0000.00.00"
BTOPVER="$(cat "$ROOT/BTOP_VERSION")"

fail() { echo "FAIL: $1" >&2; exit 1; }

bash "$ROOT/scripts/build.sh" "$BTOPVER" "$PLGVER"

PKG="unraid.btop-${PLGVER}-x86_64-1"
TXZ="$ROOT/dist/$PKG.txz"
[ -f "$TXZ" ] || fail "missing $TXZ"
[ -f "$ROOT/dist/$PKG.md5" ] || fail "missing $PKG.md5"
[ -f "$ROOT/dist/btop-${BTOPVER}.sha256" ] || fail "missing sha256 record"

LIST="$(tar -tf "$TXZ")"
echo "$LIST" | grep -q '^\./usr/bin/btop$' || fail "btop binary missing from package"
echo "$LIST" | grep -q '^\./usr/share/btop/themes/.*\.theme$' || fail "themes missing from package"
echo "$LIST" | grep -q '^\./usr/share/btop/LICENSE$' || fail "upstream LICENSE missing"

# Every file of the source tree must be in the package
for f in $(cd "$ROOT/source/btop" && find usr -type f); do
  echo "$LIST" | grep -q "^\./$f\$" || fail "source file missing from package: $f"
done

# MD5 file must match (md5sum on Linux, md5 on macOS)
cd "$ROOT/dist"
if command -v md5sum >/dev/null; then
  md5sum -c "$PKG.md5" >/dev/null || fail "md5 mismatch"
else
  expected="$(awk '{print $1}' "$PKG.md5")"
  actual="$(md5 -q "$PKG.txz")"
  [ "$expected" = "$actual" ] || fail "md5 mismatch"
fi

# pkgroot must be left in place (used by the CI smoke test)
[ -x "$ROOT/dist/pkgroot/usr/bin/btop" ] || fail "dist/pkgroot not left in place"

echo "OK: package contents verified"
```

```bash
chmod +x scripts/test_build.sh
```

- [ ] **Step 4: Lancer le test → échec attendu**

Run: `bash scripts/test_build.sh`
Expected: échec — `scripts/build.sh: No such file or directory`.

- [ ] **Step 5: Écrire `build.sh`**

`scripts/build.sh` :

```bash
#!/usr/bin/env bash
# Builds unraid.btop-<version>-x86_64-1.txz from the official btop release
# plus the source/ webgui tree. Leaves dist/pkgroot/ in place for smoke tests.
set -euo pipefail
BTOP_VERSION="${1:?usage: build.sh <btop-version> [plugin-version]}"
PLUGIN_VERSION="${2:-$(date -u +%Y.%m.%d)}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
PKG="unraid.btop-${PLUGIN_VERSION}-x86_64-1"
ASSET="btop-x86_64-unknown-linux-musl.tar.gz"
URL="https://github.com/aristocratos/btop/releases/download/v${BTOP_VERSION}/${ASSET}"

mkdir -p "$DIST/cache"
TARBALL="$DIST/cache/btop-${BTOP_VERSION}.tar.gz"
if [ ! -f "$TARBALL" ]; then
  echo "Downloading $URL"
  curl -fSL -o "$TARBALL" "$URL"
fi

# Record the SHA256 of the upstream artifact (upstream publishes no checksums)
if command -v sha256sum >/dev/null; then
  sha256sum "$TARBALL" > "$DIST/btop-${BTOP_VERSION}.sha256"
else
  shasum -a 256 "$TARBALL" > "$DIST/btop-${BTOP_VERSION}.sha256"
fi

rm -rf "$DIST/cache/btop" "$DIST/pkgroot"
tar -xzf "$TARBALL" -C "$DIST/cache"
[ -f "$DIST/cache/btop/bin/btop" ] || { echo "unexpected upstream archive layout" >&2; exit 1; }

mkdir -p "$DIST/pkgroot/usr/bin" "$DIST/pkgroot/usr/share/btop/themes"
cp -a "$ROOT/source/btop/usr/." "$DIST/pkgroot/usr/"
cp "$DIST/cache/btop/bin/btop" "$DIST/pkgroot/usr/bin/btop"
chmod 755 "$DIST/pkgroot/usr/bin/btop"
cp "$DIST/cache/btop/themes/"*.theme "$DIST/pkgroot/usr/share/btop/themes/"
cp "$DIST/cache/btop/LICENSE" "$DIST/pkgroot/usr/share/btop/LICENSE"

# Slackware package: tar.xz, root-owned, entries prefixed with ./
cd "$DIST/pkgroot"
if tar --version 2>/dev/null | grep -q GNU; then
  tar --owner=0 --group=0 -cJf "$DIST/$PKG.txz" .
else
  tar --format ustar --uid 0 --gid 0 -cJf "$DIST/$PKG.txz" .
fi

cd "$DIST"
if command -v md5sum >/dev/null; then
  md5sum "$PKG.txz" > "$PKG.md5"
else
  echo "$(md5 -q "$PKG.txz")  $PKG.txz" > "$PKG.md5"
fi

echo "Built: dist/$PKG.txz (btop v${BTOP_VERSION})"
```

```bash
chmod +x scripts/build.sh
```

- [ ] **Step 6: Lancer le test → succès attendu**

Run: `bash scripts/test_build.sh`
Expected: `OK: package contents verified`

- [ ] **Step 7: Commit**

```bash
git add BTOP_VERSION .gitignore scripts/ source/
git commit -m "feat: build reproductible du paquet unraid.btop (binaire officiel + webgui)"
```

---

### Task 2: Bibliothèque de stats PHP (TDD avec fixtures)

**Files:**
- Create: `source/btop/usr/local/emhttp/plugins/btop/include/stats_lib.php`
- Create: `tests/php/run_tests.php`
- Create: `tests/php/fixtures/` (arborescences `proc_t0/`, `proc_t1/`, `hwmon_intel/`, `hwmon_none/`)

**Interfaces:**
- Produces (consommé par Task 3 via `stats.php`) :
  - `btop_read_loadavg(string $procRoot): ?array` — `[float, float, float]` ou `null` ;
  - `btop_read_cpu_temp(string $hwmonRoot): ?int` — °C ou `null` si aucun capteur connu ;
  - `btop_sample_procs(string $procRoot): array` — `pid => ['name' => string, 'ticks' => int, 'rss' => int]` (kernel threads exclus) ;
  - `btop_top_procs(array $s0, array $s1, float $elapsed, int $n = 5, int $clkTck = 100): array` — liste triée de `['name' => string, 'cpu' => float, 'mem' => int]`.

- [ ] **Step 1: Créer les fixtures**

```bash
mkdir -p tests/php/fixtures/{proc_t0,proc_t1}/{100,200,300} \
         tests/php/fixtures/hwmon_intel/hwmon0 \
         tests/php/fixtures/hwmon_none/hwmon0 \
         tests/php/fixtures/proc_t1/400
```

`tests/php/fixtures/proc_t0/loadavg` (et identique dans `proc_t1/loadavg`) :

```
0.42 0.38 0.35 2/345 12345
```

`tests/php/fixtures/proc_t0/100/stat` (processus actif « qemu-system x », nom avec espace ; utime=500, stime=100, rss=2048 pages) :

```
100 (qemu-system x) S 1 100 100 0 -1 4194560 100 0 20 0 500 100 12 8 20 0 4 0 300 999424000 2048 18446744073709551615
```

`tests/php/fixtures/proc_t1/100/stat` (utime=530, stime=112 → delta 42 ticks) :

```
100 (qemu-system x) S 1 100 100 0 -1 4194560 100 0 20 0 530 112 12 8 20 0 4 0 300 999424000 2048 18446744073709551615
```

`tests/php/fixtures/proc_t0/200/stat` (et identique dans `proc_t1/200/stat` — processus idle, delta 0) :

```
200 (sshd) S 1 200 200 0 -1 4194560 10 0 2 0 40 20 1 1 20 0 1 0 500 12582912 3 18446744073709551615
```

`tests/php/fixtures/proc_t0/300/stat` (et identique dans `proc_t1/300/stat` — kernel thread) :

```
300 (kworker/0:1) I 2 0 0 0 -1 69238880 0 0 0 0 5 0 0 0 20 0 1 0 60 0 0 18446744073709551615
```

`tests/php/fixtures/proc_t1/400/stat` (apparu entre les deux échantillons → ignoré par `btop_top_procs`) :

```
400 (newproc) R 1 400 400 0 -1 4194560 0 0 0 0 99 0 0 0 20 0 1 0 900 4096000 100 18446744073709551615
```

Fichiers `cmdline` (le contenu importe peu, il doit être non vide pour les vrais processus, vide pour le kernel thread) :

```bash
printf 'qemu-system\0' > tests/php/fixtures/proc_t0/100/cmdline
printf 'qemu-system\0' > tests/php/fixtures/proc_t1/100/cmdline
printf 'sshd\0'        > tests/php/fixtures/proc_t0/200/cmdline
printf 'sshd\0'        > tests/php/fixtures/proc_t1/200/cmdline
: > tests/php/fixtures/proc_t0/300/cmdline
: > tests/php/fixtures/proc_t1/300/cmdline
printf 'newproc\0'     > tests/php/fixtures/proc_t1/400/cmdline
```

Fixtures capteurs :

```bash
echo coretemp > tests/php/fixtures/hwmon_intel/hwmon0/name
echo 'Package id 0' > tests/php/fixtures/hwmon_intel/hwmon0/temp1_label
echo 52000 > tests/php/fixtures/hwmon_intel/hwmon0/temp1_input
echo 48000 > tests/php/fixtures/hwmon_intel/hwmon0/temp2_input
echo 'Core 0' > tests/php/fixtures/hwmon_intel/hwmon0/temp2_label
echo nvme > tests/php/fixtures/hwmon_none/hwmon0/name
echo 35000 > tests/php/fixtures/hwmon_none/hwmon0/temp1_input
```

- [ ] **Step 2: Écrire le harnais de test (qui échoue)**

`tests/php/run_tests.php` :

```php
<?php
require __DIR__ . '/../../source/btop/usr/local/emhttp/plugins/btop/include/stats_lib.php';

$failures = 0;
function check(string $label, $expected, $actual): void {
    global $failures;
    if ($expected === $actual) { echo "ok - $label\n"; return; }
    $failures++;
    echo "FAIL - $label\n  expected: " . var_export($expected, true)
       . "\n  actual:   " . var_export($actual, true) . "\n";
}

$fx = __DIR__ . '/fixtures';

check('loadavg parsed', [0.42, 0.38, 0.35], btop_read_loadavg("$fx/proc_t0"));
check('loadavg missing file', null, btop_read_loadavg("$fx/does_not_exist"));

check('cpu temp from coretemp package sensor', 52, btop_read_cpu_temp("$fx/hwmon_intel"));
check('cpu temp with no known sensor', null, btop_read_cpu_temp("$fx/hwmon_none"));

$s0 = btop_sample_procs("$fx/proc_t0");
$s1 = btop_sample_procs("$fx/proc_t1");
check('kernel thread excluded', false, isset($s0[300]));
check('process name with space kept', 'qemu-system x', $s0[100]['name']);
check('ticks summed (utime+stime)', 600, $s0[100]['ticks']);
check('rss in bytes (pages * 4096)', 2048 * 4096, $s0[100]['rss']);

$top = btop_top_procs($s0, $s1, 0.5, 5);
check('sorted by cpu desc', 'qemu-system x', $top[0]['name']);
check('cpu percent computed', 84.0, $top[0]['cpu']); // 42 ticks / 100 Hz / 0.5 s * 100
check('idle process has 0 cpu', 0.0, $top[1]['cpu']);
check('process appearing between samples ignored', 2, count($top));

$topOne = btop_top_procs($s0, $s1, 0.5, 1);
check('top limited to n', 1, count($topOne));

echo $failures === 0 ? "ALL TESTS PASSED\n" : "$failures TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 3: Lancer les tests → échec attendu**

Run: `php tests/php/run_tests.php`
Expected: erreur fatale — `stats_lib.php` introuvable.

- [ ] **Step 4: Implémenter `stats_lib.php`**

`source/btop/usr/local/emhttp/plugins/btop/include/stats_lib.php` :

```php
<?php
// Data collection library for the btop dashboard tile.
// All functions take their /proc and /sys roots as parameters so they can
// be tested against fixtures.

function btop_read_loadavg(string $procRoot): ?array {
    $raw = @file_get_contents("$procRoot/loadavg");
    if ($raw === false) return null;
    $parts = preg_split('/\s+/', trim($raw));
    if (count($parts) < 3) return null;
    return [(float)$parts[0], (float)$parts[1], (float)$parts[2]];
}

function btop_read_cpu_temp(string $hwmonRoot): ?int {
    $known = ['coretemp', 'k10temp', 'zenpower', 'cpu_thermal'];
    foreach (glob("$hwmonRoot/hwmon*") ?: [] as $dir) {
        $name = trim(@file_get_contents("$dir/name") ?: '');
        if (!in_array($name, $known, true)) continue;
        $best = null;
        foreach (glob("$dir/temp*_input") ?: [] as $input) {
            $label = trim(@file_get_contents(preg_replace('/_input$/', '_label', $input)) ?: '');
            $val = (int)trim(@file_get_contents($input) ?: '0');
            if ($val <= 0) continue;
            $celsius = (int)round($val / 1000);
            // Prefer the package/die sensor; otherwise keep the hottest core
            if (preg_match('/^(Package id|Tctl|Tdie)/', $label)) return $celsius;
            $best = max($best ?? 0, $celsius);
        }
        if ($best !== null) return $best;
    }
    return null;
}

function btop_sample_procs(string $procRoot): array {
    $sample = [];
    foreach (glob("$procRoot/[0-9]*", GLOB_ONLYDIR) ?: [] as $dir) {
        $pid = (int)basename($dir);
        $stat = @file_get_contents("$dir/stat");
        if ($stat === false) continue;
        // Kernel threads have an empty cmdline — skip them
        $cmdline = @file_get_contents("$dir/cmdline");
        if ($cmdline === false || $cmdline === '') continue;
        // comm is wrapped in parentheses and may contain spaces/parens
        $open = strpos($stat, '(');
        $close = strrpos($stat, ')');
        if ($open === false || $close === false) continue;
        $name = substr($stat, $open + 1, $close - $open - 1);
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1)));
        // After comm: [0]=state ... [11]=utime [12]=stime ... [21]=rss (pages)
        if (count($fields) < 22) continue;
        $sample[$pid] = [
            'name'  => $name,
            'ticks' => (int)$fields[11] + (int)$fields[12],
            'rss'   => (int)$fields[21] * 4096,
        ];
    }
    return $sample;
}

function btop_top_procs(array $s0, array $s1, float $elapsed, int $n = 5, int $clkTck = 100): array {
    $out = [];
    foreach ($s1 as $pid => $proc) {
        if (!isset($s0[$pid])) continue; // appeared between samples
        $delta = $proc['ticks'] - $s0[$pid]['ticks'];
        $cpu = $elapsed > 0 ? round($delta / $clkTck / $elapsed * 100, 1) : 0.0;
        $out[] = ['name' => $proc['name'], 'cpu' => $cpu, 'mem' => $proc['rss']];
    }
    usort($out, fn($a, $b) => $b['cpu'] <=> $a['cpu'] ?: $b['mem'] <=> $a['mem']);
    return array_slice($out, 0, $n);
}
```

- [ ] **Step 5: Lancer les tests → succès attendu**

Run: `php tests/php/run_tests.php`
Expected: `ALL TESTS PASSED`, exit 0.

- [ ] **Step 6: Commit**

```bash
git add source/btop/usr/local/emhttp/plugins/btop/include/stats_lib.php tests/
git commit -m "feat: lib de stats (loadavg, temp hwmon, top processus) testée par fixtures"
```

---

### Task 3: Endpoints HTTP `stats.php` et `start_session.php` + vérification de syntaxe

**Files:**
- Create: `source/btop/usr/local/emhttp/plugins/btop/include/stats.php`
- Create: `source/btop/usr/local/emhttp/plugins/btop/include/start_session.php`
- Create: `scripts/test_php_syntax.sh`

**Interfaces:**
- Consumes: les 4 fonctions de `stats_lib.php` (Task 2).
- Produces: `GET /plugins/btop/include/stats.php` → JSON `{"load":[f,f,f]|null,"cpu_temp":int|null,"procs":[{"name":s,"cpu":f,"mem":i},...]}` (consommé par le JS de la Task 4).
- Produces: `POST /plugins/btop/include/start_session.php` → JSON `{"ok":bool}` ou `{"ok":false,"status":"busy"}` ou `{"ok":false,"error":string}` ; démarre ttyd sur `/var/tmp/btop.sock` (consommé par le JS de la Task 4 ; socket tué par le bloc remove du `.plg`, Task 5).

- [ ] **Step 1: Écrire `stats.php`**

`source/btop/usr/local/emhttp/plugins/btop/include/stats.php` :

```php
<?php
// Dashboard tile endpoint: instantaneous CPU usage needs two /proc samples.
require_once __DIR__ . '/stats_lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$s0 = btop_sample_procs('/proc');
usleep(500000);
$s1 = btop_sample_procs('/proc');

echo json_encode([
    'load'     => btop_read_loadavg('/proc'),
    'cpu_temp' => btop_read_cpu_temp('/sys/class/hwmon'),
    'procs'    => btop_top_procs($s0, $s1, 0.5),
]);
```

- [ ] **Step 2: Écrire `start_session.php`**

`source/btop/usr/local/emhttp/plugins/btop/include/start_session.php` :

```php
<?php
// Starts the fullscreen btop ttyd session behind Unraid's nginx
// (/logterminal/btop/ -> unix:/var/tmp/btop.sock). Takes NO input:
// socket path and command are fixed, so there is no injection surface.
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$sock = '/var/tmp/btop.sock';
$btop = '/usr/bin/btop';

exec('pgrep -f ' . escapeshellarg("ttyd.*$sock"), $pids, $rc);
if ($rc === 0) {
    echo json_encode(['ok' => false, 'status' => 'busy']);
    exit;
}
if (file_exists($sock)) @unlink($sock); // stale socket left by a crashed ttyd

if (!is_executable($btop)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'btop binary missing — reinstall the plugin']);
    exit;
}

// Unraid ships a ttyd-exec wrapper; fall back to plain ttyd
$ttyd = trim((string)shell_exec('command -v ttyd-exec || command -v ttyd'));
if ($ttyd === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'ttyd not found']);
    exit;
}

// -s 9: SIGKILL on close, -o: exit once the client disconnects, -m 1: single client
exec(sprintf('nohup %s -s 9 -o -m 1 -i %s %s >/dev/null 2>&1 &',
    escapeshellarg($ttyd), escapeshellarg($sock), escapeshellarg($btop)));

// Wait up to 2 s for the socket to appear
for ($i = 0; $i < 20 && !file_exists($sock); $i++) usleep(100000);

echo json_encode(['ok' => file_exists($sock)]);
```

- [ ] **Step 3: Écrire le vérificateur de syntaxe PHP (couvre aussi les `.page`)**

`scripts/test_php_syntax.sh` :

```bash
#!/usr/bin/env bash
# php -l on every .php file, and on the PHP part of every .page file
# (.page = header lines, a --- separator, then PHP/HTML).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

for f in $(find "$ROOT/source" -name '*.php'); do
  php -l "$f" >/dev/null
done

for f in $(find "$ROOT/source" -name '*.page'); do
  tmp="$(mktemp)"
  awk 'found{print} /^---$/{found=1}' "$f" > "$tmp"
  php -l "$tmp" >/dev/null || { echo "PHP syntax error in $f" >&2; rm -f "$tmp"; exit 1; }
  rm -f "$tmp"
done

echo "PHP syntax OK"
```

```bash
chmod +x scripts/test_php_syntax.sh
```

- [ ] **Step 4: Vérifier syntaxe + tests**

Run: `bash scripts/test_php_syntax.sh && php tests/php/run_tests.php`
Expected: `PHP syntax OK` puis `ALL TESTS PASSED`.

- [ ] **Step 5: Commit**

```bash
git add source/btop/usr/local/emhttp/plugins/btop/include/ scripts/test_php_syntax.sh
git commit -m "feat: endpoints stats.php (tuile) et start_session.php (btop plein écran)"
```

---

### Task 4: Tuile dashboard (`btop-dashboard.page` + `btop-tile.js`)

**Files:**
- Create: `source/btop/usr/local/emhttp/plugins/btop/btop-dashboard.page`
- Create: `source/btop/usr/local/emhttp/plugins/btop/scripts/btop-tile.js`

**Interfaces:**
- Consumes: `GET /plugins/btop/include/stats.php` et `POST /plugins/btop/include/start_session.php` (Task 3) ; format JSON défini en Task 3.
- Produces: tuile dashboard enregistrée sous `$mytiles['btop']['column1']`, éléments DOM `#btop-load`, `#btop-temp`, `#btop-procs`, `#btop-fullscreen`.

- [ ] **Step 1: Écrire `btop-dashboard.page`**

`source/btop/usr/local/emhttp/plugins/btop/btop-dashboard.page` :

```
Menu="Dashboard:0"
Icon="btop.png"
Cond="version_compare(parse_ini_file('/etc/unraid-version')['version'],'6.12.0','>=')"
---
<?php
/* btop dashboard tile: load average, CPU temperature and top processes.
   Content is rendered client-side by scripts/btop-tile.js polling
   include/stats.php every 3 seconds. */
$mytiles['btop']['column1'] = <<<'EOT'
<tbody id="tblBtopDash" title="_(btop system monitor)_">
<tr>
  <td>
    <span class="tile-header">
      <span class="tile-header-left">
        <img src="/plugins/btop/images/btop.png" style="width:32px;height:32px">
        <div class="section">_(btop)_<br>
          <span id="btop-load">_(Loading)_&hellip;</span>
          <span id="btop-temp"></span>
        </div>
      </span>
      <span class="tile-header-right">
        <span class="tile-header-right-controls">
          <a href="#" id="btop-fullscreen" title="_(Open btop fullscreen)_">
            <i class="fa fa-fw fa-expand control"></i>
          </a>
        </span>
      </span>
    </span>
  </td>
</tr>
<tr class="header">
  <td>
    <span class="w44">_(Process)_</span>
    <span class="w18">_(CPU)_</span>
    <span class="w18">_(Memory)_</span>
  </td>
</tr>
<tr><td><div id="btop-procs"></div></td></tr>
</tbody>
EOT;
?>
<script src="/plugins/btop/scripts/btop-tile.js"></script>
```

- [ ] **Step 2: Écrire `btop-tile.js`**

`source/btop/usr/local/emhttp/plugins/btop/scripts/btop-tile.js` :

```js
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
```

- [ ] **Step 3: Vérifier la syntaxe (PHP du `.page` + JS)**

Run: `bash scripts/test_php_syntax.sh`
Expected: `PHP syntax OK`

Run (optionnel, seulement si node est installé) : `node --check source/btop/usr/local/emhttp/plugins/btop/scripts/btop-tile.js`
Expected: aucune sortie (exit 0).

- [ ] **Step 4: Vérifier que le paquet embarque la tuile**

Run: `bash scripts/test_build.sh`
Expected: `OK: package contents verified` (le test générique vérifie que tout fichier de `source/` est dans le `.txz`).

- [ ] **Step 5: Commit**

```bash
git add source/btop/usr/local/emhttp/plugins/btop/btop-dashboard.page source/btop/usr/local/emhttp/plugins/btop/scripts/
git commit -m "feat: tuile dashboard native (load, temp, top processus) + plein écran"
```

---

### Task 5: Le fichier plugin `btop.plg`

**Files:**
- Create: `btop.plg`

**Interfaces:**
- Consumes: le paquet `unraid.btop-<version>-x86_64-1.txz` publié en GitHub Release (Task 6 le publie ; les entités `version`/`md5` sont réécrites par `release.yml`).
- Produces: URL d'installation `https://raw.githubusercontent.com/Greite/unraid-btop/main/btop.plg` (consommée par le template CA, Task 8).

- [ ] **Step 1: Écrire `btop.plg`**

Note : `version` et `md5` contiennent des valeurs de bootstrap — la première exécution de `release.yml` (Task 6) les remplace par les vraies valeurs avant toute installation réelle.

```xml
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
  <!ENTITY name      "btop">
  <!ENTITY author    "Greite">
  <!ENTITY version   "2026.07.06">
  <!ENTITY md5       "00000000000000000000000000000000">
  <!ENTITY package   "unraid.btop-&version;-x86_64-1">
  <!ENTITY gitURL    "https://github.com/Greite/unraid-btop/releases/download">
  <!ENTITY pluginURL "https://raw.githubusercontent.com/Greite/unraid-btop/main/btop.plg">
  <!ENTITY plugin    "/boot/config/plugins/&name;">
  <!ENTITY emhttp    "/usr/local/emhttp/plugins/&name;">
]>

<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" icon="btop.png" min="6.12.0">

<CHANGES>
###2026.07.06
- Initial release: btop CLI + native dashboard tile (top processes, load, CPU temperature)
</CHANGES>

<!-- Remove packages from other versions -->
<FILE Run="/bin/bash">
<INLINE>
rm -f $(ls &plugin;/unraid.btop-*.txz 2>/dev/null | grep -v '&version;')
</INLINE>
</FILE>

<!-- Download and install the package (MD5 checked by the plugin system) -->
<FILE Name="&plugin;/&package;.txz" Run="upgradepkg --install-new">
<URL>&gitURL;/&version;/&package;.txz</URL>
<MD5>&md5;</MD5>
</FILE>

<!-- Persist btop CLI config on the flash drive -->
<FILE Run="/bin/bash">
<INLINE>
mkdir -p &plugin;/config
if [ ! -L /root/.config/btop ]; then
  mkdir -p /root/.config
  rm -rf /root/.config/btop
  ln -sf &plugin;/config /root/.config/btop
fi
echo ""
echo "----------------------------------------------------"
echo " btop installed — dashboard tile + 'btop' in the CLI"
echo "----------------------------------------------------"
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
# Kill the fullscreen ttyd session if any
pkill -f '/var/tmp/btop\.sock' 2>/dev/null
sleep 1
removepkg &package;
rm -rf &emhttp;
rm -f /root/.config/btop
rm -f &plugin;/*.txz
# &plugin;/config is kept on purpose (user's btop settings survive reinstalls)
echo "btop uninstalled"
</INLINE>
</FILE>
</PLUGIN>
```

- [ ] **Step 2: Valider le XML (avec résolution des entités)**

Run: `xmllint --noout --noent btop.plg && echo XML_OK`
Expected: `XML_OK`

- [ ] **Step 3: Commit**

```bash
git add btop.plg
git commit -m "feat: fichier plugin btop.plg (install/update/remove, config persistante)"
```

---

### Task 6: Workflows CI (`ci.yml`, `release.yml`, `check-btop-update.yml`)

**Files:**
- Create: `.github/workflows/ci.yml`
- Create: `.github/workflows/release.yml`
- Create: `.github/workflows/check-btop-update.yml`

**Interfaces:**
- Consumes: `scripts/build.sh`, `scripts/test_build.sh`, `scripts/test_php_syntax.sh`, `tests/php/run_tests.php`, `BTOP_VERSION`, entités `version`/`md5` de `btop.plg`.
- Produces: GitHub Releases taguées `YYYY.MM.DD[a-f]` contenant `.txz` + `.md5` ; commits de bump sur `main` (marqués `[skip ci]`).

- [ ] **Step 1: Écrire `ci.yml`**

`.github/workflows/ci.yml` :

```yaml
name: CI
on:
  pull_request:
  push:
    branches: [main]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: PHP unit tests
        run: php tests/php/run_tests.php
      - name: PHP syntax (php + page files)
        run: bash scripts/test_php_syntax.sh
      - name: Validate plg XML
        run: xmllint --noout --noent btop.plg
      - name: Build package and verify contents
        run: bash scripts/test_build.sh
```

- [ ] **Step 2: Écrire `release.yml`**

`.github/workflows/release.yml` :

```yaml
name: Release
on:
  workflow_dispatch:
    inputs:
      btop_version:
        description: "btop version (e.g. 1.4.7) — empty = latest upstream"
        required: false
        default: ""
permissions:
  contents: write
concurrency: release
jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Resolve target btop version
        id: btop
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          V="${{ inputs.btop_version }}"
          if [ -z "$V" ]; then
            V="$(gh api repos/aristocratos/btop/releases/latest --jq .tag_name)"
            V="${V#v}"
          fi
          echo "version=$V" >> "$GITHUB_OUTPUT"

      - name: Compute plugin version (UTC date + suffix on collision)
        id: plg
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          BASE="$(date -u +%Y.%m.%d)"
          VER="$BASE"
          for s in a b c d e f; do
            gh release view "$VER" -R "$GITHUB_REPOSITORY" >/dev/null 2>&1 || break
            VER="$BASE$s"
          done
          echo "version=$VER" >> "$GITHUB_OUTPUT"

      - name: Run tests
        run: |
          php tests/php/run_tests.php
          bash scripts/test_php_syntax.sh

      - name: Build package
        run: bash scripts/build.sh "${{ steps.btop.outputs.version }}" "${{ steps.plg.outputs.version }}"

      - name: Smoke test the binary
        run: |
          OUT="$(dist/pkgroot/usr/bin/btop --version)"
          echo "$OUT"
          echo "$OUT" | grep -F "${{ steps.btop.outputs.version }}"

      - name: Create GitHub release (artifacts first, plg bump after)
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          gh release create "${{ steps.plg.outputs.version }}" \
            dist/unraid.btop-*.txz dist/unraid.btop-*.md5 \
            -t "${{ steps.plg.outputs.version }} (btop v${{ steps.btop.outputs.version }})" \
            -n "Packages btop v${{ steps.btop.outputs.version }}.
          Upstream artifact SHA256: $(cat dist/btop-*.sha256)"

      - name: Bump btop.plg and BTOP_VERSION
        run: |
          PLGVER="${{ steps.plg.outputs.version }}"
          BTOPVER="${{ steps.btop.outputs.version }}"
          MD5="$(awk '{print $1}' dist/unraid.btop-*.md5)"
          sed -i -E "s|(<!ENTITY version +\").*(\">)|\1${PLGVER}\2|" btop.plg
          sed -i -E "s|(<!ENTITY md5 +\").*(\">)|\1${MD5}\2|" btop.plg
          awk -v entry="###${PLGVER}\n- Update btop to v${BTOPVER}" \
            '{print} /^<CHANGES>$/{print entry}' btop.plg > btop.plg.new
          mv btop.plg.new btop.plg
          xmllint --noout --noent btop.plg
          echo "$BTOPVER" > BTOP_VERSION
          git config user.name "github-actions[bot]"
          git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
          git add btop.plg BTOP_VERSION
          git commit -m "release: ${PLGVER} (btop v${BTOPVER}) [skip ci]"
          git push
```

- [ ] **Step 3: Écrire `check-btop-update.yml`**

`.github/workflows/check-btop-update.yml` :

```yaml
name: Check btop update
on:
  schedule:
    - cron: "30 5 * * *"
  workflow_dispatch:
permissions:
  actions: write
  contents: read
jobs:
  check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Compare packaged version with upstream latest
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          CURRENT="$(cat BTOP_VERSION)"
          LATEST="$(gh api repos/aristocratos/btop/releases/latest --jq .tag_name)"
          LATEST="${LATEST#v}"
          if [ "$CURRENT" != "$LATEST" ]; then
            echo "New btop release: $LATEST (packaged: $CURRENT) — triggering release"
            gh workflow run release.yml -R "$GITHUB_REPOSITORY" -f btop_version="$LATEST"
          else
            echo "Up to date ($CURRENT)"
          fi
```

- [ ] **Step 4: Valider les workflows localement (si PyYAML disponible)**

Run: `python3 -c "import yaml" 2>/dev/null && for f in .github/workflows/*.yml; do python3 -c "import yaml; yaml.safe_load(open('$f'))" && echo "$f OK"; done || echo "PyYAML absent — GitHub validera les workflows au push"`
Expected: trois lignes `... OK`, ou le message de repli (GitHub rejette de toute façon un workflow YAML invalide au push).

- [ ] **Step 5: Commit**

```bash
git add .github/
git commit -m "ci: build/test sur PR, release automatisée, suivi quotidien des versions btop"
```

---

### Task 7: README du repo

**Files:**
- Create: `README.md`

**Interfaces:**
- Consumes: URL d'installation du `.plg` (Task 5), fonctionnement de la CI (Task 6).

- [ ] **Step 1: Écrire `README.md`**

```markdown
# unraid-btop

Unraid plugin for [btop](https://github.com/aristocratos/btop): the btop
resource monitor in your terminal, plus a native dashboard tile.

## Features

- **`btop` CLI** available in SSH and the Unraid web terminal, with settings
  persisted on the flash drive across reboots.
- **Dashboard tile**: load average, CPU temperature and the top 5 processes
  by CPU — the things the stock Unraid dashboard doesn't show.
- **Fullscreen btop** in the browser (one click from the tile), served through
  Unraid's own authenticated web-terminal infrastructure.
- **Auto-updated**: CI tracks btop releases daily, rebuilds, smoke tests and
  publishes a plugin update automatically.

## Install

Unraid 6.12.0 or later, x86_64.

Plugins → Install Plugin →

    https://raw.githubusercontent.com/Greite/unraid-btop/main/btop.plg

Or search for “btop” in Community Applications (once listed).

## Notes

- Don't install alongside the NerdTools btop package — both ship `/usr/bin/btop`.
- GPU monitoring inside btop is unavailable (the static musl binary cannot
  load NVML/ROCm libraries).
- Uninstalling keeps your btop settings in `/boot/config/plugins/btop/config/`.

## Development

    bash scripts/build.sh "$(cat BTOP_VERSION)"   # build dist/unraid.btop-<date>-x86_64-1.txz
    bash scripts/test_build.sh                    # build + verify package contents
    php tests/php/run_tests.php                   # unit tests for the stats library
    bash scripts/test_php_syntax.sh               # php -l on all php/page files

Releases are fully automated: `check-btop-update.yml` (daily cron) detects new
btop releases and triggers `release.yml`, which builds, smoke tests, publishes
the GitHub Release and bumps `btop.plg` + `BTOP_VERSION` on `main`.

## License

Plugin code: MIT. btop itself is Apache-2.0 (its LICENSE ships in the package
at `/usr/share/btop/LICENSE`).
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: README du repo (install, notes, dev, automatisation)"
```

---

### Task 8: Template Community Apps dans `unraid-templates`

**Files:**
- Create: `/Users/g-painteaux/Projects/perso/unraid-templates/btop/btop.xml`
- Modify: `/Users/g-painteaux/Projects/perso/unraid-templates/README.md` (tableau des templates)

**Interfaces:**
- Consumes: `PluginURL` = URL raw du `btop.plg` (Task 5), icône du repo `unraid-btop` (Task 1).

- [ ] **Step 1: Écrire `btop/btop.xml` dans le repo `unraid-templates`**

```xml
<?xml version="1.0"?>
<Container version="2">
  <Name>btop</Name>
  <Plugin>true</Plugin>
  <PluginURL>https://raw.githubusercontent.com/Greite/unraid-btop/main/btop.plg</PluginURL>
  <PluginAuthor>Greite</PluginAuthor>
  <Support>https://github.com/Greite/unraid-btop/issues</Support>
  <Project>https://github.com/aristocratos/btop</Project>
  <Overview>btop resource monitor for Unraid. Installs the btop CLI (available over SSH and the web terminal, settings persisted across reboots) and adds a native dashboard tile showing load average, CPU temperature and the top 5 processes by CPU usage, with one-click fullscreen btop in the browser. Auto-updates track upstream btop releases. Requires Unraid 6.12+, x86_64. Note: do not install alongside the NerdTools btop package.</Overview>
  <Category>Tools:System</Category>
  <Icon>https://raw.githubusercontent.com/Greite/unraid-btop/main/source/btop/usr/local/emhttp/plugins/btop/images/btop.png</Icon>
  <TemplateURL>https://raw.githubusercontent.com/Greite/unraid-templates/main/btop/btop.xml</TemplateURL>
  <MinVer>6.12.0</MinVer>
</Container>
```

- [ ] **Step 2: Valider et ajouter la ligne au README de `unraid-templates`**

Run: `xmllint --noout /Users/g-painteaux/Projects/perso/unraid-templates/btop/btop.xml && echo XML_OK`
Expected: `XML_OK`

Dans le tableau `## Available templates` du README, ajouter :

```markdown
| [btop](./btop/btop.xml) | Unraid plugin: btop CLI + dashboard tile (top processes, load, CPU temp) | plugin (`btop.plg`) |
```

- [ ] **Step 3: Commit dans `unraid-templates`**

```bash
cd /Users/g-painteaux/Projects/perso/unraid-templates
git add btop/btop.xml README.md
git commit -m "feat: template plugin btop (unraid-btop)"
```

(Ne pas pousser sans l'accord de l'utilisateur — la publication CA rend le plugin visible.)

---

### Task 9: Publication GitHub, première release et validation sur le serveur

Cette tâche nécessite l'accord de l'utilisateur (création du repo public) et son serveur Unraid pour la validation finale.

**Files:** aucun nouveau — exécution et vérification.

**Interfaces:**
- Consumes: tout ce qui précède.

- [ ] **Step 1: Créer le repo GitHub et pousser (après accord utilisateur)**

```bash
cd /Users/g-painteaux/Projects/perso/unraid-btop
gh repo create Greite/unraid-btop --public --source . --push
```

- [ ] **Step 2: Lancer la première release**

```bash
gh workflow run release.yml -R Greite/unraid-btop
gh run watch -R Greite/unraid-btop --exit-status
```

Expected: run vert ; `gh release list -R Greite/unraid-btop` montre une release datée avec `.txz` + `.md5` ; `btop.plg` sur `main` contient la vraie version et le vrai MD5 (plus `00000000...`).

- [ ] **Step 3: Installer sur le serveur Unraid (manuel, checklist spec §7)**

Sur le GUI Unraid : Plugins → Install Plugin → `https://raw.githubusercontent.com/Greite/unraid-btop/main/btop.plg`. Vérifier :

- [ ] la tuile « btop » apparaît sur le dashboard (load, temp, top 5 processus cohérents avec `htop`) et se met à jour toutes les ~3 s ;
- [ ] deux onglets dashboard ouverts en parallèle → les deux tuiles vivent ;
- [ ] bouton plein écran → vrai btop interactif ; fermer la fenêtre → `pgrep -f btop` ne montre plus la session ttyd après quelques secondes ;
- [ ] `btop` fonctionne en SSH et via le web terminal ; changer un réglage dans btop, vérifier qu'il est écrit sous `/boot/config/plugins/btop/config/` ;
- [ ] reboot du serveur → plugin réinstallé automatiquement, réglages conservés ;
- [ ] désinstallation depuis la page Plugins → plus de tuile, plus de `/usr/bin/btop`, plus de processus ttyd, `/boot/config/plugins/btop/config/` conservé.

- [ ] **Step 4: Valider le circuit de mise à jour auto**

```bash
gh workflow run check-btop-update.yml -R Greite/unraid-btop
gh run watch -R Greite/unraid-btop --exit-status
```

Expected: log `Up to date (<version>)` (la release vient d'être faite). Le circuit détection→release a déjà été exercé de bout en bout si la première release a été déclenchée par ce workflow.

- [ ] **Step 5: Pousser le template CA (après accord utilisateur)**

```bash
cd /Users/g-painteaux/Projects/perso/unraid-templates && git push
```

Puis vérifier l'apparition dans Apps → (repo déjà référencé par CA) et créer un thread de support sur le forum Unraid si les modérateurs CA le demandent.
