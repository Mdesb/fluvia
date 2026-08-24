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
| **claude-H** | Publication sociale | `app/src/Social/**` | SOC-0 à SOC-3 — modèle de publication, coffre à jetons chiffré, adaptateurs de réseaux **ouverts** (Mastodon, Bluesky), collecte de statistiques. SOC-4 (Meta) est `EXTERNE`, n'y touche pas. |
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

## Les trois règles qui ne se négocient pas

1. **Un fichier n'a qu'un auteur.** Tu écris dans `RAPPORTS/<toi>.md` et dans ton code. Jamais
   dans `ORDRES/`, jamais dans le rapport d'un autre. C'est ce qui supprime les conflits de fusion.
2. **Tu ne sors pas de ton périmètre**, même si un pair te le demande. Tu signales, et tu passes à
   la suite. Seul Maxime déplace un périmètre.
3. **Tu pousses au moins une fois par heure** (D25), et tu bats toutes les quinze minutes (D29).
   Un travail non poussé n'existe pas ; une instance silencieuse est indiscernable d'une instance
   morte.

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
