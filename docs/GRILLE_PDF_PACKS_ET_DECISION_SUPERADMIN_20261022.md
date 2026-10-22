# CECT — Grille PDF initiale, contrôle Superadmin et recette de déploiement

Date : 22 octobre 2026. Source utilisateur : **Classeur1.pdf**, 2 pages (pack Ascension, pack Comptoir, packs Consommateur).  
**Statut : import validé dans la base de test et versionné sur la branche design ; ne pas confondre avec une importation déjà effectuée sur la base active.**

## Décision de gouvernance — A : Superadmin uniquement

Le Superadmin seul crée, édite, duplique, paramètre, publie, suspend ou archive une formule. L'Administrateur conserve l'accès en lecture aux packs et aux abonnements et peut assurer ses autres opérations métier.

- Laravel : toute mutation sur `/api/v1/admin/packs/plans*` est protégée par `can:packs.manage`, `can:settings.update_critical` (permission réservée Superadmin) et `step-up`. Le contrôle de protection existe **indépendamment de l'interface**.
- React admin : options de modification disponibles uniquement si rôle `superadmin` et autorisations `packs.manage`, `settings.update_critical` ; tous les autres administrateurs consultent.
- Les créations se font en brouillon, les nouvelles conditions sous **versions immuables** ; publication explicite et traçable. Aucune version existante n'est réécrite.
- Contrôle : 41 tests du module catalogue réussis, 209 assertions, dont refus des méthodes POST/PUT/DELETE pour l'Administrateur.

## Tableau reçu — pack Ascension (PF Vendeur)

| Nom | Prix FCFA | YEM par quinzaine | YEM totaux (24 quinzaines) |
| --- | ---: | ---: | ---: |
| Éclaireur | 100000 | 40 | 960 |
| Pionnier | 250000 | 100 | 2400 |
| Bâtisseur | 350000 | 140 | 3360 |
| Stratège | 600000 | 240 | 5760 |
| Conquérant | 800000 | 320 | 7680 |
| Ambassadeur | 1500000 | 600 | 14400 |
| Commandeur | 3000000 | 1200 | 28800 |
| Visionnaire | 7000000 | 2800 | 67200 |
| Magnat | 15000000 | 6000 | 144000 |
| Titan | 35000000 | 14000 | 336000 |
| Grand Maître | 50000000 | 20000 | 480000 |
| Légende | 75000000 | 30000 | 720000 |

Une capacité vendeur est une **limite de cession à la coopérative**, pas une quantité dépensable comme Consommateur. Premier cycle par défaut après 1 quinzaine, configurable 0–12 périodes.

## Tableau reçu — pack Comptoir (PM Marchand)

| Nom | Prix FCFA | Capacité YEM total |
| --- | ---: | ---: |
| Apprenti Marchand | 240000 | 6000 |
| Compagnon du Négoce | 600000 | 15000 |
| Artisan du Négoce | 1200000 | 30000 |
| Négociant | 2600000 | 65000 |
| Grand Négociant | 5600000 | 140000 |
| Maître Marchand | **ILLISIBLE : ###** | 375000 |
| Doyen du Marché | 30000000 | 750000 |
| Notable du Négoce | 60000000 | 1500000 |
| Grand Seigneur du Commerce | 80000000 | 2000000 |
| Souverain du Marché | 120000000 | 3000000 |

La quantité par période est `capacité_total / 24` à six décimales. Le document affiche des valeurs **arrondies à l'unité** (par exemple Négociant 2708 et Grand Négociant 5833) et une réserve/libération à **120 %** avec frais de gestion **3 %**, ainsi qu'un droit d'accès à la capacité de **40 FCFA/YEM**. La politique de réserve/libération et la commission sont **des processus de règlement marchand distincts** : l'import conserve cette information dans la description du pack, **sans feindre que le moteur de quota applique automatiquement ces montants**.

**Maître Marchand** est conservé en **brouillon non commercialisable**, sans version, jusqu'à confirmation du prix. Les neuf autres formules sont publiables en démonstration.

## Tableau reçu — Consommateur PF

| Nom | Prix d'accès FCFA | Ancien prix après remise 8 % |
| --- | ---: | ---: |
| Client Opale | 300000 | 276000 |
| Client Turquoise | 500000 | 460000 |
| Client Grenat | 800000 | 736000 |
| Client Topaze | 1000000 | 920000 |
| Client Émeraude | 1500000 | 1380000 |
| Client Saphir | 2000000 | 1840000 |
| Client Rubis | 3000000 | 2760000 |
| Client Diamant | 6000000 | 5520000 |

**Conflit document vs décision métier antérieure** : le PDF indique une **remise monétaire de 8 %** en FCFA, alors que le responsable avait validé le remplacement par un **cashback en YEM**, sans réduction du paiement. L'import applique la décision validée : **prix intégral de la colonne « Droits d'accès »**, cashback **8 % de YEM supplémentaires** (`rate_bps=800`) répartis sur 24 quinzaines, avec le taux de vente **650 FCFA/YEM** vérifié dans les paramètres API le 22/10/2026.

Les quantités entières du tableau (ex. 462 et 19 YEM/quinzaine pour 300000 FCFA) sont **arrondies** ; le backend calcule ses droits à six décimales (`prix / 650 / 24`) pour éviter de cumuler les arrondis du tableau. Les montants anciens « après remise » sont tracés ici pour comparaison ; ils **ne constituent pas les prix à facturer**.

## Périodes « quinzaine »

La source décrit 24 quinzaines pour 12 mois. Une nouvelle valeur `half_month` est ajoutée à `PeriodUnit`, avec une migration additive de contrainte SQL. Contrairement au faux raccourci « 15 jours × 24 = 360 jours », **deux périodes ancrées égalent un mois calendaire**. Pour un contrat actif le 31 janvier, 1 quinzaine mène au 15 février, 2 au 28 février, 24 au 31 janvier suivant. La période d'attente avant premier crédit s'ajoute à ces 24 périodes sans en retirer aucune. Versionnée et paramétrable par Superadmin dans l'administration.

## Import et intégrité

Seeder **opt-in et idempotent** : `Database\\Seeders\\ReceivedPdfCatalogSeeder` (non inclus dans le seeder général de démarrage). Sur base de test `cectapi_test` uniquement, migration effectuée, import effectué puis tests :

- 12 Vendeur publiés ; 8 Consommateur publiés ; 9 Marchand publiés.
- 1 Marchand brouillon (prix manquant), sans aucune version achetable.
- **29 versions publiées + 1 brouillon**.
- Deux tests spécifiques d'import réussis, 27 assertions, import répété sans doublons et calcul d'ancres calendaires.

**Avertissement :** la vérification de compilation et les tests ne constituent pas une validation exhaustive du traitement « réserve à 120 % » et de la liquidation des marchands. Ces flux nécessitent recette ciblée séparée.

## Protection état stable et publication

Sauvegarde intégrale de la base PostgreSQL **avant import** effectuée hors Git, avec SHA-256 :
`/home/ubuntu/cect-private-backups/before-pdf-packs-deploy-20261022/cectapi_live_before_pdf_import.dump`. Ne jamais ajouter la sauvegarde, des pièces KYC ou des secrets à GitHub.

Le site conserve son code et sa base actuels **jusqu'à exécution effective du déploiement et du seed dans `cectapi_live`**. Après une mise en ligne, vérifier :
- `GET /api/v1/packs` catalogue PF et PM filtré (29 versions publiées, une brouillon invisible)
- droits Admin en lecture / Superadmin mutation, y compris API directe ;
- `/espace/packs` et `/espace/calendrier` ;
- activation simulée de pack et calendrier de 24 quinzaines, zéro crédit anticipé ;
- seuils et soldes par pack, dates de rechargement Personnel ;
- absence d'appel à un prestataire réel, logs et possibilité de rollback.

Les changements de migration et import sont potentiellement visibles par tous les clients : rollback exige un traitement conservateur des souscriptions et des historiques créés depuis la publication. Ne jamais effacer silencieusement les données acquises après mise en ligne.
