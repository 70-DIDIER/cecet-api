# CECT — Recette agressive métier, finance et intégrations (20 octobre 2026)

**Statut général : recette automatisée VERTE, homologation des prestataires externes NON ACQUISE.** Les résultats ci-dessous proviennent des journaux d'exécution de la machine staging `ip-172-31-25-53` à la date indiquée. Ils ne constituent ni un audit réglementaire, ni une preuve de transactions d'argent/YEM réels, ni une attestation de rentabilité après charges. Aucun appel de création d'achat/décaissement réel n'a été effectué pour ce test.

## 1. Isolement et préservation
- Source : `70-DIDIER/cecet-api` branche `custum`.
- Site staging : base `cectapi_live` conservée (**4 membres, 6 souscriptions, 10 tentatives de paiement** lors de la dernière lecture), **aucune remise à zéro ni publication de pack effectuée**.
- Tests fonctionnels et concurrence : base PostgreSQL distincte `cectapi_test` définie dans `phpunit.xml`, avec réinitialisation de test contrôlée ; ne jamais exécuter `migrate:fresh` sur `cectapi_live`.
- Copie de sauvegarde PostgreSQL `/home/ubuntu/cect-qa-backups/cectapi-live-before-ultra-recette-20261018.dump` mise à jour immédiatement avant les scénarios. Empreinte vérifiée au moment de la création `503b39b5310040a536d8bd0921f0b89672b0e1232f4f5fb2701270fa35f2`. Le nom historique du fichier ne correspond plus à son contenu initial d'octobre 18.
- Logs dans `/home/ubuntu/cect-qa-results/` (protéger ces chemins, ne jamais exporter de clés ni secrets sur GitHub).

## 2. Résultats automatisés vérifiés

| Campagne | Volume exécuté | Résultat | Preuve serveur |
|---|---:|---|---|
| Tests métier backend complets (adhésion, paiement, packs, top-up, QR, rééchange, reporting, sécurité et intégrations simulées) | 595 tests / 6 511 assertions | **595 passés, 0 échec** | `full-aggressive-20261020.log` |
| Concurrence réelle multiprocessus PostgreSQL (réservation de quota et rééchange Marchand) | 5 tests / 84 assertions | **5 passés, 0 échec** | `concurrency-aggressive-20261020.log` |
| Propriétés aléatoires des algorithmes de cashback/périodes | 5 000 scénarios, 352 518 assertions / 2 tests | **2 tests passés, 0 anomalie relevée** | `property-fuzz-aggressive-20261020.log` ; `tests/Unit/FinancialPropertyStressTest.php` |
| Charge calculatoire PostgreSQL synthétique, sans API et sans écritures métier persistantes | 100 000 comptes synthétiques, 1 000 000 opérations, 500 000 bonus | **0 cashback interdit, 0 solde négatif dans ce modèle** ; ~1,97 s | `stress-million-aggressive-20261020.json` |
| Sécurité HTTP sans authentification sur 14 routes sensibles Admin, Finance, Membre, Marchand et SuperAdmin | 14 requêtes | **14 réponses HTTP 401** | Vérification HTTPS directe |
| Intégrité staging | 6 comptes de quotas et 1 compte Marchand ; 131 événements d'audit | Journaux de quotas et chaîne d'audit **cohérents** | `ledger:verify`, `audit:verify` |

**Limites techniques :** la charge synthétique est une requête SQL sur des tables temporaires, pas un million de paiements HTTP ; les tests de processus concurrents contrôlent les scénarios écrits, pas tous les interleavings. Les tests FedaPay intégrés au code reposent sur des réponses HTTP simulées. Une compilation UI et des retours 200/401/403 ne remplacent pas un parcours utilisateur navigateur de bout en bout.

## 3. Calcul économique — conditions contractuelles non ratifiées

**Hypothèses du modèle :** un Consommateur paie 100 000 FCFA ; 650 FCFA par YEM acheté ; toutes les quantités achetées **et** bonus cashback sont finalement reçues puis cédées à la CECT par des Marchands. Le modèle suppose un seul cycle vente→rééchange, n'inclut **aucuns frais PSP, coûts d'acquisition YEM, charges opérationnelles, commissions additionnelles, liquidité initiale, retards, litiges ou impôts**. Il mesure uniquement la différence entre vente de ce lot et contre-valeur du rééchange, et ne doit jamais être présenté comme bénéfice net de la coopérative.

| Cashback en YEM | Rééchange Marchand 610 FCFA/YEM | Rééchange 650 moins 3 % = 630,50 FCFA/YEM |
|---|---:|---:|
| 0 % | +6 153,85 FCFA | +3 000,00 FCFA |
| 4,5 % | +1 930,77 FCFA | −1 365,00 FCFA |
| 5 % | +1 461,54 FCFA | −1 850,00 FCFA |
| 8 % | −1 353,85 FCFA | −4 760,00 FCFA |

**Seuil théorique de cashback pour un cycle nul avant frais :** `650 / 610 - 1 = 6,5574 %` au cours Marchand 610 ; `650 / 630,5 - 1 = 3,0928 %` dans le scénario « 650 - 3 % ». Dans le premier cas, **5 % laisse seulement 1,46 % du billet client pour absorber les frais et aléas**. Un cashback 8 % dépasse le seuil. **Aucun taux ne doit être présenté comme rentable sans charges réelles et calendrier de trésorerie.**

Stress de liquidité illustratif : 2 000 000 YEM rééchangés par mois pendant 60 mois représentent **73,2 milliards FCFA de sorties cumulées à 610 FCFA/YEM**, **75,66 milliards à 630,5**, hors bonus. Ces sorties cumulées **ne sont pas** le capital initial requis grâce aux rotations possibles du stock ; celui-ci dépend des délais de recouvrement, de la caisse et des réserves.

Modèle reproductible : `python3 scripts/qa-economics.py`. Il utilise `Decimal` avec six décimales de YEM et ne modifie rien.

## 4. Résultat des intégrations externes — blocages

### FedaPay
- Configuration de démonstration : `FEDAPAY_ENVIRONMENT=sandbox` ; présence d'une clé secrète, mais **secret de signature des webhooks absent**. Aucun nouveau webhook FedaPay ne peut être validé cryptographiquement tant que la configuration manque ; refus par défaut (fail-closed).
- Test réseau **lecture seule** depuis le serveur : `GET /v1/currencies` a renvoyé **HTTP 401** (~249 ms) ; `GET /v1/payment_methods` a renvoyé **HTTP 404** (~142 ms). Cela prouve l'accès réseau mais **ne valide pas l'authentification**, les permissions sandbox, les URL du fournisseur ou les opérations de paiement. Une clé invalide, un contexte d'authentification incomplet ou un endpoint non disponible doivent être investigués auprès de FedaPay.
- Le paramètre applicatif `payment.inbound_provider` = **fake** et `payment.payout_provider` = **fake**, à ce stade. **Aucun débit/crédit réel** ni émission de décaissement FedaPay effectué. Tests de contrats backend existants : autorisations, signatures, anti-replay, transitions d'états, erreurs réseau, retries, maker-checker, idempotence et réconciliation simulée.
- **Avant une vraie recette sandbox FedaPay :** obtenir une clé sandbox authentifiée, configurer et vérifier le secret du webhook ; utiliser des destinataires/transactions de test approuvés, exécuter un paiement sandbox et un payout sandbox avec autorisation explicite, comparer les références API et toutes les écritures. Ne pas basculer vers le fournisseur live sans contrat et contrôle de trésorerie.

### Foundation YEM
- Les échanges YEM et les paiements Marchand actuellement testés reposent sur références Pernum, déclarations de transfert, réservations et confirmation Comptabilité dans la plateforme. **Aucune preuve de connexion technique à un service de transfert YEM Foundation réel n'a été obtenue.**
- Il reste à définir les identifiants d'API approuvés, le contrat, les signatures, l'état de finalité du transfert, l'idempotence externe et les procédures de litiges/réconciliation. Un clic utilisateur « transféré » ne suffit jamais à prouver un mouvement externe.

### Exploitation
- Laravel `queue:work database` actif, **0 tâche en attente et 0 tâche échouée**, ordonnanceur Laravel défini pour crédits périodiques, expiration, reliquat, intégrité et paiements.
- `ops:golive` : **bloquant** à juste titre pour `APP_ENV=staging`, fournisseurs fictifs, `ops.alert_emails` vide et absence de compte Comptabilité actif. Les autres points (HTTPS, cookies sécurisés, débogage désactivé, hash Argon2id, mailer non fictif, queue) passent dans les conditions observées.
- `cect:demo-check` : 1 formule Vendeur publiée et **0 Consommateur, 0 Personnel, 0 Marchand**, 1 SuperAdmin, aucun Administrateur ni Comptable actif, OTP interne à trois adresses non activé. **L'ensemble du parcours de présentation ne peut donc pas être accompli sur la base staging sans ces prérequis**, malgré les tests métier verts en base isolée.

## 5. Décisions nécessaires et ordre de traitement

**P0 :** (1) confirmer la cession Marchand à 610 FCFA/YEM ou 650 moins 3 %, ainsi que les éventuels frais supplémentaires ; (2) confirmer les cashback maximum et frais PSP supportés ; (3) configurer le secret webhook FedaPay **sandbox**, vérifier une authentification 2xx et uniquement ensuite effectuer des transactions sandbox supervisées ; (4) spécifier le contrôle et la confirmation externe YEM Foundation ; (5) créer/activer les identités nominatives Admin et Comptabilité et tester l'arrivée des codes sur les trois adresses vérifiées.

**P1 :** ratifier et publier les pack Consommateur, Personnel et Marchand de présentation sans altérer les historiques existants ; définir les alertes d'exploitation ; réaliser les tests navigateur et les tests de restauration du backup ; exécuter DAST/pentest avec périmètre et fenêtre de maintenance.

**Non entrepris :** aucun paiement réel, aucun débit carte, aucun payout live, aucun transfert Foundation réel, aucun repeuplement ni purge des 4 membres staging. **Ne pas écrire « tout fonctionne en production » sur la base de ce seul rapport.**

## 6. Modifications livrées

- Nouveau test probabiliste reproductible `tests/Unit/FinancialPropertyStressTest.php` (5 000 combinaisons de cashback, plafonds, répartitions périodiques et exclusions Vendeur/Marchand).
- Nouveau simulateur analytique `scripts/qa-economics.py`, reproductible sans données privées.
- Rapports persistants situés sur le serveur dans `/home/ubuntu/cect-qa-results`.
- Aucun paramètre financier réel ou crédit membre modifié au cours de la campagne.
