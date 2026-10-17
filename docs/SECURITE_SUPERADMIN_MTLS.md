# CECT — SuperAdmin isolé : spécification et installation

**Statut : code applicatif ajouté sur `custum`. Activation production différée et sécurisée.** Aucun certificat personnel, CA privée, adresse réelle ou clé d'accès ne doit être commité.

## Comportement requis

1. **Barrière réseau :** `https://superadmin.<domaine>` exige un certificat client signé par la CA interne (`ssl_verify_client on`). Sans certificat, Nginx répond avant l'application.
2. **Premier accès :** le SuperAdmin provisionné dans `SUPERADMIN_USER_ID`, détenteur du certificat épinglé `SUPERADMIN_BOOTSTRAP_CERT_SHA256`, configure **trois e-mails distincts** et entre **trois codes différents**. Le navigateur crée un couple RSA-3072 puis prépare un fichier privé `.cectkey`.
3. **Activation :** la clé privée signe un défi à usage unique ; le backend n'en conserve que la clé publique. Le fichier est téléchargé **avant** l'activation.
4. **Connexion ordinaire :** certificat navigateur + fichier `.cectkey` + défi signé, puis **le même code OTP transmis aux trois e-mails**, saisi **une fois**.
5. **Rotation après connexion :** trois OTP **différents**, un par e-mail. Nouvelle clé `.cectkey`, téléchargement et confirmation de possession ; l'ancienne clé est alors révoquée de façon atomique. Aucune route métier n'est disponible avant la confirmation.
6. **Fichier expiré/perdu :** la récupération n'est permise qu'avec le certificat mTLS toujours valide et **trois OTP différents** sur les e-mails préalablement configurés.
7. **Changement des trois e-mails :** seulement depuis une session SuperAdmin cryptographiquement validée, avec confirmation **des trois nouvelles adresses** par OTP distincts.

**Important :** pour la connexion, le même code diffusé à trois adresses ne démontre pas la possession des trois boîtes ; il suffit de compromettre une seule boîte pour voir ce code. La possession du fichier cryptographique et le certificat mTLS restent des barrières séparées. Pour un niveau de sûreté plus élevé, ajouter une clé FIDO2 matérielle indépendante.

## Couche réseau et frontières de confiance

Utiliser le modèle `deploy/nginx/cect-superadmin-mtls.conf.example`. Il publie `/api` et `/sanctum` **sous le même sous-domaine mTLS** que la SPA React. Nginx transmet `CECT_MTLS_VERIFY` et `CECT_MTLS_CERT` uniquement via **variables FastCGI serveur**, jamais par en-têtes HTTP client. Laravel calcule SHA-256 du certificat X.509 (DER) et le compare au certificat enregistré.

AUCUNE autre virtual host PHP-FPM ne doit définir ces variables. Les API SuperAdmin refusent toute requête dépourvue de cette preuve. Avec `SUPERADMIN_MTLS_ENABLED=true`, les mots de passe, sessions héritées, jetons bearer et panneaux Filament/Blade classiques ne permettent plus d'entrer comme SuperAdmin ; les comptes Administrateur/Comptabilité restent inchangés.

## Création de la CA et du certificat navigateur

Depuis une machine administrative **hors ligne**, dans un répertoire chiffré et privé :

```bash
bash scripts/create-superadmin-client-pki.sh "$HOME/secure-cect-pki" superadmin-principal
```

La commande génère `ca.crt`, la clé racine **chiffrée** `ca.key`, un certificat client `superadmin-principal.crt` et un `.p12` **protégé par mot de passe**. Importer le `.p12` dans le magasin de certificats du navigateur. Certains navigateurs, notamment Firefox, utilisent un magasin de certificats indépendant. Détruire les CSR et extensions intermédiaires après archivage si souhaité, sans perdre les registres nécessaires à la révocation.

Le seul matériel transféré à Nginx est **`ca.crt`** (et ultérieurement la CRL signée). Copier `ca.key` sur un serveur accessible sur Internet est interdit. La clé de certificat client `.key` est non chiffrée pendant l'émission ; la conserver uniquement sur un média chiffré hors ligne, puis la supprimer après transfert vérifié du `.p12`.

## Variables API

Dans l'environnement PHP de production (jamais dans GitHub) :

```dotenv
SUPERADMIN_MTLS_ENABLED=false
SUPERADMIN_USER_ID=<ULID_DU_COMPTE_SUPERADMIN_ACTIF>
SUPERADMIN_BOOTSTRAP_CERT_SHA256=<EMPREINTE_SHA256_DER_DU_CERTIFICAT_CLIENT>
SUPERADMIN_KEY_DAYS=30
```

- Installer une virtual host HTTPS fonctionnelle, avec certificat serveur valide (Let's Encrypt ou autre), `ca.crt`, `ssl_verify_client on`.
- Ajouter le sous-domaine SuperAdmin à `SANCTUM_STATEFUL_DOMAINS` ; sur ce sous-domaine, les appels React sont same-origin, et le cookie Laravel doit être `HttpOnly`, `Secure` et de préférence host-only. Définir `SESSION_SECURE_COOKIE=true`. Éviter un `SESSION_DOMAIN=.domaine` partagé qui faciliterait des transferts de sessions inter-domaines.
- Déployer la migration `2026_10_18_000000_create_superadmin_security_tables.php` et le frontend Admin (incluant la route `/superadmin/connexion`).
- Vérifier qu'aucun autre virtual host, route PHP-FPM ou proxy ne transmet `CECT_MTLS_VERIFY`/`CECT_MTLS_CERT` depuis le réseau public, et que l'accès public direct à l'API SuperAdmin est rejeté.
- Tester en préproduction : sans certificat, certificat non autorisé, expiré, clé révoquée, échecs OTP, récupération, rotation après connexion et changement d'e-mails.
- **Ensuite seulement**, renseigner `SUPERADMIN_MTLS_ENABLED=true`, vider la configuration Laravel, vérifier `nginx -t` et recharger Nginx. Garder un rollback documenté, sans désactiver silencieusement les contrôles en exploitation.

### Vérifications Nginx (après configuration, pas avant)

```bash
sudo nginx -t && sudo systemctl reload nginx
curl -i https://superadmin.example.tg/api/v1/superadmin/status  # doit échouer sans certificat
curl -i --cert superadmin-principal.crt --key superadmin-principal.key https://superadmin.example.tg/api/v1/superadmin/status
```

Les commandes curl avec la clé privée non chiffrée doivent être exécutées **uniquement sur la machine de test sécurisée**. Pour l'usage normal, employer le `.p12` dans le navigateur.

## Gestion opérationnelle

- **CA racine :** disque/média chiffré, coffre physique ou HSM, mot de passe fort distinct, plusieurs sauvegardes scellées, journal d'émission, double contrôle administratif.
- **Révocation X.509 :** publier une CRL signée et à jour (`ssl_crl`) et définir un processus d'urgence. Il ne suffit pas de révoquer seulement le fichier `.cectkey` si le certificat navigateur est compromis.
- **Fichiers .cectkey :** les clés privées RSA exportées dans ces fichiers ne sont **pas chiffrées par l'application**. Stocker chaque fichier sur un volume chiffré ; ne pas l'envoyer par e-mail, cloud non chiffré ni journal applicatif. En production haute assurance, substituer une clé matérielle non exportable/FIDO2 à ce fichier exportable.
- **OTP :** 6 chiffres, hachage HMAC serveur, TTL 5 minutes, 5 tentatives au maximum, jamais enregistrés dans les logs. Livraison fiable des trois messages requise.
- **Sessions :** à la connexion, renouveler l'identifiant ; l'accès métier est subordonné au certificat mTLS et à la clé active courante. Mettre une durée de session stricte et invalider les autres sessions SuperAdmin en cas de révocation.
- **Disponibilité :** conserver une procédure de récupération physique documentée, avec approbation hors bande et certificats de secours préprovisionnés. Ne jamais rendre publique une route de bootstrap ou une génération de clé non authentifiée.
- **Backups :** protéger la base et ses clés publiques, les adresses vérifiées, les défis, les événements de sécurité et le registre de révocation.

## Contrôle anti-régression

Le frontend public `CECT-frontend` ne doit pas contenir d'identifiants ou de secrets SuperAdmin. Le site d'administration normal `cect-admin` reste disponible pour les autres rôles ; l'espace SuperAdmin n'est servi qu'après mTLS par le sous-domaine isolé. Les branches Git utilisées sont **`custum`**.
