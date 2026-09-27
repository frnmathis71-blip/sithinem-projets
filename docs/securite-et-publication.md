# Sécurité et publication

## Textes à finaliser

Renseigner LEGAL_OPERATOR, LEGAL_ADDRESS, LEGAL_PRIVACY_EMAIL, LEGAL_HOST et LEGAL_RETENTION dans la configuration du serveur. Les pages /confidentialite et /cgu sont des projets adaptés aux fonctions existantes, pas une certification de conformité. Compléter les destinataires, transferts internationaux et modalités réelles de conservation. Aucune purge générale automatique n’est ajoutée. Des CGV et les informations légales commerciales restent à préparer avant une véritable ouverture aux commandes.

## HTTPS

En production HTTPS est obligatoire, indépendamment de FORCE_HTTPS. En local Herd, FORCE_HTTPS=true active également la redirection. Installer un certificat TLS valide, définir APP_URL=https://votre-domaine et configurer la redirection au niveau du serveur web pour couvrir aussi les fichiers statiques. L’application renvoie HTTP vers HTTPS avec 308 et HSTS sur les réponses sécurisées. Les cookies de session sont Secure. Derrière un reverse proxy, déclarer seulement les IP de proxy réellement utilisées dans la configuration Laravel des proxies de confiance ; ne pas faire confiance arbitrairement à tous les en-têtes Forwarded.

## Cookies

Aucun traceur facultatif n’est intégré. Session, panier, CSRF et choix de cookies sont nécessaires. Le choix chiffré, HttpOnly et SameSite=Lax dure 180 jours ; il est Secure sur HTTPS. Accepter n’autorise pas automatiquement de futurs outils. Pour ajouter une finalité : mise à jour des informations et de la version du choix, puis chargement du prestataire seulement après consentement approprié. Le refus n’empêche pas de commander.

## Secrets et appels navigateur

Twilio, clé VAPID privée et jeton d’impression restent côté serveur, jamais dans une variable VITE_ ou les assets. La clé VAPID publique et le jeton CSRF doivent rester accessibles aux fonctions qui les utilisent. Les appels internes de créneaux, panier et administration restent nécessaires et protégés selon leur usage. Une URL d’API n’est pas un secret ; l’accès aux commandes et à l’administration repose sur l’authentification et les autorisations. Le jeton Twilio partagé dans la conversation doit être révoqué et remplacé avant usage réel.

## Anti-spam

Piège invisible sur inscription, récupération du mot de passe, vérification téléphonique et commande ; limitation IP des POST publics sensibles, en complément des limites Laravel/Fortify et SMS. Pas de CAPTCHA tiers ni traçage ajouté. Le piège et les quotas réduisent les abus mais ne remplacent pas une protection DDoS du serveur. En multi-serveurs, utiliser un cache partagé et configurer correctement les proxies pour identifier les IP.

## Référencement

/sitemap.xml utilise APP_URL et les menus visibles disponibles. /robots.txt bloque tout en local ou en démonstration ; en production, il indique le sitemap et exclut les pages privées. robots.txt ne constitue pas un contrôle d’accès. Les pages privées portent aussi X-Robots-Tag et Cache-Control private,no-store. Les URL inexistantes renvoient le statut HTTP 404 avec un écran personnalisé.

Sources de rédaction : https://www.cnil.fr/fr/conformite-rgpd-information-des-personnes-et-transparence et https://www.cnil.fr/fr/cookies-et-traceurs-comment-mettre-mon-site-web-en-conformite
