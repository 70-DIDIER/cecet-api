# Historique des travaux

## 2026-10-11


### Recette transverse et durcissement

- Revue complète frontend/backend/RBAC/paiements/sécurité sur la recette publique.
- Suite backend complète validée : 534 tests, 5 997 assertions, y compris les scénarios de concurrence.
- Build TypeScript/Vite validé ; lint restauré sous ESLint 9 avec 0 erreur.
- Audit Composer : aucune vulnérabilité connue dans les dépendances PHP.
- Audit npm des dépendances de production : aucune vulnérabilité connue après mise à jour de React Router.
- Ajout d'un écran d'activation des comptes internes par OTP + définition du mot de passe.
- Ajout d'un indicateur visuel Mode recette piloté par le backend.
- Alignement UX/RBAC : seul un Superadmin peut proposer/attribuer le rôle Superadmin.
- Les six acteurs métier sont rendus explicites : Utilisateur, Administrateur, Superadmin, Validateur, Comptabilité, Yempay/prestataire externe.
- FedaPay reste en sandbox et les payouts automatiques restent désactivés sur la recette.
- Le compte Superadmin de recette demandé a été créé en attente d'activation OTP ; l'ancien compte membre portant la même adresse a été préservé via un alias Gmail.
- Voir recette-2026-10-11.md pour les preuves, limites et points résiduels.


### Back-office packs

- Audit du frontend admin : absence d'une vraie page CRUD/versioning des packs.
- Vérification du backend catalogue : routes présentes et tests fonctionnels.
- Exécution de `CatalogAdministrationTest` : 40 tests, 200 assertions, succès.
- Ajout de `/admin/packs` côté frontend.
- Ajout de la navigation « Packs » dans le shell admin.
- Build TypeScript/Vite validé et déployé.

### Back-office Blade sans styles

- Reproduction du problème sur `/backoffice/login`.
- Identification des assets CSS/JS renvoyés comme `text/html`.
- Cause : Nginx envoyait `/vendor/spark/*` et `/admin-assets/*` vers le root du SPA frontend.
- Ajout des locations statiques vers `/var/www/cect-api/public`.
- `nginx -t` validé avant reload.
- Vérification locale : `main.css` renvoyé en `text/css`.

### Nettoyage utilisateurs

- Suppression des comptes de test non présents dans le jeu de démonstration.
- Conservation du client démo, marchand démo et superadmin démo.
- Suppression des données dépendantes de ces comptes temporaires.

### Paiement / recette

- Option B retenue : frais FedaPay supportés par le client, CECT reçoit le net métier.
- Correction du libellé de paiement FedaPay.
- Alignement de l'affichage frontend sur les frais ajoutés au checkout.
- Réconciliation automatique au retour de FedaPay.
- Mode présentation staging activable pour simuler une adhésion payée sans transaction réelle.
- Garde-fou de production maintenu.

### FedaPay

- Vérification d'une transaction Sandbox refusée.
- Webhook `transaction.declined` reçu et traité.
- Adaptateur rendu compatible avec les réponses transaction contenant `currency_id`.
- Support explicite du statut prestataire `expired`.

## 2026-10-10

- Déploiement initial stabilisé sur IP statique `13.50.26.235`.
- HTTPS Let's Encrypt sur `13-50-26-235.sslip.io`.
- PostgreSQL local, PHP-FPM, Nginx, queue et scheduler opérationnels.
- CORS/Sanctum sécurisés pour l'origine HTTPS.
- Configuration SMTP Gmail validée.
- FedaPay Sandbox configuré, clé et webhook secret chargés côté serveur.
- Provider fake désactivé hors tests.
- Simulation frontend de paiement supprimée.
- Tentatives de paiement historiques périmées/échouées conservées avec audit au lieu d'être effacées.
