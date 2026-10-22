# CECT — Diagnostic de la redirection Superadmin vers /connexion (22 octobre 2026)

## Symptôme fourni par l'utilisateur

Capture Firefox Kali Linux : le navigateur affiche `https://admin.13-49-203-235.sslip.io/connexion`, avec le formulaire **« Connexion — CECT Admin »**, après tentative d'ouverture du lien `https://admin.13-49-203-235.sslip.io/superadmin/connexion?utm_source=chatgpt.com`. Ce formulaire est **réservé aux personnels ordinaires** ; il ne fournit pas la preuve mTLS, le fichier `.cectkey` ni les codes du circuit Superadmin.

## Constatations vérifiées côté serveur

- La route sécurisée **sans certificat navigateur** renvoie **HTTP 403 Nginx**, avec ou sans `utm_source`. Ce paramètre de traçage n'est pas la cause.
- Le domaine admin `/connexion` renvoie HTTP 200 ; le formulaire personnel est normalement accessible aux administrateurs non Superadmin.
- Le sous-domaine cible `superadmin.13-49-203-235.sslip.io` n'est **pas opérationnel en TLS** et n'est pas couvert par le certificat HTTPS actuellement installé. La séparation par vhost est un travail d'infrastructure indépendant.
- Le build admin intègre `VITE_SUPERADMIN_PATH_MODE=true`. Le backend Laravel active bien le contrôle Superadmin mTLS. La clé CECT `.cectkey` est une preuve applicative **après** le certificat client `.p12/.pfx` du navigateur, pas un substitut à celui-ci.
- La capture d'écran seule ne permet pas de démontrer si le certificat est absent, mal importé, expiré ou rejeté. La requête directe au chemin sécurisé sans certificat retourne 403 ; elle ne redirige pas vers `/connexion`. Le retour sur `/connexion` peut correspondre à une navigation du SPA/ancien lien, pas à un contournement mTLS.

## Correction UX déployée

Une **page informative non authentifiée** est publiée sur :
`https://admin.13-49-203-235.sslip.io/acces-superadmin`.

- Elle présente explicitement les prérequis **Firefox et certificat personnel .p12/.pfx**.
- Elle affiche les étapes Firefox (Paramètres → Vie privée et sécurité → Certificats → Afficher les certificats → Vos certificats → Importer).
- Elle distingue le certificat mTLS navigateur de la clé applicative `.cectkey` ; elle ne demande jamais de télécharger la clé privée vers un serveur.
- Elle explique HTTP 403 et que la validation/révocation se fait par la PKI, sans désactiver les contrôles.
- Un bouton **navigation complète** pointe vers le vrai chemin Nginx `/superadmin/connexion`, qui reste strictement protégé.
- Le lien « Accès Superadmin sécurisé » sur le formulaire personnel ainsi que sur le formulaire Staff Guardian pointe désormais vers cette page de préparation plutôt que vers une route dont Nginx pouvait refuser l'accès avant le rendu React.
- Le garde de route et le protocole backend mTLS ne sont pas modifiés. Aucune règle Nginx, certificat privé, base de données, compte ou secret modifié.

## Recette et restauration

- Build TypeScript et Vite : **succès**.
- `GET /acces-superadmin` : **HTTP 200** ; bundle JS dédié contenant les instructions et le lien sécurisé, JS/CSS : **HTTP 200**.
- `GET /connexion`, `GET /admin`, `GET /admin/packs` : **HTTP 200**, personnels ordinaires non perturbés.
- `GET /superadmin/connexion` sans certificat client et `GET /api/v1/superadmin/status` sans certificat : **HTTP 403** (fail-closed).
- `GET /api/v1/health` : **HTTP 200**.
- Release atomique : `/home/ubuntu/cect-releases/admin-superadmin-cert-preflight-20261022` ; pointeur actif : `/home/ubuntu/cect-current/admin`.
- Sauvegarde version précédente et rollback : `/home/ubuntu/cect-private-backups/before-superadmin-cert-preflight-20261022/` → `admin-superadmin-isolation-ready-20261022`.
- Branch : `70-DIDIER/cect-admin` → `design/pf-pm-premium-20261022`.

**À vérifier avec l'utilisateur** : Firefox a-t-il bien importé son certificat client `.p12/.pfx` dans « Vos certificats » ? Ne jamais demander l'envoi du certificat privé, de sa phrase secrète ni du fichier `.cectkey` via conversation. Sans certificat personnel valide, la connexion protégée est volontairement impossible. La configuration du sous-domaine dédié relève d'un déploiement HTTPS/Nginx ultérieur.
