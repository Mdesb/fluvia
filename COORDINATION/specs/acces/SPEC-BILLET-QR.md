# Un billet vendu n'existe pas pour le contrôle d'accès

**Statut : constat mesuré, correction EN ATTENTE de la spécification des typologies de produits.**
Maxime, 30/08 : « Les billets sont en général imprimés, mais ils peuvent aussi les retrouver depuis
l'application et ça peut aussi être fait par la borne, donc un billet QR code doit répondre **en
fonction du paramétrage**. On en parlera plus en détail au moment du débrief sur les types de
produits. »

Rien n'est donc construit. Ce document existe pour que la mesure ne soit pas à refaire.

---

## Le constat, mesuré des deux côtés

Trouvé par `allaccess-8e` en vérifiant le bloc « passages » de la fiche client — pas en cherchant ce
défaut.

    numéros de billets vendus (Piscine A)   BIL-9XV…, BIL-3AG…, BIL-T7N…
    supports connus du contrôle d'accès     5
    croisement                              AUCUN

Et le code dit pourquoi : **deux entités portent le mot « support » et ne se connaissent pas.**

| | créée par | porte |
|---|---|---|
| `Vente\Entity\BilletSupport` | `ValiderVenteService:310`, à la validation de vente | `identifiantSupport`, un code unique et signé |
| `Acces\Entity\Support` | `AppairageHandler:42` **uniquement**, plus les fixtures | `identifiant`, connu des tourniquets |

Aucune projection, aucun abonné, rien ne fait le pont depuis la vente. Cherché, pas supposé.

## La conséquence, écrite noir sur blanc

`ValidationPassageHandler`, lignes 99–101 :

```php
$support = findOneBy(['identifiant' => $evt->identifiantSupport]);
if (!$support) → refuser(…, CodeMotifRefus::DroitInvalide, 'Support inconnu.')
```

Un billet QR imprimé au guichet est donc **refusé au tourniquet**, avec un motif qui ne dit ni au
porteur ni à l'exploitant ce qui se passe réellement.

⚠ Pour une carte ou un bracelet RFID, l'absence de pont est **correcte** : on remet un objet physique
à quelqu'un, et l'appairage est ce geste. Le trou ne concerne que ce qui n'a pas de geste de remise.

## Ce que la réponse de Maxime change

La question n'est pas « faut-il créer le support à la vente » — elle est réglée, il le faut pour le
QR. La question est **quand**, et elle dépend du paramétrage :

- billet **imprimé** au guichet → le support doit exister dès la vente, sinon le premier passage est
  refusé ;
- billet **retrouvé depuis l'application** → le support doit exister avant, ou naître à la première
  présentation ;
- billet **émis par la borne** → la borne vend et imprime sans agent : aucun appairage possible.

Les trois convergent vers « le support naît à la vente », mais le paramétrage décide si c'est
systématique, et par typologie de produit. **C'est le débrief des typologies qui le tranchera.**

## Ce qu'il faudra vérifier le jour où on le construira

⚠ Le risque n'est pas d'oublier de créer le support : c'est d'en créer **deux**. Un billet dont le
support serait posé à la vente PUIS appairé produirait soit un doublon, soit un conflit
« support déjà appairé à un droit actif » (`AppairageHandler`, CA-2). Le pont devra donc être écrit
en sachant que l'appairage existe toujours pour les cartes — les deux chemins doivent se rencontrer,
pas se doubler.

Et le test qui compte n'est pas « le support est créé » : c'est **un passage présentant un billet
vendu et jamais appairé est ACCEPTÉ**. Le premier est vrai d'un support créé au mauvais
établissement, avec le mauvais type, ou sans droit rattaché.

---

## Addendum du 30/08 (claude-A) — la cause est plus précise que « rien ne fait le pont »

Le constat ci-dessus dit : *« Aucune projection, aucun abonné, rien ne fait le pont depuis la
vente. »* C'est vrai du résultat, et trop sévère sur le moyen. **La projection existe, elle
fonctionne, et elle est câblée sur une implémentation réelle.** Ce qui manque est son déclencheur.

Mesuré :

| | |
|---|---|
| `App\Acces\Port\ProjectionDroitInterface` | câblé sur `App\Acces\Projection\StubProjectionDroit` (`services.yaml:85`) |
| ce que fait cette implémentation | lit un `Vente\Entity\BilletSupport`, écrit un `Acces\Entity\DroitAcces` — droit `carte_quota` avec `creditRestant` pour une carte, `billet`/`abonnement` sinon, fenêtre de validité par `CardExpiryCalculator` |
| appelants de `->projeter()` | **un seul** : `Acces\State\AppairageProcessor:87`, l'écran d'appairage manuel |
| occurrences de `Projection` ou `DroitAcces` dans tout `app/src/Vente` | **zéro** (grep avec témoin positif : le premier grep rend une douzaine de lignes ailleurs) |

⚠ **« Stub » ne veut pas dire « inerte » ici.** Le nom vient du découpage en couches L2/L3 : c'est
l'implémentation locale en attendant l'intégration L4, pas un bouchon qui refuse tout. Elle écrit de
vrais droits. Ne pas conclure d'un nom.

**Donc la correction n'est pas « écrire une projection », c'est « appeler celle qui existe au bon
moment ».** La question du paramétrage QR que Maxime a posée porte exactement là : *quand* un billet
vendu devient-il un droit — à la vente, à l'impression, au premier scan, jamais si l'établissement ne
l'a pas activé ? Le déclencheur dépend de la réponse, pas l'inverse.

⚠ **Et il y a une conséquence à ne pas manquer :** `CardExpiryCalculator` n'est appliqué qu'à la
**première** projection. Si la projection se met à partir de la vente alors qu'un support a déjà été
projeté par appairage manuel, la validité ne sera pas recalculée — c'est voulu (ne pas réinitialiser
une carte rechargée), mais ça veut dire que **l'ordre des deux événements change le résultat**. À
trancher en même temps que le déclencheur, pas après.
