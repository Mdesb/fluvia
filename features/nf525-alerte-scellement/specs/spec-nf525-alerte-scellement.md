# Spec — nf525-alerte-scellement

**Statut :** en revue <!-- brouillon → en revue → validée (CP-1) -->
**Auteur :** Claude Code (session cloud), arbitrages de Maxime du 18/11/2026
**Date :** 2026-11-18
**Issue :** #370 (suite de #368, revue sensible points 7 et 8 ; s'appuie sur #369, PR #373)
**Faits :** `features/nf525-alerte-scellement/refs/faits-chercheur.md`

## Contexte & problème

Quand un scellement NF525 est refusé, l'application lève un 409 et écrit une ligne `critical` dans le journal du conteneur. **Personne ne lit ce journal** (VERIFIED). Rien n'est persisté, personne n'est prévenu, et le caissier reçoit un message technique : nom de variable de clé, identifiants, procédure serveur. Ce message lui est renvoyé tel quel jusque dans la quarantaine des ventes hors connexion (VERIFIED).

Une chaîne bloquée refuse **chaque** vente qui suit. Une alerte par refus inonderait donc tout le monde (VERIFIED : la cloche n'a aucun dédoublonnage).

Aucun courriel ne sort de la plateforme (E-8). La seule notification réelle passe par la **cloche** de l'application.

## Arbitrages de Maxime (18/11/2026)

1. **Une alerte NF525 dédiée par chaîne bloquée**, qui alimente la cloche une seule fois à son ouverture.
2. **Destinataires** : les administrateurs des établissements concernés (droit `securite.gerer`), avec un texte simple, et **l'éditeur**, qui voit le détail technique et peut agir.
3. **Chaîne compta ou facturation** : on prévient les administrateurs de **tous** les établissements couverts par le profil exploitant.
4. **Résolution** : l'alerte se ferme **d'elle-même** au premier scellement réussi sur cette chaîne. L'éditeur peut y poser une note. L'administrateur la lit seulement.
5. **Déclencheurs** : un scellement refusé, **et** une vérification qui trouve la chaîne rompue (même alerte, dédoublonnée). Le contrôle de déploiement `check-keys` n'ouvre rien : il fait déjà échouer le déploiement.
6. **Le caissier voit un message générique**, avec la référence de l'alerte. Le détail n'est que dans l'alerte.

## Objectifs (Goals)

- **G-1 — Alerte persistée, une par chaîne.** Un scellement refusé (vente, compta, facturation) ouvre une alerte NF525. Si une alerte est déjà **ouverte** pour la même chaîne, il la met à jour au lieu d'en créer une autre. Une alerte porte :
  - la chaîne : type, point de vente ou profil exploitant, journal le cas échéant ;
  - la cause : signature invalide, clé inconnue, régression de clé, ordre illisible ;
  - le premier et le dernier refus, et le nombre de refus ;
  - le détail technique : identifiants de clé nettoyés (`safeId`), numéro de maillon, **jamais une clé** ;
  - l'état : ouverte ou résolue.

  Le dédoublonnage tient sous concurrence : deux ventes refusées en même temps donnent une seule alerte ouverte.
- **G-2 — L'alerte survit au refus.** Le refus annule la transaction du scellement. L'alerte, elle, doit être **effectivement enregistrée** malgré cette annulation.
- **G-3 — Vérification rompue.** Une vérification de chaîne qui conclut « non intacte » ouvre ou met à jour l'alerte de cette chaîne, avec la même règle de dédoublonnage que G-1. Sont concernées la vérification vente, compta et facturation, et celle de l'archive fiscale quand elle porte sur une chaîne identifiable.
- **G-4 — Cloche à l'ouverture seulement.** À l'**ouverture** d'une alerte, et à elle seule, une notification de gravité « critique » part dans la cloche :
  - pour les administrateurs (`securite.gerer`) de l'établissement du point de vente ;
  - pour une chaîne compta ou facturation, pour les administrateurs de chaque établissement couvert par le profil exploitant.

  Le texte est simple : la caisse ou la facturation est bloquée, l'éditeur est prévenu. Il mène à l'écran de l'alerte. Les refus suivants ne sonnent plus.
- **G-5 — Écran des alertes, côté établissement.** Un administrateur voit, en lecture seule, les alertes NF525 des chaînes de son établissement : ouvertes et résolues, cause en clair, dates, nombre de refus. Il ne voit **ni** le détail technique **ni** les alertes d'un autre établissement (cloisonnement serveur).
- **G-6 — Écran des alertes, côté éditeur.** L'éditeur voit toutes les alertes de tous les établissements, avec le détail technique, et peut poser une note sur une alerte. C'est son seul geste d'écriture.
- **G-7 — Résolution automatique.** Le premier scellement **réussi** sur une chaîne dont l'alerte est ouverte la passe à « résolue », avec la date. Si la chaîne refuse de nouveau ensuite, une **nouvelle** alerte s'ouvre et la cloche sonne de nouveau.
- **G-8 — Message générique à la caisse.** Le 409 d'un scellement refusé ne contient plus aucun détail technique : ni nom de variable, ni identifiant de clé ou de point de vente, ni procédure. Il dit que la chaîne fiscale est bloquée, que l'administrateur et l'éditeur sont prévenus, et donne la **référence de l'alerte**. Il en va de même pour la raison inscrite dans la quarantaine des ventes hors connexion.

## Hors périmètre

- **Le courriel**, bloqué par E-8. Il viendra quand un prestataire d'envoi existera, via les mêmes alertes.
- **Une alerte pour `app:nf525:check-keys`** : le déploiement échoue déjà.
- **Une alerte de niveau plateforme** pour une chaîne sans établissement identifiable (point de vente sans établissement, profil sans établissement) : seul l'éditeur la voit, et la cloche ne sonne pour personne d'autre.
- **Tout geste de réparation depuis l'écran** : la rotation de clé reste une procédure serveur (README).
- **La signature du ZIP d'archive** (Issue à part, #369).

## Parcours utilisateur / UX

- **Caissier** : une vente refusée affiche le message générique et la référence de l'alerte. Rien d'autre ne change à la caisse.
- **Administrateur d'établissement** : la cloche affiche « Chaîne fiscale bloquée — caisse X » (critique). Un clic mène à l'écran « Alertes NF525 » de son établissement, en lecture seule.
- **Éditeur** : l'écran « Alertes NF525 » de l'éditeur liste toutes les alertes, avec un filtre ouvertes/résolues, le détail technique et un champ « note ».
- La conception visuelle reste celle des écrans existants (tokens du design system) : pas de nouveau langage visuel. `concepteur-ui` n'est pas requis, `relecteur-ui` l'est après le code.

## Contraintes & décisions techniques connues

- **S'appuie sur #369** (PR #373, non fusionnée) : `SealKeyring`, `SealingRefusedException`, `safeId`. La branche `feature/nf525-alerte-scellement` part de `feature/nf525-ordre-cles`. On reporte `main` dès que #373 est fusionnée.
- **Réutiliser le centre de notifications existant** (`Notification`, `NotificationRule`, `NotificationRecipientResolver`, la cloche) pour G-4, en ajoutant une règle.
- **Survie au rollback (G-2)** : aucun précédent dans le dépôt (VERIFIED). Le plan choisit le mécanisme : capture après annulation, connexion séparée ou message Messenger. Il le justifie, et un test d'intégration prouve qu'une vente refusée laisse bien une alerte en base.
- **Dédoublonnage sous concurrence** : sur le modèle de `TreasuryCashAlert` (index unique sur l'alerte ouverte d'une chaîne).
- **Migration écrite à la main** (nouvelle table). Requêtes paramétrées. Cloisonnement serveur testé entre deux établissements.
- **Fiches de référence** de la version installée pour toute API Symfony ou API Platform nouvelle (Messenger, `kernel.exception`, ApiResource…) : le plan les liste, et `documentaliste` les produit en un seul lancement.
- **Revue** : `relecteur` et `security-reviewer` en mode adversarial, `architecte-donnees` pour la migration, `relecteur-ui` pour les écrans.

## Points UNVERIFIED (bloquants pour CP-1)

Aucun bloquant pour les objectifs. À mesurer pendant le plan :
- [ ] Valeur de `MESSENGER_TRANSPORT_DSN` en préprod et en production, si le plan retient Messenger.
- [ ] Ce que l'écran de caisse affiche aujourd'hui d'un 409 (frontal non lu).

## Critères d'acceptation

Chaque critère correspond à un test automatisé : PHPUnit, et `node --test` pour le frontal là où le dépôt le fait.

- **G-1** : trois ventes refusées sur la même chaîne → **une** alerte ouverte, avec 3 refus et les bonnes dates. Deux scellements refusés en parallèle → une seule alerte ouverte (contrainte en base). **Témoin** : deux chaînes différentes → deux alertes.
- **G-2** : une vente refusée de bout en bout (API) → la vente n'existe pas, l'alerte existe.
- **G-3** : une vérification rompue → l'alerte s'ouvre. **Témoin** : une vérification intacte → aucune alerte.
- **G-4** : à l'ouverture, une notification par administrateur de chaque établissement concerné. Au deuxième refus, aucune nouvelle notification. Un non-administrateur ne reçoit rien. Un profil à deux établissements → les administrateurs des deux.
- **G-5** : l'administrateur A ne voit pas l'alerte de l'établissement B. Il ne reçoit pas le détail technique (champ absent de la réponse). Il ne peut pas écrire.
- **G-6** : l'éditeur voit tout et pose une note. Un administrateur qui tente de poser une note reçoit un refus.
- **G-7** : refus → alerte ouverte ; scellement réussi → alerte résolue ; nouveau refus → nouvelle alerte, et la cloche sonne de nouveau.
- **G-8** : le corps du 409 et la raison de quarantaine contiennent la référence de l'alerte. Ils ne contiennent ni `NF525_`, ni identifiant de clé, ni UUID de point de vente.
