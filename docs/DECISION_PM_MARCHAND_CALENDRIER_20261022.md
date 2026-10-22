# CECT — Décision métier 22/10/2026 : packs PF/PM et fiche de règlements

## Décision validée par le responsable métier
- **Personne morale (PM) :** autorisée à souscrire exclusivement à la famille **MARCHAND**.
- **Personne physique (PF) :** familles **VENDEUR**, **CONSOMMATEUR** et **PERSONNEL**. Un particulier ne souscrit pas de pack Marchand.
- Les limites spécifiques de souscription, notamment Vendeur 2 packs simultanés, restent inchangées.
- La règle est **exclusive et indépendante du catalogue** : le Superadmin ne peut pas publier une version multi-profils qui accorderait un droit incompatible ; les anciennes versions qui listent encore les deux types de personne ne doivent pas contourner les contrôles à la souscription.

## Contrats techniques
1. `PackFamily::defaultEligiblePersonTypes()` : Marchand = `legal`, autres = `natural`.
2. `PackVersionValidator` : rejette une liste `eligible_person_types` qui sort de cette matrice.
3. `MemberPackController` : filtre les plans pour l'utilisateur identifié, sans proposer de formule inéligible.
4. `SubscribeToPack` : vérifie indépendamment famille autorisée ET version autorisée sous verrou transactionnel.
5. Frontend connecté : affiche le catalogue à travers `/me/catalog-packs`, et ne montre pas le quota de packs Vendeur pour les PM.

## Nouvelle fiche « Mes règlements et échéanciers »
Le responsable métier a demandé, pour PF et PM, un suivi sous forme de calendrier/fiche avec dates, montants et états. L'interface de conception conserve une distinction indispensable :

- **Règlements** : frais d'adhésion initiale, souscription de pack (prix payé une fois dans les règles actuelles), et historique des rechargements par pack. Source des montants : enregistrements de souscription / adhésion / rechargement.
- **Échéancier YEM** : les périodes existantes de crédit du quota par pack avec `starts_at`, `quota` et `credited`. **Ce calendrier n'est pas une suite de mensualités à payer.** Les périodes sont calculées par le moteur existant lors de l'activation.
- **Statuts** : réglé / en attente / annulé et crédité / à créditer uniquement lorsque le backend fournit des données correspondantes.
- Interface : filtrage par statut, fiche déroulante par pack, onglet crédit/rechargements, impression navigateur. Pas de calcul de montant financier inventé ni de prélèvement automatique.
- Protection : endpoints existants sous `auth:sanctum`, accès limité aux propres packs du membre.
- Aucune donnée issue des captures d'un site tiers n'est réutilisée comme valeur comptable CECT.

## Décision encore attendue : versements échelonnés ?
Dans l'état actuel, la formule est réglée intégralement à la souscription et le YEM est crédité par périodes. Si la coopérative souhaite désormais des **mensualités de paiement de pack**, il faut une nouvelle décision explicite précisant échéances, règles de crédit en cas d'impayé, paiement partiel, retard, rattrapage et responsabilité de validation. **Ne pas activer de mensualités automatiques à partir des seules captures de référence.**

## Tests et déploiement
- Tests de souscription/catalogue PF/PM : **62 réussis, 365 assertions** sur la base de test dédiée, le 22 octobre 2026.
- Frontend : compilation React/TypeScript + Vite réussie, échéancier ajouté à la branche de refonte.
- Branches : `70-DIDIER/cecet-api` et `70-DIDIER/CECT-frontend`, `design/pf-pm-premium-20261022`, dérivées de `custum`.
- **Pas de déploiement** et **pas de migration** sur le site de démonstration. Sauvegarde privée disponible dans `/home/ubuntu/cect-private-backups/redesign-stable-20261022/`.
