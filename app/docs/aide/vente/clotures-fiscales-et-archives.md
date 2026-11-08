---
titre: "Clore le mois et l'année, archiver l'exercice"
categorie: compta-regie
publicCible: agent
portee: global
moduleLie: vente
statut: publie
resume: "Clôtures mensuelle et annuelle scellées, puis archive annuelle des ventes et de la fréquentation, signée et vérifiable."
motsCles: [cloture mensuelle, cloture annuelle, archive, archivage, exercice, anti-fraude, TVA, NF525, frequentation, inalterabilite]
---
## À quoi ça sert

La loi anti-fraude TVA demande à un logiciel de caisse trois niveaux de clôture — **journalière,
mensuelle et annuelle** — dont les totaux se cumulent, puis une **archive** de l'exercice, figée et
conservée six ans. Fluvia arrête déjà chaque journée (la nuit, automatiquement, ou à la main) ; cet
écran ajoute le mois, l'année et l'archive.

Tout se trouve dans **Comptabilité / Régie › Clôtures fiscales & archives**.

## Clore un mois

1. Choisissez le **point de vente** et le **mois** (par défaut, le mois précédent).
2. Cliquez sur **Clore le mois**.

Le mois additionne les clôtures journalières de ses journées. Il est refusé, avec un message qui dit
quoi faire, dans quatre cas :

- le mois **n'est pas terminé** ;
- il est **déjà clos** (une clôture ne se refait pas) ;
- le **mois précédent** porte des journées arrêtées et n'est **pas clos** : clôturez-le d'abord ;
- une **journée de ventes n'est pas arrêtée** : arrêtez-la d'abord (le message donne la date).

Chaque clôture affiche trois totaux : le **total du mois**, le **cumul de l'exercice** (depuis le
1er janvier) et le **cumul perpétuel** (depuis la mise en service, jamais remis à zéro). Un badge
**écart** signale que la table des ventes ne dit plus ce que les journées ont scellé : prévenez le
support, c'est le signe qu'une vente a été touchée hors de l'application.

## Clore l'année

Même geste avec **Clore l'année** (par défaut, l'année précédente). L'année additionne ses mois : elle
est refusée tant qu'un mois porteur de ventes n'est pas clos, et tant que l'année n'est pas terminée.

## Archiver l'exercice (en début d'année)

Quand l'année est close **sur chaque point de vente** qui a vendu :

1. Saisissez l'**année** (par défaut, l'année précédente).
2. Cliquez sur **Archiver l'année**.

L'archive est un fichier ZIP qui contient, en CSV lisible par un tableur : les ventes, leurs lignes,
les règlements, les avoirs, le segment de la chaîne d'inaltérabilité, les clôtures journalières,
mensuelles et annuelle, et la **fréquentation** (chaque passage : entrée ou sortie, accepté ou refusé,
espace). Une notice en français (`LISEZMOI.txt`) en décrit le contenu. Les noms des clients et les
cartes d'accès n'y figurent pas.

L'archive est rangée à part des données actives : la vente n'en est pas ralentie, et les écrans
habituels continuent d'afficher l'historique. Sa fiche donne, mois par mois, le nombre de ventes, le
chiffre d'affaires et les entrées, sans ouvrir le fichier.

Une année ne s'archive qu'une fois.

## Vérifier une archive

Le bouton **Vérifier** refait cinq contrôles et les affiche : empreinte du fichier, signature,
fichiers conformes au manifeste, recalcul de la chaîne d'inaltérabilité, et égalité entre les ventes
archivées et la clôture annuelle. Une archive modifiée, même d'un octet, est déclarée **altérée** et le
contrôle en cause est nommé.

Le bouton **Télécharger** remet le fichier ZIP, à conserver six ans et à présenter en cas de contrôle.

## Qui peut faire quoi

- Clore un mois ou une année, archiver et télécharger une archive : droit **vente.fiscal_closing**.
- Voir les clôtures et les archives, et vérifier : droit **vente.lire**.

Le premier n'est donné par défaut qu'à l'administrateur ; c'est lui qui le délègue, rôle par rôle.
