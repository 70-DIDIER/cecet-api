# CECT — Refonte opérationnelle PF/PM : décision et mise en ligne du 22 octobre 2026

## Décisions métier confirmées

### Fréquences ouvertes aux futures formules
Le Superadmin peut créer de nouvelles versions avec une fréquence quotidienne, hebdomadaire, **quinzaine calendaire (deux par mois)** ou mensuelle ; il fixe nombre de cycles, quota, cumul, délai avant premier crédit, prix, bonus et politique de fin de terme. **Les packs fournis dans Classeur1.pdf restent à 24 quinzaines**, et les formulaires de création d'une nouvelle formule périodique proposent ce réglage comme valeur initiale sans enfermer le Superadmin dans cette seule cadence. Le moteur vérifie chaque version et les anciennes souscriptions ne sont pas recalculées.

### Personnel PF : YEM à la demande, sans calendrier
**Distinguer l'ouverture du portefeuille et l'achat effectif des YEM.** Une formule `PRS-LIBRE` (*Personnel Libre*) est ouverte gratuitement, sans quota par période ni date de fin, puis chaque rechargement a son propre montant choisi (minimum paramétré à 650 FCFA pour cette première formule). Au cours actuellement configuré de 650 FCFA/YEM, payer 6 500 FCFA (hors éventuels frais) représente 10 YEM. La conversion et la facturation finale sont recalculées côté backend et enregistrées par rechargement. La quantité YEM est créditée **après confirmation** du paiement, une seule fois. Elle est immédiatement disponible et peut être consommée sans restriction **de calendrier** ; elle reste bien sûr limitée par le solde réellement acheté et les règles de sécurité. Aucun échéancier fictif n'est créé. Le Superadmin peut créer de nouvelles versions et fixer le minimum, les frais, les offres de cashback et le cours admissible.

### Sens opérationnel des indicateurs d'un pack
1. **Crédité (contrat)** : quantité totale YEM programmée pour toute la durée d'un pack périodique, calculée exactement par somme des périodes contractuelles, cashback inclus ; **ce n'est PAS un solde immédiatement utilisable**. Pour Personnel, ce compteur représente les achats YEM effectivement crédités à date.
2. **Disponible** : solde réellement débloqué et non engagé, issu du compte/ledger YEM du pack.
3. **Réservé** : YEM actuellement bloqués dans une opération en cours (vente, paiement marchand).
4. **Consommé** : YEM débités définitivement après confirmation d'une opération.

La carte présente distinctement **« Débloqué à ce jour »** pour les crédits réellement inscrits au ledger, avec progression de l'entitlement total. Le décompteur de prochaine distribution ne s'applique qu'aux packs périodiques. La périodicité et les dates de fin proviennent des règles contractuelles, non d'une hypothèse mensuelle.

### Expérience PF/PM et mobile
- Filtre de catalogue PF **Tous / Consommateur / Personnel / Vendeur** ; PM voit Marchand uniquement.
- Recherche textuelle et cartes compactes, informations essentielles puis conditions dépliables.
- Cartes Mes packs à quatre mesures dans l'ordre validé, progression, prochaine date/décompteur et actions contextuelles.
- Personnel : accès **Acheter des YEM**, **Utiliser mes YEM** ; aperçu du nombre de YEM selon cours public, avec avertissement que l'API fait foi.
- Vente Vendeur : sélection du pack, soldes, contrôle que la quantité ne dépasse pas le disponible, simulation à **510 FCFA/YEM** au cours d'achat public (le versement final peut dépendre de frais), mise en garde avant premier déblocage.
- Calendrier : période de crédit pour les packs périodiques ; pour Personnel, **historique de rechargements**, sans cycles artificiels.
- Conception responsive en Tailwind : mise en page des quatre mesures en 2 colonnes sur petit écran, actions flexibles et contenus détaillés dépliables ; compilation UI réussie. Une recette manuelle multi-navigateurs demeure pertinente.

## Mise en production de démonstration (staging)
- URL membre : https://13-49-203-235.sslip.io/espace/packs
- Calendrier : https://13-49-203-235.sslip.io/espace/calendrier
- Vente : https://13-49-203-235.sslip.io/espace/vendre
- Superadmin : https://admin.13-49-203-235.sslip.io/admin/packs
- API : https://api.13-49-203-235.sslip.io/api/v1/packs

Versions frontend/admin publiées par symlinks atomiques avec anciens builds préservés :
`/home/ubuntu/cect-releases/frontend-personnel-on-demand-ultimate-20261022` ;
`/home/ubuntu/cect-releases/admin-packs-frequency-flexible-20261022`.

Le backend live a reçu uniquement `MemberDashboardController.php` modifié (champ en lecture seule `contractual_total`) et `PersonnelOnDemandSeeder.php` opt-in, exécuté une fois. **Aucune migration SQL ni suppression d'utilisateur** lors de cette phase. Snapshot des versions web et de l'ancien contrôleur :
`/home/ubuntu/cect-private-backups/before-personnel-premium-20261022/`.
Ne pas restaurer une ancienne sauvegarde DB en écrasant les nouveaux achats créés après la publication.

Le premier essai de bascule a révélé une erreur de permission de répertoire Nginx (`500`). **Corrigée aussitôt** avec les droits de lecture statique sur les répertoires de release, suivie des vérifications **HTTP 200** des pages Packs, Calendrier, Vente, Administration et API. Les assets du build sont publiés sous la nouvelle release.

Tests isolés `cectapi_test` : **Personnel à la demande 1 test / 20 assertions**, **quota contractuel vs réel 2 tests / 15 assertions**. Builds production frontend et admin TypeScript/Vite réussis. Catalogue API live vérifié : **30 packs publiés**, dont **12 Vendeur + 8 Consommateur + 9 Marchand + 1 Personnel**, prix manquant Maître Marchand toujours non commercialisé. Les paiements restent **simulés** sur le site de démonstration.

## Limites de vérification
La compilation, les tests E2E backend et les réponses HTTP **ne prouvent pas l'intégralité de l'UX en navigation authentifiée sur chaque résolution mobile**. Les derniers ajustements seront corrigés à partir des captures et retours utilisateurs. La règle de réserve/libération Marchand à 120 % reste à spécifier complètement avant de modifier le moteur financier.
