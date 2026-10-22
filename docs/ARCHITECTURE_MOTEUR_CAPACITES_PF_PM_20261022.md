# CECT — Moteur de capacités PF / PM et contrats d'affichage
**Date :** 22 octobre 2026  
**État :** proposition d'architecture ; règles métier non encore validées explicitement ; **aucune migration ou politique modifiée**.

## But
Obtenir une interface spectaculaire mais strictement fidèle au métier, sans afficher des droits fictifs ni autoriser une opération simplement parce qu'un bouton est visible.

## Sources de vérité (backend)
1. Identité : type `person_type` (`natural` / `legal`), statut du compte et PERNUM.
2. Vérification : dossier KYC / KYB, résultat du prestataire et contrôle CECT applicable. Ne jamais déduire une approbation KYC de la seule couleur d'une carte frontend.
3. Adhésion : statut de cotisation et activation, issus de `memberships`.
4. Packs : offres publiées, versions, type de personne éligible, compteurs de souscription et état `active` / `pending_payment`.
5. Quotas : par pack, séparation `available / reserved / consumed / credited`. **Jamais de fusion des droits Vendeur avec les YEM dépensables Consommateur/Personnel.**
6. Paiement marchand : droit ouvert par pack compatible, puis vérification transactionnelle et réservation atomique.
7. Vente à la CECT / rééchange marchand : contrôle du rôle, du pack, des seuils, du moyen de réception et des étapes de validation Comptabilité.
8. Réglement : provider de démonstration uniquement sur environnement de présentation ; ne jamais débiter des fonds réels dans le flux de recette.

## Contrat de présentation proposé (en lecture seule)
Le backend pourrait exposer `GET /me/capabilities`, en extension du dashboard actuel, à introduire seulement après validation des règles ci-dessous :

```json
{
  "persona": "PF_OR_PM",
  "stage": "ACCOUNT_ACTIVE_OR_OTHER",
  "membership": { "active": true },
  "verification": { "status": "provider_or_cect_truth" },
  "pack_entitlements": [],
  "actions": [
    {
      "id": "subscribe_pack",
      "allowed": false,
      "reason_code": "NEEDS_PUBLISHED_ELIGIBLE_PLAN",
      "next_step": "open_catalog"
    }
  ],
  "balances": { "spendable": "0", "reserved": "0" }
}
```

Tous les montants et droits sont dérivés des tables métier à chaque requête. Le frontend peut améliorer les libellés, **pas accorder un droit**.

## États d'interface sans mensonge
- Aucun pack : proposer des opérations accessibles après souscription, sans rendre ces opérations exécutables.
- Catalogue vide : afficher « aucune formule publiée », non un faux échec technique ni une offre inventée.
- Appartenance PM : titre et prochaines actions centrés sur activité légale/marchande ; PF sur usages personnels. Les types éligibles réels viennent du catalogue et du backend.
- Adhésion active : ne pas afficher une transaction YEM implicite.
- 0 YEM : afficher 0, non des données de démonstration générées artificiellement.
- Échec API : distinguer erreur réseau, refus de permission et absence légitime de données.

## Invariants à conserver
- Authentification/autorisation Laravel serveur indépendantes de la navigation React.
- Pas de doubles souscriptions ni paiements doublés : clés d'idempotence, statuts, verrouillage DB.
- Seuls les packs activés ouvrent leurs droits respectifs.
- Une opération de quota ne peut rendre `available` négatif ; réservation avant finalisation si le workflow existant le prévoit.
- Aucune vente YEM depuis un pack de consommation.
- L'historique d'audit survit à la clôture/suppression des membres selon les règles de conservation.
- Aucune carte de synthèse ne doit divulguer de pièce d'identité, selfie, NIF ou information bancaire.
- La simulation de paiement n'est jamais une preuve de règlement réel.

## Questions métier bloquantes à faire valider avec le propriétaire
1. Quelles familles exactes de packs PF et PM sont autorisées, cumulables ou exclusives ?
2. Quel ordre définitif pour KYC/KYB et adhésion ? Le déploiement de démonstration actuel applique KYC puis cotisation.
3. Pack Personnel : accès, cycle, recharge et cumul.
4. Validation QR marchand : à quel moment débiter le quota et qui confirme réception ?
5. Vente/revente : barèmes, limites, commissions, moyens de réception par pays et responsable de validation.
6. Durée d'adhésion et effets d'une suspension, expiration, rejet KYC ou changement de statut.
7. Seuils et règles paramétrables par Superadmin : ce qui est configurable et ce qui reste invariant.

## Phases sans risque
- **UI-1** : refonte dashboard PF/PM, vrais indicateurs backend et parcours visible ; branche dédiée sans déploiement.
- **UI-2** : pages Packs / Activité / Vente / QR / Profil adaptées, messages d'états vides explicites et actionnables.
- **BE-1** : centraliser la décision métier après validation, sans dupliquer les règles côté React.
- **BE-2** : tests PF/PM, contrat API, vérification de l'absence de fuite inter-profils, limites de packs, transitions et tentatives répétées.
- **Release** : contrôle visuel et fonctionnel complet sur staging, sauvegarde et plan de retour arrière, puis activation contrôlée.

## Protection de l'état stable
Le 22/10/2026, un point de restauration local hors Git a été réalisé sous `/home/ubuntu/cect-private-backups/redesign-stable-20261022/`. L'archive PostgreSQL est vérifiée, l'archive application / frontend / admin est contrôlée par SHA-256. Les configurations root-protégées Nginx et anciens snapshots internes inaccessibles à l'utilisateur serveur sont exclus et identifiés dans `RESTORE_MANIFEST.txt`. **Aucun secret, dump ou KYC brut ne doit rejoindre GitHub.**
