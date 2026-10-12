# Fonctionnement interne

## Parcours d’une commande

1. `StoreController` charge le catalogue et conserve les références et quantités du panier en session. `CatalogService` vérifie les produits, variantes, compositions et prix ; `DynamicMenuService` gère les formules personnalisées.
2. `SlotService::bookingDates` détermine les jours réservables sur 30 jours. Une exception datée prend toujours le pas sur l’horaire hebdomadaire, même lorsqu’elle ferme le restaurant.
3. `SlotService::forDate` complète les créneaux manquants et calcule leur disponibilité. Le plafond et la consommation sont exprimés en centimes ; les commandes annulées sont exclues, les commandes terminées restent comptées.
4. `OrderService::place` prend le verrou global du restaurant, puis celui du créneau, recalcule le panier et vérifie la capacité actuelle. La clé de validation évite de créer deux commandes lors d’un double envoi. Les lignes enregistrent les noms, compositions et prix au moment de la commande.
5. La commande, son historique et l’événement de notification sont enregistrés dans la transaction. Le push est mis en file après validation de la transaction ; le planificateur assure le rattrapage des événements en attente. Le paiement reste enregistré manuellement sur place.

## Lectures optimisées

- Les jours réservables chargent les horaires hebdomadaires et les exceptions en deux requêtes, au lieu de les rechercher séparément pour chaque jour.
- Les rafraîchissements des créneaux existants lisent les créneaux une seule fois et regroupent les montants et nombres de commandes dans une requête. Les horaires de la journée sont partagés entre les calculs. Les créneaux historiques restent présents après une modification des horaires.
- L’endpoint de disponibilités réutilise les paramètres et la liste des dates pour construire sa réponse.
- La composition d’un menu ou d’une offre charge ses produits et leurs parents par lots. Les produits archivés restent identifiables dans la composition, mais sont refusés pour une nouvelle commande.
- Les statistiques regroupent somme et nombre dans la même requête pour chaque période et chaque point du graphique. Les bornes restent calculées dans le fuseau du restaurant puis converties en UTC.

Aucun cache persistant de capacité, de tarif ou de disponibilité n’est ajouté. Une nouvelle lecture tient compte des changements en base. `SlotService::describe` relit toujours les montants pour les contrôles de réservation et de réactivation ; le regroupement des lectures de présentation ne remplace pas ce contrôle sous verrou.

## Contrôles de non-régression

`tests/Feature/InternalOptimizationTest.php` vérifie le nombre de requêtes, la priorité des exceptions, l’égalité des descriptions groupées et individuelles, les annulations, l’archivage des produits et les frontières de journée à Paris. La suite fonctionnelle existante couvre les parcours et les règles métier. Le test PostgreSQL séparé vérifie la concurrence réelle lorsque son serveur de test est disponible.

Les modifications concernent le serveur et cette documentation : aucun changement de vue, de CSS, de JavaScript ou de texte affiché n’est nécessaire.
