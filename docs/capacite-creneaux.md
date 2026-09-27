# Capacité des créneaux par montant

La limite de commandes est remplacée par un plafond en euros par créneau de 20 minutes. Le montant total enregistré de chaque commande non annulée consomme ce plafond, y compris les commandes terminées. Les remises et menus utilisent leur prix final : le prix est un indicateur approximatif de charge, pas une mesure du temps exact de préparation.

Le panier rend indisponibles les créneaux dont le solde ne suffit pas. La réservation contrôle à nouveau le prix et la capacité dans la transaction protégée par RestaurantLock. Une commande doit tenir entièrement dans son créneau ; elle n’est pas répartie automatiquement sur plusieurs horaires. Une commande annulée peut être réactivée seulement si sa valeur tient encore dans le créneau.

Dans Créneaux & capacité, saisir les montants en euros (deux décimales maximum). Une personnalisation vide reprend le défaut ; zéro ferme la capacité. Les montants sont stockés en centimes. Réduire le plafond ne supprime aucune commande et affiche le dépassement.

Migration : les anciennes limites sont converties avec une hypothèse initiale de 20 € par commande (10 commandes deviennent 200 €). Les limites nulles et les capacités à zéro sont préservées. Ajuster ces montants à la charge réelle de la cuisine. Les anciennes colonnes sont conservées pour permettre le retour de migration, mais ne sont plus utilisées par le calcul.
