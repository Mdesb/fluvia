# CMS et site vitrine — ce qui existe, ce qui manque, ce qu'il faut trancher

Statut : **note de conception, aucun code écrit.** Rédigée par claude-A dans la nuit du 29/08.

Demande de Maxime, dans ses termes :

> « Étant donné qu'on va faire le site vitrine, il faudra que j'aie mon outil d'administration du
> CMS, il devra aussi permettre de vendre avant de basculer sur l'app pour l'intégration des
> clients. Un client pourra aussi potentiellement prendre le module CMS. »

Et, plus tard : « Pour mon site vitrine, je peux réserver un autre sous-domaine ».

---

## 1. Ce n'est pas un terrain vierge, et c'est la première chose à savoir

Trois briques existent déjà et personne ne devrait les réécrire.

| Brique | Où | Ce qu'elle fait |
|---|---|---|
| `Vitrine` | `App\Boutique\Entity` | Devanture d'un établissement : slug, logo, couleurs, langues, canaux actifs, délai d'expiration du panier |
| `VitrineResolver` | `App\Boutique\Service` | Résout une vitrine par **slug** ou par UUID, sans route ambiguë |
| `PublicSubscriptionCart` / `SubscriptionFunnel` | `App\Subscription` | Vend un abonnement à un prospect **qui n'est pas encore client** |

La demande de Maxime n'est donc pas « construire une boutique » : elle est **« ajouter du contenu
éditorial et une administration par-dessus, et réserver un domaine propre »**.

`Vitrine::$domainesIntegration` existe déjà mais ne fait PAS ce qu'on pourrait croire : elle
alimente la carte nginx des en-têtes CSP pour l'intégration en iframe. Ce n'est pas de la résolution
de locataire par nom d'hôte.

---

## 2. Les quatre chantiers, dans l'ordre où ils se débloquent

### 2.1 Le contenu éditorial — le seul vrai manque

Rien n'existe : pas de page, pas de bloc, pas de menu, pas de média éditorial. C'est le cœur du
« module CMS », et c'est ce qu'un client pourrait acheter.

Modèle minimal viable, dans un module `App\Cms` propre (D2 : aucune dépendance vers `Boutique`, la
vitrine est désignée par un `?Uuid` nu) :

- `Page` — chemin, titre, état (brouillon / publiée), date de publication, vitrine de rattachement.
- `Block` — un bloc typé dans une page, ordonné. Le contenu est du **JSON structuré**, jamais du HTML
  libre : du HTML saisi par un opérateur et rendu tel quel est une injection de script à retardement.
- `Menu` / `MenuItem` — la navigation, qui n'est pas déductible de l'arborescence des pages.

⚠ **La publication est un état, pas une suppression.** Dépublier une page ne l'efface pas : un
opérateur qui se trompe doit pouvoir revenir en arrière, et une page effacée par mégarde un vendredi
soir ne se retrouve pas.

⚠ **Le rendu public ne doit jamais interroger l'API métier.** Une page vitrine lue par un moteur de
recherche ne doit pas pouvoir déclencher une requête sur le catalogue d'un client. Le contenu publié
est servi depuis sa propre projection.

### 2.2 Le domaine propre — la décision d'architecture

Aujourd'hui une vitrine se résout **par segment de chemin** (`/v/mon-club`). Maxime veut un
sous-domaine. Cela introduit un **second axe de résolution de locataire**, à côté de l'en-tête
`X-Etablissement`.

⚠ **Et c'est là qu'est le danger.** D6 dit que l'en-tête `X-Etablissement` est un *sélecteur*, pas
une preuve : l'autorité vient de `codesEffectifs()`, qui ne garde que les affectations sur
l'établissement actif. Un nom d'hôte n'a pas cette propriété — **il n'y a personne d'authentifié
derrière une requête publique**. Il faut donc que la résolution par hôte ouvre un périmètre
**strictement en lecture, strictement public**, et qu'elle ne puisse jamais servir à sélectionner un
établissement pour une opération authentifiée.

Formulation de la règle à poser : *un nom d'hôte désigne du contenu publié, jamais des droits.*

### 2.3 Vendre avant que le client n'existe — presque fait, et cassé au dernier maillon

`SubscriptionFunnel::openCart()` vend déjà à un prospect. Il porte même le report de configuration :

```php
public function openCart(..., ?Etablissement $demo = null)
```

> « Le paramétrage de démo est prélevé **maintenant**, pas au provisionnement (RG-ED-08, D11) : entre
> les deux, le prospect peut abandonner et son bac à sable être détruit. »

**Aucun appelant ne remplit ce paramètre**, et la ressource publique n'a pas de champ pour le dire.
Un prospect qui configure une démonstration puis souscrit **perd tout ce qu'il a réglé**. Le
mécanisme est écrit, documenté par une règle, et inatteignable.

⚠ **Ne pas le brancher naïvement.** `openCart` est une route **publique et non authentifiée**. Lui
faire accepter un identifiant d'établissement permettrait à n'importe qui de capturer la
configuration d'un client réel dans son propre abonnement — une exfiltration en une requête.

La forme correcte : le bac à sable émet à sa création un **jeton opaque, à usage unique et
expirant**, que le panier accepte. Jamais un identifiant d'établissement.

### 2.4 Le CMS vendu comme module à un client

`FonctionnaliteEtablissement` existe déjà (capacités par établissement). Le CMS y entre comme une
capacité de plus : rien de neuf à inventer, à condition que le module soit dès le départ cloisonné
par établissement comme les autres.

---

## 3. Ce que je recommande de NE PAS faire

- **Ne pas réécrire `Vitrine`.** Le CMS s'y rattache, il ne la remplace pas.
- **Ne pas stocker de HTML libre.** Blocs typés, contenu JSON, rendu côté serveur.
- **Ne pas faire résoudre le locataire par l'hôte pour les routes authentifiées.** Un hôte désigne du
  contenu, pas des droits.
- **Ne pas ouvrir d'opérations d'API avant que l'écran existe.** Le cliquet d'écart les refuse, et il
  a raison : c'est exactement la faute qu'on a passé deux jours à corriger.

---

## 4. Les décisions qui attendent Maxime

1. **Le sous-domaine.** Un seul pour l'éditeur (`www.fluvia.app`), ou un par client
   (`monclub.fluvia.app`) ? La réponse change tout : le premier est une page de plus, le second est
   une résolution de locataire par hôte, avec certificats et carte nginx.
2. **Le report de démonstration.** Confirmes-tu qu'un prospect doit retrouver sa configuration après
   paiement ? Si oui, il faut le jeton décrit en 2.3 — une demi-journée, pas un branchement.
3. **La profondeur du CMS.** Pages et blocs suffisent-ils pour la V1, ou faut-il d'emblée les menus,
   les médias et le multilingue ? `Vitrine::$langues` existe déjà et suggère que le multilingue est
   attendu.
4. **Qui l'écrit.** Le contenu éditorial est autant un chantier d'écran que de moteur ; ça ne tient
   pas dans une seule session.
