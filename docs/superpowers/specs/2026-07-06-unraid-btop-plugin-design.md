# Spec — Plugin Unraid « btop »

Date : 2026-07-06
Statut : validé (design approuvé en session de brainstorming, révisé : tuile native stats au lieu de l'iframe)

## 1. Objectif

Un plugin Unraid qui :

1. installe le CLI **btop** sur le serveur (utilisable en SSH et dans le web terminal Unraid) ;
2. ajoute une **tuile au dashboard** Unraid affichant en HTML natif les infos que le dashboard ne montre pas déjà (top processus, load average, température CPU), avec un bouton pour ouvrir le **vrai btop plein écran** dans le navigateur ;
3. est publié sur **Community Apps** via le repo de templates existant `Greite/unraid-templates`.

Le code du plugin vit dans le repo `Greite/unraid-btop` (ce dossier). Modèle de packaging : `ich777/intel-gpu-top`. Modèle de tuile dashboard : le fichier `.page` d'exemple de `SimonFair/gpustat-unraid` (format officiel des tuiles 6.12+).

Version minimale d'Unraid : **6.12.0** (nouveau dashboard à tuiles + infra `logterminal`).

## 2. Décisions actées

| Décision | Choix | Alternatives écartées |
| --- | --- | --- |
| Forme du panneau dashboard | Tuile native HTML (stats lues depuis /proc et les capteurs) + bouton plein écran vers le vrai btop | Iframe btop live via ttyd (un seul viewer, session à gérer) ; tuile-bouton seule |
| Pourquoi pas « extraire » btop | btop est une TUI sans mode batch/export (ni JSON ni texte) : impossible de scraper sa sortie proprement | — |
| Contenu de la tuile | Ce qu'Unraid n'affiche pas déjà : top processus (CPU/RAM), load average, température CPU | Dupliquer les jauges CPU/RAM/réseau déjà natives du dashboard |
| Backend plein écran | Infra ttyd/nginx native d'Unraid (`/logterminal/<tag>/` → `unix:/var/tmp/<tag>.sock`, auth Unraid incluse) | ttyd dédié sur un port TCP (pas d'auth, mixed-content HTTPS) |
| Distribution | Community Apps, template dans `Greite/unraid-templates` | Repo perso seul ; install locale manuelle |
| Source du binaire | Release officielle btop `btop-x86_64-linux-musl` (Apache-2.0), repackagée dans notre `.txz` | Compilation maison ; dépendance au `.tbz` upstream à l'install |
| Nom du paquet Slackware | `unraid.btop` (préfixé, comme `intel.gpu.top` d'ich777) | `btop` nu (conflit avec le paquet NerdTools) |
| Versioning | Date (`YYYY.MM.DD`), convention plugins Unraid | SemVer |
| Hébergement des paquets | GitHub Releases (+ MD5 dans le `.plg`) | `.txz` committés dans le repo |

## 3. Architecture

### 3.1 Arborescence du repo `unraid-btop`

```
unraid-btop/
├── btop.plg                        # fichier plugin XML (racine)
├── source/btop/usr/
│   ├── bin/                        # btop (injecté au build, non committé)
│   ├── share/btop/themes/          # thèmes officiels (injectés au build)
│   └── local/emhttp/plugins/btop/
│       ├── btop-dashboard.page     # tuile dashboard native
│       ├── include/
│       │   ├── stats.php           # endpoint JSON : load, temp, top processus
│       │   └── start_session.php   # endpoint : démarre la session ttyd plein écran
│       ├── scripts/btop-tile.js    # polling + rendu de la tuile
│       ├── images/btop.png         # icône du plugin
│       └── README.md               # affiché dans la page Plugins d'Unraid
├── scripts/build.sh                # build reproductible du .txz + .md5
├── docs/superpowers/specs/         # ce document
└── README.md
```

Les binaires ne sont pas committés : `build.sh` télécharge la release officielle btop (checksum vérifié), l'injecte dans `source/btop/usr/`, construit `unraid.btop-<version>.txz` (tar propriétaire root, compression xz) et son `.md5`. Les artefacts sont publiés en GitHub Releases taguées `<version>`.

### 3.2 Le fichier `btop.plg`

Entités XML : `name=btop`, `author=Greite`, `version=YYYY.MM.DD`, `gitURL` (releases), `md5`, `min=6.12.0`, section `<CHANGES>`.

- **Install / update / boot** (rejoué à chaque boot par le système de plugins Unraid) :
  1. supprime les vieux `.txz` d'autres versions dans `/boot/config/plugins/btop/` ;
  2. télécharge `unraid.btop-<version>.txz` (MD5 vérifié) → `/boot/config/plugins/btop/` ;
  3. `upgradepkg --install-new` ;
  4. crée `/boot/config/plugins/btop/config/` si absent et pose le symlink `/root/.config/btop` → ce dossier (config CLI persistante entre reboots).
- **Remove** :
  1. tue la session ttyd du plugin (`pkill -f '/var/tmp/btop\.sock'`) ;
  2. `removepkg unraid.btop-<version>` ;
  3. supprime `/usr/local/emhttp/plugins/btop` et le symlink `/root/.config/btop` ;
  4. **conserve** `/boot/config/plugins/btop/config/` (réglages utilisateur) mais supprime les `.txz`.

### 3.3 La tuile dashboard (`btop-dashboard.page`)

En-tête du `.page` :

```
Menu="Dashboard:0"
Icon="btop.png"
Cond="version_compare(parse_ini_file('/etc/unraid-version')['version'],'6.12.0','>=')"
```

Corps : enregistre une tuile via `$mytiles['btop']['column1']` — un `<tbody>` avec le markup standard des tuiles (`title="_(btop)_"`, `tile-header`, icône, contrôles à droite, spans `w18/w26/w36/w44` pour l'alignement). Contenu **100 % HTML natif** :

- une ligne d'en-tête de données : **load average** (1/5/15 min) et **température CPU** ;
- un tableau **top 5 processus** : nom, CPU %, mémoire (RSS lisible), trié par CPU ;
- dans `tile-header-right` : un bouton plein écran (`fa-expand`) qui appelle `start_session.php` puis `window.open('/logterminal/btop/')` — c'est là que vit le vrai btop.

`scripts/btop-tile.js` : au chargement du dashboard, requête `stats.php` puis rafraîchissement toutes les 3 s (uniquement quand l'onglet est visible — `document.visibilityState` — pour ne pas échantillonner pour rien). Rendu par simple remplacement du contenu du tableau. Plusieurs onglets/viewers simultanés : aucun problème, l'endpoint est sans état.

### 3.4 L'endpoint `stats.php`

Répond un JSON :

```json
{
  "load": [0.42, 0.38, 0.35],
  "cpu_temp": 52,
  "procs": [
    {"name": "qemu-system", "cpu": 42.1, "mem": 8815321088},
    ...
  ]
}
```

Sources :

- `load` : `/proc/loadavg` ;
- `cpu_temp` : capteurs hwmon (`/sys/class/hwmon`), en réutilisant si possible la sélection de capteur déjà faite par dynamix (config « system temp ») ; `null` si aucun capteur — la tuile masque alors le champ ;
- `procs` : top 5 par **CPU instantané** — deux échantillons de `/proc/<pid>/stat` espacés d'un court intervalle (~500 ms), delta de temps CPU rapporté au temps écoulé (même principe que top/btop). `mem` = RSS. Détails d'implémentation (agrégation par processus, exclusion des kernel threads) fixés au plan.

Aucun paramètre d'entrée → pas de surface d'injection. Réservé aux sessions authentifiées (servi par emhttp comme toute page de plugin).

### 3.5 L'endpoint `start_session.php` (plein écran)

Entrée : `tag=btop` uniquement (liste blanche stricte — toute autre valeur = HTTP 400). Comportement :

1. si un ttyd vivant écoute déjà sur `/var/tmp/btop.sock` → répond `busy` (le bouton affiche « session déjà ouverte ») ;
2. si le socket existe sans processus (orphelin) → le supprime ;
3. vérifie que `/usr/bin/btop` existe, sinon répond une erreur explicite ;
4. lance : `ttyd -s 9 -o -m 1 -i /var/tmp/btop.sock btop` (mêmes options que l'invocation native d'Unraid dans `OpenTerminal.php` ; utiliser le wrapper `ttyd-exec` s'il est présent sur le système, comme le fait l'OS). btop utilise la config CLI normale (`/root/.config/btop`) ;
5. `-o` (once) + `-m 1` : la session s'arrête seule à la fermeture de la fenêtre — aucun processus résiduel. Un seul plein écran à la fois (limitation assumée, sans impact sur la tuile).

### 3.6 Le CLI

- `/usr/bin/btop` + `/usr/share/btop/themes/` installés par le paquet ;
- config persistante via le symlink vers la flash (3.2) ;
- **conflit NerdTools** : le paquet btop de NerdTools installe aussi `/usr/bin/btop`. Documenté dans le README : ne pas installer les deux (le dernier installé gagne, la désinstallation de l'un casse l'autre) ;
- **limitation GPU** : le binaire statique musl ne peut pas charger les libs GPU (NVML/ROCm via dlopen) → panneau GPU de btop indisponible. Documenté dans le README.

## 4. Publication Community Apps

- Template `btop/btop.xml` ajouté au repo `Greite/unraid-templates` : `<Plugin>true</Plugin>`, `<PluginURL>` → `https://raw.githubusercontent.com/Greite/unraid-btop/main/btop.plg`, `<Icon>`, `<Overview>`, catégorie `Tools:System` (CA le classe côté plugins via `<Plugin>true</Plugin>`), `<Support>` → GitHub Issues du repo `unraid-btop` ;
- ajout de la ligne correspondante dans le README de `unraid-templates` ;
- point d'attention : les modérateurs CA peuvent exiger un thread de support sur le forum Unraid pour les plugins — à créer au moment de la soumission si demandé.

## 5. Gestion d'erreurs

| Situation | Comportement |
| --- | --- |
| `stats.php` en erreur / réponse invalide | La tuile affiche « données indisponibles » et réessaie au tick suivant (pas de tuile cassée) |
| Aucun capteur de température | Champ temp masqué, le reste de la tuile fonctionne |
| Binaire btop absent (install corrompue) | `start_session.php` répond une erreur ; message clair côté bouton plein écran |
| Socket orphelin (crash ttyd) | Nettoyé par `start_session.php` avant relance |
| Session plein écran déjà occupée (`-m 1`) | Message « session déjà ouverte », pas de fenêtre cassée |
| `tag` non whitelisté | HTTP 400, rien n'est exécuté |
| Téléchargement `.txz` échoué / MD5 invalide | Le système de plugins Unraid annule l'installation (comportement natif du `.plg`) |
| Désinstallation | Session tuée, paquet retiré, config utilisateur conservée sur la flash |

## 6. Tests & validation

1. **Build** : `scripts/build.sh` reproductible — vérifie le checksum du binaire upstream, produit `.txz` + `.md5` ; contrôle `tar -tf` du contenu attendu.
2. **Test sur serveur réel** (le serveur Unraid de l'utilisateur) :
   - install via URL du `.plg` (branche de test) ;
   - tuile visible sur le dashboard : load, temp, top 5 processus cohérents avec `htop`/`btop`, refresh ~3 s ;
   - deux onglets dashboard ouverts simultanément → les deux tuiles vivent ;
   - bouton plein écran → vrai btop interactif dans une fenêtre, session fermée à la fermeture de la fenêtre ;
   - `btop` fonctionne en SSH et via le web terminal ;
   - reboot → plugin réinstallé automatiquement, config CLI conservée ;
   - désinstallation → plus de tuile, plus de binaire, plus de processus ttyd résiduels.
3. Pas de CI en v1 ; une GitHub Action de packaging des releases pourra venir plus tard.

## 7. Hors scope (v1)

- Mode iframe « btop live » dans la tuile (écarté au profit de la tuile native ; possible en v2 commutable) ;
- page de settings (nombre de processus affichés, intervalle de refresh) — valeurs fixes en v1 (5 processus, 3 s) ;
- support GPU dans btop ;
- architectures non x86_64.
