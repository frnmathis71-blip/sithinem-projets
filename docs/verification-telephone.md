# VÃƒÂ©rification du tÃƒÂ©lÃƒÂ©phone par SMS

Le panier reste accessible. La premiÃƒÂ¨re commande nÃƒÂ©cessite un tÃƒÂ©lÃƒÂ©phone vÃƒÂ©rifiÃƒÂ© via Twilio Verify. Les comptes existants doivent ÃƒÂ©galement vÃƒÂ©rifier leur numÃƒÂ©ro. Un changement de numÃƒÂ©ro conserve lÃ¢â‚¬â„¢ancien numÃƒÂ©ro vÃƒÂ©rifiÃƒÂ© jusquÃ¢â‚¬â„¢ÃƒÂ  validation du nouveau.

## Configuration

1. CrÃƒÂ©er un compte Twilio et un service Verify avec le canal SMS activÃƒÂ© (code ÃƒÂ  6 chiffres conseillÃƒÂ©).
2. Renseigner cÃƒÂ´tÃƒÂ© serveur dans `.env` :

```dotenv
TWILIO_ACCOUNT_SID=AC...
TWILIO_AUTH_TOKEN=...
TWILIO_VERIFY_SERVICE_SID=VA...
```

Ne pas mettre ces secrets dans Git ou dans le navigateur.
3. ExÃƒÂ©cuter `php artisan migrate --force` puis `php artisan config:cache` sur le serveur de production.
4. Activer les destinations nÃƒÂ©cessaires dans les permissions gÃƒÂ©ographiques Verify et configurer les protections et alertes de dÃƒÂ©penses Twilio. Utiliser un cache partagÃƒÂ© entre les serveurs pour les limites dÃ¢â‚¬â„¢envoi.
5. Tester avec un vrai mobile : rÃƒÂ©ception, code incorrect, succÃƒÂ¨s, commande puis changement de numÃƒÂ©ro. Un compte dÃ¢â‚¬â„¢essai Twilio peut restreindre les destinataires.

Sans configuration et hors simulation locale, les commandes nÃƒÂ©cessitant une vÃƒÂ©rification restent bloquÃƒÂ©es et un message dÃ¢â‚¬â„¢indisponibilitÃƒÂ© apparaÃƒÂ®t. Aucun code de contournement nÃ¢â‚¬â„¢est prÃƒÂ©vu. Les SMS rÃƒÂ©els sont facturÃƒÂ©s par Twilio.

## Comportement

Les formats franÃƒÂ§ais ÃƒÂ  10 chiffres sont convertis en +33 ; les autres pays nÃƒÂ©cessitent un indicatif international. La normalisation contrÃƒÂ´le la syntaxe ; seule la rÃƒÂ©ception du code prouve la possession du numÃƒÂ©ro. Twilio contrÃƒÂ´le lÃ¢â‚¬â„¢expiration du code (10 minutes par dÃƒÂ©faut). LÃ¢â‚¬â„¢application ne stocke pas les codes et ne les conserve pas dans les anciennes entrÃƒÂ©es de formulaire.

Limites applicatives : un envoi par minute et dix par jour par compte, cinq par heure par numÃƒÂ©ro, vingt par heure par IP ; cinq contrÃƒÂ´les par dix minutes par compte, dix par numÃƒÂ©ro et cinquante par IP. Les plafonds Twilio sÃ¢â‚¬â„¢ajoutent ÃƒÂ  ces limites.

Les tests automatisÃƒÂ©s utilisent une API simulÃƒÂ©e et nÃ¢â‚¬â„¢envoient aucun SMS : `php artisan test --compact --filter=PhoneVerification`.

Documentation : https://www.twilio.com/docs/verify/api/verification et https://www.twilio.com/docs/verify/api/verification-check

## Simulation locale gratuite

Dans `.env`, utiliser `APP_ENV=local` et `PHONE_VERIFICATION_SIMULATE=true`, puis `php artisan config:clear`.
Demander un code depuis le site, puis lire la derniÃ¨re entrÃ©e Â« Simulation SMS locale Â» dans `storage/logs/laravel.log`. Le code alÃ©atoire expire aprÃ¨s 10 minutes et ne sert quâ€™une fois ; le renvoi remplace le prÃ©cÃ©dent. Les limites de tentatives restent actives. Aucun appel Twilio et aucun SMS facturÃ©.

La simulation est refusÃ©e dans tout environnement autre que `local`, mÃªme si le drapeau est activÃ©. En production, utiliser `APP_ENV=production` et `PHONE_VERIFICATION_SIMULATE=false` avec les identifiants Twilio. Ne pas transfÃ©rer la base de dÃ©veloppement : ses tÃ©lÃ©phones validÃ©s par simulation ne prouvent pas la possession rÃ©elle dâ€™un numÃ©ro.
