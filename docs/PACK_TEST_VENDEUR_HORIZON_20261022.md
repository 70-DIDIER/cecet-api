# CECT — Pack Vendeur Horizon TEST pour la chaîne PF, 22 octobre 2026

## Objectif et périmètre

À la demande du responsable projet, mettre à disposition des **Personnes physiques uniquement** une formule vendeur de simulation suffisamment généreuse pour tester un scénario complet de vente de YEM sans modifier les douze packs Vendeur officiels.

**Code :** `TST-VND-HORIZON`  
**Nom :** `Vendeur Horizon — TEST`  
**Famille :** `VENDEUR`, éligibilité obligatoire `natural` (PF), personne morale interdite.  
**Statut :** publié dans `APP_ENV=staging` **uniquement**.  
**Prix :** 100 000 FCFA **fictifs, payables uniquement via fournisseur simulé**.  
**Fréquence :** deux quinzaines par mois, 24 périodes calendaires sur 12 mois.  
**Quota cessible :** 10 000 YEM par quinzaine, soit **240 000 YEM de droits contractuels**.  
**Premier quota :** **immédiatement après confirmation de paiement simulé** ; aucun délai d'attente ; les quinzaines suivantes continuent selon l'échéancier.  
**Quantité par opération :** minimum 1 YEM, maximum 10 000 YEM (le disponible réel peut être inférieur).  
**Cumul des droits :** oui ; l'opération doit respecter le solde réellement disponible, la réservation, l'expiration et les règles générales.  
**Cours de référence :** `rates.purchase_xof_per_yem` (actuellement 510 FCFA/YEM), instantané sur chaque ordre, modifiable globalement par personnel habilité.

**Avertissement fonctionnel :** ces 10 000 YEM sont **des droits à céder les YEM personnels au cours de rachat CECT**, pas une dotation réelle ni un virement de YEM vers un wallet. Les chiffres FCFA ou YEM qui s'affichent pendant la démonstration sont simulés et ne sont ni un avoir légal ni une dette réelle.

La carte de catalogue PF ressemble aux packs commerciaux de la marque CECT et comporte une bande orange **« Démonstration · Paiement fictif »**, un prix explicitement simulé et des conditions détaillées. Une souscription active propose **« Continuer mes tests de vente »** au lieu de créer un second pack test.

## Sécurité et separation réelle/test

- La publication exige `APP_ENV=staging` ou `testing` ; **le seeder refuse production**.
- `GET /api/v1/packs` et `GET /api/v1/me/catalog-packs` excluent toutes les offres `TST-%` si l'environnement n'est pas `staging` / `testing`.
- L'API `SubscribeToPack` **refuse explicitement la souscription d'un TST-% hors simulation** ; ne repose pas sur les seules restrictions d'interface.
- Les règlements entrants et sortants configurés sur le serveur actuel utilisent exclusivement le fournisseur `fake` en staging (vérifié via SettingsService). Le PayoutProviderManager interdit FedaPay en staging.
- **Un membre peut avoir au maximum 1 pack Vendeur TEST** actif ou en paiement, **en plus des 2 packs Vendeur officiels autorisés**. Les offres `TST-%` n'épuisent pas les deux emplacements commerciaux. Aucun assouplissement des quotas officiels n'est appliqué.
- PF/PM ségrégés côté serveur, idempotence du seeder, version contractuelle figée, audit, aucun compte ou transaction historique altéré.

## Parcours de recette validé sur base isolée cectapi_test

1. Souscription PF à 100 000 FCFA fictifs, confirmation du paiement `fake`.
2. Activation ; **24 périodes planifiées, première ouverte immédiatement**, compte : 10 000 YEM cessibles.
3. Réservation d'une demande de vente de 3 000 YEM → disponible 7 000 / réservé 3 000.
4. Déclaration d'envoi : ne change aucun quota et ne déclenche pas de versement.
5. Comptabilité (rôle `comptabilite`, step-up) valide une réception partielle de **2 500 YEM**, soit **1 275 000 FCFA fictifs** au cours 510 ; les 500 YEM non reçus sont libérés.
6. Nouveau solde : **disponible 7 500, réservé 0, cédé 2 500** ; un paiement fictif est planifié, aucun paiement réel.
7. Deux packs commerciaux actifs + un TEST actif sur le même PF : autorisé. Un troisième commercial ou un deuxième TEST : bloqué (HTTP 409).
8. PM : refus de souscription (éligibilité incorrecte). Double import : aucune version doublonnée. Environnement production : seed interdit.

**Résultat : 26 tests, 234 assertions réussis** sur les scénarios tests Vendeur/abonnements, dont 4 scénarios spécifiques pack Horizon (création/import, protection production, flux complet, cohabitation avec 2 offres officielles).

## Livraison, vérification et retour arrière

- API live : `/var/www/cect-api`, `APP_ENV=staging`, base `cectapi_live`.
- Frontend live : `/home/ubuntu/cect-current/frontend` → `/home/ubuntu/cect-releases/frontend-vendeur-horizon-independent-test-slots-20261022`.
- Sauvegarde PostgreSQL avant import et fichiers API d'origine : `/home/ubuntu/cect-private-backups/before-vendeur-horizon-demo-20261022/`. Empreinte SHA256 valide, aucun secret ajouté à GitHub.
- HTTP 200 pour `/espace/packs` et `/api/v1/health`, catalogue public **31 packs publiés** au total (les 30 packs antérieurs inchangés + Horizon TEST). Les utilisateurs préexistants sont conservés.
- Les nouveaux fichiers sont versionnés sur `70-DIDIER/cecet-api` et `70-DIDIER/CECT-frontend` branche `design/pf-pm-premium-20261022`, sans fusion de `custum` / `main`.
- Pour retirer l'offre une fois la campagne terminée : faire passer `TST-VND-HORIZON` en `retired` avec audit via console Superadmin, puis vérifier qu'aucune souscription de test en cours ne nécessite une clôture avant de supprimer l'accès. Ne pas supprimer rétroactivement les écritures de démonstration ou rétablir une ancienne base après création d'opérations.

**Limite :** le scénario automatique de bout en bout est exécuté en base de test isolée, pas sur de vraies transactions d'un membre live ; le propriétaire de la PF doit réaliser son essai visuel avec des paiements et transferts de démonstration uniquement.
