## VERDICT DU DÉPLOIEMENT — 22 OCTOBRE 2026

Demande explicite : « fais tous là et déploie ». Le MCP Didit a servi
à publier les workflows PF et PM dans l'application CECT-test, tandis que
la destination webhook reste créée mais volontairement désactivée.

**Résultat de recette GLOBAL actuel : 483 tests réussis, 121 échecs,
5 936 assertions, 256,86 secondes**, isolée sur `cectapi_test` ;
les builds frontend membre et admin passent. **NO-GO déploiement.**
Les erreurs touchent notamment la connexion, backoffice financier,
paiements, membres, achats de packs, marchands et anciens scénarios
Validateur. Les statuts HTTP les plus fréquents sont `403` à la place
de `200`/`201` ou `404` à la place de `200`. Certaines attentes
appartiennent à l'ancien modèle métier, d'autres demandent une
analyse de cause réelle. Ne pas désactiver la sécurité pour faire
passer les tests.

**Obstacles indépendants :** la clé API Didit et le secret HMAC
n'existent toujours pas dans la configuration privée du serveur ;
le MCP masque intentionnellement ces valeurs ; aucun profil de
conformité Didit n'est enregistré et `response_attributes=null`
autorise un retour de trop nombreuses données sensibles. La liaison
KYB PM côté API CECT n'est pas validée en situation réelle.

**Aucune mise à jour du service CECT n'a été déployée** :
checkout backend `/var/www/cect-api` reste sur `b1143de`, base
`cectapi_live` inchangée, 2 comptes internes, 1 membre, 0 pack,
une clé d'accès Superadmin. Services API HTTP 200, worker actif,
journal des quotas cohérent, chaîne d'audit intègre (134 événements).
Le contrôle Superadmin par certificat n'a pas été modifié.

**Plan de sortie :** installation sécurisée et non exposée des
secrets depuis la console Didit ; contrôle environnement Sandbox
et minimisation des attributs retournés ; profil de conformité
validé par le responsable ; implémentation/corrélation KYB PM ;
réparation et classification des régressions ; essais caméra/selfie
avec données synthétiques ; sauvegarde, déploiement coordonné,
activation du webhook après tests de signature, puis rollback
si un contrôle d'intégrité échoue.

---
## Rapport de préparation au déploiement — 22 octobre 2026 (réel, via MCP Didit)

### Effectué chez Didit

- Application ciblée : `CECT-test (Sandbox)` (UUID
  `3d71e41c-317b-49d8-ba1c-c220866328f5`) ; mode technique
  `sandbox` doit encore être confirmé par l'information mode
  (le nom ne constitue pas une preuve).
- Workflow PF `635693e0-768a-45fe-ad42-e7bae9c649b8` : **PUBLISHED** ;
  documents caméra (incluant les documents togolais dans le catalogue),
  liveness `ACTIVE_3D`, face match, IP analyse ; vérification complète
  de son graphe **validée** après correction de l'allow-list documentaire.
- Workflow PM `96d81562-e65f-4694-b7a5-c2552d92c83c` : **PUBLISHED** ;
  registre KYB, documents de société, contrôle UBO/dirigeants lié au
  KYC PF. Graphe **validé**.
- Les workflows prédéfinis de la société et `My Application` n'ont
  pas été modifiés. Aucun workflow CECT publié n'a été défini comme
  workflow global par défaut.
- Destination webhook `2112dabd-33d4-4025-a32f-d78962d51627`, v3
  vers `https://api.13-49-203-235.sslip.io/api/v1/webhooks/kyc/didit`
  : **CREATED, DISABLED**, tant que le secret n'est pas déployé.
- Solde affiché lors du contrôle : 0 USD, recharge automatique OFF ;
  les 500 usages gratuits de certains contrôles demeurent disponibles.
  Le liveness actif est facturable hors Sandbox réel ; aucune session
  ni commande de recharge lancée dans ces opérations.

### État du déploiement CECT

**Non déployé / gate de release BLOQUÉ** pour les raisons suivantes :

1. Le MCP Didit renvoie `api_key_set=true`, mais masque de façon
   permanente la valeur de la clé API et celle du secret webhook.
   L'API Laravel n'a ni `DIDIT_API_KEY` ni
   `DIDIT_WEBHOOK_SECRET` configurés. Ne pas contourner cette protection.
2. La suite de tests fonctionnels globale conserve plusieurs échecs
   de scénarios historiques sans KYC ; ne pas publier un parcours
   cassant inscription/paiements/administration.
3. Le compte Didit n'a aucun **compliance profile** enregistré,
   donc `didit_compliance_check_workflow` ne peut pas statuer ;
   l'utilisation réelle de biométrie doit recevoir une revue formelle.
4. La diffusion de données Didit au client est trop large par défaut :
   `response_attributes=null` signale la transmission de tous les
   attributs, potentiellement vidéos et documents. Réduire avant trafic.
5. L'ancien compte existant dans `cectapi_live` doit être traité par
   une règle de migration KYC explicite avant une bascule générale.
6. L'identité KYB doit être corrélée de façon probante à la PM dans
   notre base Laravel ; pour l'instant `business_verified=false`
   tant que la session société réelle n'est pas liée et contrôlée.

### Vérifications du code et précautions

- Tests KYC/Didit/OTP/admin ciblés : **41 réussis / 223 assertions**
  dans la base indépendante `cectapi_test` ; API Laravel `cectapi_live`
  inchangée.
- Compilations React member/admin : toutes deux **réussies**.
- Backend actuel sur serveur : commit `b1143de` ; code nouveau sur
  branche `custum`, NON déployé. Base `cectapi_live` contient
  2 comptes internes, 1 membre et 0 pack lors du dernier inventaire.
- Les tests globaux sont en cours de vérification finale ; ne jamais
  déclarer mise en production à cause de la seule réussite des tests ciblés.

### Marche finale pour l'administrateur autorisé

Conserver le webhook désactivé tant que l'API n'est pas opérationnelle.
Depuis la console Didit / API & Webhooks, faire installer **directement
vers le gestionnaire de secrets du serveur** (et non le chat/GitHub)
la valeur brute de la clé API et le secret partagé du webhook.
Ajouter le workflow UUID PF ci-dessus et `DIDIT_ENVIRONMENT=sandbox`,
puis exécuter des tests de bout en bout sur un véritable Sandbox et
la suite complète de non-régression avant le déploiement coordonné.

---
## COMPTE MCP DIDIT EFFECTIVEMENT CONNECTÉ — opérations réelles (22 oct 2026)

**Cette section remplace l'ancien blocage OAuth et les mentions selon lesquelles
aucune application/workflow n'aurait été créée dans Didit.**

- Organisation : `CECT-test`, UUID `38472d7c-adb4-4a07-9eaf-a70e85856f5f`.
- Application choisie : `CECT-test (Sandbox)`, UUID
  `3d71e41c-317b-49d8-ba1c-c220866328f5`. Une autre application
  `My Application` existe ; elle est restée intacte.
- **Workflow PF créé dans Didit, DRAFT NON PUBLIÉ** :
  `635693e0-768a-45fe-ad42-e7bae9c649b8`,
  `CECT | KYC PF | Test biométrique` avec étapes OCR caméra,
  LIVENESS `ACTIVE_3D`, FACE_MATCH, IP_ANALYSIS. 3 tentatives, contrôle
  des fichiers suspects et revue manuelle ; pas de mise en défaut,
  pas de branding blanc payant. Prix théorique affiché 0,15–0,38 USD
  selon usages/quotas et options. **Ce tarif n'est pas une facture**.
- **Workflow PM KYB créé dans Didit, DRAFT NON PUBLIÉ** :
  `96d81562-e65f-4694-b7a5-c2552d92c83c`,
  `CECT | KYB PM | Entreprises test` : registre d'entreprise, pièces
  présence légale/structure d'actionnariat, personnes clés UBO + dirigeants
  liées au KYC PF ci-dessus ; workflow distinct car KYB ≠ KYC. Prix
  théorique console 2 USD, sans débit constaté.
- **Destination webhook créée mais DÉSACTIVÉE** :
  UUID `2112dabd-33d4-4025-a32f-d78962d51627`, version v3,
  seulement `status.updated`,
  `https://api.13-49-203-235.sslip.io/api/v1/webhooks/kyc/didit`.
  Secret de destination présent chez Didit et masqué par le MCP ;
  **non injecté sur notre serveur** ; activer uniquement après le
  déploiement sécurisé de l'API et un test signé réussi.
- Balance Didit consultée : 0 USD, auto-recharge désactivée,
  500 utilisations gratuites ID/Passive/Face match/IP encore disponibles ;
  attention `ACTIVE_3D` est une option payante sur une application LIVE.
- Clé API : application `api_key_set=true` chez Didit, mais valeur
  brute **REDACTED** dans ce MCP. Aucune clé copiée dans GitHub.
- **Aucune session KYC créée**, aucun selfie/vidéo capturé et
  aucune facturation lancée.

### Étapes bloquantes toujours ouvertes

1. Vérifier **la véritable valeur du champ environnement/mode de l'application**
   dans la console Didit (le nom `Sandbox` n'est pas une preuve suffisante).
2. Confirmer les paramètres et les données minimales retournées à CECT.
   La configuration Didit `response_attributes=null` signifie que
   **tous les champs sont retournés**, potentiellement incluant documents
   biométriques et vidéo ; limiter dans la console avant tout usage réel.
3. Publier seulement lorsque mode Sandbox, données retournées, règle
   de vérification et coûts ont été revus.
4. Lier de façon sûre le secret webhook et la clé application au
   backend sans les exposer aux logs, ChatGPT ou Git ; valider les vrais
   formats Didit de réponses/vérifications contre la documentation.
5. Tester sessions fictives, webhooks, interface, refus, rejoués, liveness
   vidéo et PM avec KYB, puis refaire les tests globaux de régression.
6. **Ne pas déployer le connecteur ni activer la collecte biométrique réelle**
   tant que les contrôles sécurité et conformité ne sont pas terminés.

Résultat : création réelle de 2 workflows Didit et d'une destination webhook
non activée. Les historiques de CECT `cectapi_live` et tous les comptes
internes n'ont pas été modifiés.

---
## Accès fournisseur — compte Didit confirmé, console non connectée (22/10/2026)

Le responsable du projet a créé son compte Didit. Un email de bienvenue officiel
avec objet « Your Didit account is live » confirme l'activation d'une organisation.
Cela **ne prouve pas** qu'une application Sandbox, un workflow KYC, une destination
webhook ou une clé API aient déjà été créés.

Le compte a été ouvert via connexion Google, mais la session navigateur Didit
n'est **pas** partagée automatiquement avec l'agent dans cette conversation.
L'accès à la console nécessite un navigateur Work autorisé ou une connexion
OAuth au MCP officiel `https://mcp.didit.me/mcp`. Aucun connecteur Didit
n'est disponible parmi les connecteurs installés ici.

**Séquence dans la console dès que l'accès est autorisé :**
1. Sélectionner l'organisation CECT et créer/identifier une application **Sandbox**.
2. Créer un workflow KYC `CECT-KYC-PF-SANDBOX` avec vérification de document
   officiel (pays cibles dont Togo), selfie, **liveness actif `ACTIVE_3D`**,
   face match 1:1, revue humaine pour les signaux de risque ; puis **Publish**.
3. Prévoir séparément `CECT-KYB-PM-SANDBOX` (registre, société, bénéficiaires
   effectifs, représentant et liaison des identités), qui exige encore
   des développements et ne doit pas être présenté comme fonctionnel.
4. Dans API & Webhooks, créer la clé de l'application et la destination
   HTTPS `https://api.13-49-203-235.sslip.io/api/v1/webhooks/kyc/didit`
   pour `status.updated`, récupérer son secret distinct.
5. Stocker les trois secrets dans une configuration privée du serveur,
   jamais dans GitHub/ChatGPT ; lancer une recette Sandbox avec médias fictifs.
6. Conserver `DIDIT_AUTO_APPROVE=false` ; contrôler les signatures V2,
   résultats `ACTIVE_3D`, face match, cas de refus/réessai et ségrégation
   des rôles avant un déploiement avec sauvegarde/rollback.

**BLOCAGE ACTUEL :** aucune authentification Didit utilisable par l'agent
pour créer le workflow, récupérer les secrets et tester l'API réelle.
Le dépôt `custum` est prêt pour la suite des travaux ; le backend déployé
sur `cectapi_live` n'a pas été mis à jour pour Didit.

Sources Didit officielles :
https://docs.didit.me/integration/mcp/overview
https://help.didit.me/getting-started/understanding-the-console
https://help.didit.me/workflows/build-a-verification-workflow
https://docs.didit.me/integration/webhooks

---
## Situation vérifiée — 22 octobre 2026 : branche prête, compte Didit manquant

**Actions effectivement réalisées :** étude de la documentation officielle v3,
durcissement des signatures HMAC-V2 en PHP (objets JSON vides et Unicode),
reconnaissance de la méthode réelle `ACTIVE_3D`, exigence de cohérence
de `X-Timestamp` avec l'horodatage signé, traitement sécurisé des webhooks
de démonstration console et maintien des contrôles de séparation Sandbox/Live.

Tests finaux sur l'ancienne base de QA `cectapi_test` :
**22 tests ciblés réussis, 128 assertions.** Appels HTTP Didit simulés,
pas de confirmation de bout en bout contre l'infrastructure Didit.

**Blocage authentification externe :** aucune intégration Didit connectée
à cet espace ChatGPT, et aucun courrier Didit existant trouvé dans les
deux comptes Gmail connectés. Clés `DIDIT_API_KEY`, `DIDIT_WORKFLOW_ID`
et `DIDIT_WEBHOOK_SECRET` absentes du .env du backend LIVE consulté.
**Aucun compte, organisation, application Sandbox ou workflow Didit réel
n'a été créé à cette étape.** Aucun secret n'est stocké dans GitHub.

**KYB des PM NON implémenté :** la documentation Didit v3 retourne les
preuves d'entreprise dans un objet de session business distinct avec
`registry_checks[]`, `document_verifications[]` et `key_people_checks[]`,
et non `business_verifications[]` dans la décision KYC du représentant.
Par sécurité, le code retourne `business_verified=false` tant qu'une
session KYB distincte, liée au représentant et à la PM, n'est pas
implémentée et vérifiée. Cela **bloque le paiement des PM** et ne doit
pas être présenté comme un KYB fonctionnel.

**Déploiement : NON**, en particulier avant :
- Connexion personnelle autorisée à la console Didit et création de
  l'organisation/app Sandbox au nom convenu pour la CECT ;
- Activation d'un workflow **ID Verification + 3D active liveness + Face Match**
  dans cette application, et configuration de son webhook `status.updated` ;
- Stockage serveur sécurisé des trois secrets et essais Didit réels sur
  des documents fictifs (la Sandbox conserve réellement les captures) ;
- Implémentation KYB PM, revue de la protection des données biométriques,
  conformité locale et correction des tests globaux encore échoués.

Sources primaires de vérité :
https://docs.didit.me/integration/webhooks
https://docs.didit.me/integration/sandbox-testing
https://docs.didit.me/integration/verification-statuses

---

### Sécurisation complémentaire, recette finale ciblée

- Les notifications Didit signées sont envoyées à un **job Laravel** :
  réponse HTTP immédiate, retries si la consultation Didit échoue.
- Environnement webhook signé obligatoire : `sandbox` ou `live`, jamais
  mélanger les deux. Mode `live` requis avant une vraie approbation
  (le mode `sandbox` ne peut activer un membre que dans la suite de tests isolée).
- **Sandbox Didit simule les verdicts MAIS conserve les médias réels uploadés.**
  Ne jamais utiliser de vrais documents, selfies ni vidéos personnels
  lors des tests sandbox ; employer exclusivement les pièces de démonstration.
- Nouveaux tests ciblés : **20 tests réussis, 120 assertions**, incluant
  webhook signé HMAC-V2, replay potentiel/erreur d'environnement,
  vérification indépendant du statut faux, séparation Validateur/Comptabilité.
- Les appels API réels et la reconnaissance faciale de Didit restent
  **NON TESTÉS** faute des trois secrets dans la configuration du serveur.
- À la date du contrôle, la base active utilise encore son ancien commit
  backend et reste intacte. Aucun déploiement ni débit Didit.

Documentation éditeur sandbox :
https://help.didit.me/getting-started/sandbox-and-testing


## MISE À JOUR V5.16 — Didit retenu et connecteur développé (22 octobre 2026)

**Décision métier : DIDIT est le prestataire officiel choisi pour le KYC CECT.**
Cet état remplace les mentions précédentes « prestataire non choisi ».

### Implémentation sur la branche custum (non déployée)

- `KYC_PROVIDER=didit` par défaut dans le nouveau code, échec sécurisé tant que
  les identifiants sont absents. Ancien mode manuel réservé aux tests isolés.
- Backend : `app/Domain/Kyc/Didit/DiditGateway.php` crée des sessions hébergées
  en POST `https://verification.didit.me/v3/session/` avec `workflow_id` et
  `vendor_data=cect-kyc:<ulid>` ; seul le serveur reçoit `DIDIT_API_KEY`.
- Frontend : page `/espace/kyc`, bouton Didit ouvrant le parcours hébergé
  capture documentaire, selfie et liveness actif de Didit ; CECT ne demande
  pas les enregistrements biométriques en local.
- Webhook HTTPS : `POST /api/v1/webhooks/kyc/didit`, événement officiel
  `status.updated`. Vérification HMAC du corps complet `X-Signature-V2`
  (JSON canonique) ou `X-Signature` (octets bruts), délai de validité de
  300 secondes, corrélation stricte sur une session stockée dans CECT.
  `X-Signature-Simple` est volontairement refusé.
- Réconciliation de secours : `POST /api/v1/me/kyc/didit/refresh` interroge
  `GET /v3/session/{id}/decision/` avec la clé serveur, débit limité.
  Un verdict dans l'URL de retour navigateur ou le corps du webhook
  NE peut pas approuver directement le dossier.
- Décision CECT : le verdict Didit « Approved » doit aussi posséder
  ID verification APPROVED, liveness ACTIF 3D ACTION / FLASH APPROVED,
  face match APPROVED, aucune alerte et environnement correct.
  Les résultats non documentés/incomplets restent en révision humaine.
- Personne morale : même traitement du représentant ET une preuve
  `business_verifications` KYB approuvée ; sans résultat société,
  le paiement est bloqué.
- Le Validateur dispose des indicateurs Didit, sans accès aux médias
  biométriques bruts. `DIDIT_AUTO_APPROVE=false` par défaut ; activation
  automatique possible uniquement après arbitrage légal/risque.
- Les historiques Superadmin, paramètres admin, clés mTLS et base
  `cectapi_live` n'ont pas été modifiés par ce chantier.

### Paramétrage Didit nécessaire (secrets jamais dans GitHub)

1. Dans https://business.didit.me/ créer une application dans le SANDBOX.
2. Workflow officiel CECT : ID Verification + **3D Action liveness**
   (ou méthode active certifiée que le contrôle backend reconnaît)
   + Face Match 1:1 ; KYB pour personnes morales, sous réserve
   des modules/disponibilités du contrat et des pays couverts.
3. Copier depuis la console **en environnement serveur sécurisé** :
   `DIDIT_API_KEY`, `DIDIT_WORKFLOW_ID`, `DIDIT_WEBHOOK_SECRET`.
   Configurer `DIDIT_ENVIRONMENT=sandbox` pour les essais, puis `live` sur l'application de production Didit,
   `DIDIT_AUTO_APPROVE=false` et `KYC_PROVIDER=didit`.
4. Déclarer la destination webhook HTTPS
   `https://api.13-49-203-235.sslip.io/api/v1/webhooks/kyc/didit`
   et s'abonner à `status.updated`. Confirmer les signatures
   `X-Signature` et `X-Signature-V2` exactes avec **Try Webhook**.
5. Tester avec données fictives en sandbox l'OTP, les documents,
   la caméra, selfie, liveness vidéo, match, refus/réessai,
   KYB PM, duplications/replays webhook et paiement bloqué.
6. Vérifier politique vie privée, transfert international, rétention
   des données et contrat Didit. Pour activer en conditions réelles,
   obtenir les identifiants production et repasser la recette complète.

**Tests réalisés en environnement isolé `cectapi_test` : 20 tests ciblés
réussis (120 assertions), dont vérification HMAC falsifiée et vérification
des preuves de liveness actif ; frontend membre compilé.**
Cette preuve technique n'est pas un essai réel des serveurs Didit :
les appels HTTP Didit ont été simulés. Sans clés sandbox, workflow réel,
webhook réel, essais UI caméra et suite globale satisfaisante,
**le déploiement reste BLOQUÉ**.

Références Didit vérifiées :
https://didit.me/developers/
https://help.didit.me/integration/webhooks-basics
https://help.didit.me/liveness-biometrics/how-liveness-works
https://help.didit.me/verification-sessions/reading-a-verification-result

---
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
Les pièces sont conservées sous forme chiffrée sur le disque privé Laravel,
non accessibles par URL publique. Le déchiffrement n'intervient qu'après
contrôle d'autorisation et consultation journalisée. Seul un personnel actif explicitement autorisé avec
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
- Tests ciblés KYC et administration : **15 réussis, 84 assertions**.
  Vérifications : dossier et documents privés, chiffrement, autorisation
  Validateur, séparation de Comptabilité, refus de paiement sans KYC approuvé,
  approbation, demande de complément et dépôt corrigé.
- Suite générale de tests fonctionnels sur cette version de développement :
  **473 réussis, 123 en échec, 5 884 assertions** (exécution précédente, avant les dernières corrections ciblées). Certains cas anciens
  supposent un paiement sans vérification et le retrait du rôle Validateur ;
  d'autres échecs portent sur des routes/backoffice non encore qualifiées.
  **Ne pas attribuer automatiquement tous ces échecs au KYC.**
- **Déploiement : bloqué** tant que la régression, la protection des pièces
  et les exigences juridiques ne sont pas satisfaisantes.

Le système KYC manuel est développé dans les trois dépôts mais n'est PAS
encore accessible sur le site actif. Le KYC automatique ne fonctionne pas
sans un prestataire d'identité réel et une interface contractuelle sécurisée.

## EXIGENCE COMPLEMENTAIRE — SELFIE, VIDEO ET KYC BIOMETRIQUE (22 octobre 2026)

Décision complémentaire expresse de l'entreprise : **le KYC CECT doit inclure
une prise de SELFIE EN DIRECT et une SEQUENCE VIDEO GUIDEE**, et pas uniquement
des photos de documents officiels. Le niveau attendu est un onboarding
biométrique réel, avec liveness et confrontation documentaire.

### Parcours d'identification attendu — PF et représentant PM

1. Consentement/information claire sur les finalités, prestataire, conservation
   des données biométriques, éventuels transferts à l'étranger et droits.
2. Scan des documents officiels recto/verso (ou passeport), extraction OCR,
   contrôle de qualité, cohérence, validité et indices de falsification.
3. **Selfie en direct** via caméra, cadrage et contrôle de qualité, pas une
   image importée depuis la galerie comme preuve exclusive.
4. **Liveness actif par VIDEO/sequence caméra** : actions aléatoires imposées
   par SDK tiers (tête, regard, flash/mouvements selon prestataire),
   protection contre photo, masque, vidéo rejouée, spoof et deepfake.
   La nature exacte du fichier brut (vidéo stockée vs frames sécurisées)
   dépend de l'API du fournisseur et de la politique de minimisation.
5. Comparaison biométrique selfie/video contre la photographie de la pièce
   (face match), vérification de qualité et des seuils documentés.
6. Vérification des doublons, signaux appareils/IP, contrôles risque,
   listes sanctions/PEP seulement si justifiés et autorisés ; vérification
   KYB du PM/identité du représentant et bénéficiaires effectifs selon règles.
7. Résultat tiers authentifié : VERIFIED / IN_REVIEW / REJECTED / RETRY.
   Liveness raté, doublon, photo incohérente ou vidéo non vérifiable
   imposent une REVUE HUMAINE, jamais une approbation automatique forcée.
8. Validation humaine par rôle Validateur KYC, motif, preuve de contrôle,
   historique, second avis/recours et journal d'accès aux pièces.
9. Paiement de l'adhésion débloqué uniquement lorsque **toutes** les étapes
   obligatoires et le verdict d'identité sont approuvés.

### Exigences techniques et opérationnelles

- Interface mobile-first, caméra avant/arrière avec permission explicite
  getUserMedia / SDK, possibilité de reprendre une capture de mauvaise
  qualité, contrôle de bande passante, reprise après perte réseau,
  navigation accessible et support d'appareils modestes en Afrique.
- Session fournisseur éphémère liée à un dossier CECT via nonce/idempotence,
  webhook signé, réconciliation serveur, prévention des replay et des faux
  callbacks ; aucune décision finale fournie par le navigateur n'est fiable.
- Séparer statut de l'étape DOCUMENT, SELFIE, VIDEO_LIVENESS, FACE_MATCH,
  FRAUD_CHECK, KYC_FINAL, identité du Validateur, source auto/manuelle.
- Chiffrement au repos et en transit, conservation minimale, journal
  d'accès, droits d'effacement/rectification dans les limites légales,
  procédure de gestion des incidents et analyse antimalware des fichiers.
- La reconnaissance faciale/biométrie requiert une revue conformité
  spécifique avec l'IPDCP Togo AVANT traitement réel, notamment sous
  la loi 2019-014. Vérifier les conditions de transfert international.

### Prestataires possibles et coûts consultés en octobre 2026

- DIDIT : 500 KYC mensuels gratuits pour ID + liveness PASSIF + face match
  + analyse IP/appareil, puis 0,33 USD par vérification complète.
  Le liveness ACTIF (mouvements/3D) est facturé 0,15 USD par contrôle
  dès le premier contrôle ; confirmez le format de séquence VIDEO précis
  proposé au SDK avant contractualisation.
- SUMSUB : Basic 1,35 USD / KYC, engagement minimum 149 USD / mois ;
  document, selfie, liveness, face match. Contrôler les options vidéo.
- IDENFY : Basic 1,35 USD / KYC, engagement minimum 135 USD / mois ;
  options de liveness 3D et contrôle humain facturées séparément.
- SMILE ID : tarif sur devis ; catalogue documents officiels incluant
  le Togo et solutions selfie/liveness. Vérifier le périmètre vidéo.

Sources fournisseurs :
https://didit.me/pricing/
https://help.didit.me/getting-started/free-plan
https://help.didit.me/liveness-biometrics/how-liveness-works
https://sumsub.com/fr/pricing/
https://idenfy.com/fr/tarifs/
https://smile.id/supported-documents
Autorité de protection des données du Togo : https://ipdcp.tg/

### Etat d'implémentation honnête

Le code KYC déjà ajouté sur custum sait recevoir un document chiffré,
présenter une file Validateur et vérifier une décision manuelle ;
**LE CONNECTEUR DIDIT EST MAINTENANT DEVELOPPE DANS LE CODE, MAIS NON ACTIVE SUR LE SITE ACTIF ET NON TESTE AVEC LES VRAIES API/SDK DIDIT.**
Le parcours complet document + selfie + vidéo est une EXIGENCE FERME
de la prochaine phase de développement, pas une fonctionnalité déployée.
Ne jamais qualifier le KYC précédent de complet ou suffisamment
biométrique avant intégration réelle, tests anti-fraude et conformité.

### Critères de recette bloquants

Un client qui soumet seulement une pièce sans selfie/liveness ne doit
pas être automatiquement qualifié de KYC complet. Après activation
du nouveau dispositif biométrique, la règle de paiement doit exiger
un résultat KYC final incluant preuve de vie vérifiée, correspondance
faciale et contrôle documentaire, ou une procédure d'exception
formellement autorisée et auditée. Aucune validation des documents
fictifs, capture vidéo locale seule ou score OCR ne vaut preuve.
