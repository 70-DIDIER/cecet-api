# CECT DEMO — Installation sécurisée du SuperAdmin sur un endpoint

**État au 18 octobre 2026 : préparé dans GitHub. Non activé sur Nginx.**
Ce document vise `https://admin.13-49-203-235.sslip.io/superadmin/connexion`, sous le certificat HTTPS **déjà valide** pour ce domaine.

## Architecture

- Le serveur HTTPS existant `admin.13-49-203-235.sslip.io:443` propose un certificat client avec `ssl_verify_client optional` (directive autorisée seulement dans `http/server`).
- Nginx **bloque obligatoirement** `/superadmin`, `/superadmin/*`, `/api/*` et `/sanctum/*` sur cet hôte quand `$ssl_client_verify != SUCCESS`.
- Le front React doit être compilé avec `VITE_SUPERADMIN_PATH_MODE=true` et `VITE_API_URL=https://api.13-49-203-235.sslip.io`. Après entrée par `/superadmin/connexion`, **tous** ses appels SuperAdmin et son accès au tableau de bord passent par le même hôte vérifié, pas par l'API publique.
- Le bloc `location = /index.php` doit être `internal`, avec variables FastCGI `CECT_MTLS_VERIFY` et `CECT_MTLS_CERT` définies par Nginx, non par le client.
- En backend, la validation mTLS, la signature RSA de la clé .cectkey, le compte SuperAdmin autorisé et les OTP restent obligatoires.
- L'administration ordinaire `/connexion` et la partie publique restent accessibles normalement sans certificat.

## Prérequis

1. Reconnecter l'agent Desktop Commander Remote sur le serveur et obtenir un accès administrateur système **autorisé** pour modifier/recharger Nginx (le connecteur non privilégié ne peut pas effectuer ces opérations).
2. Importer `CECT-SuperAdmin-DEMO.p12` dans le navigateur avec son mot de passe. Conserver `.cectkey` en sécurité.
3. Vérifier que les fichiers publics `ca.crt` et `clients.crl.pem` sont présents sur le serveur dans `/home/ubuntu/cect-demo-pki/`. Leur **clé CA privée ne doit PAS être présente sur le serveur**.
4. Installer le code sur `custum` et appliquer la migration `2026_10_18_000000_create_superadmin_security_tables.php`.
5. **Attention :** la CRL de démo expire le **24 octobre 2026 à 22:36:04 UTC**. Elle devra être régénérée/signée par la CA hors ligne ou toute connexion sera refusée après expiration. Ne désactivez pas silencieusement la CRL en production.

## Activation en préproduction

1. Répertorier le bloc Nginx qui sert réellement `admin.13-49-203-235.sslip.io` sur **443**. Le sauvegarder intégralement.
2. Fusionner sans doublons les directives du fichier `deploy/nginx/cect-superadmin-path-mtls.snippet.conf` **dans ce seul bloc**. Ne pas ajouter un autre `server` avec le même `server_name`.
3. Vérifier `nginx -t`, puis recharger Nginx. Ne pas appliquer la configuration en cas d'erreur de syntaxe ou de collision de `location`.
4. Tester : `/connexion` **200 sans certificat** ; `/superadmin/connexion` **403 sans certificat** ; `/api/v1/superadmin/status` **403 sans certificat depuis ce même hôte** ; `/superadmin/connexion` **200 avec le certificat installé**.
5. Définir dans l'environnement serveur : `SUPERADMIN_BOOTSTRAP_CERT_SHA256=580bb700af5fbe79acfa275cdd344ad22455514816fd689aff7e9e12d344ce9e`. Il s'agit de l'empreinte **publique** SHA-256 DER du certificat client émis pour cette seule démo.
6. Exécuter en staging `php artisan cect:provision-superadmin-demo --dry-run`, puis `php artisan cect:provision-superadmin-demo`. Le compte créé est **sans mot de passe** ; le CLI affiche son ULID. Vérifier son identité et son rôle en base.
7. Définir `SUPERADMIN_USER_ID=<ULID_AFFICHE>`, `SUPERADMIN_KEY_DAYS=30`, puis **seulement après le test de Nginx** `SUPERADMIN_MTLS_ENABLED=true` ; purger/recréer le cache de configuration Laravel.
8. Déployer le **nouveau** build de `cect-admin` sur le site admin HTTPS, avec sauvegarde de l'ancienne release. Tester l'ancienne connexion et un accès non autorisé.
9. Première utilisation : ouvrir `/superadmin/connexion` avec `.p12`, importer `.cectkey`, saisir les 3 adresses e-mail ; valider 3 OTP **différents**, confirmer l'activation de la clé ; vérifier l'accès au tableau de bord.
10. Déconnexion/reconnexion : fichier `.cectkey` + **un même code** transmis aux 3 e-mails, puis rotation avec 3 OTP **différents** ; vérifier que l'ancienne clé est révoquée une fois la nouvelle activée.

## Tests négatifs avant toute utilisation

- Aucune page SuperAdmin sans certificat ; pas d'accès direct par `/index.php`, API publique ou port backend.
- Certificat invalide, CA inconnue, certificat expiré ou révoqué : refus.
- Certificat client signé par CA mais non épinglé sur le compte : refus.
- Ancien fichier, fichier expiré, preuve cryptographique altérée, mauvais OTP, rejouage d'un défi, OTP utilisé deux fois : refus.
- API normale et rôle Administrateur/Comptabilité restent accessibles selon leurs propres permissions.
- Tester le retour arrière sans supprimer les données d'audit, ni créer un mot de passe SuperAdmin de secours.

## Limites volontaires de la démo

- `ssl_verify_client optional` est appliqué à **l'ensemble du serveur TLS** mais les **chemins SuperAdmin** rejettent les requêtes sans certificat **avant l'application**. Ce n'est pas un rejet complet de poignée de main TLS comme sur un serveur `ssl_verify_client on` isolé.
- Les 3 copies du code OTP identique ne prouvent pas le contrôle des 3 boîtes. La preuve RSA + le certificat mTLS sont les autres facteurs.
- Le fichier privé `.cectkey` reste exportable et n'est pas chiffré par notre application. Le stocker sur un support chiffré et le renouveler régulièrement.
- L'autorité de certification de démonstration n'est **pas adaptée à la production** ; remplacer par une CA gérée hors ligne avec CRL actualisée, sauvegarde chiffrée et idéalement authentification FIDO2 indépendante.
