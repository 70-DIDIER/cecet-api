# État de conformité SuperAdmin v5.1 — 18 octobre 2026

**Référence active :** `Cahier_des_charges_CECT_YEM_v5.0.md`, addendum §13 ; branches `custum` des dépôts `70-DIDIER` uniquement.

| Exigence | Constat vérifié | Reste à recetter |
| --- | --- | --- |
| Trois rôles internes | SuperAdmin, Administrateur, Comptabilité dans PostgreSQL ; Validateur retiré | Paiements et validations avec comptes Comptabilité réels |
| Gouvernance | Écran `/admin/gouvernance` alimenté par API et navigation vers modules | Exercer tous les boutons avec des permissions minimales |
| Tarifs et cours YEM | 510/650/610 FCFA/YEM, frais PF/PM configurables, réservations SuperAdmin | Ratification des conditions économiques |
| Paramètres versionnés | Éditeur + motif + date d'effet ; historique consultable | Vérifier répercussion immédiate sur inscription et achats |
| Packs et cashback | Catalogue géré par API, 25 packs conservés, configuration de bonus YEM | Barèmes définitifs, quotas et cas limite d'utilisation |
| Accès privilégié | mTLS + RSA + OTP, 403 sans certificat ; SuperAdmin conservé | E2E navigateur, renouvellement CRL et clé chiffrée |
| Économie financière | Paiements `fake` exclus du KPI encaissé ; démo vidée | Recette paiement, payouts, contrôles d'intégrité |
| Sécurité et sauvegarde | Journalisation et sauvegarde PostgreSQL préalable | Test de restauration, audit indépendant |

**État : conformité fonctionnelle partielle ; pas de go-live production validé.**

---

## Archives antérieures (non normatives)
# Matrice de conformité — Cahier des charges CECT/YEM

Dernière revue historique : 11 octobre 2026. **Mise à jour de gouvernance du 18 octobre 2026 ci-dessous : les lignes anciennes « Validateur » et la branche `joseph` ne sont plus des références opérationnelles.**

Ce document relie les exigences du cahier des charges fonctionnel à l'implémentation de la branche `joseph`. Il complète le changelog et sert de checklist de non-régression.

Légende :

- **OK** : implémenté et couvert par le backend / l'interface.
- **OK+** : implémenté avec un contrôle supplémentaire par rapport au minimum demandé.
- **RECETTE** : disponible mais un comportement de démonstration est volontairement réservé au staging.
- **À VALIDER MÉTIER** : le code peut supporter la règle, mais la valeur/règle finale appartient à la coopérative.
- **PRODUCTION** : contrôle à refaire avant le go-live réel.

## 1. Inscription et adhésion

| Exigence | État | Implémentation |
| --- | --- | --- |
| Inscription personne physique / morale | OK | Formulaires adaptés, modèle `Organization` séparé pour la personne morale. |
| Pernum obligatoire et unique | OK | Identité Pernum normalisée et contrôlée côté backend. |
| Vérification email par OTP | OK | Code court, durée/limites configurables, stockage sécurisé. |
| Paiement avant activation | OK | Le backend reste la source de décision ; une redirection navigateur ne suffit pas. |
| Plusieurs tentatives de paiement | OK | Intent / attempts séparés, états historisés. |
| Frais FedaPay supportés par le client | OK | Montant CECT net séparé des frais prestataire. |
| FedaPay webhook + réconciliation serveur-à-serveur | OK+ | Signature vérifiée puis transaction relue chez le prestataire. |
| Mode de démonstration | RECETTE | `platform.demo_gateway_bypass` n'est autorisé qu'en testing/staging et bloque le go-live. |

## 2. Packs et quotas

| Exigence | État | Implémentation |
| --- | --- | --- |
| Familles Vendeur / Consommateur / Personnel / Marchand | OK | Catalogue versionné, aucune formule métier imposée par le frontend. |
| Création d'une formule sans changement de code | OK | Plans + versions séparés. |
| Conditions achetées figées | OK+ | Une version publiée est immuable et chaque souscription garde sa version. |
| Limite de packs | OK | `packs.max_structured_active` configurable ; famille Personnel excluable via paramétrage. |
| Périodes et acquisition de quota | OK | Tâches planifiées et ledger de quota. |
| Cumul Vendeur | OK | Quota non consommé conservé selon les conditions de version. |
| Éligibilité par type de personne | OK+ | Éligibilité stockée dans la version ; le catalogue membre ne retourne que les packs autorisés. |
| Pack Marchand pour personne morale | OK+ | Valeur initiale `eligible_person_types=[legal]` et refus backend pour une personne physique. |
| Administration complète du catalogue | OK | Créer, dupliquer, versionner, publier, suspendre, retirer, archiver. |

## 3. Échanges YEM

| Exigence | État | Implémentation |
| --- | --- | --- |
| Demande depuis un pack autorisé | OK | Contrôle d'appartenance, état et quota côté serveur. |
| Quantité <= quota disponible | OK | Réservation atomique avant traitement. |
| Pays / moyen / numéro de versement | OK | Destination figée par opération ; prise en charge du pays ajoutée au modèle. |
| Référence unique | OK | Référence interne de l'échange. |
| Déclaration d'envoi insuffisante seule | OK | La confirmation opérateur reste obligatoire. |
| Confirmation YEM | OK | File de validation dédiée. |
| Litige | OK | État et actions de dispute tracés. |
| Débit au moment de la confirmation | OK | Ledger de quota et audit. |

## 4. Versements / comptabilité

| Exigence | État | Implémentation |
| --- | --- | --- |
| Création automatique du montant dû | OK | Payout créé après confirmation métier. |
| Découpage Mobile Money | OK | Seuils / nombre d'items configurables. |
| Reprise uniquement du reste dû | OK | Items de payout individualisés et idempotents. |
| Banque / espèces pour gros montant | OK | Règlement manuel avec preuve/référence et maker-checker. |
| Séparation Validateur / Comptabilité | OK+ | Matrice de permissions incompatibles appliquée au backend. |
| Payout automatique | À VALIDER MÉTIER | Provider isolé ; activation dépend du contrat réel du prestataire et du go-live. |

## 5. Back-office

| Exigence | État | Interface |
| --- | --- | --- |
| Tableau de bord | OK | `/admin` |
| Membres + fiche complète + suspension | OK | `/admin/membres` |
| Catalogue packs | OK | `/admin/packs` |
| File de validations | OK | `/admin/validations` |
| Finance / versements | OK | `/admin/finance` |
| Paramétrage | OK+ | `/admin/configuration`, versions + motif + date d'effet |
| Comptes internes / rôles / permissions | OK | `/admin/access` |
| Audit / sécurité / webhooks / supervision | OK+ | `/admin/audit` |
| Exports CSV | OK | `/admin/export` |
| Snapshots consolidés | OK+ | ZIP versionné, manifeste, SHA-256, génération automatique et manuelle |
| Réimport contrôlé | OK+ | Staging, diff, conflits, validation par un autre opérateur |
| Rapports PDF / Excel | OK | Endpoints dédiés et période de rapport |
| Contenus publics | OK | `/admin/contenus` |
| Annuaire marchands | OK+ | Modération back-office et annuaire public séparé |


## 5 bis. Acteurs et représentation d'accès

| Acteur du cahier des charges | Représentation technique | État |
| --- | --- | --- |
| Utilisateur | Compte membre, personne physique ou morale | OK |
| Administrateur | Rôle staff administrateur | OK |
| Superadmin | Rôle staff superadmin protégé | OK+ |
| Validateur | Rôle staff validateur | OK+ |
| Comptabilité | Rôle staff comptabilite, séparé du Validateur | OK+ |
| Yempay / prestataire | Intégration externe par API/webhook, sans session staff implicite | OK+ |

Cette représentation évite de donner à un prestataire externe un compte humain privilégié uniquement pour satisfaire une liste de rôles métier.

## 6. Site public et espace membre

- Site public React séparé de l'espace membre et du back-office.
- Catalogue public alimenté par le backend.
- FAQ, actualités, partenaires et chiffres clés pilotés par le paramétrage.
- Annuaire des marchands alimenté uniquement par les fiches publiées de marchands actifs.
- Espace membre : packs, activité, profil, paiement marchand, vente/rééchange YEM et espace marchand.
- Une personne physique ne doit pas recevoir de Pack Marchand dans son catalogue authentifié.

## 7. Sécurité

Contrôles actuellement retenus :

- TLS ;
- mots de passe hachés ;
- OTP et step-up pour les actions sensibles ;
- RBAC côté serveur ;
- idempotence sur les créations financières sensibles ;
- webhook signé + réconciliation serveur ;
- audit métier append-only ;
- séparation des fonctions ;
- cookies de session sécurisés ;
- CORS limité à l'origine de la plateforme ;
- secrets hors Git ;
- contrôle d'appartenance aux objets ;
- journal de sécurité ;
- vérification d'intégrité du ledger et de l'audit ;
- snapshots consolidés avec empreinte SHA-256.

Un audit de dépendances et un test de régression complet doivent être exécutés avant chaque release candidate. Une absence d'alerte connue ne doit jamais être formulée comme une garantie de « zéro vulnérabilité ».

## 8. Recette vs production

L'environnement public actuel est une recette. Les éléments suivants doivent obligatoirement être revus pour la production :

1. `APP_ENV=production` et mode présentation désactivé.
2. Rotation de tous les secrets ayant servi aux tests.
3. Clés FedaPay live dédiées.
4. Contrat et limites réelles des payouts.
5. Destinataires des alertes d'exploitation.
6. Pernum officiel de la coopérative.
7. Sauvegarde hors serveur / stratégie de restauration.
8. Go-live check intégral.
9. Audit de dépendances et tests complets.
10. Validation métier finale des tarifs, taux, seuils et règles des packs.
