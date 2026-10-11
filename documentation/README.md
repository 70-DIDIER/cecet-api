# Documentation CECT — branche `joseph`

Ce dossier est la documentation opérationnelle et technique de la plateforme CECT/YEM telle qu'elle est construite et testée sur la branche API `joseph`.

> Règle de maintenance : toute modification fonctionnelle, sécurité, déploiement, paiement, back-office ou configuration significative doit être ajoutée ici au même moment que le code.

## Index

- [Architecture](./architecture.md)
- [Déploiement et environnement de recette](./deployment-staging.md)
- [Paiements FedaPay](./payments-fedapay.md)
- [Back-office et gestion des packs](./backoffice-packs.md)
- [Sécurité et garde-fous](./security.md)
- [Matrice de conformité au cahier des charges](./conformite-cahier-des-charges.md)
- [Historique des travaux](./changelog.md)

## Dépôts actifs

- Backend : `70-DIDIER/cecet-api`, branche `joseph`
- Frontend : `70-DIDIER/CECT-frontend`, branche `joseph`
- Dépôt `70-DIDIER/cect` : squelette Laravel séparé, non utilisé pour le déploiement courant.

## Recette publique

- URL : `https://13-50-26-235.sslip.io`
- Backend Laravel : `/var/www/cect-api`
- Frontend React/Vite : `/var/www/cect-frontend`
- Nginx expose le frontend et route les API / back-office Laravel vers PHP-FPM.

Les secrets ne doivent jamais être ajoutés à cette documentation. Seuls les noms de variables et leur état `SET/EMPTY` peuvent être documentés.
