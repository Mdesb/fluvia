# Plan technique — Module SEPA partagé (pain.008 bi-régime)

- **Objectif :** extraire le moteur de prélèvement SEPA de `App\Sport` vers un **module partagé `App\Sepa`**, réutilisable par la **piscine en régie**, le **sport privé** et les futures verticales ; y implémenter la **génération pain.008.001.02 réelle**, pilotée par le régime.
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB.
- **Références de format (anonymisées, dans le repo) :** `specs/sepa/exemples/pain008-regie-FRST.xml`, `specs/sepa/exemples/pain008-prive-RCUR.xml`. (Les vrais fichiers du client ne sont PAS versionnés.)

## 1. Constat bi-régime (issu des 2 fichiers réels du client)
Un seul format (pain.008.001.02), **une seule différence structurante = le bloc créancier** :
- **Régie / collectivité** : `Cdtr` = la **collectivité** ; **`UltmtCdtr`** présent = la **régie** (avec `OrgId/Othr/Id`). `AmdmntInd` présent.
- **Privé** : `Cdtr` = l'**entité** elle-même ; **pas de `UltmtCdtr`**. `AmdmntInd` absent.
- Commun : `GrpHdr`(MsgId/CreDtTm/NbOfTxs/CtrlSum), `PmtInf`(PmtMtd=DD, SvcLvl=SEPA, LclInstrm=CORE, **SeqTp**), `CdtrAcct/IBAN`, `CdtrAgt/BIC`, `CdtrSchmeId`=**ICS**, puis par transaction `PmtId`(InstrId/EndToEndId), `InstdAmt`, `MndtRltdInf`(MndtId/DtOfSgntr), `DbtrAgt/BIC`, `Dbtr/Nm`, `DbtrAcct/IBAN`, `RmtInf/Ustrd`.

→ La variante se pilote via le **régime de l'établissement** (L4 `ProfilExploitant.type` : régie ⇒ avec UltmtCdtr ; DSP/privé ⇒ sans).

## 2. Entités `App\Sepa\Entity\*`
- **`ConfigCreancierSepa`** (1 par établissement) : `variante` (enum `REGIE`/`PRIVE`, dérivée du `ProfilExploitant` mais surchargeable), `ics` (ex. FR..ZZZ..), `creancierNom`, `creancierIban` (masqué en lecture : 4 derniers seulement), `creancierBic`. En régie : `collectiviteNom` (= `Cdtr`), `ultimateCreancierNom` + `ultimateCreancierOrgId`. Config saisie une fois par site.
- **`MandatSepa`** (générique, remplace `App\Sport\Entity\MandatSepaFitness`) : `rum` (=MndtId), `dateSignature`, `ibanToken` (**jamais de `#[Groups]`**), `iban4Derniers`, `bicDebiteur`, `debiteurNom`, `sequenceCourante` (enum FRST/RCUR/FNAL/OOFF), `statut`, `nbCollectesReussies`, rattachement `Client` (L5) + établissement.
- **`RemiseSepa`** (générique) : `messageId`, `dateCreation`, `dateCollecte`, `seqTp` (une remise = une séquence), `nbTxs`, `ctrlSum`, `statut`, contenu XML généré (stocké ou régénéré), établissement.
- **`LigneRemiseSepa`** : lien remise ↔ (mandat + montant + endToEndId + libellé/RmtInf + échéance d'origine).
- **`RejetSepa`** (retour) : `endToEndId`/`mndtId`, `codeMotif` (code R), `dateRejet`, rattaché à la ligne. Alimenté par un port (pas de parser réel pour l'instant).

## 3. Services
- **`SeqTpResolver`** : pour un mandat + une échéance → `FRST` si `nbCollectesReussies == 0` ; sinon `RCUR` ; `FNAL` si l'échéance est la **dernière d'un engagement à durée déterminée** (info fournie par la verticale appelante via un petit contrat, ex. `EcheanceSepaSource::estDerniereEcheance()`). `OOFF` si paiement unique.
- **`Pain008Generator`** : produit le XML pain.008.001.02. **Régime-aware** : injecte `UltmtCdtr` + `AmdmntInd` en variante `REGIE`, les omet en `PRIVE`. Groupe les lignes en **un `PmtInf` par `SeqTp`**. Calcule `NbOfTxs`/`CtrlSum` (somme exacte, 2 décimales). Namespace/encodage conformes aux échantillons. **Sortie testée** en comparant la structure aux fichiers `specs/sepa/exemples/*`.
- **`GenerationRemiseHandler`** : à partir d'un lot d'échéances dues (fournies par la verticale via un port), crée `RemiseSepa`+lignes, résout les `SeqTp`, appelle `Pain008Generator`, puis `CollecteurSepaInterface`.

## 4. Ports (adaptateurs enfichables)
- **`CollecteurSepaInterface`** (transmission de la remise à la banque) + `CollecteurSepaStubAdapter` (dépose/journalise, pas d'EBICS/SFTP réel). — reprend le stub existant de Sport.
- **`RetourSepaInterface`** (ingestion des rejets) + `RetourSepaStubAdapter` : **pas de parser pain.002 réel** (le client n'a pas de fichier retour). Interface prête ; une saisie/simulation manuelle depuis le tableau de bord alimente les `RejetSepa` en attendant.
- **`TokenisationIbanInterface`** (HMAC applicatif, non-PCI) — déplacé depuis Sport.

## 5. Refactor de `App\Sport` (recâblage, sans perte de fonctionnalité)
- Remplacer `MandatSepaFitness` par `App\Sepa\Entity\MandatSepa` (migration de renommage/déplacement, garder les données de fixtures). `RemiseSepa`/`RejetPrelevement`/collecteur/tokenisation Sport → utiliser le module partagé.
- Le **moteur anti-impayés** (`MoteurAntiImpayesHandler`) et le couplage accès (`PropagationAccesFitnessHandler`) **restent dans Sport** — ils consomment le module SEPA partagé.
- Sport fournit ses échéances au module via l'implémentation d'un port source (`EcheanceSepaSource`), en indiquant les dernières échéances d'engagement (pour `FNAL`).
- **Piscine (régie)** : pas de recâblage obligatoire dans ce lot ; juste vérifier que le module est utilisable côté régie (une remise régie de démo dans les fixtures/tests). Le branchement des abonnements piscine sur SEPA sera un lot ultérieur.

## 6. API Platform
- `ConfigCreancierSepa` : GET/PUT (droit `sepa.configurer` ou réutiliser `compta.gerer`). IBAN créancier masqué en lecture.
- `MandatSepa` : GET (masqué), création via processor (IBAN en entrée → tokenisé). Jamais d'IBAN complet en réponse.
- `RemiseSepa` : opération `POST /sepa/remises/generer` (à partir des échéances dues), `GET /sepa/remises/{id}/pain008` (télécharge le XML), `GET` liste. `security:` via PermissionVoter.
- `RejetSepa` : `POST /sepa/rejets` (saisie/simulation manuelle en attendant le retour réel).

## 7. Migrations
- Schéma `sepa_*` (config créancier, mandat, remise, ligne, rejet) ; migration de **déplacement** des données `sport_mandat_*` → `sepa_mandat` ; permissions `sepa.*`.

## 8. Tests (`tests/Sepa`)
- pain.008 **régie** : contient `UltmtCdtr` + `AmdmntInd`, `Cdtr`=collectivité.
- pain.008 **privé** : **pas** de `UltmtCdtr`, `Cdtr`=entité.
- `SeqTpResolver` : FRST (0 collecte) / RCUR (≥1) / FNAL (dernière échéance d'engagement).
- `NbOfTxs`/`CtrlSum` exacts ; groupement par `SeqTp` (une remise mixte produit plusieurs `PmtInf`).
- **IBAN jamais exposé** (mandat + config créancier), en API et en base sérialisée.
- Non-régression Sport (le recâblage ne casse aucun test Sport) — vérifier PAR LOTS (OOM).

## 9. Risques / hors périmètre
- Remise bancaire réelle (EBICS/SFTP), coffre IBAN PCI-DSS, PSP CB : hors périmètre (derrière les ports).
- Parser pain.002/CAMT.054 réel : **non fait** (pas de fichier retour client) — port prêt.
- Validation XSD stricte pain.008.001.02 : viser la conformité structurelle aux échantillons ; une validation XSD complète peut être ajoutée si le schéma officiel est fourni.
