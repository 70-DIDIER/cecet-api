# Sauvegarde, restauration et reprise d'activité

Objectifs de conception (à ratifier par le CA, cahier v4.0 §27) : **RPO ≤ 15 minutes**, **RTO ≤ 4 heures**.
Un backup n'est valide que s'il a été **vérifié** et **restauré pour de vrai** au moins une fois par trimestre.

## 1. Ce qu'il faut sauvegarder

| Élément | Où | Fréquence | Remarque |
|---|---|---|---|
| Base PostgreSQL (`cectapi`) | serveur de base | complète quotidienne + archivage WAL continu | contient tout : ledgers, audit, règlements, paramètres |
| Archives de snapshots et imports | `storage/app/private/snapshots`, `storage/app/private/imports` | quotidienne | copie de consultation, mais utile pour le réimport |
| Fichier `.env` (sans l'exposer) | coffre de secrets | à chaque changement | **jamais dans l'archive applicative** |
| `APP_KEY` | coffre de secrets, **copie séparée** | à chaque changement | sans elle, les données chiffrées (jobs d'OTP) et les sessions sont perdues |
| Clés FedaPay, secret de webhook | coffre de secrets | à chaque changement | régénérables chez le prestataire |

La copie **3-2-1** s'applique : 3 copies, 2 supports différents, 1 copie hors du serveur de production.

## 2. Sauvegarde de la base

### Complète quotidienne (logique, portable)

```bash
pg_dump --format=custom --compress=9 --no-owner \
  --host "$DB_HOST" --username "$DB_USERNAME" --dbname cectapi \
  --file "/sauvegardes/cectapi-$(date -u +%Y%m%dT%H%M%SZ).dump"
```

Le mot de passe est fourni par `PGPASSFILE` (jamais en argument).

### Point dans le temps (RPO ≤ 15 min)

Activer l'archivage continu des journaux WAL (`archive_mode = on`, `archive_command` vers un stockage séparé) et une sauvegarde de base régulière (`pg_basebackup`). Si l'hébergeur propose la restauration à un instant donné (PITR), l'utiliser et noter ici son périmètre réel.

### Vérification de chaque sauvegarde (obligatoire)

1. Le fichier existe, sa taille est cohérente avec celle de la veille (écart > 30 % : alerte).
2. `pg_restore --list fichier.dump > /dev/null` réussit.
3. Calculer et conserver son empreinte : `sha256sum fichier.dump`.
4. Copier hors site, comparer l'empreinte à l'arrivée.

Le job de sauvegarde « terminé » ne prouve rien : seule la restauration d'essai (§4) valide.

## 3. Rétention (à approuver par le CA)

Proposition : 14 sauvegardes quotidiennes, 8 hebdomadaires, 12 mensuelles. Les journaux WAL sont gardés 7 jours. Les snapshots applicatifs sont déjà limités par `reporting.snapshot_retention_count`.

## 4. Test de restauration (trimestriel, sur un environnement isolé)

Ne **jamais** restaurer sur la production pour un test.

```bash
createdb cectapi_restore
pg_restore --no-owner --dbname cectapi_restore fichier.dump
```

Puis, avec `DB_DATABASE=cectapi_restore` dans un `.env` d'essai :

```bash
php artisan migrate:status          # aucune migration en attente ni manquante
php artisan ledger:verify           # journaux de quota et marchands cohérents
php artisan audit:verify            # chaîne d'audit intacte
php artisan ops:check --integrity   # tout à OK
php artisan snapshots:generate --force
```

Contrôles métier : le nombre de membres, de packs actifs et le total collecté d'un rapport (`/admin/reports/summary`) correspondent aux chiffres relevés avant la sauvegarde.

**Compte-rendu à conserver** : date, sauvegarde utilisée (nom + empreinte), durée de restauration mesurée, résultat de chaque commande, anomalies, signataire. Une restauration non documentée ne compte pas pour AC-25.

## 5. Restauration réelle après sinistre

1. **Décider** (responsable d'astreinte + un second avis) : restauration complète ou à un instant donné. Noter l'heure du dernier état sain connu.
2. **Couper l'écriture** : mettre l'API en maintenance (`php artisan down --secret=...`) et arrêter les workers (`queue:work`, planificateur). Sans cela, des payouts pourraient partir pendant la restauration.
3. Restaurer la base sur un serveur sain (§4, vers la base définitive) et, si PITR, rejouer les WAL jusqu'à l'instant choisi.
4. Restaurer `.env` et `APP_KEY` depuis le coffre.
5. `php artisan migrate --force`, puis les contrôles du §4 (`ledger:verify`, `audit:verify`, `ops:check --integrity`).
6. **Rapprochement avec le prestataire avant de rouvrir** : les opérations passées entre le dernier état sain et le sinistre ne sont plus connues de la base.
   - Lancer `php artisan payouts:refresh` : il interroge le prestataire par référence marchande et remet les statuts à jour.
   - Comparer la liste des paiements et des virements du tableau de bord FedaPay avec `payment_attempts` et `payout_items` sur la période perdue. Tout paiement reçu et inconnu de la base est à traiter à la main (le webhook rejoué par FedaPay est idempotent).
   - **Ne relancer aucun virement sans avoir vérifié qu'il n'est pas déjà parti** (risque de double paiement).
7. Rouvrir : `php artisan up`, redémarrer workers et planificateur, puis surveiller `ops:check` pendant 1 heure.
8. Informer les membres si des opérations déclarées ont été perdues (elles devront être redéclarées).
9. Rédiger le retour d'expérience (cause, durée réelle, RPO réel, actions correctives).

## 6. Perte de l'`APP_KEY`

Les mots de passe, les sessions ouvertes et les jobs d'OTP chiffrés en file ne sont plus lisibles : tous les utilisateurs doivent se reconnecter et les codes en file sont perdus (ils expirent de toute façon en quelques minutes). Générer une nouvelle clé (`php artisan key:generate`) et prévenir les utilisateurs. Les données financières, qui ne sont pas chiffrées par cette clé, restent intactes.
