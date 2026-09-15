# Formules de menu — modèle et recette

## Règles appliquées

Le restaurant crée les formules dans Administration → Carte → Formules de menu. Le client choisit une formule existante dans l’onglet Menus, puis personnalise uniquement ses produits sur une page dédiée. Le constructeur libre de catégories n’est jamais exposé au client.

| Catégorie | À la carte | Dans une formule |
|---|---|---|
| Plat classique (main) | Plat seul autorisé ; accompagnement facultatif, séparé ou réuni au plat | Choix du plat, puis accompagnement obligatoire |
| Plat du chef (chef_main) | Produit complet, au prix affiché, garniture intégrée | Choix du plat du chef, sans sélection ni facturation d’un accompagnement séparé |

Les formules utilisent un prix fixe défini par le gérant (option A), en centimes côté serveur. Les nouveaux formulaires d’administration proposent 20 € comme valeur de test. Chaque formule peut avoir son propre nom et son propre prix, même si d’autres formules utilisent les mêmes catégories.

Arbitrages retenus : Plat et Plat du chef sont mutuellement exclusifs dans une formule ; les menus sans plat sont permis ; un seul produit par catégorie ; au moins une catégorie, sans plafond artificiel de formules actives. L’ordre d’affichage est réglable. Pour proposer deux boissons, le client les ajoute séparément à la carte ; une formule ne possède pas deux emplacements Boisson.

## Modèle de données

### Produits et catégories

categories.code distingue starter, main, chef_main, side, dessert, drink. Les catégories héritées sans code doivent être classées depuis la gestion de la carte pour être proposées dans les formules. Les produits hérités non classés gardent leur ancien comportement à la carte.

Product::requiresSide() identifie les plats main dont l’accompagnement est obligatoire dans une formule uniquement. Product::includesSide() vaut vrai uniquement pour chef_main. Ces deux propriétés sont dérivées du code métier, donc aucun booléen éditable ne peut contredire la catégorie. Les déclinaisons vendables héritent de la catégorie et des restrictions de leur parent.

restricted_sides et compatible_side_ids (JSON d’identifiants entiers) définissent la compatibilité des plats classiques. Sans restriction, tous les accompagnements disponibles sont autorisés. Une restriction vide empêche de choisir ce plat dans un menu mais autorise toujours son achat seul à la carte. Les restrictions sont sans effet pour les plats du chef.

### Formules

La table menu_rules représente les formules :

| Champ | Rôle |
|---|---|
| id | Identifiant stable de la formule |
| name, description | Présentation libre du gérant |
| category_key | Catégories choisies, dans l’ordre canonique starter,main,chef_main,dessert,drink |
| price | Prix fixe en centimes |
| available | Activation des commandes et propositions |
| visible | Affichage dans l’onglet Menus |
| position | Ordre d’affichage, puis identifiant pour départager |
| version | Incrémentée à chaque modification administrative |

Accompagnement n’est jamais une catégorie cochée dans l’administration : MenuRule::steps() l’insère uniquement après main. L’ordre des sélections est Entrée → Plat ou Plat du chef → Accompagnement si Plat → Dessert → Boisson, en omettant les catégories absentes.

available et visible sont indépendants : l’onglet liste seulement les formules actives et visibles. Une formule masquée mais active reste accessible par lien direct et peut être suggérée pour faire économiser le client. Désactiver une formule la retire également de la détection et empêche sa commande, même depuis un ancien panier.

### Panier et commande

Le panier conserve le contrat clé → quantité. Une clé composed: encode les sélections, l’identifiant de formule et un UUID d’instance. Cet encodage n’est jamais considéré comme une validation : tous les identifiants, catégories, disponibilités et tarifs sont relus sur le serveur. Sans identifiant de formule, une ligne composée est exclusivement un couple plat classique/accompagnement à la carte.

Une formule du chef contient une sélection chef_main ; une formule classique contient main et side. Le mélange des deux ou l’ajout d’un side à une formule du chef est rejeté. La quantité d’une ligne répète la composition entière. Limites existantes : 50 lignes et 50 unités par ligne.

order_items conserve le nom de formule, ses produits et le prix facturé, ainsi que instance_uuid et pricing_snapshot (identifiant/version de formule, catégories et prix de référence). Le nom et le prix des anciennes commandes restent figés même après modification du catalogue.

La migration 020000 ajoute les champs des formules, nomme les tarifs existants et retire l’unicité des catégories sans supprimer les anciens tarifs ou commandes. Les anciennes compositions du chef qui exigeaient une garniture séparée doivent être recomposées ; elles ne sont pas transformées ni facturées silencieusement. Revenir à l’ancien schéma nécessite de consolider d’abord les éventuelles formules partageant les mêmes catégories ; la migration refuse de perdre ces données.

## Wireframes

### Administration

~~~text
Créer / modifier une formule
Nom : [Menu du chef gourmand]
Description : [...]
Catégories : [ ] Entrée [ ] Plat [x] Plat du chef [x] Dessert [ ] Boisson
Garniture du chef incluse : aucun choix d’accompagnement séparé.
Prix : [20,00 €]    Ordre : [0]
[x] Formule active    [x] Visible dans Menus
[Enregistrer la formule]
~~~

Le choix Plat décoche Plat du chef, et inversement. Le serveur rejette aussi une requête qui forcerait les deux. L’activation et la visibilité sont expliquées dans le formulaire et affichées dans la liste de gestion.

### Onglet client Menus

~~~text
Tout voir | Entrées | Plats | Plats du chef | Desserts | Menus | Offres
Nos menus
[Menu du chef gourmand]     [Menu Entrée + Plat + Dessert]
Plat du chef + Dessert      Entrée + Plat + Dessert
Garniture déjà incluse      Accompagnement au choix compris
20,00 €                    Prix fixé par le restaurant
[Choisir ce menu]           [Choisir ce menu]
~~~

Les vignettes affichent nom, description éventuelle, catégories et prix. La page vide renvoie vers la carte sans proposer de création libre.

### Personnalisation d’une formule

~~~text
Menu du chef gourmand                         [← Les menus]
Étape 1 / 2 : Choisissez votre plat du chef
[Photo : Curry]  [Photo : Lok Lak]
Garniture incluse
[Précédent]                 [Suivant →]

Au toucher de Curry : fenêtre de déclinaisons
[Photo carrée : Poulet] [Photo carrée : Crevettes]
Sélection → fermeture automatique, choix enregistré.

Étape 2 / 2 : Choisissez votre dessert
[Photo : Tiramisu] [Photo : Flan]
Récapitulatif et total : 20,00 €
[Précédent]                 [Ajouter au panier]
~~~

Une catégorie par écran, cartes visuelles uniquement. Navigation par boutons ou swipe horizontal, sans perdre les choix précédents. Modifier le plat efface seulement un accompagnement devenu incompatible. Le dernier écran affiche l’ajout au panier, actif uniquement lorsque tous les choix sont complets. JavaScript est nécessaire pour ce parcours ; un message l’explique s’il est désactivé. La validation serveur reste obligatoire.

### Proposition de conversion non bloquante

~~~text
Votre commande correspond à « Menu du chef gourmand » !
Ramen du chef · Dessert déjà sélectionné
À la carte : 23,00 € → En formule : 20,00 €
Vous économisez 3,00 €.
[Basculer vers le menu] [Non merci, continuer à la carte] [×]
~~~

Les montants de ce wireframe sont illustratifs. Le panneau réel utilise exclusivement les prix calculés. Fermer et refuser ont le même effet : aucun changement au panier et mémorisation du refus exact.

## Détection détaillée

1. À l’affichage après chaque mutation du panier, extraire les unités disponibles à la carte. Ignorer les offres, les menus déjà constitués et les lignes invalides.
2. Conserver main + side comme un couple indivisible ; rapprocher également un plat et un accompagnement achetés séparément lorsque leur compatibilité le permet. Un chef_main reste une unité complète, sans side ; il ne devient jamais un candidat main.
3. Parcourir toutes les formules actives, y compris les formules masquées. Chercher un sous-ensemble du panier contenant exactement un produit par catégorie de la formule. Les articles supplémentaires restent indépendants.
4. Pour chaque formule, classer les candidats de chaque catégorie par prix décroissant et explorer les combinaisons via une file de priorité. Les quantités ne sont pas développées en objets répétés.
5. Comparer la somme des produits concernés au prix de la formule. Retenir uniquement une économie strictement positive. La meilleure économie immédiate est proposée ; en cas d’égalité, l’identifiant de formule départage. Pour deux formules de même composition, la moins chère gagne. Cela ne promet pas l’optimisation globale de plusieurs menus successifs.
6. La signature de refus contient les clés des lignes consommées, formule/version et montants. Ajouter un produit sans rapport ne repropose pas la même conversion ; une nouvelle composition ou un nouveau tarif peut en générer une autre. Historique limité à 100 signatures en session.
7. À l’acceptation, recalculer le devis et vérifier la signature ainsi que l’empreinte du panier. Si une catégorie, un tarif ou une disponibilité a changé, conserver le panier et demander de vérifier la nouvelle proposition.
8. Décrémenter les seules unités utilisées, retirer les lignes devenues vides, créer une ligne de menu avec les mêmes produits et renouveler la clé de commande. Les quantités restantes sont conservées.

Les mutations utilisent le verrou de session Laravel ; les modifications administratives suivent RestaurantLock. OrderService revalide les produits et le prix sous le verrou de réservation. Un changement de total depuis l’affichage du panier demande une nouvelle confirmation. Les doubles soumissions restent idempotentes.

## Recette et exploitation

| Cas | Résultat attendu |
|---|---|
| Plat seul à la carte | Ajout accepté au prix du plat |
| Menu classique sans garniture ou avec garniture incompatible | Rejet serveur, panier intact |
| Plat du chef seul, même avec restrictions de garnitures vides | Ajout direct, sans fenêtre ni surcoût de garniture |
| Formule chef + dessert | Deux choix, sans section side |
| Formule classique + dessert | Plat, accompagnement compatible, dessert |
| Produit chef soumis comme main ou inversement | Rejet serveur |
| Catégorie hors formule, choix manquant ou prix client falsifié | Rejet ou prix serveur appliqué |
| Formule main + chef forcée par requête | Rejet sans mutation |
| Plusieurs formules de même structure | Création autorisée ; meilleure économie proposée |
| Formule inactive | Absente de l’onglet, non commandable, non suggérée |
| Formule masquée mais active | Absente de l’onglet ; lien direct et conversion possibles |
| Refus puis ajout sans rapport | Panier inchangé, même proposition non répétée |
| Chef + deux desserts à la carte | Conversion d’un dessert ; l’autre unité reste à la carte |
| Double conversion ou tarif modifié | Aucun double prélèvement d’unités ni changement silencieux |
| Déclinaison de plat du chef | Garniture intégrée héritée ; disponibilité du parent respectée |
| Catalogue modifié après commande | Nom, produits et montants historiques inchangés |

Exécuter composer test et npm run build. Les tests DynamicMenusTest et MenuFormulasTest couvrent ces règles serveur, les pages et l’administration. Le parcours navigateur vérifie les pages de personnalisation et le panier.

Appliquer php artisan migrate avant de reconstruire les assets. En local/test, RestaurantDemoSeeder crée des exemples de formules à 20 € sans écraser les tarifs et noms déjà enregistrés. Le constructeur administratif n’énumère ni n’impose ces exemples. La migration de production ne crée aucune nouvelle formule commerciale.

Le test de capacité PostgreSQL nécessite son serveur dédié ; il reste distinct des tests fonctionnels SQLite. Lors de la livraison précédente, 127.0.0.1:55439 était indisponible.

## Compléments de la dernière itération

### Catégories et déclinaisons

L’éditeur présente toujours les six types métier, avec les catégories personnalisées restantes dans un groupe séparé. Un type absent est créé lors de l’enregistrement du produit, jamais à la simple ouverture du formulaire. La suppression d’une catégorie conserve ses produits désactivés, à reclasser explicitement.

Les déclinaisons restent des lignes products avec parent_id, variant_label, image et price. Le parent porte le prix minimum des déclinaisons ; prix_supplement est dérivé de la différence avec ce minimum dans les données de sélection. Le gérant modifie les prix et photos de chaque déclinaison dans l’éditeur. Les formules gardent leur prix fixe sans supplément implicite.

La carte et les écrans de menu ne montrent qu’une carte par produit parent. La modale présente uniquement les déclinaisons disponibles en carrés photo + nom. Une seule déclinaison disponible est choisie directement. Une image de déclinaison remplace celle du parent ; à défaut, l’image parent est utilisée, puis une illustration explicitement identifiée. Le prix à la carte figure dans la modale d’achat, sans modifier le prix forfaitaire des menus.

### Photos et serveur

JPG/JPEG, PNG et WebP jusqu’à 20 Mo par photo. GD convertit en WebP qualité 82, côté long limité à 1600 pixels, proportions conservées, orientation EXIF corrigée pour les JPEG. Les cartes recadrent au centre par CSS, sans détériorer le fichier source redimensionné. HEIC n’est pas pris en charge par le décodeur GD installé : exporter en JPEG avant import.

public/.user.ini prévoit upload_max_filesize=20M, post_max_size=128M et memory_limit=512M pour un hébergement PHP/FastCGI classique. Herd utilise un routeur PHP externe : les mêmes limites ont donc été appliquées à son php.ini local, avec sauvegarde php.ini.before-catalog-uploads et redémarrage PHP. Les limites effectives ont été vérifiées par une requête HTTP, puis la route temporaire a été supprimée. Vérifier ces paramètres et la limite du corps HTTP lors d’un déploiement sur un autre serveur.

### Accueil et popularité

config/restaurant.php définit featured_limit=6 et featured_min_units=3. À chaque affichage, les unités des commandes non annulées sont cumulées, y compris les compositions dont les identifiants produits sont connus ; les variantes sont regroupées sous leur parent. Les boissons et desserts sont exclus, ainsi que les produits indisponibles. Les produits atteignant le seuil apparaissent par quantité décroissante, puis les plats du chef complètent les places restantes. Les anciens composants de menu sans identifiant produit ne sont pas rapprochés par nom pour éviter les faux comptages.

CatalogExperienceTest couvre les six catégories, les photos volumineuses portrait/paysage et de variantes, les prix fixes, la conversion de lignes séparées et la popularité. Recette navigateur sur une base SQLite isolée : modales, swipe aller-retour, conservation des choix, dernier écran, ajout du menu à 20 €, ajout d’une déclinaison à la carte et plat seul. Aucune commande réelle n’a été passée.
