# Labeling des processus VM - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Quand `PROC_NAMES=container`, les processus qemu du tableau de la tuile affichent le nom de leur VM (parsé depuis `-name guest=` dans leur cmdline) au lieu de `qemu-system-x86_64`, comme les processus Docker affichent leur nom de container (issue #2).

**Architecture:** Un parser pur `btop_vm_name()` extrait le nom de VM d'une cmdline qemu NUL-séparée. La fonction de mapping existante `btop_map_container_names()` est renommée `btop_map_proc_labels()` et tente le nom de VM pour les processus `qemu-system*` sans container Docker. Le champ JSON `cname` est réutilisé : aucun changement fonctionnel côté tuile.

**Tech Stack:** PHP (plugin Unraid, pas de framework), tests maison `tests/php/run_tests.php` (fixtures /proc), pages dynamix `.page`.

**Spec:** `docs/superpowers/specs/2026-07-23-vm-process-labels-design.md`

## Global Constraints

- Pas de fonction PHP 8-only dans `stats_lib.php` (utiliser `strncmp`, pas `str_starts_with`) — le code existant se limite à PHP 7.4+ (`fn()`).
- Tout nom affiché passe par `btop_truncate_name()` (cap 48 chars, issue #1).
- La valeur de config `PROC_NAMES=container` ne change pas (compat configs existantes) ; seul le libellé UI change.
- Textes UI en anglais avec balises de traduction `_( )_` (convention dynamix).
- Messages de commit : préfixe `feat:`/`docs:` en minuscules, description en français **sans accents**, référence `(issue #2)` (convention du repo).
- Suite de tests : `php tests/php/run_tests.php` doit finir sur `ALL TESTS PASSED` ; syntaxe : `bash scripts/test_php_syntax.sh`.
- Piège PHP : dans une chaîne double-quoted, ne jamais faire suivre `\0` d'un chiffre 0-7 (`"\04096"` = octal `\040` + `96`). Les cmdline de test de ce plan sont écrites pour éviter ce cas.

---

### Task 1: Parser `btop_vm_name()`

**Files:**
- Modify: `source/btop/usr/local/emhttp/plugins/btop/include/stats_lib.php` (avant le bloc `/* Docker container mapping ... */`, ~ligne 143)
- Test: `tests/php/run_tests.php` (nouvelle section après la ligne 158)

**Interfaces:**
- Consumes: rien (parser pur).
- Produces: `btop_vm_name(string $cmdline): ?string` — prend le contenu brut NUL-séparé de `/proc/<pid>/cmdline`, retourne le nom de VM ou `null`. Utilisée par Task 2.

- [ ] **Step 1: Écrire les tests qui échouent**

Dans `tests/php/run_tests.php`, insérer avant la ligne `echo $failures === 0 ...` (fin de fichier) :

```php
// --- v4 (issue #2): VM name parsed from the qemu command line ---
check('vm name from libvirt cmdline', 'Windows 11',
    btop_vm_name("qemu-system-x86_64\0-name\0guest=Windows 11,debug-threads=on\0-nodefaults\0"));
check('vm name without guest= prefix', 'mavm',
    btop_vm_name("qemu-system-x86_64\0-name\0mavm\0"));
check('vm name unescapes doubled commas', 'a,b',
    btop_vm_name("qemu\0-name\0guest=a,,b,debug-threads=on\0"));
check('vm name without -name arg', null,
    btop_vm_name("qemu-system-x86_64\0-machine\0q35\0"));
check('vm name with -name as last arg', null,
    btop_vm_name("qemu-system-x86_64\0-name\0"));
check('vm name empty guest value', null,
    btop_vm_name("qemu\0-name\0guest=,debug-threads=on\0"));
```

- [ ] **Step 2: Vérifier qu'ils échouent**

Run: `php tests/php/run_tests.php`
Expected: erreur fatale `Call to undefined function btop_vm_name()` (les tests précédents affichent `ok - ...` avant l'erreur).

- [ ] **Step 3: Implémenter le parser**

Dans `source/btop/usr/local/emhttp/plugins/btop/include/stats_lib.php`, ajouter juste avant le bloc `/* Docker container mapping ... */` (ligne 143) :

```php
/* VM processes all run as "qemu-system-x86_64" which makes several VMs
   indistinguishable in the tile (issue #2). libvirt puts the VM name on the
   qemu command line (-name guest=<name>,debug-threads=on) - parse it there. */

function btop_vm_name(string $cmdline): ?string {
    $args = explode("\0", $cmdline);
    $i = array_search('-name', $args, true);
    if ($i === false || !isset($args[$i + 1])) return null;
    $val = $args[$i + 1];
    if (strncmp($val, 'guest=', 6) === 0) $val = substr($val, 6);
    // Sub-options start at the first single comma (",debug-threads=on");
    // libvirt escapes commas inside VM names by doubling them
    preg_match('/^(?:[^,]|,,)*/', $val, $m);
    $name = str_replace(',,', ',', $m[0]);
    return $name !== '' ? $name : null;
}
```

- [ ] **Step 4: Vérifier que tout passe**

Run: `php tests/php/run_tests.php`
Expected: `ALL TESTS PASSED`

- [ ] **Step 5: Commit**

```bash
git add source/btop/usr/local/emhttp/plugins/btop/include/stats_lib.php tests/php/run_tests.php
git commit -m "feat: parseur du nom de VM depuis la cmdline qemu (issue #2)"
```

---

### Task 2: Intégration dans le mapping + fixtures

**Files:**
- Modify: `source/btop/usr/local/emhttp/plugins/btop/include/stats_lib.php` (bloc `btop_map_container_names`, lignes 143-172)
- Modify: `source/btop/usr/local/emhttp/plugins/btop/include/stats.php:67`
- Modify: `tests/php/fixtures/proc_t0/100/cmdline`, `tests/php/fixtures/proc_t1/100/cmdline`
- Create: `tests/php/fixtures/proc_t0/600/stat`, `tests/php/fixtures/proc_t0/600/cmdline`, `tests/php/fixtures/proc_t1/600/stat`, `tests/php/fixtures/proc_t1/600/cmdline`, `tests/php/fixtures/proc_t1/600/cgroup`
- Test: `tests/php/run_tests.php` (lignes 42 et 154-158)

**Interfaces:**
- Consumes: `btop_vm_name(string): ?string` (Task 1), `btop_truncate_name(string): string`, `btop_container_id`/`btop_container_name` (existants).
- Produces: `btop_map_proc_labels(array $procs, string $procRoot, string $dockerRoot): array` — remplace `btop_map_container_names` (même signature, même sémantique du champ `cname`). `stats.php` l'appelle ; plus aucune référence à l'ancien nom.

- [ ] **Step 1: Mettre à jour les fixtures**

Le pid 100 (qemu, top CPU) reçoit une cmdline libvirt réaliste ; un pid 600 (nginx, host, ni Docker ni VM) est ajouté aux deux échantillons pour conserver un cas « host proc sans cname » dans le top.

```bash
cd tests/php/fixtures
printf 'qemu-system\0-name\0guest=Windows 11,debug-threads=on\0' > proc_t0/100/cmdline
printf 'qemu-system\0-name\0guest=Windows 11,debug-threads=on\0' > proc_t1/100/cmdline
mkdir -p proc_t0/600 proc_t1/600
echo '600 (nginx) S 1 600 600 0 -1 4194560 0 0 0 0 20 10 0 0 20 0 1 0 200 8192000 500 18446744073709551615' > proc_t0/600/stat
echo '600 (nginx) S 1 600 600 0 -1 4194560 0 0 0 0 20 10 0 0 20 0 1 0 200 8192000 500 18446744073709551615' > proc_t1/600/stat
printf 'nginx\0' > proc_t0/600/cmdline
printf 'nginx\0' > proc_t1/600/cmdline
echo '0::/' > proc_t1/600/cgroup
cd ../../..
```

Conséquences sur le top (t0→t1, tri CPU puis mem) : `[qemu-system (84.0), nginx (0.0, rss 500 pages), sshd (0.0, rss 3 pages)]` — 3 processus au lieu de 2.

- [ ] **Step 2: Adapter les tests existants et ajouter les tests d'intégration (échec attendu)**

Dans `tests/php/run_tests.php` :

Ligne 42, le top compte maintenant 3 processus :

```php
check('process appearing between samples ignored', 3, count($top));
```

Remplacer le bloc des lignes 154-158 (`// proc_t1: pid 200 (sshd) has a docker cgroup...` jusqu'au dernier `check('host proc gets no cname', ...)`) par :

```php
// proc_t1: pid 100 (qemu) has a -name guest= cmdline, pid 200 (sshd) a docker
// cgroup, pid 600 (nginx) neither
$mapped = btop_map_proc_labels(btop_top_procs($s0, $s1, 0.5, 5), "$fx/proc_t1", "$fx/docker");
check('vm proc gets the VM name as cname', 'Windows 11', $mapped[0]['cname'] ?? null);
check('vm proc keeps its process name', 'qemu-system', $mapped[0]['name']);
check('host proc gets no cname', false, isset($mapped[1]['cname']));
check('containerized proc gets cname', 'plex', $mapped[2]['cname'] ?? null);
check('containerized proc keeps its process name', 'sshd', $mapped[2]['name']);
```

- [ ] **Step 3: Vérifier l'échec**

Run: `php tests/php/run_tests.php`
Expected: erreur fatale `Call to undefined function btop_map_proc_labels()` (après des `ok - ...`).

- [ ] **Step 4: Renommer et étendre la fonction de mapping**

Dans `stats_lib.php`, remplacer le bloc commentaire + fonction `btop_map_container_names` (lignes 143-172) par :

```php
/* Container / VM labels. A containerized process shows up under its inner
   name ("ffmpeg", "node") and every VM under "qemu-system-x86_64" - neither
   means much on an Unraid box. The actionable label is the container name
   ("plex") or the VM name ("Windows 11"). The container id comes from the
   process cgroup and its name from Docker's own metadata (no docker CLI
   call); the VM name from the qemu command line (btop_vm_name above). */

function btop_container_id(string $cgroup): ?string {
    // cgroup v2: 0::/docker/<id> ; v1: N:ctrl:/docker/<id> ; systemd: docker-<id>.scope
    return preg_match('~/docker[/-]([0-9a-f]{64})~', $cgroup, $m) ? $m[1] : null;
}

function btop_container_name(string $id, string $dockerRoot): ?string {
    if (!preg_match('/^[0-9a-f]{64}$/', $id)) return null;
    $raw = @file_get_contents("$dockerRoot/containers/$id/config.v2.json");
    if ($raw === false) return null;
    $name = json_decode($raw, true)['Name'] ?? null;
    return is_string($name) && $name !== '' ? ltrim($name, '/') : null;
}

function btop_map_proc_labels(array $procs, string $procRoot, string $dockerRoot): array {
    $names = []; // id -> name, resolved once even if several procs share a container
    foreach ($procs as &$proc) {
        $cgroup = @file_get_contents("$procRoot/{$proc['pid']}/cgroup");
        if ($cgroup === false) continue; // process already gone
        $id = btop_container_id($cgroup);
        if ($id !== null) {
            if (!array_key_exists($id, $names)) $names[$id] = btop_container_name($id, $dockerRoot);
            if ($names[$id] !== null) $proc['cname'] = btop_truncate_name($names[$id]);
            continue;
        }
        if (strncmp($proc['name'], 'qemu-system', 11) !== 0) continue;
        $cmdline = @file_get_contents("$procRoot/{$proc['pid']}/cmdline");
        if ($cmdline === false) continue;
        $vm = btop_vm_name($cmdline);
        if ($vm !== null) $proc['cname'] = btop_truncate_name($vm);
    }
    return $procs;
}
```

Note : le commentaire de Task 1 au-dessus de `btop_vm_name` devient redondant avec ce bloc — le réduire à :

```php
/* VM name from a qemu command line: libvirt always passes
   -name guest=<name>,debug-threads=on (issue #2). */
```

Puis dans `stats.php` ligne 67, remplacer :

```php
                $rates['procs'] = btop_map_container_names($rates['procs'], '/proc', '/var/lib/docker');
```

par :

```php
                $rates['procs'] = btop_map_proc_labels($rates['procs'], '/proc', '/var/lib/docker');
```

- [ ] **Step 5: Vérifier que tout passe et qu'aucune référence à l'ancien nom ne reste**

Run: `php tests/php/run_tests.php`
Expected: `ALL TESTS PASSED`

Run: `grep -rn "btop_map_container_names" source/ tests/ && echo "REFS RESTANTES" || echo "OK"`
Expected: `OK`

- [ ] **Step 6: Commit**

```bash
git add source/btop/usr/local/emhttp/plugins/btop/include/stats_lib.php \
        source/btop/usr/local/emhttp/plugins/btop/include/stats.php \
        tests/php/run_tests.php tests/php/fixtures
git commit -m "feat: affiche le nom de la VM pour les processus qemu (issue #2)"
```

---

### Task 3: Libellés UI et commentaire tuile

**Files:**
- Modify: `source/btop/usr/local/emhttp/plugins/btop/SettingsBtop.page:74,77`
- Modify: `source/btop/usr/local/emhttp/plugins/btop/scripts/btop-tile.js:111`

**Interfaces:**
- Consumes: rien (textes uniquement, la valeur `container` reste inchangée).
- Produces: rien.

- [ ] **Step 1: Mettre à jour le libellé du dropdown et l'aide**

Dans `SettingsBtop.page` ligne 74, remplacer `_(Docker container name)_` par `_(Docker container / VM name)_` (l'attribut `value="container"` ne change pas) :

```php
: <select name="PROC_NAMES"><option value="process"<?=btop_sel($btop_cfg['PROC_NAMES'], 'process')?>>_(Process name)_</option><option value="container"<?=btop_sel($btop_cfg['PROC_NAMES'], 'container')?>>_(Docker container / VM name)_</option></select>
```

Ligne 77, remplacer le texte d'aide par :

```
> Labels each row of the process table with the process name, or with the name of the Docker container or virtual machine the process belongs to. Processes matching neither keep their process name; hovering a row always shows both.
```

- [ ] **Step 2: Mettre à jour le commentaire de la tuile**

Dans `btop-tile.js` ligne 111, remplacer :

```js
    // cname = Docker container name, sent when the "container" labelling is on
```

par :

```js
    // cname = Docker container or VM name, sent when the "container" labelling is on
```

- [ ] **Step 3: Vérifier la syntaxe et la suite complète**

Run: `bash scripts/test_php_syntax.sh && php tests/php/run_tests.php`
Expected: pas d'erreur de syntaxe, `ALL TESTS PASSED`

- [ ] **Step 4: Commit**

```bash
git add source/btop/usr/local/emhttp/plugins/btop/SettingsBtop.page \
        source/btop/usr/local/emhttp/plugins/btop/scripts/btop-tile.js
git commit -m "feat: libelle du labeling etendu aux VMs dans les settings (issue #2)"
```

---

## Vérification serveur (manuelle, après merge)

Sur le serveur Unraid (alias ssh `unraid`), avec 2+ VMs actives et « Label processes with » sur « Docker container / VM name » : chaque ligne qemu de la tuile affiche le nom de sa VM, le tooltip montre « <VM> · qemu-system-x86_64 ». La release passe par le flux CI habituel (tag après merge sur main) ; l'entrée détaillée du changelog `.plg` se fait à ce moment-là, hors scope de ce plan.
