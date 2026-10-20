# CECT — SuperAdmin isolé : spécification et installation

**Statut de la démonstration :** endpoint SuperAdmin mTLS activé sur le domaine `admin.13-49-203-235.sslip.io` ; mode sous-domaine dédié reste l'architecture cible. Version PHP de démo activée le 18 octobre 2026. Les trois OTP distincts sont réservés à l'initialisation/récupération/renouvellement, pas aux connexions ordinaires. Aucun certificat personnel, CA privée, adresse réelle ou clé d'accès ne doit être commité.

## Comportement requis

1. **Barrière réseau :** `https://superadmin.<domaine>` exige un certificat client signé par la CA interne (`ssl_verify_client on`). Sans certificat, Nginx répond avant l'application.
2. **Premier accès :** le SuperAdmin provisionné dans `SUPERADMIN_USER_ID`, détenteur du certificat épinglé `SUPERADMIN_BOOTSTRAP_CERT_SHA256`, configure **trois e-mails distincts** et entre **trois codes différents**. Le navigateur crée un couple RSA-3072 puis prépare un fichier privé `.cectkey`.
3. **Activation :** la clé privée signe un défi à usage unique ; le backend n'en conserve que la clé publique. Le fichier est téléchargé **avant** l'activation.
4. **Connexion ordinaire :** certificat navigateur + fichier `.cectkey` + défi signé, puis **le même code OTP transmis aux trois e-mails**, saisi **une fois**.
5. **Renouvellement exceptionnel de la clé :** la connexion quotidienne ne remplace **plus** automatiquement la clé. En cas de perte, d'expiration ou de renouvellement de sécurité, trois OTP **différents** sont nécessaires pour obtenir et activer un nouveau `.cectkey`. L'ancienne clé est révoquée à l'activation de la nouvelle.
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

Seuls **`ca.crt` et `clients.crl.pem` (la CRL signée et tenue à jour)** sont transférés à Nginx. Copier `ca.key` sur un serveur accessible sur Internet est interdit. La clé de certificat client `.key` est non chiffrée pendant l'émission ; la conserver uniquement sur un média chiffré hors ligne, puis la supprimer après transfert vérifié du `.p12`.

## Variables API

Dans l'environnement PHP de production (jamais dans GitHub) :

```dotenv
SUPERADMIN_MTLS_ENABLED=false
SUPERADMIN_USER_ID=<ULID_DU_COMPTE_SUPERADMIN_ACTIF>
SUPERADMIN_BOOTSTRAP_CERT_SHA256=<EMPREINTE_SHA256_DER_DU_CERTIFICAT_CLIENT>
SUPERADMIN_KEY_DAYS=30
```

- Installer une virtual host HTTPS fonctionnelle, avec certificat serveur valide (Let's Encrypt ou autre), `ca.crt`, la **CRL signée `clients.crl.pem`** et `ssl_verify_client on`.
- Ajouter le sous-domaine SuperAdmin à `SANCTUM_STATEFUL_DOMAINS` ; sur ce sous-domaine, les appels React sont same-origin, et le cookie Laravel doit être `HttpOnly`, `Secure` et de préférence host-only. Définir `SESSION_SECURE_COOKIE=true`. Éviter un `SESSION_DOMAIN=.domaine` partagé qui faciliterait des transferts de sessions inter-domaines.
- Déployer la migration `2026_10_18_000000_create_superadmin_security_tables.php` et le frontend Admin (incluant la route `/superadmin/connexion`).
- Vérifier qu'aucun autre virtual host, route PHP-FPM ou proxy ne transmet `CECT_MTLS_VERIFY`/`CECT_MTLS_CERT` depuis le réseau public, et que l'accès public direct à l'API SuperAdmin est rejeté.
- Tester en préproduction : sans certificat, certificat non autorisé, expiré, clé révoquée, échecs OTP, récupération, renouvellement exceptionnel et changement d'e-mails.
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
- **Révocation X.509 :** publier une CRL signée et à jour (`ssl_crl`) et définir un processus d'urgence. Une fois sur la machine de CA hors ligne, exécuter `openssl ca -config openssl-ca.cnf -revoke CLIENT.crt`, puis `openssl ca -gencrl -config openssl-ca.cnf -out clients.crl.pem` ; transférer et recharger Nginx. Renouveler la CRL avant ses 7 jours de validité, même sans révocation. Il ne suffit pas de révoquer seulement le fichier `.cectkey` si le certificat navigateur est compromis.
- **Fichiers .cectkey :** les **nouveaux fichiers v2** exportent la clé privée sous enveloppe chiffrée par **AES-256-GCM** avec une clé dérivée de la phrase secrète par **PBKDF2-SHA256 (600 000 itérations, sel aléatoire de 128 bits, nonce GCM aléatoire de 96 bits)** ; la phrase secrète comporte au moins 14 caractères et n'est jamais transmise à Laravel. Les anciens fichiers v1 de démonstration sont **en clair** et restent reconnus uniquement pour ne pas bloquer l'accès existant et pour permettre la **conversion dans le navigateur** en version chiffrée. Faire une copie de sauvegarde vérifiée du nouveau fichier et détruire ensuite les copies v1 non chiffrées. Conserver aussi les fichiers v2 sur volume chiffré, indépendamment de leur phrase secrète. Pour un niveau supérieur de sécurité, choisir une clé matérielle non exportable/FIDO2.
- **OTP :** 6 chiffres, hachage HMAC serveur, TTL 5 minutes, 5 tentatives au maximum, jamais enregistrés dans les logs. Livraison fiable des trois messages requise.
- **Sessions :** à la connexion, renouveler l'identifiant ; l'accès métier est subordonné au certificat mTLS et à la clé active courante. Mettre une durée de session stricte et invalider les autres sessions SuperAdmin en cas de révocation.
- **Disponibilité :** conserver une procédure de récupération physique documentée, avec approbation hors bande et certificats de secours préprovisionnés. Ne jamais rendre publique une route de bootstrap ou une génération de clé non authentifiée.
- **Backups :** protéger la base et ses clés publiques, les adresses vérifiées, les défis, les événements de sécurité et le registre de révocation.

## Contrôle anti-régression

Le frontend public `CECT-frontend` ne doit pas contenir d'identifiants ou de secrets SuperAdmin. Le site d'administration normal `cect-admin` reste disponible pour les autres rôles ; l'espace SuperAdmin n'est servi qu'après mTLS par le sous-domaine isolé. Les branches Git utilisées sont **`custum`**.

## Mise à jour du 18 octobre 2026 — Parcours et remise à zéro de démonstration

- Le SuperAdmin a été provisionné sans mot de passe et avec certificat client épinglé. Seul l'hôte Admin HTTPS présente les routes SuperAdmin et l'API de même origine via mTLS, sans perturber le rôle Administrateur ordinaire.
- **Connexion récurrente :** certificat X.509 + fichier .cectkey + signature RSA du défi + **un OTP commun** aux trois adresses, saisi une seule fois. Le backend appelle directement `authenticated()` et n'exige plus de renouvellement de clé à chaque session.
- **Clé expirée/perdue :** la récupération nécessite toujours trois codes distincts. Le modèle de menace distingue émission de clé et authentification quotidienne.
- **KPI :** les tableaux financiers doivent présenter seulement les opérations monétaires confirmées par un fournisseur non fictif ; les transactions `provider=fake` servent uniquement au test et ne constituent aucun encaissement réel.
- **Réinitialisation de la démonstration :** exécuter `cect:reset-demo-activity` en préproduction après `pg_dump` sécurisé. Cette commande supprime membres, paiements, adhésions, exports et opérationnels de test ; elle préserve SuperAdmin, certificats/clés actives, profils de sécurité, journaux d'audit/sécurité, packs, rôles, prix, paramètres et migrations. Le script demande un token explicite et un fichier de sauvegarde vérifié.
- **Constat de recette après la remise à zéro du 18 octobre :** 0 membre, 0 paiement, 0 adhésion, 0 FCFA collecté, 25 packs, 1 SuperAdmin et 1 clé active. Copie PostgreSQL archivée localement avec permissions restreintes.

**Attention :** le compte SuperAdmin et l'historique de sécurité **ne doivent pas** être purgés par une recette métier. La CA et la CRL de démonstration ne conviennent pas à une mise en production ; la CRL actuelle doit être renouvelée avant le 24 octobre 2026, 22:36 UTC.


## Mise à jour sécurité du 20 octobre 2026

- **Version `.cectkey v2` déployée** sur la console Admin : fichier chiffré en AES-256-GCM avec phrase secrète PBKDF2-SHA256 (600 000 itérations). Signature RSA-3072/SHA-256 produite **uniquement côté navigateur** après déchiffrement local de la clé ; l'API reçoit seulement la signature et la clé publique lors de l'enrôlement.
- **Anciennes clés `.cectkey v1` encore utilisables** afin de conserver l'accès aux certificats déjà importés. Le bouton « Télécharger une copie chiffrée » réenveloppe la **même** clé privée et conserve l'identifiant de clé : aucune modification du profil côté serveur. Supprimer l'ancien fichier seulement après vérification de la nouvelle copie.
- **Tests réalisés** : erreurs de phrase secrète, altération AES-GCM, dérivation publique, preuve RSA et migration v1 → v2. Compilation TypeScript/Vite réussie.
- **Déploiement et non-régression** : retour HTTP `200` sur la console et ses bundles et HTTP `403` sur l'accès SuperAdmin sans certificat ; clé active et profil existants préservés.
- **CRL :** le dernier `nextUpdate` connu pour la CRL de démonstration était le **24 octobre 2026 à 22:36 UTC**. Programmer sa régénération depuis la CA autorisée hors ligne avant cette échéance. Le contrôle d'accès doit rester fail-closed si la liste de révocation est périmée.
- **Accès interne :** nouveau parcours OTP à trois destinataires implémenté et testé, mais son activation sur staging attend des comptes Administrateur et Comptabilité actifs et une vérification des trois boîtes de sécurité. Garder la connexion encore opérationnelle jusqu'à bascule coordonnée.
