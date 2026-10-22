# Procès-verbal technique — Déploiement CECT de démonstration du 22 octobre 2026

## Portée et statut

**Statut : déploiement exécuté et vérifié par API et HTTP.**  
Environnement : `APP_ENV=staging`, base `cectapi_live`, paiements d'essai (simulateur, pas de transactions financières réelles).

### Adresses

- Espace membre : https://13-49-203-235.sslip.io/espace
- Packs membre : https://13-49-203-235.sslip.io/espace/packs
- Fiche des règlements et crédits YEM : https://13-49-203-235.sslip.io/espace/calendrier
- Back-office : https://admin.13-49-203-235.sslip.io/admin/packs
- API de santé : https://api.13-49-203-235.sslip.io/api/v1/health
- Catalogue public : https://api.13-49-203-235.sslip.io/api/v1/packs

### Origine versionnée et versions publiées

Les modifications de code sont commit sur les branches `design/pf-pm-premium-20261022` de :
- `70-DIDIER/cecet-api` PR #4 ;
- `70-DIDIER/CECT-frontend` PR #4 ;
- `70-DIDIER/cect-admin` PR #5.

Les builds statiques sont publiés par changement **atomique** des liens :
- `/home/ubuntu/cect-current/frontend` → `/home/ubuntu/cect-releases/frontend-pfpm-pdf-packs-20261022`
- `/home/ubuntu/cect-current/admin` → `/home/ubuntu/cect-releases/admin-superadmin-pdf-packs-20261022`

Le backend est déployé par copie contrôlée de 12 fichiers PHP (règles catalogue/permissions/quotas/relevé, nouvelle période quinzaine, migration et import), rafraîchissement du cache de routes, exécution de migration et import opt-in. Aucun `git push` ou merge vers `custum` ou `main` n'a été effectué ; les fichiers de fonctionnement dans le répertoire live ont été mis à jour sur instruction explicite.

### Catalogue réellement enregistré dans la base de démonstration

- Vendeur PF : **12** packs publiés ;
- Consommateur PF : **8** packs publiés (cashback contractuel YEM 8%, prix intégral FCFA) ;
- Marchand PM : **9** packs publiés ;
- **Maître Marchand : 1 brouillon** sans tarif ni version, non proposé aux clients.

Totaux interrogés sur `cectapi_live` : **29 publiés, 1 brouillon, 29 versions half_month de 24 cycles**, aucune souscription au moment de l'import ; **5 utilisateurs préexistants conservés**.

### Politique financière réellement activée

`merchant_redemption.pricing` a été modifié par création d'une version de réglage auditée : `mode=sale_minus_fee`, `fee_bps=300`, `fixed_fee_xof=0`, `fee_rounding=down`; cours de vente confirmé `650 FCFA/YEM`.

Exemple reconnu de Classeur1.pdf : 6000 YEM × 650 = 3 900 000 FCFA brut, frais 117 000, rachat net 3 783 000 ; moins accès au pack Apprenti (240 000) = **3 543 000 FCFA**. Les nouveaux rachats gardent leur photographie de tarification ; les précédents ne sont pas recalculés.

Les prix, quotas et délais de premier crédit YEM sont modifiables par nouvelle version **Superadmin uniquement**. Le rôle Administrateur conserve le droit de consultation, pas de modification ou publication.

### Sauvegarde, restauration et préservation

Sauvegardes privées vérifiées :
- Base avant import : `/home/ubuntu/cect-private-backups/before-pdf-packs-deploy-20261022/cectapi_live_before_pdf_import.dump` avec fichier `SHA256SUMS` valide.
- Répertoire de restauration : `/home/ubuntu/cect-private-backups/pre-ui-pdf-launch-20261022/RESTORE.json`, `api-original/`, `API_SHA256SUMS`.
- Versions web précédentes conservées :
  - `/home/ubuntu/cect-releases/frontend-kyc-normal-adhesion-demo-20261022`
  - `/home/ubuntu/cect-releases/admin-superadmin-direct-entry-20261022`

Le retour arrière des interfaces peut être réalisé en rebasculant les symlinks. **Ne pas restaurer automatiquement l'ancienne base après que des membres ont commencé à souscrire**, car cela détruirait les nouvelles opérations. La migration `half_month` ne doit pas être annulée tant que des versions l'utilisent.

### Contrôles passés

- Batterie métier principale PF/PM : **92 tests réussis, 757 assertions**.
- Recette source PDF : **3 tests réussis, 51 assertions** (dont vraies souscriptions des packs importés, comptes PF et PM, cashback, délais, idempotence).
- Restriction Superadmin : **41 tests catalogue réussis, 209 assertions**.
- Paiements, QR et rachats Marchand : **29 tests réussis, 349 assertions**.
- Paiements d'adhésion tenant compte du KYC : **38 tests réussis, 194 assertions**.
- KYC : scénarios de vérification d'e-mail, stockage privé, validation, refus et anti-contournement passés.
- Tarification/permissions des réglages : **21 tests réussis, 207 assertions**.
- Compilations Vite/TypeScript frontend et admin réussies avec `VITE_API_URL=https://api.13-49-203-235.sslip.io`.
- Vérification distante : **HTTP 200** sur accueil, espace membre, calendrier, packs, administration et API `health`. Les bundles JS/CSS et le logo de chaque interface répondent **HTTP 200**.
- `GET /api/v1/packs` renvoie **29 plans** ; les 3 familles et premiers délais sont conformes ; les API privées répondent **401** sans session.
- Route de simulation sur staging présente : `POST /api/v1/me/payments/{attempt}/simulate`.
- **Limitation de preuve** : test visuel automatisé navigateur non exécuté sur la machine distante (dépendances graphiques Chrome manquantes). Le succès du build + HTTP ne certifie pas l'absence de bugs d'affichage après authentification. Recette humaine nécessaire.

### Points métier encore à préciser et non inventés

1. **Maître Marchand** : le prix du document est illisible (`###`), et la publication est bloquée.
2. **Réserve/libération Marchand à 120 %** : le document expose deux plafonds (capacité et réserve), mais n'explicite pas assez les événements contractuels et la fréquence de déblocage. L'import conserve la règle descriptive **sans modifier arbitrairement le moteur de réservations**. Le plafond de rachat par période reste celui du contrat publié.
3. Tous les frais, quotas et contrôles sont calculés sur les règles CECT ; les encaissements sur cet environnement restent simulés.
