# Spec - Labeling des processus VM (nom de la VM à la place de qemu-system-x86_64)

Date : 2026-07-23
Statut : validé (design approuvé en session de brainstorming)
Origine : issue GitHub #2 - avec plusieurs VMs actives, toutes les lignes qemu du tableau
de processus affichent `qemu-system-x86_64` ; impossible de savoir quelle VM consomme.
Prérequis : release `2026.07.13` (labeling Docker déjà en place, spec settings `2026-07-06`).

## 1. Objectif

Quand l'option « Label processes with » est sur le labeling par nom de container
(`PROC_NAMES=container`), les processus qemu du tableau affichent le **nom de la VM**
(ex. `Windows 11`) à la place de `qemu-system-x86_64`, exactement comme les processus
containerisés affichent le nom de leur container Docker. Aucune nouvelle option :
le réglage existant couvre Docker **et** VMs.

## 2. Décisions actées

| Décision | Choix | Alternatives écartées |
| --- | --- | --- |
| Source du nom de VM | Parsing de `-name guest=<nom>` dans `/proc/<pid>/cmdline` (libvirt y met toujours le nom de la VM) | Parsing du cgroup `machine-qemu\x2dN\x2d<nom>.scope` (échappement systemd fragile, noms non-ASCII mutilés) ; interrogation libvirt/virsh (appel externe, contraire au principe « no CLI call ») |
| Option de réglage | Valeur interne `container` inchangée (compat configs existantes) ; libellé du dropdown → « Docker container / VM name » | Nouvelle valeur d'enum ou option séparée (migration inutile, l'issue demande un comportement automatique) |
| Champ JSON | Réutilisation de `cname` (la tuile affiche déjà `cname \|\| name`, tooltip « X · Y ») | Champ `vmname` dédié (changement JS pour zéro bénéfice) |
| Détection des processus VM | Nom de process commençant par `qemu-system` (couvre `qemu-system-x86_64`, `-aarch64`, etc.), et seulement si aucun nom de container Docker trouvé | Lire la cmdline de tous les processus du top (lectures inutiles) |

## 3. Collecte (`stats_lib.php`)

### 3.1 Nouveau parser pur

`btop_vm_name(string $cmdline): ?string` - prend le contenu **brut NUL-séparé** de
`/proc/<pid>/cmdline`, retourne le nom de la VM ou `null` :

1. découpe sur `\0`, cherche l'argument `-name` ; absent → `null` ;
2. prend l'argument suivant ; s'il commence par `guest=`, retire ce préfixe
   (qemu lancé à la main avec `-name mavm` fonctionne aussi) ;
3. coupe les sous-options à la première virgule **simple** (`,debug-threads=on`),
   en dé-échappant `,,` → `,` (libvirt échappe ainsi les virgules des noms de VM) ;
4. chaîne vide après parsing → `null`.

Les noms avec espaces ne posent aucun problème : la cmdline est NUL-séparée, l'espace
fait partie de l'argument.

### 3.2 Intégration dans le mapping existant

`btop_map_container_names()` est renommée **`btop_map_proc_labels()`** (elle ne fait
plus que du Docker) ; même signature `(array $procs, string $procRoot, string $dockerRoot)`.
Pour chaque process du top, dans l'ordre :

1. tentative Docker (cgroup → id → nom), inchangée ;
2. si pas de `cname` posé **et** que `name` commence par `qemu-system` : lecture de
   `$procRoot/<pid>/cmdline`, `btop_vm_name()`, et si non-null →
   `$proc['cname'] = btop_truncate_name($nom)`.

Process disparu entre l'échantillonnage et le mapping → lecture en échec silencieux,
process ignoré (même politique que le cgroup). `stats.php` ne change que le nom de
fonction appelée ; le gate `PROC_NAMES === 'container'` est inchangé.

## 4. UI

- `SettingsBtop.page` : libellé de l'option → `_(Docker container / VM name)_` ;
  texte d'aide complété : les processus qemu affichent le nom de leur VM.
- `btop-tile.js` : aucun changement fonctionnel ; le commentaire sur `cname` est mis à
  jour (« Docker container name » → « container/VM name »). Le tooltip donne
  naturellement « Windows 11 · qemu-system-x86_64 ».

## 5. Erreurs & cas limites

| Situation | Comportement |
| --- | --- |
| qemu sans `-name` (lancé à la main) | Garde son nom de process |
| Nom de VM avec virgules | Dé-échappement `,,` → `,` |
| Nom de VM très long | Tronqué à 48 chars (`btop_truncate_name`, issue #1) |
| Architecture non-x86 (`qemu-system-aarch64`…) | Couverte par le préfixe `qemu-system` |
| Pid disparu entre sampling et mapping | Ignoré silencieusement |
| Process Docker nommé `qemu-system*` (VM dans un container) | Nom du container prioritaire (étape 1 avant étape 2) |

## 6. Tests

1. **Unitaires parser** : cmdline libvirt réelle (`-name guest=Windows 11,debug-threads=on`
  → `Windows 11`), `-name mavm` sans `guest=`, virgules échappées (`guest=a,,b,debug-threads=on`
  → `a,b`), pas de `-name` → `null`, `-name` dernier argument → `null`.
2. **Intégration** : fixture qemu existante (pid 100) enrichie d'un `-name guest=…` ;
   le top proc mappé reçoit `cname` = nom de VM et garde `name` = process. Ajout d'un
   process host neutre dans les fixtures pour conserver le test « host proc gets no cname »
   (les compteurs des tests existants concernés sont ajustés).
3. Suite existante verte ; `test_php_syntax.sh` couvre les fichiers modifiés automatiquement.
4. **Serveur** : avec 2+ VMs actives et le labeling activé, chaque ligne qemu affiche le
   nom de sa VM ; tooltip montre les deux noms ; VMs arrêtées/redémarrées suivies au refresh.

## 7. Hors scope

- Labeling des processus auxiliaires d'une VM (virtiofsd, swtpm…) - seuls les processus
  qemu portent un `-name` ;
- regroupement/agrégation des threads d'une même VM ;
- toute nouvelle option de réglage.
