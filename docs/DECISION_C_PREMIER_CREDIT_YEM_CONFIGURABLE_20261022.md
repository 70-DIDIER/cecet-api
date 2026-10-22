# Décision CECT — Premier crédit YEM configurable par formule

**Validation métier :** 22 octobre 2026  
**Statut :** décision validée ; développement isolé dans `design/pf-pm-premium-20261022`  
**Périmètre :** PF Vendeur / Consommateur et PM Marchand ; Personnel exclu des crédits périodiques.

## Décision opposable

Le **Superadmin** fixe, pour chaque **version de formule**, le nombre entier de périodes d'attente entre l'activation du pack (après **paiement intégral confirmé**) et le **premier crédit de quota YEM** : paramètre `rules.first_credit_delay_periods` entre **0 et 12**.

- `0` : première période créditable immédiatement à l'activation.
- `1` : première période débute après un intervalle contractuel entier.
- `N` : première période débute après `N × period.every` unités de `period.unit` depuis l'activation.
- Les crédits suivants arrivent à chaque intervalle contractuel. Le nombre de distributions `period.cycles` **n'est pas réduit** par le délai.
- **Valeurs par défaut** : Vendeur PF = **1 période** ; Consommateur PF = **0** ; Marchand PM = **0**. Ces valeurs ne sont pas une règle universelle : le Superadmin peut les modifier **par version** dans la plage validée.
- Personnel PF = sans calendrier de crédits périodiques : recharge à la demande, et chaque recharge doit être confirmée avant délivrance de YEM.
- La date d'activation (règlement validé) reste distincte de la date du premier crédit YEM. **Aucun crédit en avance**.
- Ce paramètre est matérialisé dans `PackVersion.rules` lors de la création d'une **nouvelle version** et publié via l'API. Si une version historique ne le contient pas, le backend utilise la valeur par défaut **correspondant à sa famille** pour préserver son comportement antérieur.
- Une fois une version publiée, elle est **immuable**. Un changement ultérieur nécessite une nouvelle version publiée ; il **ne décale pas les périodes déjà créées** pour les abonnements antérieurs.

## Algorithme contractuel

Soient :
- `T0` = instant de confirmation du paiement intégral et d'activation ;
- `D` = `first_credit_delay_periods` ;
- `E` = `period.every` ;
- `U` = `period.unit` (jour, semaine, mois) ;
- `C` = `period.cycles`.

Pour `i = 1 … C` :

```text
debut(i) = T0 + (D + i − 1) × E unités U
fin(i)   = T0 + (D + i)     × E unités U
```

Les mois sont additionnés à l'ancre `T0` par des règles calendaires sans glissement. `expires_at = fin(C)` (en cas de politique de fin de terme de type expire, ou de maintien sous conditions si keep). Si `D=1`, `C=12`, `E=1 mois`, le premier crédit est à `T0+1 mois` et le dernier cycle se termine à `T0+13 mois` ; annoncer simplement « valable 12 mois à partir du paiement » serait faux.

Le `PeriodRoller` ne crédite qu'une période dont `starts_at <= maintenant` et `credited_at = NULL`, sous verrouillage transactionnel et écritures de grand livre YEM idempotentes.

## Quotas, droits et cas limites

- `Quota contractuel théorique = quota_per_period × C + cashback YEM éventuel`, avec arrondis/splits réalisés par le moteur métier existant (ne pas arrondir via JavaScript).
- Une souscription activée peut avoir **0 YEM disponibles** avant sa première période. Cela n'est pas une erreur d'affichage.
- Le calendrier de paiement du pack conserve **un seul encaissement** ; les périodes ci-dessus sont des **crédits YEM**, et non des mensualités de paiement.
- Les crédits YEM Vendeur définissent une capacité **à vendre à la coopérative** et ne se confondent pas avec le solde dépensable Consommateur/Personnel.
- Pour les quotas non cumulables, le reliquat du cycle précédent expire suivant la version. Les réservations déjà engagées et les politiques de fin de terme restent traitées par le moteur existant.
- Retard d'exécution : reprise ordonnée des échéances, sans double crédit ni droit anticipé.
- L'admin doit montrer le délai réel utilisé comme valeur initiale : **Vendeur = 1**, Consommateur/Marchand = **0**. Les options PF/PM sont fixes en conformité avec la décision PM = Marchand uniquement.

## Conséquences financières et comptables

1. **À T0 :** un paiement FCFA du prix total du pack, frais prestataire isolés. Les encaissements de démonstration restent des **simulations**, non des mouvements bancaires.
2. **De T0 au dernier crédit :** le contrat comporte des **droits YEM futurs non encore distribués** : l'outil doit suivre les quotas promis par période, déjà attribués, en attente et expirés.
3. **À chaque échéance :** création d'une écriture de **quota YEM**, pas d'un nouvel encaissement FCFA. Le ledger, la période et la référence unique d'idempotence doivent se recouper.
4. **Rapprochement :** prix encaissé ≠ quantité YEM immédiatement créditée ≠ quota disponible ≠ vente/revente financière chez la CECT.
5. **Comptabilisation du produit :** différer la reconnaissance de tout ou partie du chiffre d'affaires ou constater un engagement pour les droits YEM restant à fournir peut être nécessaire selon le référentiel comptable applicable et l'analyse du contrat. Le traitement exact et la valeur des obligations restent à valider par la Comptabilité/conseil juridique ; **ne pas attribuer automatiquement tout le paiement au résultat à T0**.
6. **Reporting à produire :** `pack_cash_received_xof`, `periods_planned`, `periods_due`, `periods_credited`, `yem_promised`, `yem_credited`, `yem_available`, `yem_reserved`, `yem_consumed`, séparés entre personnes/familles/périodes. Ces chiffres ne doivent pas être mélangés.

## Interface PF/PM

- **Superadmin** : sélecteur entier `0 … 12` sur l'éditeur de version ; aperçu « premier crédit après N périodes », affichage de l'attente **en plus** du nombre de distributions, historique des versions.
- **Membre** : détail de formule « 1er crédit », nombre de distributions et durée réelle depuis l'activation ; ensuite dates exactes du calendrier retournées par `/me/packs/{id}`.
- **Avant paiement** : pas de dates absolues de crédits garanties, car `T0` n'existe pas encore. Ne pas confondre la date d'achat et la première échéance YEM.

## Déploiement

Ce contrat reste cantonné aux branches `design/pf-pm-premium-20261022` des dépôts `70-DIDIER/cecet-api`, `CECT-frontend`, `cect-admin` ; pas de migration SQL nécessaire. Base live, fichiers publiés et `custum` inchangés. Sauvegarde vérifiée sous `/home/ubuntu/cect-private-backups/redesign-stable-20261022/`. La recette autonome de navigation et d'affichage dans un navigateur de validation reste distincte des tests automatisés.

## Résultats des contrôles automatisés (22 octobre 2026)

Les quatre nouveaux scénarios de premier crédit valident **77 assertions** : valeurs par défaut PF/PM, override Superadmin par nouvelle version, conservation des conditions antérieures, rejet des délais invalides, exclusion Personnel, calendrier décalé en semaines, absence de crédit avant échéance et idempotence.

**Recette combinée finale : 88 tests réussis, 711 assertions** sur `cectapi_test` isolée, incluant souscriptions, catalogue, limites PF/PM, période, règlement intégral, relevé de paiements privé et validation des contrôles KYC. Compilations TypeScript/Vite **frontend membre et administration réussies**. La validation navigateur reste à faire séparément et aucune publication n'est autorisée automatiquement par ces succès techniques.
