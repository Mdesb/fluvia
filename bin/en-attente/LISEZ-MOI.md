# Deux garde-fous prêts, garés ici, et pourquoi

Ces deux fichiers sont **finis et vérifiés**. Ils ne sont pas dans `bin/` parce qu'ils **ne peuvent
pas y être** tant que `220a571` n'est pas fusionné dans `main`.

## Le blocage

Le filet de complétude de `hooks/pre-commit` compare l'inventaire de l'**arbre** — tout
`bin/garde-fou-*.php` présent sur le disque, suivi ou non — à ce qu'a lancé le hook **installé**,
lequel vient de `main`. Un garde-fou neuf est donc refusé par le filet même censé garantir qu'il
sera lancé, et il ne peut pas entrer dans `main` puisqu'il ne peut pas être committé.

`220a571` corrige exactement ça, sans rien desserrer de réel : un garde-fou câblé nulle part reste
refusé, vérifié avec un témoin non câblé, seul à ressortir en échec.

## Pourquoi ici plutôt que dans `/tmp`

`/tmp` est partagé entre les sessions et se nettoie — `TASKS.md` §3 quinquies le signale, une session
y a déjà écrasé le fichier d'une autre.

Le sous-dossier ne correspond pas au motif `bin/garde-fou-*.php` que le filet parcourt. Ces fichiers
ne sont donc **pas** des garde-fous actifs, et c'est exact : ils ne s'exécutent nulle part pour
l'instant. Ce n'est pas un contournement du filet, c'est l'aveu écrit qu'ils attendent.

## La remise en place, une fois `220a571` dans `main`

```
git fetch origin && git merge --no-edit origin/main
bash bin/reinstaller-hooks.sh
git mv bin/en-attente/garde-fou-unique-entity.php bin/
git mv bin/en-attente/garde-fou-validation-avant-processeur.php bin/
rmdir bin/en-attente
```

Le câblage des deux — `bin/garde-fous.sh`, `hooks/pre-commit`, `hooks/pre-receive` — est **déjà
écrit** et attend dans la même branche. Trois listes, pas deux : le filet m'a repris là-dessus.

## Ce que chacun fait

**n°40 — `garde-fou-unique-entity.php`.** Une contrainte `UniqueEntity` sur un champ fermé à
l'écriture ne tombe pas : elle *disparaît*. Elle porte sur `null`, ne trouve aucun doublon parmi les
entités sans valeur, et laisse passer. Aucun test ne rougit. Naît à zéro défaut — 5 champs cités par
4 entités, tous écrivables — donc il interdit seulement le retour.

**n°34 — `garde-fou-validation-avant-processeur.php`.** Une propriété non exposée en écriture, dont
le défaut satisfait sa contrainte, et qu'écrit son processeur : la contrainte valide le défaut, passe
toujours, et la valeur réellement enregistrée ne traverse rien. Elle ressemble à une protection et
n'en est pas une. Cliquet à 4, ligne de base déjà committée
(`bin/validation-avant-processeur.ligne-de-base.json`).

Ses trois témoins sont intégrés : il **refuse de parler** s'il ne se comporte pas correctement sur
eux. C'est ce qui manquait à la version d'origine, qui ratait son propre témoin positif sans le dire.
