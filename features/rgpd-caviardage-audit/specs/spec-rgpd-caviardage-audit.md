# Spec — rgpd-caviardage-audit

**Statut :** validée (CP-1, Maxime, 2026-11-25) <!-- brouillon → en revue → validée (CP-1) -->
**Auteur :** Claude (session cloud), pour Maxime
**Date :** 2026-11-25
**Issue :** Mdesb/fluvia#363. Elle met en œuvre la décision « D2 → A », retenue mais non codée dans la PR #353.

## Contexte & problème

Faits établis par la recherche (VERIFIED, fichiers relatifs à `app/`) :

- **Ce que dit D2.** Dans la PR #353, D2 constate : « les entrées d'audit antérieures à une anonymisation contiennent encore l'identité ». L'option A retenue consiste à « les caviarder au moment de l'anonymisation : vider les champs d'identité de `valeurAvant` et `valeurApres` des entrées de cette fiche ». Le commentaire d'intégration précise que le code fusionné applique l'option C.
- **Un seul point d'anonymisation.** `Crm/Service/EffacementRgpdHandler.php` (`anonymiser()`) est appelé dans trois cas :
  - une demande d'une personne (`traiter()`) ;
  - une purge (`ApplyPersonalDataPurgeProcessor`) ;
  - la conservation, par la commande `AppliquerConservationCommand`.

  Il n'y a pas de suppression physique : une purge est une anonymisation.
- **Ce qui est déjà expurgé.** `Audit/Doctrine/AuditWriteSubscriber.php` (`CHAMPS_IDENTITE_CLIENT`, 11 champs) n'expurge que l'entrée de l'anonymisation elle-même. Les entrées antérieures de la fiche (création, modifications) gardent en clair, dans `valeurAvant` et `valeurApres` : nom, prénom, email, téléphone, date de naissance, SIRET, raison sociale, `externalRef`… Exemple typique : `ClientM4Adapter::creerRapide` écrit une entrée « creation » avec le nom et l'email.
- **Stockage.** La table `audit_entree` (`EntreeAudit`) contient `cibleType` (nom de classe), `cibleId`, `valeurAvant` et `valeurApres` (JSON `{champ: valeur}`). Elle n'a d'index que sur `date_heure`.
- **Un append-only seulement applicatif.** L'API n'expose aucune écriture. Il n'y a ni chaîne d'empreintes, ni scellement, ni trigger SQL sur `audit_entree`. Deux migrations passées font déjà des `UPDATE audit_entree`, sur une colonne de métadonnée.
- **NF525 est distinct.** Il repose sur `nf525_operation_scellee`, la chaîne d'empreintes et `InalterabiliteListener`. Les entrées NF525 ne portent pas de donnée nominative : `Vente.client` est un UUID.
- **Registre des traitements** (`docs/juridique/registre-traitements.md`, T7) : la durée de conservation de l'audit y est « À arbitrer ». La phrase « l'anonymisation d'une fiche n'y recopie plus l'identité effacée » ne vaut aujourd'hui que pour l'entrée de l'anonymisation.

## Objectifs (Goals)

- **G-1 — Caviardage au moment de l'anonymisation** (décision D2 → A).
  - Quand une fiche `Client` est anonymisée, quelle que soit la voie (demande, purge, conservation), toutes les entrées d'audit dont `cibleType` est `Client` et `cibleId` cette fiche sont caviardées.
  - Le caviardage a lieu dans la **même transaction** que l'anonymisation : si l'une échoue, rien n'est écrit.
- **G-2 — Forme du caviardage** (décision de Maxime : marqueur et trace).
  - Dans `valeurAvant` et `valeurApres`, chaque champ d'identité présent et non nul est remplacé par le marqueur constant `"[caviardé]"`.
  - La date, l'auteur, l'action, le nom des champs, les champs non personnels et les valeurs nulles restent inchangés. Un champ absent n'est pas ajouté.
  - Les champs d'identité sont les 11 de `CHAMPS_IDENTITE_CLIENT`, une seule liste partagée avec l'expurgation actuelle.
- **G-3 — Trace du caviardage.** Une entrée d'audit « caviardage » est écrite pour la fiche : qui, quand, combien d'entrées ont été caviardées. Elle ne contient aucune donnée personnelle.
- **G-4 — Périmètre : la fiche client seule** (décision de Maxime).
  - Seules les entrées de cette fiche `Client` sont touchées.
  - Les entrées d'un autre usager, d'une autre entité, NF525 (ventes, paiements, opérations scellées, clôtures) et les entrées de famille restent identiques octet pour octet.
- **G-5 — Rattrapage des fiches déjà anonymisées** (décision de Maxime).
  - Une commande console caviarde l'audit de toutes les fiches déjà au statut « anonymisé ».
  - Elle a un mode essai (`--dry-run`), qui compte les fiches et les entrées sans rien écrire, et une limite (`--limit`), comme `AppliquerConservationCommand`.
  - Elle est idempotente : un second passage ne caviarde plus rien et n'écrit pas de nouvelle trace.
  - Elle se lance une fois en préprod puis en prod, sous supervision.
- **G-6 — Lecture inchangée.** L'écran du journal d'audit et l'export montrent les entrées caviardées avec le marqueur. Aucune autre lecture n'est modifiée.
- **G-7 — Documentation.**
  - Dans le registre des traitements, le paragraphe T7 doit décrire la réalité : caviardage des entrées antérieures, et rattrapage.
  - **La validation du registre (D5) et la durée de conservation de T7 restent à Maxime.** Le lot n'y touche pas ; il ne modifie que la phrase factuelle.
  - Le mode opératoire du rattrapage va dans `infra/README.md`.

## Hors périmètre

- Le libellé de famille, les contacts de société (`CustomerContact`, non audité) et les autres entités liées (porte-monnaie, abonnements…). Ils relèvent du lot suivant, déjà annoncé au registre.
- La durée de conservation de l'audit (option B de D2) et la validation du registre (D5) : décisions de Maxime.
- Les journaux hors audit (courriels, journaux fichiers, exports déjà téléchargés).
- NF525.
- Le champ `auteur` d'une entrée, quand l'usager est aussi un utilisateur de l'établissement (cas du personnel, pas d'un client).

## Contraintes & décisions techniques connues

- **Requêtes.** Le caviardage cherche les entrées par `cibleType` et `cibleId`, avec des requêtes paramétrées. Le plan décide s'il faut un index sur `(cible_type, cible_id)`, au vu de la volumétrie (UNVERIFIED). Si oui, la migration est écrite à la main, avec le SQL demandé à Doctrine avant et le contrôle de dérive après.
- **Écriture sur `audit_entree`.** C'est une exception assumée à l'append-only, décidée en D2. Elle est confinée à un seul service, qui ne fait que remplacer des valeurs d'identité par le marqueur.
- **Nommage.** Les identifiants ajoutés sont en anglais (D5).
- **Zone sensible** (audit et RGPD) : relectures adversariales obligatoires.

## Points UNVERIFIED (bloquants pour CP-1)

- [x] Forme du caviardage : marqueur et trace (Maxime).
- [x] Périmètre : la fiche client seule (Maxime).
- [x] Rattrapage par une commande (Maxime).
- [ ] *(non bloquant pour CP-1, à lever au plan)* Les entrées écrites hors subscriber (`JournalAudit`, `SynchroOperationsProcessor`, `Autorisation`, `PersonalDataExportProvider`) contiennent-elles une donnée personnelle d'un client sous un autre `cibleType` ? Si oui, on le signale ; le périmètre ne bouge pas sans l'accord de Maxime.
- [ ] *(non bloquant)* Volumétrie d'`audit_entree` en préprod, pour décider de l'index et de la taille des lots du rattrapage.

## Critères d'acceptation

- **G-1 et G-4.**
  - Deux usagers U1 et U2, chacun avec une entrée « creation » et une entrée « modification ».
  - On anonymise U1 par chacune des trois voies (demande, purge, conservation).
  - Après coup, aucune entrée de U1 ne contient son ancien nom, prénom, email ou téléphone : on cherche les valeurs exactes dans le JSON.
  - Les entrées de U2, d'une autre entité et NF525 sont identiques à avant (comparaison des lignes).
  - Témoin positif : avant l'anonymisation, la recherche trouve bien les valeurs de U1.
- **G-1, transaction.** Si l'écriture du caviardage échoue (doublure), la fiche n'est pas anonymisée et l'audit est inchangé.
- **G-2.**
  - Les clés, la date, l'auteur, l'action et les champs non personnels sont inchangés.
  - Les valeurs d'identité non nulles valent `"[caviardé]"`.
  - Les nulles restent nulles, et aucune clé n'est ajoutée.
- **G-3.** Une entrée « caviardage » porte le nombre d'entrées traitées et aucune donnée personnelle.
- **G-5.**
  - `--dry-run` compte sans écrire : les lignes sont identiques avant et après.
  - Le passage réel caviarde.
  - Un second passage caviarde 0 entrée et n'écrit pas de nouvelle trace.
  - `--limit` est respecté.
- **G-6.** L'API du journal d'audit renvoie les entrées caviardées avec le marqueur.
- **G-7.** Le registre (T7) et `infra/README.md` sont à jour, sans toucher aux points réservés à Maxime.
