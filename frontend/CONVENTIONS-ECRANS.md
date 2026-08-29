# Conventions d'écran — le contrat que tous les écrans respectent

Écrit le 28/08/2026 par `claude-A`, sur demande de Maxime : « standardise les écrans, garde une
simplicité dans l'utilisation, des modales quand on peut ou des onglets ».

Ce fichier ne décrit pas un idéal : il décrit ce que **les meilleurs écrans du dépôt font déjà**, et
que les autres ne font pas encore. Chaque règle est donc applicable sans rien inventer — il existe
au moins un écran qui la respecte, cité en exemple.

> **La règle qui prime sur toutes les autres :** on ne standardise pas au prix d'une fonction. Si
> l'application d'une règle fait disparaître un geste, un compteur ou un avertissement, c'est la
> règle qui plie. On note l'écart en commentaire, et on continue.

---

## 0. La règle qui a coûté le plus cher : le nom de classe qui n'existe pas

En écrivant ce fichier le 28/08, j'ai cru que la standardisation consistait à ajouter des en-têtes et
à remplacer six `.seg` par `<Tabs>`. C'était l'affleurement, pas le défaut.

**Quatre noms de classe étaient employés 136 fois dans 22 fichiers, et déclarés dans aucune règle de
`styles.css`** : `page-head`, `panel`, `panel-h`, `alert`. Neuf écrans entiers — tous ceux ouverts le
27/08 — s'affichaient donc sans cadre, sans en-tête, sans marge de page et sans couleur d'erreur. Un
message d'erreur y sortait en texte noir ordinaire.

> **Une classe inconnue ne lève rien.** Le navigateur l'ignore sans un mot dans la console, React ne
> s'en occupe pas, `npm run build` passe, les tests passent. Le seul symptôme est visuel, et il ne
> ressemble pas à une panne : il ressemble à un écran mal dessiné. Personne n'ouvre la feuille de
> style pour un écran « moche ».

C'est la même famille que les 61 en-têtes `.num` alignés à gauche (raconté dans `styles.css` ligne
115) et que les 312 `.sub` sans règle globale : une convention appliquée par les auteurs et jamais
honorée par le CSS.

`scripts/verifier-classes.mjs` a été écrit pour ça et branché au lanceur de garde-fous. **Le
vocabulaire ci-dessous n'est donc plus une préférence : il est vérifié.** Avant d'inventer un nom de
classe, cherchez-le dans `styles.css` — s'il n'y est pas, ou vous vous trompez de nom, ou il vous
manque une règle.

## 0 bis. La règle qui a coûté le plus de colonnes : la relation lue comme un objet

Le 28/08, **seize défauts confirmés dans onze modules**, tous de la même cause — plus que toutes les
autres règles de ce fichier réunies.

API Platform n'embarque une relation **que si l'entité cible expose au moins une propriété dans le
groupe de sérialisation courant**. Sinon elle rend une IRI nue : `"/api/mandat_sepas/xxx"`. Et côté
écran, `x.relation?.propriete` sur une chaîne ne lève pas — il vaut `undefined`.

> **`undefined` s'affiche comme une donnée manquante, jamais comme une erreur de lecture.** Le build
> passe, les tests passent, la colonne sort vide, et un tiret se lit « il n'y en a pas ».

Ce que ça a donné en vrai, écran par écran :

| Écran | Ce que la colonne disait | Ce qui était vrai |
|---|---|---|
| Assistance | « non affecté » | le ticket avait un agent |
| Autorisations | « tout le monde » | le plafond ne visait qu'un rôle |
| Cautions | « montant libre » | la retenue appliquait un barème |
| Tableau de bord | « — » | une caisse a toujours un opérateur (`nullable: false`) |
| SEPA, Recouvrement, Achats, Stock, Sport, Projets, Documents | vide | la donnée existait |

**Les trois premières sont pires que vides : elles affirment.** Un repli doit constater, jamais
conclure.

### Ce qu'on fait

1. **Vérifier avant d'écrire** `x.relation.y` : l'entité cible déclare-t-elle une propriété dans le
   groupe qui porte la relation ? `frontend/scripts/mesurer-relations.mjs` répond pour tout le dépôt.
2. **Résoudre** contre une liste déjà chargée : `resoudre(relation, liste)` dans `components/Liste.jsx`.
   Elle accepte l'IRI **et** l'objet embarqué, pour que l'écran se répare tout seul le jour où un
   `#[Groups]` est ajouté côté serveur.
3. **Distinguer les deux absences** quand on ne peut pas résoudre : `nomOuAbsence()` sépare « le
   champ est nul » (personne) de « le champ est une IRI » (quelqu'un, dont on ne lit pas le nom).
4. **Demander le `#[Groups]`** : la résolution côté écran est un contournement. Le prochain écran qui
   lira la même relation retombera dans le trou.

## 1. L'ossature d'une page

Toute page de `frontend/src/pages/` rend **exactement** cette ossature :

```jsx
<div className="view">
  <div className="view-head">
    <div className="ttl">
      <h1>Prélèvements SEPA</h1>
      <p>Mandats, remises à la banque, rejets</p>
    </div>
    {/* actions globales de l'écran, s'il y en a */}
  </div>
  …
</div>
```

Le `<p>` sous le titre n'est pas décoratif : c'est la seule phrase qui dit à quoi sert l'écran à
quelqu'un qui l'ouvre pour la première fois. Un écran sans elle se lit comme un tableau sans légende.

Le sous-titre s'écrit `<p>`. Neuf écrans plus anciens écrivent `<div className="sub">` au même
endroit ; `styles.css` rend les deux à l'identique plutôt que d'imposer une réécriture pour un nom de
balise. Les deux sont acceptés, `<p>` est la forme canonique.

Le `.view` extérieur n'est pas décoratif non plus : c'est lui qui porte la marge de page
(`padding: 24px 26px 60px`). Une page qui commence par un `<div>` nu colle son contenu à la barre
latérale.

**Corrigé le 28/08 sur les neuf écrans qui ne l'avaient pas** : `Autorisations`, `Campagnes`,
`Documents`, `MentionsLegales`, `Pipeline`, `Projets`, `Social`, `Sport`, `Support`.

## 2. La navigation interne : `<Tabs>`, jamais un `.seg` à la main

Dès qu'un écran a plus d'une vue, il utilise `components/Tabs.jsx` :

```jsx
<Tabs onglets={[['mandats', 'Mandats'], ['remises', 'Remises']]} actif={onglet} onChange={setOnglet} />
```

Trois écrans réécrivaient le même `<div className="seg">` avec leur propre boucle de boutons. Ils
s'affichaient pareil **ce jour-là** — c'est justement le problème : le jour où le composant change
(un compteur dans l'onglet, un état désactivé, une navigation au clavier), les copies restent en
arrière et personne ne le voit, parce que rien ne casse. Convertis le 28/08 : `Campagnes`,
`Personnel`, `Reservation`.

**⚠ `.seg` n'est PAS toujours un onglet, et il ne faut pas le convertir aveuglément.** Quatre autres
écrans — `Caisse`, `Catalogue`, `Clients` — s'en servent comme d'un **bouton radio de formulaire** :
« Choix unique / Choix multiple », « Montant € / % », « Dépense / Ajustement », « Accepté / Refusé /
Timeout » pour le simulateur de TPE. Ce sont des champs, pas de la navigation ; `<Tabs>` y mettrait
une marge de bas de page et une sémantique de changement de vue. On les laisse.

La distinction tient en une question : **est-ce que ça change ce qu'on regarde, ou ce qu'on va
envoyer ?** Le premier cas est un onglet, le second est un champ.

Trois onglets ou moins qui tiennent à l'écran : pas d'onglets du tout, on empile les cartes.

## 3. L'écriture : une modale, jamais un formulaire qui pousse la liste

Toute création / modification passe par `components/Modal.jsx`. Raison d'usage, pas de goût : un
formulaire inséré dans la page **déplace la liste au moment où l'utilisateur la lisait**, et sur un
écran de travail (caisse, guichet) ça se paie en erreurs de saisie.

Le patron d'une modale :

```jsx
<Modal open={!!cible} onClose={fermer} titre="Créer un mandat SEPA">
  <form onSubmit={envoyer}>
    {erreur && <div className="banner banner-error">{erreur}</div>}
    <div className="field">
      <label htmlFor="x">Libellé *</label>
      <input id="x" className="input" required … />
      <div className="hint">Ce qui explique le champ à qui ne connaît pas le métier.</div>
    </div>
    <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
      <button className="btn" type="button" onClick={fermer}>Annuler</button>
      <button className="btn primary" type="submit" disabled={enCours}>Créer</button>
    </div>
  </form>
</Modal>
```

Invariants :
- `Annuler` à gauche, action principale à droite, jamais l'inverse.
- L'action principale est `disabled` pendant l'envoi **et** tant que le formulaire est invalide.
- L'erreur s'affiche **dans la modale**, pas derrière elle : sinon on ferme la modale pour lire
  pourquoi on n'a pas pu la valider.

### 3 bis. L'erreur derrière la modale — quatre fois en un jour, sur quatre écrans

C'est devenu le défaut le plus fréquent du dépôt, et il ne ressemble pas à un défaut : il ressemble à
un bouton qui ne fait rien.

Le 28/08, la même faute a été trouvée sur `RolesSection`, sur les trois modales du nouvel écran
`Acces`, et sur `PlafondModal`. Dans les quatre cas le code **attrapait** l'erreur — il l'écrivait
simplement dans le bandeau de la *carte*, c'est-à-dire **sous la fenêtre restée ouverte**. On clique
« Enregistrer », la modale ne se ferme pas, rien ne bouge, et l'explication est cachée dessous.

**Les messages concernés sont précisément ceux qui débloquent :**

| Écran | Ce que le serveur répondait, et que personne ne voyait |
|---|---|
| `RolesSection` | « ce rôle est la seule source du droit d'administration de X — désignez un remplaçant avant de retirer ce droit » |
| `Acces` | « support déjà appairé : révocation préalable requise », « support bloqué », « un terminal est déjà enrôlé pour cette référence ITBOX » |
| `PlafondModal` | « une limite porte soit un rôle, soit un utilisateur, jamais les deux ni aucun » |

Aucun ne dit non : tous disent **dans quel ordre faire**. Les cacher ne laisse qu'un bouton inerte.

**La cause est structurelle, pas distraite.** Un écran a un `agir(fn, message)` mutualisé qui pose
l'erreur dans l'état de la page ; on le réutilise pour les gestes de tableau *et* pour les modales,
parce que c'est le même appel. Il faut deux chemins :

```jsx
// Gestes déclenchés depuis le TABLEAU : rien devant, le bandeau de l'écran convient.
async function agir(fn, message) {
  try { await fn(); setSucces(message); await recharger() }
  catch (e) { setErreur(e.message); }
}

// Gestes déclenchés depuis une MODALE : on RELANCE, la modale affiche chez elle.
async function agirDepuisModale(fn, message) {
  const r = await fn()          // l'erreur remonte à l'appelant
  setSucces(message); await recharger(); return r
}
```

**Comment on le trouve :** en provoquant le refus, jamais en relisant. Réappairer deux fois le même
numéro, retirer le dernier droit d'administration, enregistrer un plafond sans cible. Un écran qui
n'a jamais reçu de 4xx n'a pas été essayé.

**Le corollaire :** une modale ne doit pas non plus neutraliser sa propre fermeture. `onClose={() => {}}`
laisse une croix affichée qui ne fait plus rien — un bouton mort, dans la famille qu'on corrige. Si la
sortie doit être retenue, on retient le bouton principal et on dit pourquoi ; la croix ferme.

**Le critère n'est pas « y a-t-il un `<form>` », c'est « est-ce que ça bouge ».**

Un formulaire qui **apparaît au clic** au milieu de la page déplace la liste au moment précis où
l'utilisateur la lisait — et souvent la ligne sur laquelle il vient de cliquer passe sous le pli.
C'est le cas à convertir, et c'est le seul.

Deux l'ont été le 28/08, tous deux de la même forme `{truc && (<form …>)}` :

- `AbsencesSection` — déclarer une absence poussait vers le bas les lignes « à décider ».
- `NoShowSection` — exonérer poussait vers le bas la ligne qu'on venait de désigner.

**Ce qui a été examiné et laissé en place, avec la raison :**

| Où | Pourquoi on ne convertit pas |
|---|---|
| `Catalogue` (3 formulaires) | Barres de saisie rapide **permanentes** (libellé, type, bouton, sur une ligne). Rien n'apparaît, donc rien ne bouge — et une modale ajouterait un clic à l'action la plus fréquente de l'écran. |
| `Facturation` | Idem : le formulaire de création est la carte principale de l'écran, toujours visible. |
| `TarifsProduit` | Rendu **à l'intérieur** de `ProduitFicheModal`. Une modale dans une modale est pire que le formulaire. |
| `Caisse`, `SessionCaisse`, `Login`, l'éditeur | Écrans de travail continu où la saisie *est* la page. |

Autrement dit : on convertit ce qui surgit, pas ce qui est déjà là.

## 4. Les listes

- **Consultation pure** → `components/Liste.jsx`. Il gère déjà chargement, erreur, 403, vide et
  rechargement. Le réécrire à la main, c'est perdre le message « droits insuffisants » qui distingue
  un écran vide d'un écran interdit.
- **Liste avec gestes par ligne** → `.card` + `.card-h` / `.card-b` + `<table className="tbl">`.
  `Liste` ne prend pas d'action par ligne ; c'est sa limite acceptée.

Dans les deux cas, le message de vide **dit ce qui ferait apparaître une ligne** :

> « Aucun rejet. Les prélèvements refusés par la banque apparaîtront ici, et ouvriront un impayé. »

et non « Aucun élément ». Un écran vide qui n'explique pas son vide se lit comme une panne.

## 5. États, messages, formats

| Besoin | Ce qu'on écrit |
|---|---|
| Carte | `.card` + `.card-h` (titre) + `.card-b` (corps) — **pas** `panel` / `panel-h` |
| Erreur | `<div className="banner banner-error">` — **pas** `alert crit` |
| Succès | `<div className="banner banner-ok">` — **pas** `alert good` |
| Avertissement porteur de conséquence | `<div className="banner banner-warn">` — **pas** `alert warn` |
| Chargement | `<div className="center"><div className="spinner" /></div>` |
| Vide | `<div className="empty">` |
| Montant en centimes | `euroCentimes()` de `Liste.jsx` — **jamais** une division à la main |
| Date / date+heure | `dateFr()` / `dateHeureFr()` |
| Valeur d'énumération serveur | `mot()` de `api/vocabulaire.js` |
| Identifiant, IBAN, code | `className="mono"` |
| Colonne de nombres | `className="num"` sur le `<th>` **et** le `<td>` |

## 6. Les droits

Un geste que l'utilisateur ne peut pas faire ne s'affiche pas grisé : il ne s'affiche pas. On teste
avec `aLeDroit(droits, 'sepa.gerer')` — **jamais** `droits.includes(…)`, qui ne voit pas les
permissions joker (`*.lire`) et vide le menu d'un administrateur. L'explication complète est en tête
de `api/droits.js`.

Quand l'absence du droit change ce que l'écran veut dire, on le dit en toutes lettres plutôt que de
laisser un blanc :

> « Rouvrir un accès sans que la dette soit payée demande un droit distinct que votre profil n'a pas. »

## 7. Ce qu'on n'invente pas

Un écran n'appelle **que** des opérations qui existent dans `debug:router`. Pas de route devinée,
pas de champ espéré. La vérification tient en une commande :

```
docker exec billetterie-preprod-php-1 php bin/console debug:router | grep <module>
```

C'est la contrepartie de la règle 1 : une page qui promet un geste que le serveur ne rend pas est
pire qu'une page qui ne le promet pas.

**Et « la route existe » ne veut pas dire « elle répond ».** Le 28/08, l'écran Affaires mesurait
zéro opération inatteignable — ses deux gestes appelaient bien `PATCH /api/opportunities/{id}`.
Sauf que cette ressource répondait **500** sur toutes ses opérations, à cause d'une ligne de
cloisonnement côté serveur. L'écran s'affichait parfaitement et aucune de ses actions ne marchait.

> **Atteignable et cassé sont deux choses différentes.** `mesurer-ecart.mjs` compte ce qu'un écran
> appelle, pas ce qui lui répond. Le seul moyen de connaître la seconde, c'est de cliquer.

## 8. Ce qui ne se vérifie qu'en exécutant

Aucun des contrôles de ce dépôt n'aurait trouvé ce qui suit. Ils ont tous été trouvés en ouvrant
l'écran contre un vrai serveur, le même jour :

- une relation embarquée et une relation en IRI **dans la même réponse** (`ligne.remise` et
  `ligne.mandat`), donc une colonne « Débiteur » vide que rien n'expliquait ;
- une collection rendant les configurations **de deux établissements** sous l'en-tête d'un seul ;
- un `POST` qui répond **201** en jetant silencieusement le champ qui rattache la pièce à son
  client ;
- une ressource entière en **500** derrière un écran qui s'affiche normalement ;
- un `@` en double sur un identifiant de compte social.

**Un écran qui n'a jamais tourné n'est pas livré.** Le 26/08, vingt écrans ont été livrés sans une
seule exécution ; les défauts ci-dessus dormaient dedans. Monter un serveur de développement branché
sur l'API de préprod prend deux minutes — c'est moins cher que n'importe lequel de ces défauts.

## 9. Le modèle d'écran — arbitré par Maxime le 29/08

Cette section remplace la standardisation écran par écran. Elle ne contient rien d'inventé : tout
est tiré de ce que Fluvia fait déjà quelque part.

### 9.1 L'écriture : lisez `pages/Stock.jsx`

C'est le meilleur écran de l'application, et il l'est pour trois raisons imitables.

**Il dit ce qu'EST la chose, pas que la liste est vide.**

> « Aucun article de stock. Un article, c'est ce que vous achetez et comptez — une canette, une paire
> de lacets, un sac de sel. Il devient vendable en le rattachant à un produit du catalogue. »

Un écran vide est le moment où l'exploitant a le plus besoin d'aide, et c'est celui où l'on écrit
d'habitude le moins.

**Il prévient quand une absence de réglage DÉSACTIVE UN CONTRÔLE.**

> « Aucun seuil n'est réglé pour cet établissement, et la conséquence n'est pas neutre : aucun écart
> d'inventaire n'est considéré comme significatif. La validation par un responsable ne se
> déclenchera donc jamais, quelle que soit la taille de l'écart. **Tout fonctionne, et le contrôle
> est absent.** »

Cette phrase est l'exact inverse des défauts trouvés les 28 et 29/08, qui affirmaient tous qu'un
contrôle existait : sept opérations badgées « contrôlée » qu'aucun plafond ne limitait ; un
contrôleur d'accès affiché « En ligne » sans avoir jamais émis de signe de vie ; une checklist
« 2 sur 3 » qui ne comptait pas la caisse manquante ; un rôle nommé « Lecture seule » qui conférait
l'administration complète.

> **Un logiciel qui se tait sur un contrôle absent ment plus qu'un logiciel qui échoue.**

**Il distingue les trois raisons d'un vide.**

> « Un journal vide peut aussi vouloir dire que vos articles ne sont rattachés à aucun produit. »

Vide parce que rien ne s'est passé, vide parce que mal branché, vide parce qu'on n'a pas le droit de
voir : trois états qui se ressemblent à l'écran et n'appellent pas la même action.

### 9.2 La structure : ce qui est borné reste, ce qui grandit s'en va

Maxime, en lisant Stock : « Le stock c'est aussi plein de blocs qui vont devenir énormes dès qu'il y
a beaucoup de datas. » Il a raison : Stock empile en pleine largeur trois collections SANS BORNE —
articles, derniers mouvements, articles sous seuil — plus les réglages et l'inventaire. Avec de
vraies données, il faut faire défiler trois listes interminables pour atteindre la quatrième.

| Ce que c'est | Où ça va |
|---|---|
| Alertes, compteurs, réglages, état du jour — quelques lignes, une décision à prendre | Page d'accueil du module |
| Toute collection qui peut grandir sans limite | Une vue à elle |

Et une vue de collection, c'est **toujours** la même chose, décrite par Maxime pour les clients et
redemandée deux minutes plus tard pour les produits :

1. liste **pleine largeur** (`className="view large"`) ;
2. filtres — **ceux que le serveur accepte déjà**, cf. §7 ;
3. état dans l'URL via `useEtatUrl` (`api/url.js`) ;
4. clic sur une ligne → **fiche en page**, pas en modale ;
5. « ← Retour à la liste » qui **restitue les filtres**.

Le point 3 n'est pas un raffinement. Le jeton dure une heure : sans lui, chaque expiration efface le
travail de tri de l'exploitant, qui se reconnecte sur la caisse et recommence. Avec, il revient
exactement où il était — vérifié en supprimant le jeton et en se reconnectant, pas en le supposant.

Le point 4 non plus : une modale ne porte pas d'URL, donc elle n'est ni partageable, ni restituable,
et elle s'interdit d'ouvrir une fenêtre par-dessus.

**Ce motif n'est pas « le motif clients ».** C'est celui de toute collection de l'application. Au
29/08 il sert **cinq** écrans — `Clients`, `Catalogue`, `Assistance`, `Campagnes`, `Projets` — sans
être recopié une seule fois : tous appellent `useEtatUrl`. Ce qu'ils en tirent diffère, et c'est
normal : une fiche client devient une PAGE, une fiche projet reste une modale que l'URL désigne. Le
motif porte l'état, pas la mise en page.

**Un identifiant qui ne correspond à rien laisse la fiche fermée.** Un lien périmé, un enregistrement
archivé puis filtré : on résout contre la liste déjà chargée, et l'absence est un silence. Ouvrir une
fiche vide ferait croire à une panne.

### 9.3 Les actions : on crée une chose là où c'est le métier

Le défaut trouvé **six fois** en deux jours : l'écran Clients ne créait pas de client (seul
`ClientPicker`, au milieu d'une vente, le pouvait) ; la Piscine ne déclare pas de bassin ; la Caisse
ne crée pas de caisse ; les modules n'étaient pas activables depuis l'onglet « activables » ; un
moyen de paiement ne se réglait qu'à la création ; la comptabilité d'un produit s'affichait sans se
modifier.

Stock, lui, le fait : « + Ajouter », « + Créer le premier », « + Lancer un inventaire ».

**Et quand le DROIT est séparé, le bouton l'est aussi.** `offre.modifier_compta` n'est pas
`offre.modifier` : qui peut renommer un produit n'a pas à décider du compte sur lequel ses ventes
s'imputent. Un seul bouton aurait fait porter les deux gestes par le droit le plus faible.

## 10. Le geste qui ne se défait pas

Écrit en posant l'écran **Données personnelles** (RGPD), qui a apporté à l'application son premier
geste irréversible : l'anonymisation d'une fiche client. Jusque-là, tout se rattrapait — une facture
s'annule, un badge se réappaire, un projet se rouvre, une caisse se renomme. Rien n'obligeait à
distinguer visuellement « enregistrer » de « détruire », et rien ne le faisait.

**La règle : un geste irréversible se raconte avant, jamais après.**

Le serveur, lui, se défend très bien : il refuse la seconde tentative en 409 et verrouille l'entité.
Mais un refus arrive *après* le clic, et le clic est le moment où le dommage est fait. Le 409 protège
la base ; il ne protège pas la personne dont on vient d'effacer la fiche par erreur.

Quatre exigences, dans l'ordre où l'utilisateur les rencontre :

1. **Le bouton ne ressemble pas à « Enregistrer ».** `.btn.danger` — contour et texte critiques, pas
   d'aplat rouge : un aplat attire le clic autant qu'il en avertit. Le bouton dit ce qu'il fait
   (« Anonymiser définitivement »), pas ce qu'il valide (« OK », « Confirmer »).

2. **La modale dit ce qui va se passer, en termes de conséquences, pas de mécanismes.** Quels champs
   partent, ce qui subsiste, ce qui reste rattaché. Pas « lance le handler d'effacement ».

3. **Une case à cocher, et une seule dans toute l'application.** Partout ailleurs une confirmation
   superflue apprend à cliquer sans lire, et abîme donc celle-ci. Elle se réarme à chaque ouverture
   de la modale — un bouton destructeur déjà armé serait pire que pas de case du tout.

4. **L'erreur reste dans la modale.** Renvoyée au bandeau de l'écran, elle est masquée par la modale
   restée ouverte : c'est le défaut déjà payé sur l'écran des accès, où l'on relançait un appairage
   en boucle sans jamais voir le refus.

**Et le corollaire, qui n'est pas une question d'interface :** ne jamais promettre plus que ce que le
serveur fait. « Anonymisé » n'est pas « supprimé ». La fiche subsiste, sans identité, et les ventes
passées y restent rattachées pour que la comptabilité reste juste. Un écran qui écrirait « supprimer
définitivement » mentirait dans le seul sens qui coûte un contentieux : celui où l'on a promis à
quelqu'un un effacement qui n'a pas eu lieu.
