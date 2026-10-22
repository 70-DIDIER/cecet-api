# Correction urgente CECT — fiches Vendeur : droits de cession ≠ crédits YEM

**Date :** 22 octobre 2026  
**Branches modifiées :** `70-DIDIER/CECT-frontend` → `design/pf-pm-premium-20261022`.  
**Environnement publié :** staging/démonstration uniquement, paiements simulés.

## Anomalie constatée

Dans « Mes règlements et échéanciers », la présentation confondait :
- **Consommateur** : YEM réellement alloués à la consommation par période ;
- **Vendeur** : **droit contractuel de vendre ses propres YEM** à la coopérative dans la limite d'un quota ;
- **Marchand** : droit contractuel de rééchange, distinct des YEM reçus de ses clients ;
- **Personnel** : achats libres de YEM sans calendrier.

Ainsi, afficher une ligne « Crédit YEM / À créditer » pour un Vendeur pouvait laisser croire que la CECT allait **lui verser** des YEM automatiquement. C'est faux. Le paiement initial porte sur la souscription, tandis que la cession par le Vendeur est une opération distincte et traçable.

## Corrections déployées

### « Mes règlements »

Les montants payés à la coopérative restent sur la fiche **Paiements**. Pour un pack Vendeur, l'action de ligne affiche maintenant **« Voir mes droits de vente »**, et non « Afficher le calendrier ». Le texte rappelle que les paiements FCFA issus de ses ventes se retrouvent dans « Vendre mes YEM », et non dans les règlements de souscription.

### « Droits et échéanciers »

Un filtre permet de sélectionner la famille **Vendeur**. Chaque pack reste identifié par sa date d'activation et sa référence de contrat, évitant de confondre plusieurs souscriptions au même modèle.

Le détail Vendeur affiche :
- **Autorisé sur le contrat** = somme exacte des quotas de toutes les périodes du contrat ;
- **Disponible à vendre** = quota effectivement ouvert, non engagé, depuis le compte de quota du pack ;
- **Réservé** = quantité de droits immobilisés dans les ventes en attente ;
- **Déjà cédé** = droits définitivement consommés par les ventes validées ;
- **Progression des ouvertures** = somme des périodes marquées effectivement créditées/ouvertes dans `pack_periods`, comparée au total contractuel ;
- **Échéancier** = date prévue, quota de cession, « Droit de vente ouvert », « Droit non ouvert » ou « En cours de traitement » si la date de départ est dépassée mais le crédit de droit n'a pas encore été traité.

Les statuts proviennent de la donnée `credited` effective, jamais d'une simple supposition fondée sur la date. Les chiffres disponibles/réservés/consommés proviennent du **compte de quota immuable et contrôlé par le ledger**, pas d'une reconstruction frontend potentiellement erronée. Les quotas non cumulables ou expirés peuvent rendre le solde disponible inférieur à la somme des droits antérieurement ouverts ; c'est attendu, pas une incohérence.

Pour la personne morale, le même écran parle de **plafonds de rééchange**, non de YEM reçus automatiquement. Pour Consommateur, il conserve le vocabulaire des **crédits YEM débloqués**. Pour Personnel, il montre exclusivement les **rechargements**.

### « Mes packs » et mobile

La carte Vendeur désigne explicitement son **droit contractuel**, la **quantité cessible** et les quantités **déjà cédées**, en les distinguant d'un solde de YEM reçu. Le panneau d'échéancier utilise des **cartes verticales en petits écrans** et le tableau sur écran large. Les actions sont contextualisées.

## Contrôles

- Build TypeScript + Vite pour frontend : **réussi**.
- Régression backend `QuotaLedgerTest.php` sur `cectapi_test` : **31 tests réussis, 162 assertions** (réservations, consommation, idempotence, garanties non négatives, séparations des soldes).
- Ancienne suite `ExchangeFlowTest.php` : **22 tests passés, 1 échec constaté** sur un cas utilisant toujours le rôle **Validateur** supprimé de l'organisation CECT. Cette suite n'est donc **pas déclarée entièrement verte** et doit être réalignée sur le rôle Comptabilité, sans réintroduire des accès obsolètes.
- Vérifications d'URLs site membre `/espace`, `/espace/packs`, `/espace/calendrier`, `/espace/vendre`, back-office et API `health` : toutes **HTTP 200**.
- **Limitation :** aucune session membre réellement pilotée dans Chrome mobile lors de cette recette ; la compilation et les statuts HTTP ne remplacent pas une vérification visuelle fonctionnelle authentifiée.

## Publication et retour arrière

Release publiée par remplacement atomique du symlink membre :
`/home/ubuntu/cect-releases/frontend-seller-entitlement-calendar-20261022`.

Sauvegarde de l'ancien lien et empreinte HTML :
`/home/ubuntu/cect-private-backups/before-seller-calendar-20261022/`.

La version précédente reste disponible :
`/home/ubuntu/cect-releases/frontend-personnel-on-demand-ultimate-20261022`.

**Aucune modification du schéma ou des données SQL. Aucun utilisateur, paiement ou pack supprimé. Aucune fusion vers main ou custum.**
