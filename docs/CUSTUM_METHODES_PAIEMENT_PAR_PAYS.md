# CECT — Disponibilité des moyens de paiement simulés selon le pays

Branche de travail : `custum`, AWS staging, 17 octobre 2026.

## Configuration effective

- `payment.inbound_provider=fake` et `payment.payout_provider=fake`.
- `payment.collection_modes` classe `TMONEY`, `FLOOZ`, `WAVE`, `ORANGE_MONEY` comme `psp`, tous routés vers le simulateur `fake`.
- La configuration `payment.methods` est versionnée via `SettingsService::set` (sans écraser l'historique) :
  - **TG** : `TMONEY` affiché comme **Mixx**, `FLOOZ`.
  - **SN**, **CI** : `WAVE`, `ORANGE_MONEY`.
  - Autres pays : aucun opérateur mobile activé à ce stade.
  - `CASH` et `BANK_TRANSFER` conservent leur workflow manuel fictif.
- Tous les opérateurs ajoutés restent **désactivés par défaut dans l'environnement de production**. Un futur déploiement réel impose revue réglementaire, contrat opérateur et tests dédiés.

## Validation serveur

`POST /api/v1/me/membership/payments` accepte `payment_phone` en E.164 (même format que l'inscription), facultatif pour compatibilité historique.

`StartMembershipPayment` transmet ce numéro à `CollectionRouter`, qui déduit le pays actuellement supporté (`+228` TG, `+221` SN, `+225` CI) et n'autorise que les codes de paiement configurés pour ce pays. En absence de numéro d'opération, il utilise le téléphone du profil.

Le client React reçoit `/api/v1/config.payment_methods` et choisit les logos/méthodes correspondants, mais la **validation métier relève exclusivement de Laravel**.

Une tentative créée sous `fake` ne peut être réglée qu'avec la route authentifiée `POST /api/v1/me/payments/{attempt}/simulate`, disponible uniquement en `testing` et `staging`. Le paiement à crédit, souscription, et adhésion continuent leurs workflows normaux. Un lien de paiement simulé historique GET ne valide jamais de tentative.

## Garantie d'intégrité

- Pas de migration du schéma ni suppression d'utilisateurs.
- Paramètres versionnés dans PostgreSQL, sauvegarde pré-déploiement sous `/home/ubuntu/cect-main-deploy-backups/20261017T185830Z`.
- Tests `MembershipPaymentTest`, `ManualPaymentTest`, `PackSubscriptionTest` : 65 réussis, 397 assertions.
- Tests supplémentaires `MemberRegistrationTest` et `AuthenticationTest` : 36 réussis, 159 assertions.
- Pas de transaction réelle déclenchée pendant le déploiement.

**Attention** : Wave et Orange Money sont des *options de simulation* seulement, pas des canaux de paiement contractualisés.
