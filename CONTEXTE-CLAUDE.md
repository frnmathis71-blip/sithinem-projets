# Reprise du projet Sithi Nem — 12 octobre 2026

## Objectif utilisateur
Créer un logiciel Windows avec installateur, relié au site du restaurant, pour gérer les commandes, les imprimantes et l’administration. L’utilisateur a confirmé vouloir aussi saisir les commandes prises au téléphone et au comptoir. Le fonctionnement hors ligne n’a pas été confirmé : la première version nécessite Internet.

## Projet et travail réalisé
- Dépôt local : `C:\Users\frnma\Herd\sithinem`. Site existant en Laravel 13, Livewire 4, Blade et Tailwind, exécuté localement avec Herd.
- Application Electron dans `desktop/`, avec installateur NSIS Windows x64. Elle ouvre l’administration existante dans une fenêtre isolée et propose des réglages locaux : adresse HTTPS du site, imprimante Windows, papier 58/80 mm, jeton d’impression chiffré, lancement avec Windows et fonctionnement en arrière-plan.
- L’impression reprend `scripts/Start-OrderPrinter.ps1` et `scripts/Print-Ticket.cs`, via les endpoints `/api/printing`. Elle conserve le protocole existant de confirmation pour éviter de réimprimer automatiquement les tickets incertains.
- Nouveau parcours administrateur `/admin/commandes/nouvelle` : composer le panier depuis la carte, puis saisir nom, téléphone, origine téléphone/comptoir, créneau et consignes. Pas de compte client ni de vérification SMS pour cette saisie administrateur. Les prix, capacités et délais habituels restent contrôlés côté serveur.
- Fichiers principaux : `StaffOrderController.php`, `OrderService::placeForStaff()`, `resources/views/restaurant/cart.blade.php`, routes et vues des commandes. Migration `2026_10_12_000000_add_source_to_orders.php` appliquée **en local** : origine `online`, `phone` ou `counter`. Pour les commandes manuelles, `user_id` identifie l’administrateur ; le nom et le téléphone du client sont stockés sur la commande, sans modifier le profil administrateur.

## Livrable et vérifications
- Installateur : `C:\Users\frnma\Herd\sithinem\desktop\release\Sithi-Nem-Setup-0.1.0.exe`.
- Une copie a été placée sur le Bureau : `C:\Users\frnma\Desktop\Sithi-Nem-Setup-0.1.0.exe`, car l’utilisateur ne trouvait pas le fichier. L’installation par l’utilisateur n’est pas confirmée.
- Dernière validation : **145 tests PHP réussis**, Pint et PHPStan réussis ; test Node de validation des réglages réussi ; test du rendu d’impression Windows sans périphérique réel réussi. Démarrage de l’exécutable compilé, pont des réglages, lecture des imprimantes et chiffrement du jeton vérifiés avec `--smoke-test`.
- Sous Windows, la suite PHP a nécessité `OPENSSL_CONF=C:\Program Files\Git\usr\ssl\openssl.cnf` pour un test existant de notifications push. Sans cette variable, ce test échouait avec « Unable to create the key ».

## À poursuivre
1. Accompagner l’installation et configurer l’adresse HTTPS du vrai site, le compte administrateur et l’imprimante. Ne pas demander ni afficher les secrets dans la conversation.
2. Déployer les modifications Laravel et la migration sur le serveur pour rendre la nouvelle saisie disponible en ligne : **aucun déploiement n’a été effectué**.
3. Faire une recette réelle : commande téléphone/comptoir, encaissement, statut, impression physique, redémarrage et coupure réseau. L’impression sur le matériel réel et le parcours complet après installation restent à vérifier.
4. Limites connues : téléphone obligatoire même au comptoir ; pas de mode hors ligne ni de mise à jour automatique ; installateur non signé (alerte Windows possible) ; icône Electron par défaut. Le fonctionnement en arrière-plan nécessite une session Windows ouverte. « Transmis au pilote » ne garantit pas la sortie physique du ticket.

## Précautions et commandes
Le répertoire contient des modifications **non commitées**, dont plusieurs préexistaient à cette tâche (optimisations dans StoreController, CatalogService, SlotService, StatisticsService, JS, tableau des commandes, README et assets). Ne pas les écraser ou les annuler. Aucun commit/push n’a été effectué pendant cette tâche.

Guide détaillé : `docs/application-windows.md`. Reconstruction : dans `desktop/`, `npm ci`, `npm test`, `npm run dist`. Vérification serveur : `composer test` avec la variable OpenSSL ci-dessus. Les sorties `desktop/release` et les dépendances sont ignorées par Git : il faut reconstruire l’installateur si le dépôt est copié sans ces fichiers.
