# Paiements FedaPay

## Architecture

Le prestataire entrant configuré sur la recette est FedaPay Sandbox.

Flux réel prévu en production :

```text
Utilisateur
  -> backend CECT crée une transaction
  -> FedaPay Checkout
  -> paiement opérateur
  -> webhook signé
  -> CECT re-récupère la transaction chez FedaPay
  -> réconciliation
  -> activation métier
```

Le contenu du webhook n'est pas considéré comme une preuve suffisante. Le backend relit la transaction directement auprès de FedaPay avant de modifier l'état métier.

## Option financière retenue

**Option B** :

- le montant métier CECT doit être reçu net par la coopérative ;
- les frais FedaPay sont supportés par le client et ajoutés par FedaPay sur le checkout.

Exemple observé en Sandbox :

```text
Montant CECT        25 000 XOF
Frais FedaPay        1 042 XOF
Montant débité      26 042 XOF
Montant transféré   25 000 XOF
```

Le frontend ne doit donc pas prétendre que le total final est 25 000 XOF. Pour un paiement PSP, il affiche le montant CECT et précise que les frais prestataire sont ajoutés au checkout.

## Téléphone

Pour T-Money/Flooz, le numéro de paiement est demandé/confirmé dans l'interface et envoyé au backend. Il peut être modifié pour une transaction sans modifier le numéro de profil.

## Sandbox et `momo_test`

Le Sandbox FedaPay actuel a retourné des transactions `declined` pour les anciens numéros de test historiques. Ce comportement est traité comme un comportement du Sandbox et non comme une preuve d'échec de l'intégration CECT.

Ont été vérifiés :

- création de transaction ;
- génération du checkout ;
- réception du webhook signé ;
- réconciliation serveur-à-serveur ;
- passage correct de la tentative en `declined`.

## Compatibilité API FedaPay

La réponse transaction FedaPay actuelle peut exposer `currency_id` sans objet `currency.iso`. L'adaptateur CECT résout maintenant l'identifiant via l'endpoint currencies.

Le statut `expired` est aussi pris en charge explicitement par l'enum de statut prestataire et la réconciliation CECT.

## Mode présentation staging

Pour la recette uniquement, `platform.demo_gateway_bypass=true` autorise l'activation d'une adhésion sans débit réel afin de présenter le parcours alors que FedaPay Sandbox ne fournit pas un scénario de succès exploitable.

Garde-fous :

- autorisé uniquement en `testing` et `staging` ;
- interdit en production ;
- `ops:golive` doit échouer si cette option est active.

En production, le clic sur « payer » ne doit jamais suffire à activer le compte.
