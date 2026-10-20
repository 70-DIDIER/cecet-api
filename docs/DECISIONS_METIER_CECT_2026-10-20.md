# CECT — Registre des décisions métier, validation séquentielle

Date d'ouverture : 20 octobre 2026. Responsable de la décision : maître d'ouvrage / utilisateur. Statut : **recette fonctionnelle par opérations**.

## Mode de travail

1. Une seule opération est examinée à la fois : état existant, simulation isolée et choix proposés.
2. Les réponses explicites du maître d'ouvrage sont enregistrées comme **décisions métier approuvées**, séparées des recommandations et des comportements encore non arbitrés.
3. Aucun changement du code, des prix et des données de production n'est nécessaire à cette étape : les écarts deviennent des **actions à implémenter et à retester après la validation du parcours complet**.
4. Toutes les décisions doivent être reflétées dans le cahier des charges cumulatif, puis contrôlées par de nouveaux tests de recette métiers.

## Opération 01 — Inscription d'une personne physique (PF)

**Statut : VALIDÉ PAR LE MAÎTRE D'OUVRAGE, le 20 octobre 2026.**

| Question | Réponse choisie | Règle métier retenue |
|---|---|---|
| 1. Création du dossier | A | Créer immédiatement le dossier après l'envoi valide du formulaire, puis envoyer un OTP par e-mail. Statut initial : `pending_email_verification`. |
| 2. Sortie OTP valide | A | Après validation de l'OTP, passer en **attente de paiement des droits d'adhésion** : `pending_payment`. |
| 3. Moment d'affichage des droits | B | **Ne pas montrer les frais d'adhésion au membre pendant le formulaire d'inscription**. Les afficher **uniquement après la validation de l'e-mail**. Aucun paiement à l'inscription. |
| 4. Accès avant règlement | A | Le membre `pending_payment` peut seulement consulter **son dossier d'adhésion** et **l'écran de paiement des droits**. Pas de tableau de bord commercial, ni consultation ou souscription de packs, ni achat/vente/rééchange YEM, ni QR/paiement Marchand. |

**Paramétrage financier :** le montant des droits d'adhésion PF et PM demeure modifiable par le SuperAdmin. Aucun montant fixe nouveau n'est décidé dans cette opération ; en staging au moment du contrôle, PF = 30 000 FCFA et PM = 150 000 FCFA. Le moment de cristallisation du prix est à définir dans l'opération suivante.

**Constat technique :** les 19 tests existants d'inscription PF (95 assertions) sont réussis, mais la réponse initiale `POST /api/v1/auth/register` inclut encore un objet `membership_fee` et certaines interfaces publiques présentent le tarif pendant l'inscription. **ÉCART FONCTIONNEL OUVERT** par rapport à la décision 3 : supprimer l'affichage anticipé côté membre et vérifier l'exposition métier cohérente de la donnée (sans rendre les prix moins administrables). Documenter et tester la règle d'interface avant de déployer.

**Constat de permissions :** les autorisations API et les routes du frontend devront explicitement restreindre `pending_payment` aux fonctions « dossier » et « paiement », y compris par accès URL direct. L'accès technique nécessaire pour terminer le paiement, consulter son statut ou se déconnecter ne doit pas être cassé. **ÉCART À AUDITER** : certaines routes de consultation authentifiée sont actuellement utilisables avant l'activation. Exiger des tests d'interdiction pour les espaces commerciaux, pas simplement masquer les menus.

**Hors périmètre de la décision 01 :** modalités de paiement, preuve définitive du règlement, activation, prix figé, tentatives échouées, doubles paiements, virement bancaire et intervention Comptabilité. Ces sujets sont **EN ATTENTE DE DÉCISION — OPÉRATION 02**.

## Opération 02 — Paiement des droits d'adhésion et activation

**Statut : QUESTIONS À SOUMETTRE AU MAÎTRE D'OUVRAGE, aucune option présélectionnée comme décision.**

À déterminer : moyens de paiement par pays, état des tentatives, preuve de succès PSP ou de virement, acteur autorisé à valider, gestion des erreurs et des doublons, moment d'application du tarif versionné SuperAdmin et transition finale vers le statut `active`.
