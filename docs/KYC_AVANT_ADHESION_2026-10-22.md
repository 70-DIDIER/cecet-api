# CECT — KYC obligatoire avant les frais d'adhésion

Date : 22 octobre 2026. Branches concernées : custum (API, membre, administration).
Statut : code implémenté et en recette, **pas encore déployé**.

## Nouvelle règle métier prioritaire

La vérification e-mail OTP ne conduit plus directement au paiement. Elle conduit
à une étape d'identification KYC obligatoire. Cette instruction nouvelle prévaut
sur la règle précédemment enregistrée le 20 octobre 2026.

Parcours PF/PM : formulaire -> dossier en attente de vérification d'e-mail ->
OTP confirmé -> pending_kyc -> dépôt KYC -> contrôle d'identité -> approved ->
pending_payment -> paiement simulé -> compte active.

Le membre déclare sa pièce d'identité (CNI, passeport ou titre de séjour),
numéro, date de naissance, pays d'émission, adresse et dépose ses justificatifs
recto/verso. La PM ajoute un document juridique de la société ; son représentant
physique présente son identité. Les droits d'adhésion ne sont affichés
qu'après KYC approuvé et ne sont jamais requis à l'inscription.

Le KYC soumis est instruit par un rôle interne Validateur KYC nouveau :
- Réception, file d'attente, consultation des documents uniquement en privé ;
- Approbation justifiée par un contrôle humain réel ;
- Refus avec motif, ou demande de complément ; ré-envoi corrigé ;
- Toutes décisions journalisées et non rejouables après clôture ;
- Aucun droit de décaissement, de validation YEM ou de modification des tarifs.

Le rôle comptabilite continue à assurer les validations financières.
Le rôle validateur ne récupère PAS les anciennes permissions YEM du Validateur
supprimé. Superadmin et comptes internes existants ne sont pas modifiés.

## Validations automatiques

**Mode manuel :** parcours développé avec permission dédiée, authentification
personnel et protection step-up pour les décisions.

**Mode automatique : non opérationnel en l'absence d'un prestataire de
vérification d'identité choisi/contractualisé.** Il exigera une API signée,
des preuves d'authenticité vérifiables, un traitement des erreurs/doublons et
une revue humaine pour les échecs/dossiers incertains. Il est interdit d'appeler
« KYC approuvé » une simple lecture OCR ou une comparaison de texte. Ne pas
activer un fournisseur de simulation comme justificatif d'identité réelle.

## Protection de données

Les numéros/attributs d'identité sont chiffrés via APP_KEY dans la base.
Les pièces sont conservées sur le disque privé Laravel, non accessibles
par URL publique. Seul un personnel actif explicitement autorisé avec
kyc.review peut télécharger une pièce. Réponses no-store et nosniff.
Aucun numéro ni document ne doit figurer dans les logs métier.

Avant exposition à de vrais utilisateurs : ajouter l'antivirus, le journal
des consultations, une durée de conservation/purge validée, un droit de
recours et une information légale appropriée. La loi togolaise n°2019-014
de protection des données personnelles est applicable à examiner ; les
textes BCEAO de KYC doivent être analysés selon la qualification
juridique de la CECT (ne pas présumer qu'elle est institution financière).
Éviter toute collecte biométrique sans base juridique et nécessité démontrée.

## API

Membre : GET et POST /api/v1/me/kyc, écran /espace/kyc.
Validateur : GET /api/v1/kyc/cases, GET /api/v1/kyc/cases/{case},
GET /api/v1/kyc/cases/{case}/documents/{type},
POST /api/v1/kyc/cases/{case}/decision, écran /admin/kyc.

Migration : database/migrations/2026_10_22_000001_create_member_kyc_cases.php.
Modèle chiffré : app/Domain/Kyc/Models/MemberKycCase.php.
Gate métier : app/Domain/Kyc/KycEligibility.php.
Tests : tests/Feature/MemberKycWorkflowTest.php, base indépendante cectapi_test.

## Éléments à valider par l'entreprise avant ouverture publique

- Pièces acceptées par pays, mineurs, personne morale, représentant légal
  et bénéficiaires effectifs ;
- Prestataire KYC automatique, contrôle des documents officiels et contrats ;
- Politique de rejet / second examen / ré-envoi ;
- Conservation, effacement, journal d'accès, exercice des droits et
  revue juridique locale ;
- Rapport d'intégration et tests de non-régression pour les anciennes règles ;
- Déploiement coordonné API + front membre + front admin et sauvegarde/rollback.

Aucun changement n'a été déployé sur la base active cectapi_live durant ce
chantier de développement. Les tests sont exécutés uniquement sur cectapi_test.

## Rapport de recette du développement — 22 octobre 2026

Les trois dépôts ont été clonés dans un espace de CODE isolé sur le serveur,
sans nouvelle base et sans modifier cectapi_live, les comptes Superadmin ni
les paramètres de production. La base de test préexistante cectapi_test
est utilisée uniquement avec APP_ENV=testing et des justificatifs fictifs.

- Compilation TypeScript/Vite CECT-frontend : **réussie**.
- Compilation TypeScript/Vite cect-admin : **réussie**.
- Tests ciblés KYC et administration : **15 réussis, 81 assertions**.
  Vérifications : dossier et documents privés, chiffrement, autorisation
  Validateur, séparation de Comptabilité, refus de paiement sans KYC approuvé,
  approbation, demande de complément et dépôt corrigé.
- Suite générale de tests fonctionnels sur cette version de développement :
  **473 réussis, 123 en échec, 5 884 assertions**. Certains cas anciens
  supposent un paiement sans vérification et le retrait du rôle Validateur ;
  d'autres échecs portent sur des routes/backoffice non encore qualifiées.
  **Ne pas attribuer automatiquement tous ces échecs au KYC.**
- **Déploiement : bloqué** tant que la régression, la protection des pièces
  et les exigences juridiques ne sont pas satisfaisantes.

Le système KYC manuel est développé dans les trois dépôts mais n'est PAS
encore accessible sur le site actif. Le KYC automatique ne fonctionne pas
sans un prestataire d'identité réel et une interface contractuelle sécurisée.
