# Spec — Page de settings et tuile configurable (unraid-btop v2)

Date : 2026-07-06
Statut : validé (design approuvé en session de brainstorming)
Prérequis : v1 publiée (spec `2026-07-06-unraid-btop-plugin-design.md`), release `2026.07.06`.

## 1. Objectif

Ajouter au plugin btop :

1. une **page de settings** native Unraid (Settings → User Utilities) pour choisir ce que la tuile dashboard affiche ;
2. de **nouvelles métriques** activables dans la tuile : I/O disque agrégé, réseau agrégé, uptime + nombre de processus ;
3. des **réglages du tableau de processus** : tri (CPU ou mémoire), nombre de lignes, intervalle de refresh.

Contrainte de compatibilité : **les défauts reproduisent exactement le comportement v1** (load + temp + top 5 CPU, 3 s) — une mise à jour ne change rien sans action de l'utilisateur.

## 2. Décisions actées

| Décision | Choix | Alternatives écartées |
| --- | --- | --- |
| Mécanisme de réglages | Page Settings native (`SettingsBtop.page`) + `btop.cfg` sur la flash, écrit par l'endpoint natif `/update.php`, lu par `parse_plugin_cfg('btop')` (pattern gpustat) | Panneau de réglages dans la tuile (endpoint d'écriture custom à sécuriser) ; localStorage navigateur (non partagé, collecte non optimisable) |
| Métriques ajoutées | I/O disque agrégé, réseau agrégé, uptime + nb de processus, tri/nombre de processus | — (les 4 retenues par l'utilisateur) |
| Débits (disque, réseau) | Delta entre les **deux échantillons de 500 ms déjà effectués** par `stats.php` — zéro échantillonnage supplémentaire | Boucle de sampling dédiée |
| Disques comptés | Physiques uniquement : `sd[a-z]+`, `nvme<n>n<n>`, `hd[a-z]+` (pas les partitions, ni `md`/`loop`/`dm-` — évite le double comptage avec l'array) | Tout `/proc/diskstats` (double comptage md/sd) |
| Interfaces comptées | Physiques uniquement (celles exposant `device` dans `/sys/class/net/<if>/`) — `br0`+`eth0` ne double-comptent pas | Toutes interfaces sauf `lo` |
| Prise en compte des réglages | Au rechargement de la page dashboard | Hot-reload de la tuile (complexité sans demande) |

## 3. Configuration

### 3.1 Fichiers

- `/boot/config/plugins/btop/btop.cfg` — réglages utilisateur (flash, survit aux reboots, conservé à la désinstallation comme le reste de `&plugin;`) ;
- `source/btop/usr/local/emhttp/plugins/btop/default.cfg` — défauts livrés dans le paquet. `parse_plugin_cfg('btop', true)` fusionne défauts + overrides.

### 3.2 Clés

| Clé | Défaut | Valeurs admises | Effet |
| --- | --- | --- | --- |
| `SHOW_LOAD` | `yes` | `yes`/`no` | Load average dans l'en-tête |
| `SHOW_TEMP` | `yes` | `yes`/`no` | Température CPU dans l'en-tête |
| `SHOW_UPTIME` | `no` | `yes`/`no` | Uptime + nombre de processus dans l'en-tête |
| `SHOW_DISKIO` | `no` | `yes`/`no` | Ligne débits disque (lecture/écriture par s) |
| `SHOW_NET` | `no` | `yes`/`no` | Ligne débits réseau (↓/↑ par s) |
| `SHOW_PROCS` | `yes` | `yes`/`no` | Tableau des processus |
| `PROC_SORT` | `cpu` | `cpu`/`mem` | Tri du tableau |
| `PROC_COUNT` | `5` | entier `3`–`10` | Nombre de lignes |
| `REFRESH_MS` | `3000` | entier `2000`–`10000` | Intervalle de refresh de la tuile |

**Validation côté PHP** (cfg éditable à la main) : toute valeur hors bornes/inconnue retombe sur le défaut (clamp pour les entiers, défaut pour les enums). Aucune valeur de cfg n'atteint un shell — lecture uniquement.

## 4. Page de settings (`SettingsBtop.page`)

- En-tête : `Menu="Utilities"`, `Title="btop"`, `Icon="btop.png"` → apparaît sous Settings → User Utilities.
- Formulaire dynamix standard : `<form markdown="1" method="POST" action="/update.php" target="progressFrame">` avec champ caché `#file` = `btop/btop.cfg` ; un `<select>` par clé (yes/no, cpu/mem, valeurs discrètes pour count et refresh) ; bouton Apply natif + Done.
- Aucun endpoint custom : `/update.php` (dynamix) écrit le cfg, avec l'authentification de session Unraid.
- Libellés en anglais (langue standard des plugins), balises de traduction `_( )_`.

## 5. Collecte

### 5.1 Nouvelles fonctions `stats_lib.php` (racines paramétrées, testables par fixtures)

- `btop_read_uptime(string $procRoot): ?int` — secondes depuis `/proc/uptime` (champ 1, arrondi), `null` si illisible.
- `btop_sample_diskio(string $procRoot): ?array` — somme des compteurs de `/proc/diskstats` pour les **disques physiques entiers** (regex `^(sd[a-z]+|hd[a-z]+|nvme\d+n\d+)$` sur le nom, champ 3) : retourne `['read_sectors' => int, 'write_sectors' => int]` (champs 6 et 10), `null` si illisible.
- `btop_sample_net(string $sysRoot): ?array` — somme de `rx_bytes`/`tx_bytes` des interfaces de `/sys/class/net/*` possédant une entrée `device` (physiques) : retourne `['rx' => int, 'tx' => int]`, `null` si aucune.
- `btop_top_procs(array $s0, array $s1, float $elapsed, int $n = 5, int $clkTck = 100, string $sort = 'cpu'): array` — signature étendue ; `$sort='mem'` trie par RSS décroissant (tie-break CPU). Le nombre de processus total est dérivé de `count($s1)` (aucune fonction dédiée).

Débits : `stats.php` prélève diskio/net **avant et après** l'`usleep(500000)` existant ; débit = delta × 512 (secteurs→bytes) / 0.5 pour le disque, delta / 0.5 pour le réseau (bytes/s).

### 5.2 `stats.php`

1. Lit et valide la cfg (fonction `btop_read_cfg(): array` dans `stats_lib.php`, défauts/clamp centralisés — testable) ;
2. Ne collecte **que les sections activées** : le double échantillonnage (500 ms) n'a lieu que si au moins une section à delta est active (`SHOW_PROCS`, `SHOW_DISKIO`, `SHOW_NET`) ; `nprocs` (pour `SHOW_UPTIME`) se calcule sur un seul échantillon ; pas de lecture `diskstats`/`net` si la section est désactivée ;
3. Réponse JSON : clés présentes uniquement si activées, plus `refresh_ms` toujours présent :

```json
{
  "load": [0.42, 0.38, 0.35],
  "cpu_temp": 52,
  "uptime": 123456,
  "nprocs": 312,
  "diskio": {"read_bps": 12300000, "write_bps": 4500000},
  "net": {"rx_bps": 850000, "tx_bps": 120000},
  "procs": [{"name": "...", "cpu": 42.1, "mem": 8815321088}],
  "refresh_ms": 3000
}
```

Section en erreur (capteur absent, fichier illisible) → clé à `null` ou absente ; jamais d'erreur fatale.

## 6. Tuile

- `btop-dashboard.page` : lit la cfg côté PHP et ne rend que les conteneurs activés (`#btop-load`, `#btop-temp`, `#btop-uptime`, `#btop-rates`, `#btop-procs` + son header de colonnes). Ajout d'une **icône engrenage** (`fa-cog`) dans `tile-header-right`, lien vers `/Settings/SettingsBtop`, à côté du plein écran (inchangé).
- `btop-tile.js` : rend chaque clé présente dans le JSON (élément DOM absent → ignoré) ; formats lisibles : uptime `Xd Xh Xm`, débits `KB/s / MB/s / GB/s` ; l'intervalle du `setInterval` = `refresh_ms` de la première réponse (défaut 3000 avant la première réponse). Comportements v1 conservés : `textContent` uniquement, pause quand onglet caché, état « Stats unavailable ».

## 7. Erreurs & compatibilité

| Situation | Comportement |
| --- | --- |
| Pas de `btop.cfg` (install neuve, upgrade depuis v1) | Défauts = tuile v1 à l'identique |
| Valeur cfg invalide (édition manuelle) | Clamp/défaut silencieux côté PHP |
| Aucun disque/interface physique détecté | Clé `null` → section masquée par le JS |
| Toutes sections désactivées | La tuile n'affiche que l'en-tête (icône + boutons) — état légal |
| Désinstallation | `btop.cfg` conservé sur la flash (même politique que `config/`) |

## 8. Tests

1. **Fixtures nouvelles** : `diskstats` deux échantillons (avec `loop0`, `md1`, `sda1` (partition) à exclure et `sda`, `nvme0n1` à compter) ; `sys/class/net` avec `eth0` (physique, compté), `br0` (virtuel, exclu), `lo` (exclu) ; `uptime` ; tri `mem` ; `btop_read_cfg` (défauts, clamp bornes, enum invalide).
2. Suite existante inchangée et verte (défauts = v1).
3. `test_php_syntax.sh` couvre la nouvelle page automatiquement.
4. **Serveur** : page settings visible et fonctionnelle (Apply → cfg écrit), toggles effectifs après refresh du dashboard, tri/nombre/refresh appliqués, débits plausibles vs `iostat`/`ifstat`, upgrade depuis la v1 sans changement visuel par défaut.
5. Release via la CI (`release.yml`) après merge — aucun changement de packaging (les nouveaux fichiers sous `source/` sont embarqués automatiquement).

## 9. Hors scope

- Hot-reload de la tuile quand la cfg change (refresh du dashboard requis) ;
- I/O par disque, réseau par interface, graphes/historiques ;
- réglages du btop plein écran (il suit la config CLI de l'utilisateur, comme en v1).
