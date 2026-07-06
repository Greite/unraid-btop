# Spec — Plugin Unraid « btop »

Date : 2026-07-06
Statut : validé (design approuvé en session de brainstorming)

## 1. Objectif

Un plugin Unraid qui :

1. installe le CLI **btop** sur le serveur (utilisable en SSH et dans le web terminal Unraid) ;
2. ajoute une **tuile au dashboard** Unraid affichant le vrai btop en temps réel, interactif, dans une iframe ;
3. est publié sur **Community Apps** via le repo de templates existant `Greite/unraid-templates`.

Le code du plugin vit dans le repo `Greite/unraid-btop` (ce dossier). Modèle de packaging : `ich777/intel-gpu-top`. Modèle de tuile dashboard : le fichier `.page` d'exemple de `SimonFair/gpustat-unraid` (format officiel des tuiles 6.12+).

Version minimale d'Unraid : **6.12.0** (nouveau dashboard à tuiles + infra `logterminal`).

## 2. Décisions actées

| Décision | Choix | Alternatives écartées |
| --- | --- | --- |
| Forme du panneau dashboard | Tuile avec btop live embarqué (iframe) + bouton plein écran | Tuile-bouton seule ; tuile HTML native imitant btop |
| Backend d'affichage | Infra ttyd/nginx native d'Unraid (`/logterminal/<tag>/` → `unix:/var/tmp/<tag>.sock`, auth Unraid incluse) | ttyd dédié sur un port TCP (pas d'auth, mixed-content HTTPS) |
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
│       ├── btop-dashboard.page     # tuile dashboard
│       ├── include/
│       │   └── start_session.php   # endpoint : démarre/nettoie les sessions ttyd
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
  4. crée `/boot/config/plugins/btop/config/` si absent et pose le symlink `/root/.config/btop` → ce dossier (config CLI persistante entre reboots) ;
  5. seed la config de la session tuile (voir 3.4).
- **Remove** :
  1. tue les sessions ttyd du plugin (`pkill -f '/var/tmp/btop(-full)?\.sock'`) ;
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

Corps : enregistre une tuile via `$mytiles['btop']['column1']` — un `<tbody>` avec le markup standard (`title="_(btop)_"`, `tile-header`, icône, contrôles à droite). Contenu de la tuile :

- une `<iframe>` (hauteur ~400 px, largeur 100 %) dont le `src` est posé **après** l'appel réussi à l'endpoint ;
- un état « déconnecté » : message + bouton « Démarrer btop » qui rappelle l'endpoint et recharge l'iframe ;
- dans `tile-header-right` : un bouton plein écran (`fa-expand`) qui appelle l'endpoint avec `tag=btop-full` puis `window.open('/logterminal/btop-full/')`.

Flux au chargement du dashboard :

1. le JS de la tuile fait un POST vers `/plugins/btop/include/start_session.php` avec `tag=btop` ;
2. l'endpoint répond OK → le JS pose `iframe.src = '/logterminal/btop/'` ;
3. nginx proxifie vers `unix:/var/tmp/btop.sock` (WebSocket, auth de session Unraid, pas de mixed-content).

### 3.4 L'endpoint `start_session.php`

Entrée : `tag` ∈ {`btop`, `btop-full`} (liste blanche stricte — toute autre valeur = HTTP 400). Comportement :

1. si un ttyd vivant écoute déjà sur `/var/tmp/<tag>.sock` → répond `busy` (la tuile affiche « session déjà ouverte, reconnecter ») ;
2. si le socket existe sans processus (orphelin) → le supprime ;
3. vérifie que `/usr/bin/btop` existe, sinon répond une erreur explicite (affichée dans la tuile) ;
4. lance : `ttyd -s 9 -o -m 1 -i /var/tmp/<tag>.sock <cmd>` où
   - `tag=btop` (tuile) : `env XDG_CONFIG_HOME=/var/local/btop-tile btop` — config dédiée compacte : `shown_boxes="cpu mem net"`, refresh 2000 ms, thème sombre assorti au GUI Unraid, seedée par le `.plg` ;
   - `tag=btop-full` (plein écran) : `btop` avec la config CLI normale (`/root/.config/btop`) ;
5. `-o` (once) + `-m 1` : la session s'arrête seule à la déconnexion du client — aucun processus résiduel, et une seule session par tag à la fois.

**Limitation assumée (v1)** : un seul viewer par tag. Un second onglet dashboard affiche l'état « reconnecter » au lieu du btop live.

### 3.5 Le CLI

- `/usr/bin/btop` + `/usr/share/btop/themes/` installés par le paquet ;
- config persistante via le symlink vers la flash (3.2) ;
- **conflit NerdTools** : le paquet btop de NerdTools installe aussi `/usr/bin/btop`. Documenté dans le README : ne pas installer les deux (le dernier installé gagne, la désinstallation de l'un casse l'autre) ;
- **limitation GPU** : le binaire statique musl ne peut pas charger les libs GPU (NVML/ROCm via dlopen) → panneau GPU de btop indisponible. Documenté dans le README.

## 4. Publication Community Apps

- Template `btop/btop.xml` ajouté au repo `Greite/unraid-templates` : `<Plugin>true</Plugin>`, `<PluginURL>` → `https://raw.githubusercontent.com/Greite/unraid-btop/main/btop.plg`, `<Icon>`, `<Overview>`, catégorie `Tools:System Plugins:`, `<Support>` → GitHub Issues du repo `unraid-btop` ;
- ajout de la ligne correspondante dans le README de `unraid-templates` ;
- point d'attention : les modérateurs CA peuvent exiger un thread de support sur le forum Unraid pour les plugins — à créer au moment de la soumission si demandé.

## 5. Gestion d'erreurs

| Situation | Comportement |
| --- | --- |
| Binaire btop absent (install corrompue) | L'endpoint répond une erreur ; la tuile affiche le message + lien vers la page Plugins |
| Socket orphelin (crash ttyd) | Nettoyé par l'endpoint avant relance |
| Session déjà occupée (`-m 1`) | Tuile en état « reconnecter », pas d'iframe cassée |
| `tag` non whitelisté | HTTP 400, rien n'est exécuté |
| Téléchargement `.txz` échoué / MD5 invalide | Le système de plugins Unraid annule l'installation (comportement natif du `.plg`) |
| Désinstallation | Sessions tuées, paquet retiré, config utilisateur conservée sur la flash |

## 6. Tests & validation

1. **Build** : `scripts/build.sh` reproductible — vérifie le checksum du binaire upstream, produit `.txz` + `.md5` ; contrôle `tar -tf` du contenu attendu.
2. **Test sur serveur réel** (le serveur Unraid de l'utilisateur) :
   - install via URL du `.plg` (branche de test) ;
   - tuile visible sur le dashboard, btop live, interactif ;
   - bouton plein écran fonctionnel (session séparée) ;
   - `btop` fonctionne en SSH et via le web terminal ;
   - reboot → plugin réinstallé automatiquement, config CLI conservée ;
   - désinstallation → plus de tuile, plus de binaire, plus de processus ttyd résiduels.
3. Pas de CI en v1 ; une GitHub Action de packaging des releases pourra venir plus tard.

## 7. Hors scope (v1)

- Page de settings (choix du thème/preset de la tuile depuis le GUI) ;
- multi-viewers simultanés sur la tuile ;
- support GPU dans btop ;
- architectures non x86_64.
