# CECT — Reproduire la plateforme en local (branches `custum`)

**Version du guide : 21 octobre 2026 — à lire avant de démarrer.** Ce document sert à un développeur autorisé qui veut travailler **sur sa machine**, comparer les fonctionnalités avec la préproduction CECT, puis proposer des améliorations. Il ne doit **jamais** accéder à la base, aux clés ou aux comptes réels de la coopérative.

## 0. Périmètre et limites de « exactement la même chose »

Trois applications, toutes issues **uniquement de 70-DIDIER** :

| Composant | Dépôt | Branche obligatoire | Local |
|---|---|---|---|
| Backend Laravel et API | [cecet-api](https://github.com/70-DIDIER/cecet-api/tree/custum) | `custum` (orthographe exacte) | `http://localhost:8000` |
| Site public et espace membre | [CECT-frontend](https://github.com/70-DIDIER/CECT-frontend/tree/custum) | `custum` | `http://localhost:5173` |
| Administration React | [cect-admin](https://github.com/70-DIDIER/cect-admin/tree/custum) | `custum` | `http://localhost:5174` |

**Ne pas cloner `main`, `joseph`, les dépôts Modson ni d'anciennes releases** pour reproduire les fonctionnalités actuelles. `custum` évolue ; consigner `git rev-parse HEAD` dans chacun des trois dépôts pour obtenir un état reproductible. Pour proposer des évolutions, partir du dernier `custum`, puis créer une branche de fonctionnalité, sans pousser directement sur `custum`.

**Vérification directe du serveur de référence au 21/10/2026** (staging, pas une garantie permanente) :

- API : checkout `/var/www/cect-api`, dépôt `70-DIDIER/cecet-api`, branche `custum`, commit `b1143def1e87dd2bf67c83fcefcb40d129bbb3fc`.
- Build public servi : release `frontend-qa-v59-20261018`. Build admin servi : release `admin-superadmin-merchant-pricing-v511-20261020`. **Le SHA exact ayant généré chacun des deux bundles n'a pas été attesté** : ne pas prétendre à une copie bit à bit sur la seule base de `git clone`.
- Système constaté : Linux (AWS), PHP CLI 8.5.4, Node.js 22.22.1, npm 9.2.0, Composer 2.9.5, outillage PostgreSQL 18.6, Nginx 1.28.3, PHP-FPM 8.5, HTTPS.
- Backend : `APP_ENV=staging`, `APP_DEBUG=false`, PostgreSQL, `QUEUE_CONNECTION=database`, SMTP, FedaPay **sandbox configuré** dans l'environnement mais le provider métier de simulation peut être `fake`; `SUPERADMIN_MTLS_ENABLED=true`. **Aucun débit réel ne doit être déclenché en local.**
- Vérification HTTP : `/api/v1/health` = 200 ; page publique = 200 ; `/connexion` admin = 200 ; `/superadmin/connexion` sans certificat = 403.
- Configuration publique API : adhésion PF **30 000 XOF**, PM **150 000 XOF** ; cours achat CECT **510 XOF/YEM**, vente **650 XOF/YEM**, cession **610 XOF/YEM** ; `test_mode=true`, **47 moyens de paiement référencés**, **7 pays de décaissement**.
- Catalogue en base lors de l'inspection : **10 plans au total** (1 Vendeur publié, `V15` ; 1 Consommateur en brouillon ; 8 archivés — 4 Vendeur, 2 Consommateur, 1 Personnel, 1 Marchand). L'API publique ne montre que `V15`. Les anciennes données de démonstration présentes dans certains guides historiques ne sont **pas** une photographie du serveur actuel.

**Trois niveaux de parité** : (1) *code et interfaces* = branches `custum` ; (2) *configuration métier* = valeurs versionnées en base, packs et statuts, non toutes présentes dans Git ; (3) *sécurité/exploitation* = TLS/mTLS, certificat navigateur, CRL, files, worker, SMTP, domaines Nginx. Une base locale fraîche ne reproduit pas à elle seule les points 2 et 3. La copie intégrale d'une base réelle, des OTP, certificats, adresses personnelles, clés privées et secrets est **exclue**. Un export métier **assaini et approuvé** serait nécessaire pour égaler entièrement le catalogue privé et les historiques, sans données personnelles.

## 1. Prérequis (machine de développement)

- Git et accès en lecture aux trois dépôts ; disque disponible et ports `8000`, `5173`, `5174`.
- PHP **>= 8.3** compatible Laravel 13 (8.5.4 constaté sur le serveur), extensions **pdo_pgsql, bcmath, intl, mbstring, sodium, openssl, zip** ; Composer 2 ; PostgreSQL **18.x** ; Node.js **22** et npm.
- Pour un déploiement local HTTPS/mTLS avancé : Nginx, PHP-FPM, OpenSSL et **une CA locale neuve** ; pas requis pour le profil standard de développement.
- Ne pas exécuter la copie en tant que root ni rendre une base locale accessible sur Internet.

Contrôles de départ :

```bash
git --version
php -v
php -m | grep -E 'pdo_pgsql|bcmath|intl|mbstring|sodium|openssl|zip'
composer --version
psql --version
node --version
npm --version
```

## 2. Cloner le bon code

```bash
mkdir -p ~/projets/cect-local && cd ~/projets/cect-local
git clone --branch custum https://github.com/70-DIDIER/cecet-api.git
git clone --branch custum https://github.com/70-DIDIER/CECT-frontend.git
git clone --branch custum https://github.com/70-DIDIER/cect-admin.git

for d in cecet-api CECT-frontend cect-admin; do
  echo "=== $d ==="
  git -C "$d" remote -v
  git -C "$d" branch --show-current
  git -C "$d" rev-parse HEAD
done
```

**Garder les trois SHAs ensemble** dans le compte-rendu d'une proposition. Ne pas utiliser `git pull` au hasard sur une modification locale ; créer une branche et synchroniser `custum` proprement.

## 3. PostgreSQL isolé (Linux)

Créer un rôle non privilégié et deux bases vides, l'une pour l'application, l'autre pour les **tests**. Exemple si PostgreSQL tourne localement :

```bash
sudo -u postgres createuser --login --pwprompt cect_local
sudo -u postgres createdb --owner=cect_local cectapi
sudo -u postgres createdb --owner=cect_local cectapi_test
```

`--pwprompt` demande un mot de passe sans l'inscrire dans une commande historique. Si le rôle ou la base existe déjà, **ne pas la supprimer** : vérifier son propriétaire et son contenu avant de continuer. Dans `.env`, utiliser `DB_HOST=127.0.0.1` et le mot de passe de `cect_local` ; ne jamais viser le DNS ou l'IP PostgreSQL de staging. Le fichier `phpunit.xml` utilise `cectapi_test` ; vérifier sa connexion avant les tests pour éviter toute confusion.

## 4. Backend : configurer, installer, initialiser

```bash
cd ~/projets/cect-local/cecet-api
composer install
cp .env.example .env
```

Modifier **uniquement le `.env` local** (ne pas le committer) :

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=http://localhost:8000
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cectapi
DB_USERNAME=cect_local
DB_PASSWORD=CHOISIR_UN_SECRET_LOCAL_NON_COMMITTE
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:5174,127.0.0.1:5173,127.0.0.1:5174
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:5174
QUEUE_CONNECTION=database
MAIL_MAILER=log
PAYMENT_RETURN_URL=http://localhost:5173/espace/adhesion
CECT_MEMBER_PORTAL_URL=http://localhost:5173
SUPERADMIN_MTLS_ENABLED=false
STAFF_GUARDIAN_LOGIN_ENABLED=false
FEDAPAY_ENVIRONMENT=sandbox
FEDAPAY_SECRET_KEY=
FEDAPAY_WEBHOOK_SECRET=
DEMO_CLIENT_EMAIL=client-local@example.invalid
DEMO_MERCHANT_EMAIL=marchand-local@example.invalid
DEMO_STAFF_EMAIL=staff-local@example.invalid
DEMO_PASSWORD=CHOISIR_UN_MOT_DE_PASSE_DE_DEMO_UNIQUE
```

**Pourquoi `APP_ENV=staging` même sur son ordinateur ?** La version actuelle de `PaymentProviderManager` n'autorise le provider simulé `fake` qu'en `testing` ou `staging`. Ce profil donne un **laboratoire local de staging**, sans paiements réels. Ne pas exposer ce serveur et ne pas copier l'environnement réel de staging. `SUPERADMIN_MTLS_ENABLED=false` est **uniquement pour démarrer la démo locale non sécurisée par certificat**, et **n'équivaut pas** au Superadmin déployé. Le profil sécurisé est décrit §9.

Initialiser avec la configuration **locale** :

```bash
php artisan key:generate
php artisan config:clear
php artisan migrate
php artisan settings:sync
php artisan db:seed --class=AccessSeeder
php artisan db:seed --class=LegacyCatalogSeeder
```

`settings:sync` remplit les valeurs par défaut (ce n'est **pas** un import des valeurs de staging). `LegacyCatalogSeeder` crée la grille historique en **brouillon**, et **ne recrée ni les dix plans/versionnements/archivages actuellement en base ni `V15`**.

Pour obtenir des membres, marchands, packs publiés et mouvements fictifs en local, dans **une base de test isolée seulement** :

```bash
php artisan db:seed --class=DemoClientSeeder
```

Ce seeder ajoute une activité *fictive* (et peut créer un **Superadmin de démonstration à mot de passe**, qui **n'est pas** le compte mTLS du serveur). Il publie ses propres packs de démonstration : il ne reproduit pas le catalogue publié de staging. **Ne l'exécuter ni sur le serveur ni sur une copie de données réelles**. Dans un laboratoire nécessitant une seule clé Superadmin avec mTLS, préférer le provisionnement sécurisé du §9 plutôt que ce seeder.

## 5. Rétablir la configuration économique observée (local uniquement)

Le code possède des valeurs par défaut différentes des valeurs réelles : **PF 25 000 / PM 200 000** dans `SettingsCatalog.php`, contre **PF 30 000 / PM 150 000** relevés sur l'API publique. Après initialisation **et après tout seeder de démo**, vérifier les paramètres locaux et saisir sous contrôle de l'administrateur local :

| Clé métier | Valeur constatée en staging, 21/10/2026 |
|---|---:|
| `membership.fee.natural_person` | 30000 |
| `membership.fee.legal_person` | 150000 |
| `rates.purchase_xof_per_yem` | 510 |
| `rates.sale_xof_per_yem` | 650 |
| `rates.transfer_xof_per_yem` | 610 |

Le service `App\Domain\Settings\SettingsService::set(key, value, reason)` écrit une **nouvelle version** auditable. Ne pas modifier directement les tables ni antidater les versions. Les réglages complets (cashback, plans, plafonds, 47 moyens de paiement, 7 pays, statuts de publication) doivent être comparés à l'API publique et, pour les données privées, à un export assaini validé par CECT. **Ne pas inventer des plans manquants pour prétendre reproduire les données serveur.**

Contrôler la partie *publiquement accessible* sans compte :

```bash
curl -fsS https://api.13-49-203-235.sslip.io/api/v1/config > /tmp/cect-reference-public-config.json
curl -fsS https://api.13-49-203-235.sslip.io/api/v1/packs > /tmp/cect-reference-public-packs.json
curl -fsS http://localhost:8000/api/v1/config > /tmp/cect-local-public-config.json
curl -fsS http://localhost:8000/api/v1/packs > /tmp/cect-local-public-packs.json
```

Ces URL concernent les **données publiques**, pas les secrets. Les fichiers stockés dans `/tmp` sont seulement des instantanés de comparaison ; les catalogues peuvent évoluer entre deux appels.

## 6. Démarrer API + worker + scheduler

Ouvrir **trois terminaux séparés**, tous dans `cecet-api` :

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

```bash
php artisan queue:work
```

```bash
php artisan schedule:work
```

Le serveur Laravel traite les requêtes ; le worker délivre e-mails, webhooks et tâches en file ; le scheduler déclenche les opérations périodiques de quotas, expirations et contrôles. Pour lire l'OTP local, ouvrir `storage/logs/laravel.log` **sur la machine locale** (`MAIL_MAILER=log`). Ne jamais publier les codes ni pousser les logs dans Git. URL de contrôle : `http://localhost:8000/api/v1/health`.

## 7. Lancer les deux interfaces (deux terminaux de plus)

**Frontend public/membre :**

```bash
cd ~/projets/cect-local/CECT-frontend
cp .env.example .env
```

Dans `.env` : `VITE_API_URL=http://localhost:8000` et `VITE_SIMULATE_PAYMENT=false`. Ce dernier indicateur ne remplace pas le choix du provider côté backend. Si reCAPTCHA ou WhatsApp ne sont pas configurés, tester les écrans qui ne dépendent pas de ces services et signaler les blocages ; ne jamais réutiliser une clé privée de staging.

```bash
npm ci
npm run dev -- --host 127.0.0.1 --port 5173
```

**Admin :**

```bash
cd ~/projets/cect-local/cect-admin
cp .env.example .env
```

Dans le fichier local : `VITE_API_URL=http://localhost:8000`, `VITE_SUPERADMIN_PATH_MODE=false`, `VITE_STAFF_GUARDIAN_ENABLED=false` tant que le proxy mTLS et le flux Guardian ne sont pas configurés. Ne pas « simuler » le certificat via de faux en-têtes.

```bash
npm install
npm run dev
```

Note reproductibilité : le frontend membre possède `package-lock.json` et utilise `npm ci` ; au moment de la rédaction, l'admin ne possède **pas** de `package-lock.json` suivi dans Git. `npm install` résout des versions autorisées mais **n'assure pas** une reproduction bit à bit. Conserver localement le lock généré, et envisager une PR distincte pour le versionner après validation de l'équipe.

Adresses :
- `http://localhost:5173/` : site ; `/inscription` : adhésion ; `/espace` : espace membre authentifié.
- `http://localhost:5174/connexion` : accès administratif **non-mTLS de la démo locale** ; `/admin/packs`, `/admin/pays`, `/admin/configuration` une fois autorisé.
- Le serveur staging utilise des **domaines HTTPS distincts**, pas ces ports publics de développement.

## 8. Recette : prouver la parité fonctionnelle

Vérifier et consigner : API health + CORS/CSRF/session ; inscription PF et PM ; OTP e-mail ; restriction du dossier en attente de frais ; paiement simulé **sans argent réel** ; activation et profils ; catalogue/versions de packs ; droits Vendeur/Consommateur/Marchand ; quota crédité, disponible, réservé ; cashback ; transfert marchand QR ; vente et rééchange YEM ; validation par Comptabilité ; remboursements/versements fictifs ; pays et méthodes de paiement ; rôles, journaux, export ; contrôles mTLS **si** configurés.

Commandes de contrôle :

```bash
# Dans cecet-api ; base de test DISTINCTE de cectapi
mkdir -p /tmp/cect-qa-blade-views
php artisan test --compact --testsuite=Feature,Unit
php artisan test --compact --testsuite=Concurrency
php artisan ledger:verify
php artisan audit:verify
php artisan cect:demo-check
```

```bash
# Dans CECT-frontend puis dans cect-admin
npm run lint
npm run build
```

`cect:demo-check` est **en lecture seule** ; son résultat peut être « non prêt » dans le profil de démonstration sans mTLS ou sans les quatre familles publiées. Ne pas désactiver les contrôles du serveur pour « faire passer » un test local. Les Actions GitHub des branches `custum` avaient des échecs lors de la vérification : les builds/tests doivent être **exécutés et attestés localement**, pas présumés réussis.

## 9. Obtenir aussi la sécurité Superadmin du serveur (profil avancé)

La reproduction **fidèle du chemin Superadmin** nécessite impérativement un Nginx HTTPS local, un certificat de serveur, **une autorité de certification de test neuve**, un certificat navigateur client, une CRL, des variables FastCGI injectées par Nginx, les migrations de sécurité, l'identifiant Superadmin épinglé et le fichier d'accès `.cectkey`.

Consulter **obligatoirement** :
- [Spécification mTLS](https://github.com/70-DIDIER/cecet-api/blob/custum/docs/SECURITE_SUPERADMIN_MTLS.md).
- [Exemple Nginx mTLS](https://github.com/70-DIDIER/cecet-api/blob/custum/deploy/nginx/cect-superadmin-mtls.conf.example).
- [Mode chemin /superadmin (démo)](https://github.com/70-DIDIER/cecet-api/blob/custum/deploy/nginx/cect-superadmin-path-mtls.snippet.conf).
- [Création de PKI locale](https://github.com/70-DIDIER/cecet-api/blob/custum/scripts/create-superadmin-client-pki.sh).

**Ne jamais** copier le `.p12`, la `.cectkey`, les clés privées, les trois adresses de vérification, les empreintes d'un utilisateur réel, la CA ou les secrets du serveur. Utiliser un Superadmin **neuf** et des adresses de réception de laboratoire que le développeur contrôle. Activer `SUPERADMIN_MTLS_ENABLED=true` **uniquement après** validation complète du proxy sécurisé. Sans certificat, les routes protégées doivent rester inaccessibles, pas être débloquées temporairement. Les parcours OTP de connexion ordinaire, récupération et renouvellement diffèrent ; les tester séparément.

La route Superadmin React utilise **la même origine HTTPS que Nginx** pour l'API et Sanctum : une simple application Vite sur `localhost:5174` sans proxy mTLS **ne suffit pas**. Les autres rôles de personnel utilisent leur propre authentification.

## 10. Dépannage rapide

| Symptôme | Hypothèse/test |
|---|---|
| `500` API | `storage/logs/laravel.log`, DB et migrations, `APP_KEY`, `php artisan config:clear`; ne jamais publier une trace contenant un secret. |
| `419 CSRF` | URL de l'API, `SANCTUM_STATEFUL_DOMAINS`, origine `localhost` cohérente (ne pas mélanger avec `127.0.0.1`), cookies et `/sanctum/csrf-cookie`. |
| `401/403` | Distinguer session absente, permissions, statut d'adhésion, step-up, Guardian et mTLS ; **ne pas** supprimer le middleware pour contourner. |
| Packs absents | `LegacyCatalogSeeder` ne publie rien ; seeder démo ou publication volontaire de plans locaux après validation. |
| Paiement refusé | `APP_ENV=staging`, provider configuré `fake`, options d'opérateur par pays ; `FEDAPAY_ENVIRONMENT=sandbox` ne suffit pas à autoriser un provider réel. |
| Frontend blanc/404 | vérifier `npm run build`, console navigateur, `VITE_API_URL`, origin, CORS, SPA routing et port. |
| E-mails absents | `MAIL_MAILER=log` écrit en log, **n'envoie pas** de vrai mail ; vérifier worker et `storage/logs`. |
| Tests DB perturbent l'application | vérifier **avant** exécution que les tests ciblent `cectapi_test` (voir `phpunit.xml`) ; jamais le staging. |

## 11. Proposer des améliorations sans écraser notre branche

```bash
# À exécuter dans LE dépôt concerné (pas simultanément dans les trois)
git checkout custum
git pull --ff-only origin custum
git switch -c feat/proposition-description
# modifier -> tester -> documenter
git status
git add -A
git commit -m "feat: expliquer précisément la proposition"
git push -u origin feat/proposition-description
```

Ouvrir une **pull request vers `custum`** dans le même dépôt ; décrire : problème, comportement actuel, comportement proposé, impact API/DB/UI, effets sur les autres composants, migrations éventuelles, tests réussis et captures non sensibles. Ne pas merger automatiquement dans `main` ni déployer sur notre serveur sans validation.

Pour toute proposition métier, respecter [le cahier des charges cumulatif](https://github.com/70-DIDIER/cecet-api/blob/custum/Cahier_des_charges_CECT_YEM_v5.0.md) et [les décisions d'inscription PF](https://github.com/70-DIDIER/cecet-api/blob/custum/docs/DECISIONS_METIER_CECT_2026-10-20.md). Ne pas réintroduire le rôle Validateur ou l'ancien paiement pendant l'inscription sous prétexte que `main` le contient.

## 12. Liste de contrôle de livraison du développeur

- [ ] Trois branches `custum` clonées, URLs origin contrôlées, trois SHAs consignés.
- [ ] Base `cectapi` isolée et `cectapi_test` séparée, clés locales seulement.
- [ ] Migrations, settings, rôles, packs et démo locaux explicitement identifiés.
- [ ] Frontend et admin compilent, API/worker/scheduler démarrés ; pages publiques, membre, admin testées.
- [ ] Prix/configuration et catalogue comparés à la référence **datée**, avec écarts documentés.
- [ ] Sécurité mTLS testée avec sa **propre** PKI, ou profil réduit clairement marqué comme tel.
- [ ] Aucun `.env`, secret, certificat, jeton, OTP, donnée client ou dump de staging dans Git.
- [ ] PR vers `custum`, tests et changements documentés.

**Important :** ce document décrit un chemin de reproduction et l'état *observé* le 21 octobre 2026. Il ne certifie pas que le serveur a été cloné localement ni que les trois composants possèdent actuellement des builds validés. Pour une parité intégrale des configurations et données métiers privées, demander à un administrateur habilité un **export explicitement approuvé, assaini et versionné**, jamais un dump brut de staging.
