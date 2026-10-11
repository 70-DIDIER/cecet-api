# Historique des travaux

## 2026-10-11

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
