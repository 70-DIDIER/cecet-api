# CECT — Décision métier validée : paiement intégral d'un pack avant crédits YEM

**Date :** 22 octobre 2026  
**Portée :** personnes physiques (PF) et personnes morales (PM)  
**Décision confirmée :** **A — paiement intégral à la souscription, puis crédits YEM périodiques**  
**Branche de travail :** `design/pf-pm-premium-20261022` ; le site déployé, `custum` et `main` ne sont pas modifiés par ce chantier.

## 1. Contrat métier

1. Le prix d'un pack est fixé à la création de la souscription, à partir de la **version publiée** en vigueur. Les modifications ultérieures du catalogue n'affectent pas le prix ni les quotas du contrat existant.
2. Le membre règle **l'intégralité du prix de souscription**, plus les frais éventuels de transaction facturés au client. **Pas d'acompte, d'échéances partielles, de crédit ni de mensualités à encaisser** pour le même pack.
3. Un pack payant reste en `pending_payment` pendant le paiement. **Aucun compte de quota YEM ni calendrier actif ne doit être crédité avant validation effective du paiement côté serveur**. Une simple déclaration, une tentative en cours ou un paiement simulé non finalisé n'accorde aucun droit.
4. Le règlement définitivement validé déclenche **une seule activation** du pack, crée ses périodes, initialise son compte de quota et crédite les seules périodes immédiatement exigibles. Les autres crédits seront effectués automatiquement à leur **date de début contractuelle**.
5. Les crédits périodiques YEM sont des **droits du pack acheté**, et non des mensualités de paiement du membre. Le titulaire n'a rien à régler à chaque date de crédit.
6. Le traitement périodique est **idempotent** : pas de double crédit, y compris en cas de relance du traitement ou de confirmation répétée du paiement.
7. Pack `PERSONNEL` : formule particulière sans périodes : le contrat de base et chaque éventuel rechargement suivent leurs propres paiements complets, avec **crédit une seule fois après confirmation** ; ne pas inventer un calendrier mensuel pour ces opérations.
8. Une version de pack explicitement gratuite (`price_xof = 0`) peut s'activer sans encaissement : elle n'est **pas** un pack payant en impayé.
9. **Éligibilité :** PF = Vendeur/Consommateur/Personnel ; PM = Marchand uniquement, y compris pour les anciennes versions qui auraient publié d'autres éligibilités.
10. Le droit aux crédits dépend de la politique du pack (délai de premier crédit, période, nombre de cycles, cumul, fin de terme), figée lors de la souscription.

## 2. Déroulé applicatif

```text
Pack publié, éligible et version figée
  -> souscription créée (pending_payment)
  -> intention de paiement de 100 % du prix + frais éventuels
  -> validation serveur du règlement (transaction de paiement)
  -> statut pack active et création atomique des périodes contractuelles
  -> crédit immédiat uniquement si sa date est atteinte
  -> tâche périodique : crédit des périodes devenues exigibles
  -> fin des cycles / application de la politique de fin de terme
```

**Interdits :** permettre une souscription à tempérament sans décision complémentaire, créditer un YEM au dépôt d'une simple preuve, régénérer les périodes en double, recalculer les droits d'un pack acquis avec les paramètres actuels du catalogue, déplacer une échéance sans avenant.

## 3. Comptabilité, rapprochement et audit

**Deux journaux de nature différente** doivent être conciliés :

- **Flux monétaires FCFA :** montant de souscription contractuel, frais du prestataire, montant total débité au membre, statut de l'intention de paiement, référence prestataire, date d'encaissement, preuve de rapprochement, montant net reçu et audit. **Une seule créance / intention de souscription**, pas douze créances mensuelles inventées.
- **Grand livre YEM :** quota par période, disponible, réservé, consommé, crédité, cashback éventuel, expiration / fin de terme. Tout crédit a une clé d'idempotence et se rattache à son pack et à sa période.
- **Rapprochement :** une souscription payante activée doit pointer sur un encaissement validé ; une période créditée sur une ligne de grand livre unique. Des tentatives de paiement supplémentaires ne doivent pas créer d'autres droits.
- **Prudence comptable :** le paiement encaissé n'est pas nécessairement un produit intégral immédiatement acquis sur le plan comptable. Le rattachement du revenu aux obligations de service, le passif éventuel de YEM à livrer, les règles de taxes et de conversion nécessitent validation par la comptabilité et le référentiel juridique applicable ; **ne pas imposer automatiquement une écriture de chiffre d'affaires intégral à la date de paiement**.
- **Reporting :** séparer « souscriptions encaissées (FCFA) », « crédits YEM prévus », « crédits YEM effectués », « droits YEM restant à délivrer », « YEM consommés » et « opérations en attente ». Ces mesures ont des unités, dates et significations différentes.
- **Simulation :** sur staging, les paiements sont simulés et leur statut doit être clairement identifié ; aucune recette de démonstration ne vaut preuve d'une transaction bancaire réelle.

## 4. Fiche membre PF et PM

La page « Mes règlements et échéanciers » et la fiche individuelle d'un pack doivent distinguer visuellement :

1. **Règlement du pack :** prix intégral, frais éventuels (si disponibles dans le paiement), statut réel, date et référence de paiement, preuve si disponible.
2. **Calendrier de crédits YEM :** numéro de période, date de crédit prévue, quota YEM, statut « crédité » ou « à créditer », dates calculées **à partir des périodes contractuelles**.
3. **Régularisations :** les rechargements et leurs paiements propres ; ne pas les confondre avec un échéancier de mensualités.
4. **Pack sans calendrier :** expliquer explicitement l'absence de crédit périodique (ex. Personnel), au lieu d'afficher une échéance fictive.
5. **Pack impayé :** pas de crédit affiché comme acquis ; proposer de reprendre le paiement existant.
6. **Protection des données :** uniquement les propres packs du membre authentifié ; aucune fuite de données administratives ou de documents KYC.

## 5. Contrôles de recette obligatoires

- PF Vendeur payant : zéro YEM disponible en attente, règlement complet simulé, date du premier crédit respectée, une seule écriture par période, deuxième exécution du roller sans doublon.
- PF Consommateur payant : activation après règlement complet, premier crédit suivant les paramètres, pas de crédit du solde annuel complet en une fois.
- PM Marchand payant : seule famille autorisée, après encaissement complet première période créditée conformément au contrat, les suivantes seulement à échéance.
- Personnel : aucun cycle mensuel artificiel ; rechargements traités séparément.
- Rejet de paiement, paiement partiel, nouveau callback, double clic / appels concurrents et souscription non éligible : aucun crédit indu.
- Catalogue, dossier de souscription et fiche membre : **prix total fixé**, même historique, même date de période et même famille de pack.

**Note historique :** la refonte a une sauvegarde applicative et PostgreSQL hors Git : `/home/ubuntu/cect-private-backups/redesign-stable-20261022/`. Ne jamais committer le dump, `.env`, les preuves KYC ou les clés privées.

## 6. Implémentation du relevé de paiements (branche design uniquement)

**Endpoint en lecture seule :** `GET /api/v1/me/payment-statement` (`auth:sanctum`, périmètre limité au membre connecté, `Cache-Control: no-store, private`).

Ce relevé relit les `PaymentIntent` correspondant aux cotisations, souscriptions de packs et rechargements du membre. Une intention de paiement n'est comptabilisée dans `confirmed_total_xof` que si son statut est **succeeded**. Pour un même objet, une intention réglée l'emporte sur une tentative ultérieure non concluante. L'absence d'intention de paiement pour une formule de prix zéro activée est indiquée `free`, jamais `paid`.

Le contrat renvoie `payment_mode=upfront_once`, `installments_allowed=false`, les montants de contrat / frais / total, la date de confirmation, le type PF/PM et le prestataire (une transaction du prestataire `fake` est explicitement étiquetée **simulation** par le frontend). Les crédits YEM continuent d'être lus depuis les **PackPeriod** propres au membre, sous l'API existante : les deux registres sont distincts.

**Recette ciblée réussie sur la base de test** : 4 scénarios de paiement intégral et crédits périodiques (85 assertions) et 3 scénarios de relevé financier, frais, isolation et accès (42 assertions). Vérification TypeScript/Vite du frontend réussie. Aucun déploiement de ces nouveautés dans la version stable.
