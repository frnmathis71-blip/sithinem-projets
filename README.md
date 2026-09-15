# Sithi Nem — commandes à emporter

Application Laravel 13 / Livewire 4 / Blade / Tailwind. Paiement exclusivement sur place. L’authentification Fortify du projet est conservée. L’administration nécessite le rôle `admin` ; les inscriptions créent uniquement des clients.

## Démarrer en local

PHP 8.4 et ses extensions habituelles, Composer, Node.js et npm sont nécessaires. Pour PostgreSQL : extension `pdo_pgsql`.

```sh
composer setup
php artisan storage:link
composer dev
```

Avec Herd, ouvrir `http://sithinem.test`. Le dépôt fonctionne aussi avec SQLite pour le développement. En production, utiliser PostgreSQL, et tester également avec ce moteur.

Pour charger la carte d’exemple, uniquement en environnement local :

```sh
php artisan db:seed --class=RestaurantDemoSeeder
```

Mettre `RESTAURANT_DEMO=true` dans `.env` pour identifier cette carte comme une démonstration. Les produits d’exemple ne représentent pas la carte réelle du restaurant. Aucun administrateur ni mot de passe prédéfini n’est créé.

Créer son compte sur `/register`, puis lui accorder l’administration :

```sh
php artisan restaurant:admin votre-email@example.com
```

L’espace de gestion se trouve sur `/admin`. Le menu permet d’accéder aux commandes, créneaux, horaires, catégories, plats, menus, offres et statistiques. Les clients retrouvent leurs commandes sur `/mes-commandes` et leurs coordonnées sur `/mon-compte`.

## Formules de menu

Les formules sont créées uniquement dans **Administration → Carte → Formules de menu** (`/admin/menus-personnalisables`). Le gérant choisit leur nom, leurs catégories, leur prix fixe, leur activation et leur visibilité. Les nouveaux formulaires proposent **20 €** pour les tests. Les clients sélectionnent une formule dans **Menus**, puis leurs produits sur sa page dédiée.

Un **plat classique** peut être commandé seul à la carte ; son accompagnement compatible reste obligatoire dans une formule. Un **plat du chef** inclut déjà sa garniture et ne demande jamais de choix séparé. Les deux catégories ne se mélangent pas dans une formule. Une proposition non bloquante permet de convertir les mêmes produits à la carte vers une formule active si elle est avantageuse.

Les types de catégories et les restrictions d’accompagnement restent modifiables dans la gestion de la carte. Les catégories héritées sans type métier doivent être classées pour participer aux formules. Une formule masquée mais active reste accessible par son lien et peut être proposée en conversion ; la désactiver empêche toute nouvelle commande.

Le [document de conception et de recette](docs/menus-personnalisables.md) décrit les données, les wireframes, l’algorithme et les tests. Appliquer les migrations et reconstruire les assets après mise à jour. Le seeder local fournit des exemples sans remplacer les tarifs déjà modifiés.

## Créneaux et concurrence

- Vendredi et samedi, 18h00–23h00 initialement ; horaires modifiables et exceptions datées prioritaires.
- Créneaux complets de 20 minutes, dernier départ à 22h40 pour une fermeture à 23h00.
- Délai minimal de 30 minutes exactes, secondes incluses, dans le fuseau Europe/Paris. Réservations ouvertes sur 30 jours.
- Capacité par défaut initiale : 10 commandes. Surcharge par créneau facultative ; zéro ferme les réservations ; champ vide rétablit l’héritage du réglage global.
- Comptage de toutes les commandes non annulées, y compris celles déjà retirées. `restant = max(0, capacité − commandes)` ; seuil « presque complet » : 20 % restant, au moins une place.
- Capacité réduite, fermeture manuelle ou changement d’horaires : conservation de toutes les commandes, avec avertissement dans la gestion des créneaux.
- Annuler libère la capacité. Réactiver doit respecter à nouveau toutes les conditions de réservation.

`RestaurantLock` sérialise les écritures métier grâce à la ligne unique des paramètres, verrouillée avant toute lecture de disponibilité. `OrderService` verrouille ensuite le créneau, contrôle les règles, recalcule le panier et enregistre commande, lignes, historique et notification dans la même transaction. Les mutations d’horaires, de capacités et de catalogue prennent le même verrou. Cette stratégie privilégie la simplicité et la cohérence pour un restaurant unique.

Sur PostgreSQL, `SELECT FOR UPDATE` protège les écritures concurrentes. Sur SQLite, une écriture sans changement acquiert le verrou avant les lectures. Toutes les écritures métier doivent passer par ces services ; ne pas contourner ce protocole dans un futur import ou endpoint.

Une clé de validation unique par utilisateur rend la création idempotente. Le navigateur actualise les disponibilités toutes les 20 secondes ; seul le serveur décide de l’acceptation. Un échec conserve le panier. Les noms, compositions et prix en centimes sont figés dans les lignes de commande.

## Notifications push

La bibliothèque `minishlink/web-push` implémente Web Push/VAPID. Les abonnements sont limités aux services de navigateur reconnus et aux administrateurs. Le service worker n’effectue pas de cache hors ligne des données privées.

Sur le serveur, générer les clés une seule fois :

```sh
php artisan restaurant:push-keys --subject=mailto:contact@votre-domaine.fr
```

La commande conserve les clés existantes, écrit les nouvelles dans `.env` et ne les affiche pas. Sauvegarder ces clés : les remplacer impose de réabonner les appareils. Après un changement de configuration, redémarrer les workers.

Si Windows indique « Unable to create the key », définir `OPENSSL_CONF` dans l’environnement du **terminal avant de démarrer PHP**, avec un fichier de configuration OpenSSL valide. Ce réglage doit aussi être transmis au worker. Par exemple, si Git pour Windows est installé :

```powershell
$env:OPENSSL_CONF = 'C:\Program Files\Git\usr\ssl\openssl.cnf'
php artisan restaurant:push-keys --subject=mailto:contact@votre-domaine.fr
php artisan queue:work database --sleep=1 --tries=1 --timeout=60
```

Le définir seulement dans `.env` ne suffit pas sur certaines distributions PHP, car OpenSSL lit son environnement au démarrage du processus.

Configurer un worker permanent et le planificateur :

```sh
php artisan queue:work database --sleep=1 --tries=1 --timeout=60
php artisan schedule:work
```

En production, le planificateur peut être lancé par cron chaque minute avec `php artisan schedule:run`. Il exécute le rattrapage toutes les 10 secondes. Superviser le worker pour le relancer après un arrêt.

L’événement est persisté avant l’envoi. La mise en file intervient juste après le commit ; le planificateur récupère les événements dont la mise en file ou l’envoi a échoué. Les appareils ayant déjà reçu l’envoi sont mémorisés. Les abonnements expirés sont supprimés ; les échecs sont réessayés avec temporisation et signalés sur le dashboard. La livraison fonctionne au moins une fois, avec un tag de notification stable par commande pour limiter les doublons visuels.

Sur chaque appareil, ouvrir le dashboard **en HTTPS** et utiliser « Activer les notifications ». Certains navigateurs mobiles nécessitent l’ajout du site à l’écran d’accueil. L’envoi ne garantit pas une réception instantanée si le navigateur, le réseau ou le système bloque les notifications. Le flux des nouvelles commandes reste consultable et se rafraîchit toutes les 10 secondes.

## Statistiques et paiement

Les montants des périodes proviennent des commandes non annulées par date de création (UTC en base). Les périodes sont délimitées en heure de Paris. Comparaison avec la même durée écoulée de la période précédente, sans division par zéro.

Les indicateurs de service utilisent la date de retrait. Les produits remis sont calculés à partir des compositions figées des commandes retirées. L’encaissement est une action manuelle et idempotente ; aucun paiement en ligne n’est présent. Une annulation ne supprime pas un encaissement historique. Les remboursements doivent être traités séparément : aucun module de remboursement ou de comptabilité n’est fourni.

## Vérifications

```sh
composer test
npm run build
php tests/Integration/CapacityConcurrency.php
```

Les tests fonctionnels couvrent les rôles, les vues, les prix, les compositions, les offres, les créneaux, la diminution de capacité, les fermetures, les annulations, les doubles validations et les erreurs de push.

Le test de concurrence nécessite un serveur PostgreSQL et une base de test dédiée. Variables optionnelles : `PG_TEST_HOST` (127.0.0.1), `PG_TEST_PORT` (55439), `PG_TEST_DATABASE` (restaurant_test), `PG_TEST_USER` (postgres), `PG_TEST_PASSWORD` (vide). Il crée et supprime uniquement un schéma temporaire propre au test. Il lance deux processus PHP avec deux connexions distinctes et vérifie leur attente effective sur un verrou PostgreSQL, puis :

1. Deux clients sur une dernière place : exactement une commande.
2. Réduction de capacité pendant une réservation en attente : rejet et conservation de la commande existante.
3. Fermeture manuelle pendant une réservation en attente : rejet.

La CI fournit PostgreSQL 17 et exécute ce test. Pour exécuter aussi les tests fonctionnels sur PostgreSQL, fournir `DB_CONNECTION=pgsql` et les variables `DB_*` d’une **base de test dédiée**, car `RefreshDatabase` en reconstruit les tables.

## Déployer

1. Configurer PHP, PostgreSQL et HTTPS ; la racine web doit être `public/`.
2. Installer avec `composer install --no-dev --optimize-autoloader`, puis `npm ci && npm run build`.
3. Configurer `.env` : `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://votre-domaine.fr`, `APP_LOCALE=fr`, `DB_CONNECTION=pgsql`, paramètres `DB_*`, `SESSION_SECURE_COOKIE=true`, `QUEUE_CONNECTION=database`, `RESTAURANT_DEMO=false`.
4. Générer `APP_KEY` une seule fois (`php artisan key:generate`), puis `php artisan migrate --force` et `php artisan storage:link`.
5. Prévoir un disque **persistant** pour `storage/app/public` et les sauvegardes ; vérifier les permissions du processus web. Les fichiers uploadés acceptés sont JPEG, PNG et WebP, jusqu’à 20 Mo par photo, puis compressés en WebP et redimensionnés automatiquement à 1600 pixels maximum. PHP doit autoriser 20 Mo par fichier et 128 Mo par requête (voir `public/.user.ini`). HEIC nécessite une conversion préalable en JPEG.
6. Configurer les clés push et un vrai transport email SMTP pour la récupération de mot de passe. La configuration locale `MAIL_MAILER=log` n’envoie pas d’email.
7. Activer worker et planificateur, créer l’administrateur, remplir la vraie carte et les horaires. Personnaliser le nom et les coordonnées du restaurant avant ouverture publique.
8. Exécuter `php artisan optimize`, puis `php artisan queue:restart` à chaque livraison. Vérifier `/up`, le parcours complet de commande et les notifications sur les appareils du restaurant.

Sauvegarder PostgreSQL, les images et les secrets de configuration et tester la restauration. Les clés privées et les fichiers `.env` ne doivent jamais être versionnés.

## Organisation

- `app/Services` : règles de catalogue, disponibilité, réservation et statistiques.
- `app/Http/Controllers` : parcours client, administration et abonnements push.
- `app/Http/Middleware/RequireAdmin.php` : séparation client/administrateur.
- `app/Jobs`, `app/Console/Commands` : envois et opérations de configuration.
- `app/Models`, `database/migrations` : données et relations.
- `resources/views/restaurant`, `resources/css/restaurant.css` : interfaces responsives françaises.
- `public/sw.js` : notifications du navigateur.
- `routes/web.php` : routes et contrôles d’accès ; `php artisan route:list --except-vendor` pour les inspecter.

Les plages de service actuelles sont limitées à une plage par jour, sans passage de minuit. Un restaurant ayant plusieurs services quotidiens nécessitera d’étendre le modèle d’horaires. La base utilise un restaurant unique.
