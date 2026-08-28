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

**Exceptions légitimes, à ne pas convertir :** un écran de travail continu où la saisie *est* la
page (`Caisse`, `SessionCaisse`, `Login`, l'éditeur de boutique). Une modale y ajouterait un clic à
chaque geste.

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
