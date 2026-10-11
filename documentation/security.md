# Sécurité et garde-fous

## Principes

- RBAC serveur obligatoire.
- Superadmin possède implicitement toutes les permissions via `Gate::before`.
- Les actions catalogue utilisent `packs.view` / `packs.manage`.
- Les mutations sensibles requièrent un step-up récent.
- Les versions publiées des packs sont immuables en base.
- Journalisation et audit des changements métier.
- Les secrets ne sont jamais committés.
- Les paiements ne sont jamais validés sur la seule base d'un retour navigateur.

## Paiements

Le provider fake est limité aux tests. Le staging utilise FedaPay Sandbox, sauf mode présentation explicitement activé.

Le webhook FedaPay est signé puis la transaction est rechargée depuis FedaPay avant règlement métier.

## Données de recette

Les comptes utilisateurs temporaires peuvent être nettoyés avant démonstration. Les comptes de démonstration de référence sont conservés selon le README.

Le 11 octobre 2026, les comptes de tests créés manuellement ont été supprimés avec leurs données associées de paiement, adhésion, Pernum, OTP et événements webhook liés. Les comptes de démonstration ont été conservés.

## Secrets exposés pendant les tests

Des secrets de recette ont été saisis manuellement pendant la configuration. Avant toute production réelle, régénérer au minimum :

- clé FedaPay live si elle a été partagée hors coffre ;
- clé FedaPay sandbox après les tests ;
- mot de passe d'application SMTP Gmail.

Ne jamais copier ces valeurs dans Git, README, logs ou documentation.
