# Application Windows Sithinem

Logiciel de gestion du restaurant pour Windows x64. Il propose sa propre interface : il n’affiche pas les pages du site. Il offre les mêmes fonctions que l’administration web : commandes, prise de commande téléphone et comptoir, carte, formules, créneaux et capacité, calendrier d’ouverture et statistiques. Il gère aussi l’impression des tickets. Il dialogue avec le site par une API réservée aux administrateurs (`/api/desktop`), qui réutilise les mêmes services et validations que l’administration web (`App\Services\AdminOperations`, `OrderService`, `SlotService`…). Internet est nécessaire ; il n’y a pas de caisse hors ligne.

## Mettre en service

1. **Serveur** : déployer le code Laravel puis lancer `php artisan migrate`. Deux migrations sont concernées : l’origine des commandes (`source`) et les jetons du logiciel (`desktop_tokens`).
2. **Poste** : lancer l’installateur `Sithinem-Setup-<version>.exe` une seule fois. Il installe le logiciel pour l’utilisateur courant et crée un raccourci sur le Bureau. Le poste n’a besoin ni de PHP, ni de Node.js, ni de Herd.
3. Dans **Poste et imprimante**, enregistrer l’adresse **HTTPS à la racine** du site, par exemple `https://restaurant.fr`.
4. Se connecter avec un compte **administrateur** du site. Si la double authentification est activée sur ce compte, le code est demandé. Le mot de passe n’est jamais conservé : le site délivre un jeton propre à ce poste, chiffré par Windows (`safeStorage`). Ce jeton est révoqué par **Déconnexion** et expire après 30 jours sans utilisation. Il ne fonctionne plus si le compte perd son rôle d’administrateur.
5. Imprimante : installer le pilote dans Windows, puis choisir l’imprimante et le format 58/80 mm dans **Poste et imprimante**. Saisir le jeton d’impression du serveur (`RESTAURANT_PRINT_TOKEN`, au moins 32 caractères). Cliquer sur **Imprimer un test** et vérifier le ticket, puis activer l’impression automatique.

L’installateur n’est pas signé : Windows peut afficher une alerte d’éditeur inconnu (« Informations complémentaires » puis « Exécuter quand même »).

## Mises à jour automatiques

On n’installe le logiciel qu’une fois. Ensuite, il vérifie au démarrage, puis toutes les 4 heures, si une nouvelle version est publiée sur le site, à l’adresse `<site>/logiciel/mises-a-jour/latest.yml`. Il la télécharge en arrière-plan, puis un bandeau propose **Redémarrer pour l’installer**. Sans action, la mise à jour s’installe à la prochaine fermeture du logiciel. Le menu **Restaurant → Rechercher une mise à jour** lance une vérification immédiate.

Publier une nouvelle version :

```powershell
cd desktop
# 1. Augmenter "version" dans package.json (par exemple 1.0.0 → 1.0.1)
npm test
npm run dist           # construit release/Sithinem-Setup-1.0.1.exe et release/latest.yml
npm run publish:site   # copie l’installateur et latest.yml dans public/logiciel/mises-a-jour
```

Déployer ensuite le dossier `public/logiciel/mises-a-jour` sur le serveur. Il est ignoré par Git à cause de sa taille (environ 110 Mo) : l’envoyer par FTP ou SFTP, ou par l’outil de déploiement. Le manifeste `latest.yml` est copié en dernier, pour qu’un poste ne voie jamais une version dont l’installateur manque. Ce même fichier `.exe` sert aussi à installer un nouveau poste.

Pour vérifier qu’un poste détecte la version publiée, sans rien télécharger ni installer :

```powershell
$env:SITHINEM_SMOKE_SITE = 'https://restaurant.fr'; $env:SITHINEM_SMOKE_OUTPUT = "$env:TEMP\sithinem-check"
& "$env:LOCALAPPDATA\Programs\Sithinem\Sithinem.exe" --check-update   # écrit update-check.json
```

## Les écrans

- **Commandes** : tableau du jour actualisé toutes les 4 s, filtrable par jour, avec trois vues (en préparation, historique, toutes). Le détail d’une commande permet de la marquer terminée, d’enregistrer l’encaissement, de l’annuler ou de la remettre en préparation, comme sur le site. On peut aussi imprimer son ticket sur l’imprimante du poste ou en voir l’aperçu. L’impression de toutes les commandes du jour et la relance des tickets en erreur passent par la file du serveur. Une nouvelle commande, sur n’importe quel écran, déclenche un son (désactivable), une notification Windows et une pastille dans le menu.
- **Prise de commande** : carte par catégorie avec recherche, déclinaisons et accompagnement au choix des plats classiques, formules composées étape par étape, et offres en cours. Le panier est recalculé par le serveur à chaque modification, et le regroupement en formule est proposé quand il fait économiser. Viennent ensuite le client (nom, téléphone, origine téléphone ou comptoir), la date, le créneau (seuls les créneaux possibles sont sélectionnables) et les consignes. Après enregistrement, on peut encaisser, imprimer le ticket ou commencer une nouvelle commande. Le brouillon est conservé sur le poste en cas de fermeture. Le serveur contrôle les prix, les disponibilités, le délai de préparation et la capacité ; la clé d’idempotence évite les doublons en cas de double clic ou de coupure. Le téléphone reste obligatoire, y compris au comptoir.
- **Carte** : catégories (nom, type, ordre, suppression), plats (prix, disponibilité, catégorie, réservation aux offres, photo, déclinaisons avec prix, disponibilité et photo, accompagnements autorisés), offres (composition, produit spécifique, période de validité) et archivage.
- **Formules** : nom, description, prix fixe, ordre, activation, visibilité et catégories. Une formule ne peut pas proposer à la fois un plat classique et un plat du chef.
- **Créneaux** : capacité par défaut, puis pour chaque créneau la charge, une capacité personnalisée, la fermeture manuelle et l’accès à ses commandes.
- **Calendrier** : vue mensuelle, horaires habituels par jour, ouvertures et fermetures exceptionnelles.
- **Statistiques** : chiffre d’affaires et nombre de commandes par période avec comparaison, activité des 14 derniers jours (avec tableau des données), service du jour et commandes à préparer.

Raccourcis : Ctrl+1 à Ctrl+7 pour les écrans, Ctrl+N pour une nouvelle commande, F5 pour actualiser, Ctrl+plus et Ctrl+moins pour agrandir ou réduire, F11 pour le plein écran.

La fenêtre n’a ni barre de titre Windows ni barre de menus : les boutons réduire, agrandir et fermer s’affichent dans la bande supérieure, aux couleurs du logiciel, et cette bande sert à déplacer la fenêtre. Les anciennes entrées de menu se trouvent dans le menu latéral (« Poste et imprimante », « Mises à jour », « Déconnexion ») ou dans l’icône près de l’horloge (« Quitter »).

Depuis la version 1.1.0, le logiciel s’appelle « Sithinem » : raccourci, menu Démarrer, exécutable `Sithinem.exe` et installateur `Sithinem-Setup-<version>.exe`. Ses réglages restent dans le dossier `%APPDATA%\Sithi Nem`, pour que la mise à jour conserve ceux des postes déjà configurés. L’icône est générée depuis le logo (`desktop/build/icon.png`, `desktop/ui/img`).

## Sécurité et fonctionnement

- L’interface est locale (`ui/app.html`). Elle n’a pas accès à Node.js et une politique de sécurité du contenu (CSP) interdit toute connexion réseau directe. Toutes les requêtes passent par le processus principal (`api.cjs`), qui n’accepte que des chemins de `/api/desktop`, ajoute le jeton, refuse les redirections et limite les envois de fichiers aux photos JPG, PNG ou WebP de 20 Mo maximum.
- Côté serveur : authentification par jeton haché (SHA-256) réservée aux administrateurs, limitée à 10 tentatives de connexion par minute et par adresse IP, et 5 par adresse e-mail. Les requêtes du logiciel sont limitées à 600 par minute et par jeton.
- Fermer la fenêtre la masque ; l’impression automatique continue. L’icône près de l’horloge permet de rouvrir le logiciel ou de le quitter. **Démarrer avec Windows** nécessite une session Windows ouverte.
- L’agent d’impression reprend le protocole existant, avec confirmation et sans réimpression automatique des tickets incertains. « Transmis au pilote » ne garantit pas la sortie physique du papier. Ne pas lancer l’ancien script d’impression en parallèle.

## Construire et vérifier

```powershell
cd desktop
npm ci
npm test        # filtre des chemins de l’API, envois de fichiers, validation des réglages
npm run dist
```

Côté serveur : `composer test` (avec `OPENSSL_CONF=C:\Program Files\Git\usr\ssl\openssl.cnf` sous Windows). L’API est couverte par `tests/Feature/DesktopApiTest.php`.

Le contrôle `--smoke-test` s’exécute avec un profil temporaire et vérifie :

- les ressources embarquées ;
- l’isolation des fenêtres ;
- le refus d’un chemin d’API hors périmètre ;
- le chiffrement du jeton d’impression.

Il enregistre aussi des captures dans `SITHINEM_SMOKE_OUTPUT`. Avec `SITHINEM_SMOKE_SITE`, `SITHINEM_SMOKE_EMAIL` et `SITHINEM_SMOKE_PASSWORD` (un compte administrateur de test, jamais un compte réel de production), il se connecte et affiche chaque écran. `SITHINEM_SMOKE_ORDER=1` enregistre en plus une vraie commande de test par l’interface : à réserver à une base de développement.

Références : [isolation Electron](https://www.electronjs.org/docs/latest/tutorial/context-isolation), [installateur NSIS](https://www.electron.build/docs/nsis/), [mises à jour electron-updater](https://www.electron.build/auto-update).
