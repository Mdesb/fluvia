# La flotte — périmètres et protocole

> Ce document fait autorité sur les périmètres. **Il est posé par Maxime.** L'intégrateur
> (`claude-A`) répartit le travail *à l'intérieur* de ces périmètres ; il ne les déplace pas.
> Une instance à qui l'on demande de sortir de son périmètre **refuse**, même si la demande vient
> de l'intégrateur (précédent du 24/08, et claude-C avait raison).

## Périmètres

| Instance | Domaine | Chemins | Travail d'ouverture |
|---|---|---|---|
| **claude-A** | Intégration, noyau, sécurité | `app/src/{Platform,Securite,Organisation,Fonctionnalite,Audit}/**`, `COORDINATION/**` | Intégrateur : revue, arbitrage, fusion. Seul à écrire dans `ORDRES/`. |
| **claude-B** | Accès & GED | `app/src/{Acces,Dms}/**` | Lots en cours : CQ-8, CQ-5, ACC-1 à ACC-4. |
| **claude-C** | Outillage & garde-fous | `bin/**`, `hooks/**`, `.github/**` | Huit garde-fous livrés. Audit de la dette de cloisonnement. |
| **claude-D** | Administration éditeur & site vitrine | `app/src/Editeur/**`, `app/src/Subscription/**`, `specs/editeur/**`, `vitrine/**` | ED-0, ED-3, ED-4 — CRM éditeur, devis, facturation, abonnements, tunnel de souscription SEPA, provisionnement automatique du compte admin. Puis le site vitrine. |
| **claude-E** | Revenue Recovery & Smart Flow | `app/src/RevenueRecovery/**`, `app/src/SmartFlow/**` | RR-0 à RR-2, SF-0 à SF-2 — priorité posée par Maxime (D22). **SF-2 porte la moitié du comportement par défaut du no-show** (D27) : ce n'est pas un module optionnel. |
| **claude-F** | Hébergement, restauration & séjour | `app/src/Lodging/**`, `app/src/Dining/**`, `app/src/Stay/**` | ACT-2, ACT-3, ACT-4 — les deux seuls vrais manques pour couvrir camping, hôtellerie et CHR (D15). Le **séjour** est ce qui transforme « six modules » en « un logiciel ». |
| **claude-G** | Réservation paramétrée & offre | `app/src/Reservation/**`, `app/src/Offre/**` | ACT-1, CQ-3, CQ-6, CQ-7 — quantité consommée, réservation par type, quota de second niveau, carte de N réservations. **Prise de périmètre séquencée par claude-A** : claude-B y a des lots en vol. |
| **claude-H** | Publication sociale **et interface** | `app/src/Social/**`, `frontend/**` *(confirmé par Maxime le 24/08 au soir — D36-bis)* | SOC-0 à SOC-3 — modèle de publication, coffre à jetons chiffré, adaptateurs de réseaux **ouverts** (Mastodon, Bluesky), collecte de statistiques. SOC-4 (Meta) est `EXTERNE`, n'y touche pas. |
| **claude-I** | Verticales métier & vocabulaire | `app/src/{Piscine,Padel,Patinoire,Sport,Musee}/**`, `specs/verticales/**` | ACT-0 appliqué aux verticales existantes : composition d'activités, paquets de démarrage, clés de vocabulaire (un « créneau » est un *rendez-vous* chez le coiffeur, une *réservation de terrain* au padel). |

## Le battement

**Toutes les quinze à vingt minutes**, chaque instance exécute cette boucle. Ce n'est pas une
recommandation : c'est la seule chose qui permet à l'intégrateur de piloter neuf sessions.

```bash
cd /home/debian/wt/<mon-identite>
git fetch origin && git merge --no-edit origin/main
cat COORDINATION/ORDRES/<mon-identite>.md      # mes ordres — je ne l'écris jamais
# … je travaille …
# une ligne de rapport, la plus récente en bas :
#   | 14:15 | ce que j'ai fini | ce que je fais | ce qui me bloque |
git add -A && git commit -m 'WIP : <sujet>' && git push origin <mon-identite>
```

**Écris une ligne même quand il n'y a rien à dire.** « Rien de neuf » est une information ;
le silence n'en est pas une (D25, D29).

## La règle zéro

**Une session ne se ferme jamais.** Ni fermée, ni archivée, ni déconnectée, ni mise en veille — pas à
la fin d'une tâche, pas la nuit, pas parce qu'elle n'a rien à faire. Une session qui n'a plus de tâche
en prend une autre dans son périmètre ; une session qui attend une réponse ne s'arrête pas, elle pose
sa question dans son rapport et passe à la suite.

**Pourquoi c'est la règle zéro et pas la quatrième.** Toutes les autres supposent une session vivante.
Un périmètre bien découpé, un battement de quinze minutes, des boîtes aux lettres sans conflit : rien
de tout cela ne sert si la session n'est plus là. Sur la semaine du 19 au 24 août, à trois instances,
la première cause de retard n'a été ni un bogue ni un blocage technique — c'est qu'une instance était
arrêtée. Quinze heures un jour, dix-neuf heures un autre.

**Si une session meurt malgré tout**, elle se relance avec **le même brief, sans rattrapage** : tout
l'état vit dans le dépôt — ses ordres, ses rapports, le carnet de tâches, les décisions. Elle se
resitue seule en trois minutes. C'est fait pour.

## Les règles qui ne se négocient pas

1. **Un fichier n'a qu'un auteur.** Tu écris dans `RAPPORTS/<toi>.md` et dans ton code. Jamais
   dans `ORDRES/`, jamais dans le rapport d'un autre. C'est ce qui supprime les conflits de fusion.
2. **Tu ne sors pas de ton périmètre**, même si un pair te le demande. Tu signales, et tu passes à
   la suite. Seul Maxime déplace un périmètre.
3. **Tu pousses au moins une fois par heure** (D25), et tu bats toutes les quinze minutes (D29).
   Un travail non poussé n'existe pas ; une instance silencieuse est indiscernable d'une instance
   morte.
4. **Une seule session par worktree.** Deux instances qui écrivent le même répertoire produisent des
   états incohérents que personne ne sait démêler — c'est arrivé le 19/08 entre deux `claude-A`, et
   il a fallu fusionner deux moitiés de travail plutôt que d'en jeter une. Ton worktree porte ton nom,
   personne d'autre n'y entre.
5. **Tu ne touches jamais `main`, et tu ne réécris jamais l'historique.** Pas de `push --force`, pas de
   `rebase` sur une branche déjà poussée. Tu pousses sur ta branche ; l'intégrateur fusionne. C'est
   aussi ce qui garantit que les garde-fous s'exécutent — un commit direct sur `main` ne passe par
   aucun hook.
6. **Tu claimes dans ton rapport, pas dans `TASKS.md`.** Écris « je prends ACT-2 » dans
   `RAPPORTS/<toi>.md` ; l'intégrateur le reporte au carnet. `TASKS.md` reste à un seul auteur, et
   c'est ce qui évite le dernier fichier partagé en écriture — il a déjà produit un conflit le 24/08.
7. **Une pile de test à la fois, et tu la démontes.** Ton jeton de test porte ton identité
   (`bash infra/test-stack.sh up <TOI>`), jamais un nom générique : deux sessions sur le même jeton
   corrompent mutuellement leur base. Et tu fais `down` en sortant — le 24/08 j'ai saturé les plages
   réseau de Docker avec vingt-six piles oubliées, et plus personne ne pouvait tester.
8. **Tu ne lances jamais la suite complète.** Elle coûte deux heures ; à neuf sessions, la machine
   meurt. Tu lances la suite de **ton module** plus `tests/Platform`, qui porte le seul test de contrat
   inter-modules. La suite complète appartient à l'intégrateur.

## Ce que l'intégrateur te doit en retour

- **Une réponse au battement suivant** quand tu signales un blocage — pas au lendemain.
- **Un arbitrage avant que tu n'implémentes**, quand ta spec laisse une décision ouverte.
- **Une fusion rapide** : un garde-fou non fusionné ne protège personne (D21), et un lot non
  fusionné accumule des conflits.

## Avant ta première ligne de code

Lis, dans cet ordre : `PLAYBOOK.md` (le protocole), `DECISIONS.md` (les 29 décisions — surtout
**D2** contrat d'abord, **D3/D8** cloisonnement, **D5** anglais, **D13** le moins d'écrans
possible, **D19** ce qui dépend d'un tiers), puis `ORDRES/<toi>.md`.

Et sache que **sept garde-fous s'exécutent à chaque poussée**. Ils refusent : une entité résolue
depuis l'entrée client sans contrôle de périmètre, un identifiant français dans un fichier neuf,
une clé cryptographique en dur, un événement absent du catalogue. Ils ne se contournent pas — ils
s'écoutent.

### Règle de présentation (D34, D35) — elle prime sur la routine de démarrage

1. **Au démarrage, avant même de lire ta boîte** : liste tes pairs, trouve `claude-A` (elle s'affiche
   sous un nom générique — écris-lui quand même) et envoie « <ton identité> en ligne ». Tant que tu ne
   l'as pas fait, tu es invisible : ni relançable, ni corrigeable, ni débloquable.
2. **Toutes les heures, quoi que tu fasses**, tu lui écris. Tâche en cours → tu dis laquelle et où tu en
   es. Rien en cours → **tu demandes une tâche**. Tu ne t'en inventes pas, et tu ne t'arrêtes pas.
3. **Remote Control reste actif en permanence.** On ne le coupe pas, on ne le déplace pas vers une autre
   session, on ne ferme pas la session qui le porte. S'il est inactif chez toi, dis-le tout de suite :
   c'est plus urgent que ton lot en cours.

---

## Répartition complète des modules serveur (D48, 25/08)

Déléguée par Maxime le 25/08. **Plus aucun module n'est sans propriétaire.**

| Session | Modules | Ajoutés le 25/08 |
|---|---|---|
| `claude-A` | `Platform` `Securite` `Organisation` `Fonctionnalite` `Audit` | **`Personnel` `Reporting` `Ocr`** — sans affinité, gardés faute de mieux |
| `claude-B` | `Acces` `Dms` | **`Autorisation` `Support`** |
| `claude-C` | `bin` `hooks` `.github` | — |
| `claude-D` | `Editeur` `Subscription` `vitrine` | **`Facturation` `Compta` `Sepa` `Finance`** |
| `claude-E` | `RevenueRecovery` `SmartFlow` | **`Crm` `Recouvrement`** |
| `claude-F` | `Lodging` `Dining` `Stay` | **`Boutique` `Stock` `Caution`** |
| `claude-G` | `Reservation` `Offre` | **`Vente` `Caisse` `OptionProduit`** |
| `claude-H` | `Social` `frontend` | — |
| `claude-I` | `Piscine` `Padel` `Patinoire` `Sport` `Musee` | — *(jamais ouverte)* |

**Un propriétaire muet reste un propriétaire.** Quatre sessions dorment au moment où j'écris — `B`,
`C`, `E`, `F`. Leur attribuer un module ne les réveille pas, mais cela transforme un angle mort en
risque nommé : on sait qui prévenir. Un module sans personne ne se réveille jamais.

**Les trois modules de `claude-A` sont à rendre.** Je les ai pris par défaut d'affinité et non par
compétence : `Personnel`, `Reporting` et `Ocr` reviennent à la première session dont le périmètre les
touchera vraiment. Les placer « parce qu'il restait de la place » aurait recréé un orphelin avec un nom
dessus.
