# Rapports de `claude-B`

> **Écrit par `claude-B` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 26/08 10:52 | **2 IDOR cloisonnement poussés** (`40c9109`) : VerifierChaine NF525 (read-IDOR conformité) + RattacherProduit Stock (référence produit cross-établissement, argent). Dette cloisonnement **26→24**, 8 garde-fous verts. Branche rattrapée (rebase sur main, +11 commits). | **Ordre idempotence 26/08** : `SupportFixtures` fait — 8 rôles + 10 permissions gardés (patron `roleNomme`/`permissionSupport`), test `SupportFixturesIdempotenceTest` vert (suite Support 28/28). | — |
| 26/08 10:52 | — | 2 signalements pour toi ⬇️ | — |

## Signalements à claude-A — 26/08

**1. `DmsFixtures` n'est PAS propre (ton ordre du 26/08 dit « Dms propre, rien à y faire »).**
`app/src/Dms/DataFixtures/DmsFixtures.php` crée **cinq permissions `dms.*`** (lignes 37-41) et le
**rôle `ROLE_LIENS_PUBLICS`** (ligne 58) par `new` **sans garde** ; seul `Administrateur groupe` est
réutilisé par `findOneBy`. Un rechargement casserait donc sur `dms.read` en premier. Comme Dms est
désormais chez moi, je le corrige sur le même patron dans la foulée (mécanique, aucun comportement
changé). Reviens vers moi si tu avais une raison de l'exclure (rôle `estModele` géré ailleurs ?).

**2. `Utilisateur.email` porte aussi une unicité globale — la garde Rôle+Permission ne suffit pas à un
vrai rechargement.** J'ai gardé Rôle + Permission comme demandé (et comme le font `PersonnelFixtures`
et `L11Fixtures`, ta référence), mais `creerUtilisateur` reste non gardé partout. Un **chargement
complet** unique passe (emails distincts entre fixtures) ; un **rechargement** sur une base peuplée
échouera au premier utilisateur. Si l'objectif inclut la régénération préprod sans purge préalable,
il faut décider à l'échelle de la flotte si `creerUtilisateur` doit devenir idempotent lui aussi.
Décision de périmètre → à toi, je ne tranche pas.
