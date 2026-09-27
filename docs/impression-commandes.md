# Impression des commandes

## Fonctionnement

Le poste Windows du restaurant utilise le pilote de l’imprimante Wi-Fi : aucune commande propre à une marque n’est envoyée. Les rouleaux 58 et 80 mm sont pris en charge. Une imprimante classique avec un pilote Windows peut recevoir le même contenu étroit, sur son papier habituel. La prise en charge du format personnalisé et de la coupe dépend du pilote ; tester un ticket sur le matériel avant le service.

Le site peut être hébergé ailleurs. Le programme local contacte le site en HTTPS toutes les 5 secondes, sans port entrant à ouvrir. Il reste actif même si la page admin est fermée. Exécuter le programme dans la session Windows ouverte du restaurant, pas comme un service Windows. Le poste doit rester allumé et connecté.

## Installation

1. Installer l’imprimante Wi-Fi dans Windows avec le pilote du fabricant. Choisir le rouleau et la coupe **en fin de document** dans ses préférences. Chaque commande est un document distinct.
2. Appliquer `php artisan migrate` sur le serveur.
3. Générer un secret aléatoire d’au moins 32 caractères, par exemple avec `php -r "echo bin2hex(random_bytes(32));"`. Définir `RESTAURANT_PRINT_TOKEN` dans le `.env` du serveur puis actualiser son cache de configuration si nécessaire. Ne jamais placer ce secret dans une variable Vite ou une page web.
4. Copier ensemble `scripts/Start-OrderPrinter.ps1` et `scripts/Print-Ticket.cs` sur le poste restaurant. Définir le même secret dans la variable d’environnement `RESTAURANT_PRINT_TOKEN` de l’utilisateur Windows.
5. Lancer avec **Windows PowerShell 5.1**, dans la session de cet utilisateur :

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Start-OrderPrinter.ps1 -SiteUrl https://votre-site.fr -PrinterName "Nom exact de l’imprimante Windows" -WidthMm 80
```

Adapter l’URL, le nom exact du pilote et la largeur (58 ou 80). Utiliser un certificat HTTPS reconnu par Windows. Le programme ne choisit jamais l’imprimante par défaut à la place de celle demandée. Pour le démarrage quotidien, créer un raccourci de cette commande dans le dossier Démarrage de la session Windows. Aucun jeton ne doit figurer dans le raccourci.

Le paramètre `-ExecutionPolicy Bypass` autorise ce script local pour ce processus uniquement, sans modifier la politique Windows enregistrée. Ne pas sélectionner un pilote virtuel « Print to PDF », qui demanderait un nom de fichier.

## Commandes et reprises

- À la connexion, toutes les commandes **en préparation pour aujourd’hui**, selon le fuseau du restaurant, qui n’ont pas encore été prises en charge sont ajoutées automatiquement. Les commandes futures attendent leur jour de retrait. Les commandes anciennes et les annulations ne sont pas imprimées automatiquement.
- « Imprimer toutes les commandes du jour » ajoute un travail distinct par commande, y compris les commandes terminées et annulées (leur statut figure sur le ticket). Les filtres de la liste admin ne changent pas ce lot. Un deuxième clic intentionnel après rechargement crée une réimpression ; le renvoi du même formulaire ne duplique pas le lot.
- Sans configuration, le bouton ouvre l’impression navigateur : choisir le pilote thermique, le bon papier, l’échelle 100 %, sans en-têtes ni pieds de page. Ce secours ouvre la boîte de dialogue du navigateur et ne fournit pas l’impression automatique.
- Un ticket comporte le numéro court et la référence complète, les quantités, les noms/déclinaisons sauvegardés, la composition des menus (quantités par menu), le commentaire client, le retrait et le statut. Les textes longs sont renvoyés à la ligne et paginés, sans troncature.
- L’état « transmis » signifie **accepté par le pilote Windows**, pas une preuve que le papier est sorti. En cas de papier épuisé ou de Wi-Fi coupé après acceptation, consulter la file Windows.
- Un acquittement perdu est conservé localement puis renvoyé sans réimprimer. Une interruption incertaine ne déclenche pas de réimpression automatique : consulter les alertes de la section Impression, vérifier physiquement le ticket puis utiliser « relancer ». Actualiser l’admin pour voir les dernières alertes. Les tickets abandonnés sont détectés après cinq minutes par le prochain passage du programme.
- Ne pas supprimer `pending-ack.json` dans `%LOCALAPPDATA%\SithinemPrinter` pendant une reprise. Il conserve seulement l’identifiant et l’état du travail, jamais son commentaire client ni le secret du site.

L’envoi silencieux utilise [StandardPrintController de Microsoft](https://learn.microsoft.com/en-us/dotnet/api/system.drawing.printing.standardprintcontroller?view=windowsdesktop-10.0). L’imprimante et son pilote doivent être validés sur place ; aucun protocole universel ne garantit la coupe et les formats de tous les modèles.
