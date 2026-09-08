# Spec — referentiel-metiers

**Statut :** en revue (CP-1 demandé)
**Auteur :** session vitrine
**Date :** 2026-09-07

## Contexte & problème

Maxime, le 06/09 : « **il y a plus de métiers que ça et la liste va continuer de s'allonger** ».

Le produit connaît aujourd'hui cinq métiers, figés dans une énumération PHP. Ajouter le sixième
demande de toucher **neuf endroits** répartis entre le serveur, l'application React et les tests — et
**sept de ces neuf oublis n'émettent aucun signal** (faits VERIFIED, voir `refs/`) :

- sans case dans l'énumération, la structure du client **s'ouvre avec zéro module**, sans erreur ;
- sans préréglage, il reçoit 2 capacités sur 15, et **le test censé le voir reste vert** ;
- sans nom, sa page de vente sort avec un `<title>` vide et répond 200.

Ce n'est pas une hypothèse : **deux dérives sont déjà installées**. La table qui devine le métier
depuis le SIRET ne couvre que deux métiers sur cinq. Et l'écran « Ouvrir une structure » de
l'application, qui recopie à la main ce que chaque métier active, **se trompe sur deux métiers sur
cinq** — il promet au padel une location qu'il n'aura pas, et tait à la patinoire la réservation et
les casiers qu'elle aura.

La cause est unique : la liste vit dans du code, à plusieurs endroits, sans rien qui les confronte.

Une décision déjà arbitrée dit quoi faire — **D15** : neuf types d'activité couvrent tous les
métiers, et « une verticale devient un paquet rédigé, pas un module développé ». `specs/verticales/`
a déjà écrit la composition d'activités des cinq métiers existants : **elles ne sont pas à inventer**.

## Objectifs (Goals)

- **G-1 — Un métier devient une ligne de données, pas une ligne de code.** Un référentiel en base
  porte, pour chaque métier : son identifiant d'URL, son nom, son titre de recherche, son chapô, son
  rang d'affichage et son état publié.

- **G-2 — Chaque métier porte ses activités**, choisies parmi les neuf types de D15, et **rien
  d'autre** : un métier qui réclamerait un dixième type est un signalement, pas une extension.

- **G-3 — Les modules suggérés se déduisent des activités**, pas d'une liste recopiée par métier.
  C'est ce qui alimentera l'étape 3 du tunnel, et c'est ce qui empêche la colonne « ce que ça
  allume » de mentir : elle n'est plus saisie, elle est calculée.

- **G-4 — Le site lit le référentiel.** Les pages `/metiers`, `/metiers/<slug>`, le plan du site et
  les données structurées viennent des lignes, plus des constantes. Les cinq URL existantes sont
  conservées à l'identique.

- **G-5 — Ajouter un métier ne demande aucun déploiement.** Le geste complet se fait depuis
  l'administration : une ligne, ses activités, son texte, publier.

- **G-6 — Un garde-fou interdit la divergence.** Il refuse la fusion si un métier de l'énumération
  n'a pas sa ligne, ou l'inverse. C'est ce qui permet de garder l'énumération pour l'application
  **sans recréer une seconde liste** : on en crée une qui empêche l'autre de dériver.

- **G-7 — Aucune régression silencieuse.** Un métier sans nom, sans activité ou sans page ne doit
  plus pouvoir passer inaperçu : ce que le référentiel ne peut pas garantir par sa structure, un
  test le refuse.

## Hors périmètre

- **L'énumération `Metier` et `PresetVerticale` ne sont pas supprimées.** Arbitrage de Maxime du
  07/09 : le référentiel sert le site et le tunnel, l'application garde son énumération, et le
  garde-fou (G-6) interdit qu'elles divergent. La migration de l'application est un **second
  chantier**, dans un autre périmètre.
- **L'écran « Ouvrir une structure » n'est pas corrigé ici**, bien que sa dérive soit mesurée et
  documentée dans `refs/`. Il appartient au frontal. Il sera signalé par une Issue.
- **Les étapes 1, 3 et 4 du tunnel** ne sont pas construites ici. Ce chantier livre ce dont
  l'étape 2 a besoin ; le tunnel lui-même suivra.
- **L'écran d'administration du référentiel** (React) n'est pas dans ce lot : ce lot livre le
  modèle, la lecture par le site, et les opérations d'API. L'écran suit, une fois le modèle stable.
- **Aucune donnée fabriquée par une migration** (D66-ter). Les cinq métiers existants sont
  matérialisés par une **commande idempotente**, pas par la migration.

## Parcours utilisateur / UX

**Côté exploitant du site (l'administration).** Ajouter « Bowling » : nom, identifiant d'URL proposé
depuis le nom, titre de recherche, chapô, cocher les activités (« réservation de ressource »,
« location de matériel », « vente de produits »), écrire le corps de page, publier. La page
`/metiers/bowling` existe alors, entre au plan du site et aux données structurées.

**Côté visiteur.** Rien ne change dans l'immédiat sur les pages existantes — c'est voulu : ce lot
remplace la source sans toucher au rendu. Les cinq pages doivent rester **identiques**.

**État vide.** Tant qu'aucune ligne n'existe, le site sert les cinq métiers actuels depuis les
constantes. Le basculement se fait par la commande, pas par un déploiement.

## Contraintes & décisions techniques connues

- **D15** fixe les neuf types d'activité. **`specs/verticales/paquet.md` en orthographie quatre** :
  `entry`, `resource_booking`, `coaching`, `equipment_rental`. La spec propose pour les cinq autres :
  `membership`, `product_sale`, `appointment`, `lodging`, `dining`. ⚠ `membership` plutôt que
  `subscription` : `App\Subscription` désigne déjà l'abonnement de l'ÉDITEUR à Fluvia, pas celui de
  l'adhérent au club. Réutiliser le mot créerait une ambiguïté au cœur du modèle.
- **Nommage anglais** pour les fichiers neufs (D5).
- **Migration écrite à la main**, jamais un `migrations:diff` brut ; vérifier l'absence de dérive
  après (`doctrine:schema:update --dump-sql`), le dépôt tolère déjà 108 lignes d'écart et il ne faut
  pas en ajouter.
- **Cloisonnement** : le référentiel est un catalogue de l'éditeur, **pas une donnée
  d'établissement**. Il n'est donc pas cloisonné par établissement — et ce choix doit être écrit,
  parce qu'il est l'exception, pas la règle.
- **Le corps rédigé des pages métier est déjà en base** (`SiteBlocks`, bloc `metier.<code>.body`) :
  ne pas le dupliquer dans le référentiel.
- **Les cinq URL existantes** (`/metiers/piscine`, `sport`, `padel`, `patinoire`, `musee`) sont
  indexables et référencées : elles ne changent pas.

## Points UNVERIFIED (bloquants pour CP-1)

- [ ] **L'orthographe anglaise des cinq types d'activité non fixés** appartient à
      `specs/verticales/**`, qui n'est pas mon périmètre. Je propose les noms ci-dessus et j'ouvre
      une Issue pour que le propriétaire confirme. **Non bloquant** si vous acceptez que ce soit
      une proposition révisable tant qu'aucun paquet n'est publié.

## Critères d'acceptation

- **G-1** — Une ligne de référentiel existe pour chacun des cinq métiers actuels, créée par la
  commande, et la relancer deux fois ne crée aucun doublon.
- **G-2** — Une activité hors des neuf types est refusée à l'écriture, avec un message qui nomme
  les valeurs admises.
- **G-3** — Pour chacun des cinq métiers, les modules déduits des activités sont **comparés au
  préréglage existant**, et l'écart est affiché. ⚠ Un écart n'est pas forcément un défaut du calcul :
  il peut révéler que le préréglage a dérivé. Le critère est que l'écart soit **connu et justifié**,
  pas qu'il soit nul.
- **G-4** — Les cinq pages métier, le plan du site et les données structurées rendent **exactement**
  le même contenu qu'avant le lot, comparé octet à octet sur la page servie.
- **G-5** — Une sixième ligne créée en base fait apparaître `/metiers/<slug>` avec son titre, son
  chapô, son entrée au plan du site et ses données structurées, **sans déploiement**.
- **G-6** — Le garde-fou tombe quand on ajoute un métier à l'énumération sans sa ligne, et quand on
  ajoute une ligne sans son case. Les deux sens sont éprouvés en cassant volontairement.
- **G-7** — `WebsiteMetiersTest` cesse de promettre plus qu'il ne mesure : il vérifie l'exhaustivité
  du référentiel, pas cinq présences.
