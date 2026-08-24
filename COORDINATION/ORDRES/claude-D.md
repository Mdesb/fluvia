# Ordres pour `claude-D`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais — c'est ce qui garantit
> qu'il n'y a jamais de conflit de fusion dessus.

---

## 2026-08-24 12:40 · Premier ordre — l'administration de Maxime

Tu construis **l'outil de Maxime lui-même** : celui avec lequel il vend, facture et pilote son
activité d'éditeur. C'est la seule session dont le client final est lui, et c'est D12 qui le veut
ainsi — son administration **vit dans la plateforme**, pas à côté, parce que c'est comme cela qu'il
voit les défauts de son produit avant ses clients.

### Commence par établir l'état réel, il ne correspond pas au carnet

**Avant d'écrire une ligne**, vérifie ceci — je l'ai constaté ce matin et le carnet ment :

- `app/src/Editeur/` **n'existe pas**, alors que ED-1 est marquée `DONE` sur ce chemin ;
- `app/src/Subscription/` **existe** et contient `Entity`, `Enum`, `Exception`, `Service` — le
  catalogue d'offres et le cycle de vie d'abonnement y sont déjà, avec leurs tests ;
- `specs/editeur/spec-editeur.md` existe et fait foi sur le périmètre.

Autrement dit : **le travail d'ED-1 a atterri dans `Subscription`, pas dans `Editeur`.** Ne le
reconstruis pas. Ton premier commit est un rapport : ce qui existe, ce qui manque, et ce que tu
proposes — un module `Editeur` distinct, ou l'extension de `Subscription`. **Tranche-le et
argumente**, je ne te l'impose pas.

### Puis ED-3, le tunnel de souscription

C'est la pièce qui manque et qui rend l'ensemble vendable : un prospect choisit sa formule, ajoute ses
modules à la carte, paie **en prélèvement SEPA** (D10 — pas de carte au lancement), et **son compte
administrateur est provisionné automatiquement**. Le provisionnement doit être **idempotent** : un
rappel bancaire rejoué ne doit jamais créer deux établissements.

Trois décisions déjà prises que tu ne rouvres pas : **D10** SEPA d'abord, **D11** la démo est un bac à
sable jetable mais le paramétrage est repris, **D12** l'administration vit dans la plateforme.

### Le site vitrine vient après, pas avant

Maxime l'a dit lui-même : « le site vitrine on verra plus tard ». Ne l'ouvre pas tant qu'ED-3 n'est
pas livrée — un tunnel sans page d'entrée se teste, une page d'entrée sans tunnel ne sert à rien.

### Ce que je te demande de me signaler tout de suite

Si la spec `specs/editeur/spec-editeur.md` laisse des décisions ouvertes, **ne les tranche pas seul** :
liste-les dans ton rapport, je réponds au battement suivant. C'est le défaut que j'ai le plus répété
cette semaine et je ne veux pas le reproduire avec toi.
