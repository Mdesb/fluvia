# Spec — Clôture de caisse (Z) à rôle gradué (`M2` / évolution hors backlog initial)

- **Lot / module :** L2 · M2 Vente & Caisse (évolution) · s'appuie sur M8 Admin & Droits
- **Stories couvertes :** US-CAISSEZ-01 à US-CAISSEZ-04 — **⚠ HYPOTHÈSE : stories créées pour cette
  spec, absentes du `backlog.html` (évolution demandée directement par le client, hors MVP L0-L7).**
  Étendent US-L2-10 (clôture Z) sans la contredire.
- **Règles de gestion :** RG-CAISSEZ-01 à RG-CAISSEZ-07 (nouvelles, cette spec) — s'appuient sur et
  **ne re-tranchent pas** RG-M2-01 (session), RG-M2-06 (clôture Z, CA-14), RG-SOCLE-02/03/04/05
  (permissions `module × action`, cloisonnement multi-entités) déjà actées.
- **Statut :** brouillon

## 1. Objectif
Faire de la clôture Z une opération **à confidentialité graduée** : le caissier de plus bas niveau
compte sa caisse « à l'aveugle » (il saisit le compté mais ne voit jamais l'attendu, l'écart ni le
rapport Z), tandis que seul un rôle habilité (régisseur/administrateur du site) voit le Z complet et
est **alerté immédiatement** en cas d'écart hors tolérance — sans changer le calcul existant de
l'attendu ni le caractère irréversible/scellé (NF525) de la clôture.

## 2. Périmètre
- **Inclus :**
  - Différenciation de la **réponse** de l'endpoint de clôture (`POST /sessions-caisse/{id}/cloturer`)
    selon que l'auteur détient ou non le droit de voir le Z.
  - Deux nouvelles permissions : `caisse.voir_z` (Z complet, détail par moyen, écart) et
    `caisse.voir_ecart` (alertes d'écart).
  - Restriction des lectures existantes de `ClotureZ` (`GetCollection`, `Get`, `Get …/etat-regie`) au
    droit `caisse.voir_z` (au lieu de `caisse.lire`, trop large).
  - Génération d'une **alerte d'écart** (`AlerteEcartCaisse`) quand `|ecartTotal| > tolérance`, tracée
    à l'audit, visible aux porteurs de `caisse.voir_ecart` de l'établissement.
  - **Tolérance** paramétrable par point de vente (`toleranceEcartCaisse`), défaut **0,00 €**.
  - Rétrocompatibilité : un porteur de `caisse.voir_z` qui clôture reçoit le Z complet, comme
    aujourd'hui (US-L2-10, CA-14).
- **Exclu (pour l'instant) :**
  - Le **calcul** de l'attendu, du théorique par moyen et de l'écart : inchangé, réutilise
    `CloturerSessionProcessor` / `PanierCalculateur` existants (RG-M2-06).
  - Le **canal e-mail/SMS** de l'alerte : aucun mécanisme de notification e-mail n'existe dans le
    code (`grep App\Notification` → aucun résultat) ; l'alerte est **in-app uniquement** pour cette
    version — ⚠ HYPOTHÈSE, point ouvert (cf. §9).
  - L'**acquittement / la résolution** d'une alerte (marquer traité, commentaire d'écart) : non
    demandé par le client, ajout possible en évolution ultérieure ; l'alerte reste simplement listable.
  - La composition des rôles (quel rôle métier porte quelles permissions `caisse.*`) : configuration
    M8 (`Role`/`Affectation`), hors périmètre de cette spec comportementale.
  - Les clôtures **mensuelle/annuelle** (US-L2-11, hors Z) : non retouchées.
  - Le format du **corps envoyé** par le caissier à la clôture (`{"comptages":[...]}`) : déjà « aveugle »
    aujourd'hui — le caissier n'a jamais eu à transmettre de théorique, seulement son compté ; cette
    spec ne change **rien** à l'entrée, uniquement à la **sortie** (réponse + lectures ultérieures).

## 3. Acteurs & droits
Réutilise le modèle du socle (`module × action`, cloisonné par établissement via `Affectation`,
`RG-SOCLE-02/03/05`). Deux permissions `caisse.*` existantes (`caisse.cloturer`, `caisse.gerer`) ne
changent pas de sémantique ; deux permissions sont **ajoutées**.

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Caissier (bas niveau)** | Clôturer sa session en comptage aveugle (saisir le compté par moyen), recevoir l'accusé « caisse fermée » | Voir l'attendu, l'écart, le Z, l'état de régie, les alertes d'écart | `caisse.cloturer` |
| **Régisseur / Administrateur du site** | Clôturer, voir le Z complet (attendu, compté, écart, détail par moyen, état de régie), consulter les alertes d'écart de son établissement | Voir les alertes/Z d'un autre établissement (cloisonnement, RG-SOCLE-05) | `caisse.cloturer`, `caisse.voir_z`, `caisse.voir_ecart` |
| **Administrateur (surensemble)** | Tout ce que fait le régisseur, + paramétrer la tolérance (`caisse.gerer`, `PointDeVente`) | — | `caisse.gerer` (⚠ HYPOTHÈSE : par convention socle un rôle Administrateur composé côté M8 inclut `caisse.voir_z`/`caisse.voir_ecart` ; ces permissions ne sont **pas** automatiquement impliquées par `caisse.gerer` dans le code — c'est une composition de rôle M8, hors périmètre) |
| **Lecture seule (`caisse.lire`)** | Consulter sessions, mouvements, PDV (inchangé) | Consulter le Z (`ClotureZ`) : ce droit ne suffit plus, il faut `caisse.voir_z` (RG-CAISSEZ-03) | `caisse.lire` |

- ⚠ HYPOTHÈSE — **qui reçoit `caisse.cloturer` sans `caisse.voir_z`** : le cahier/backlog (US-L2-01,
  spec-vente.md §3) ne confiait la clôture qu'au **Régisseur**. Le client demande maintenant qu'un
  « utilisateur de plus bas niveau (caissier) » puisse aussi clôturer (comptage aveugle). Cette spec
  **n'introduit pas de nouveau rôle métier** : elle rend la permission `caisse.cloturer` attribuable
  à un rôle « Caissier » distinct du rôle « Régisseur » (les deux existent déjà comme rôles
  configurables M8) sans que ce rôle porte `caisse.voir_z`. L'attribution effective des permissions
  aux rôles reste une tâche de paramétrage M8, hors périmètre technique de cette spec.

## 4. Comportements & règles

### 4.1 Réponse différenciée à la clôture
- **RG-CAISSEZ-01** — Le calcul de l'attendu (théorique par moyen), du compté saisi et de l'écart
  (`ecartTotal`) **ne change pas** : il reste effectué et **persisté** dans `ClotureZ` par
  `CloturerSessionProcessor`, quel que soit le rôle de l'auteur de la clôture (RG-M2-06 inchangée).
- **RG-CAISSEZ-02** — La **réponse HTTP** de `POST /sessions-caisse/{id}/cloturer` est calculée selon
  le droit de l'auteur au moment de l'appel :
  - Si l'auteur **détient** `caisse.voir_z` → réponse **complète**, identique au comportement actuel
    (`cloture`, `session`, `etatSession`, `totalVentes`, `totalRemboursements`, `comptages` [avec
    `theorique`/`compte`/`ecart` par moyen], `ecartTotal`, `versement`, `fondReporte`,
    `etatDeRegie`).
  - Si l'auteur **ne détient pas** `caisse.voir_z` → réponse **minimale** (« comptage aveugle ») :
    uniquement `cloture` (id), `session` (numéro), `etatSession`, `horodatageCloture`, `message`
    (ex. « Caisse fermée. »). **Aucun** champ monétaire (`totalVentes`, `comptages`, `ecartTotal`,
    `versement`, `fondReporte`, `etatDeRegie`) n'est présent dans la réponse, ni en clair ni masqué
    (absent de la charge utile, pas juste `null`/omis côté UI).
- **RG-CAISSEZ-03** — Les opérations de lecture de `ClotureZ` (`GetCollection`, `Get`,
  `Get …/{id}/etat-regie`) exigent désormais `is_granted('PERM', 'caisse.voir_z')` (au lieu de
  `caisse.lire`, qui restait trop large et aurait permis à un profil « lecture seule » de voir le Z).
  Un caissier sans ce droit reçoit **403** s'il tente de consulter une `ClotureZ`, y compris la
  sienne.
- **RG-CAISSEZ-04** — Le comptage transmis par le caissier reste **libre par moyen** (espèces et,
  le cas échéant, autres moyens comptés manuellement) ; le format d'entrée
  `{"comptages":[{"moyen":"especes","compte":"…"}], "versement":"…", "fondReporte":"…"}` est
  **inchangé**. Le caissier ne transmet et ne reçoit **jamais** de valeur « théorique ».
  - ⚠ HYPOTHÈSE — pour qu'un comptage aveugle soit significatif (distinguer « l'auteur a compté et ça
    correspond » de « l'auteur n'a rien compté »), quand l'auteur **ne détient pas** `caisse.voir_z`,
    au moins une ligne `comptages` portant sur `especes` est **requise** ; à défaut, la clôture est
    refusée (422, « comptage espèces requis »). Un porteur de `caisse.voir_z` conserve le
    comportement actuel (comptage manquant = réputé conforme, cf. `CloturerSessionProcessor`).

### 4.2 Alerte d'écart
- **RG-CAISSEZ-05** — À la clôture, si `|ecartTotal| > toleranceEcartCaisse` du point de vente de la
  session (comparaison en valeur absolue : excédent **ou** manque), une **alerte d'écart**
  (`AlerteEcartCaisse`) est créée et persistée **dans la même transaction** que la `ClotureZ`, avant
  le `flush()` final — l'alerte existe donc dès que la clôture est confirmée, jamais en différé.
  Si `|ecartTotal| ≤ toleranceEcartCaisse`, **aucune** alerte n'est créée (RG-CAISSEZ-05bis).
- **RG-CAISSEZ-06** — L'alerte porte l'établissement de la session (pour le cloisonnement,
  RG-SOCLE-05), le montant de l'écart, la tolérance appliquée au moment de la clôture (valeur figée,
  indépendante d'un changement ultérieur du paramétrage du point de vente), la référence à la
  `ClotureZ` et à la `SessionCaisse`, et l'auteur de la clôture. Elle est visible en lecture
  (`GetCollection`/`Get`) aux porteurs de `caisse.voir_ecart` **de cet établissement uniquement**
  (même mécanisme de cloisonnement que `PerimetreVenteExtension`, étendu à `AlerteEcartCaisse`).
  L'alerte **n'expose pas** le détail par moyen de paiement (ce détail reste réservé à `caisse.voir_z`
  via `ClotureZ`) — c'est volontairement un signal, pas un Z bis.
- **RG-CAISSEZ-07** — La création d'une alerte est **tracée** au journal d'audit (`JournalAudit`,
  RG-SOCLE-07) : action `caisse.alerte_ecart`, cible `AlerteEcartCaisse`/id, établissement, auteur =
  l'agent qui a clôturé (pas le destinataire de l'alerte). Aucune alerte n'est jamais modifiable ou
  supprimable par l'API (cohérent avec l'immutabilité déjà pratiquée sur `ClotureZ`/opérations
  scellées NF525) : si un correctif est nécessaire, il passe par une nouvelle clôture/contre-passation
  existante, pas par une modification de l'alerte.

### 4.3 Tolérance
- **RG-CAISSEZ-08** — `PointDeVente` porte un champ `toleranceEcartCaisse` (montant, ≥ 0), **défaut
  0,00 €** — tout écart non nul déclenche une alerte par défaut, jusqu'à paramétrage explicite.
  Modifiable uniquement par `caisse.gerer` (même droit que les autres réglages du point de vente :
  `seuilImpression`, `seuilAlerteRetrait`, `moyensAutorises`).
  - ⚠ HYPOTHÈSE — portée du paramètre : au **point de vente** (comme `seuilAlerteRetrait`, précédent
    direct dans le code pour une alerte de caisse à seuil), et non à l'établissement ou au global.
    Si le client veut une tolérance unique par établissement, un simple changement de portée du champ
    suffit (pas de refonte de la règle).

### 4.4 Rétrocompatibilité et non-régression
- **RG-CAISSEZ-09 (rétrocompatibilité)** — Un régisseur/administrateur qui clôture aujourd'hui
  continue de recevoir exactement la réponse actuelle (§4.1, branche « complète ») : aucune régression
  fonctionnelle pour les rôles déjà habilités. Les critères CA-14/CA-15 de `spec-vente.md`
  (irréversibilité, refus si paiements incohérents, scellement NF525, chaînage) restent inchangés et
  s'appliquent identiquement quel que soit le rôle de l'auteur de la clôture.

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **ClotureZ** *(existant, inchangé)* | comptages, totalVentes, totalRemboursements, ecartTotal, versement, fondReporte, etatDeRegie | — | — | Toujours calculés/persistés (RG-CAISSEZ-01) ; exposition désormais réservée à `caisse.voir_z` (RG-CAISSEZ-03) |
| **AlerteEcartCaisse** *(nouveau)* | id | uuid | PK | — |
| | cloture | ref `ClotureZ` | requis, unique (au plus une alerte par clôture) | RG-CAISSEZ-05 |
| | session | ref `SessionCaisse` | requis | dénormalisé de `cloture.session` pour lecture directe |
| | etablissement | ref `Etablissement` | requis | cloisonnement (RG-SOCLE-05), dénormalisé (comme `DemandeEscalade.etablissement`) |
| | ecartMontant | decimal(10,2) | requis, signé (positif = excédent, négatif = manque) | = `ClotureZ.ecartTotal` au moment de la création |
| | toleranceAppliquee | decimal(10,2) | requis, ≥ 0 | valeur de `PointDeVente.toleranceEcartCaisse` figée à l'instant T (RG-CAISSEZ-06) |
| | auteurCloture | ref `Utilisateur` | requis | qui a déclenché la clôture (peut être le caissier bas niveau) |
| | horodatage | datetime immutable | requis, = horodatage de la `ClotureZ` associée | — |
| **PointDeVente** *(existant, complété)* | toleranceEcartCaisse | decimal(10,2) | requis, ≥ 0, défaut `0.00` | RG-CAISSEZ-08 ; modifiable par `caisse.gerer` |
| **Permission** *(socle, complété)* | module=`caisse`, action=`voir_z` | — | — | RG-CAISSEZ-03 |
| | module=`caisse`, action=`voir_ecart` | — | — | RG-CAISSEZ-06 |

## 6. Critères d'acceptation

- **CA-1 (US-CAISSEZ-01, RG-CAISSEZ-01/02)** — *Étant donné* une session ouverte et un utilisateur
  portant `caisse.cloturer` mais **pas** `caisse.voir_z`, *quand* il clôture en saisissant le compté
  espèces, *alors* la réponse contient uniquement l'accusé de clôture (id, numéro de session, état,
  horodatage, message) — **aucun** champ `totalVentes`, `comptages`, `ecartTotal`, `versement`,
  `fondReporte`, `etatDeRegie` n'apparaît dans la charge utile.
- **CA-2 (US-CAISSEZ-01, RG-CAISSEZ-01)** — *Étant donné* la clôture du CA-1, *quand* un
  administrateur consulte ensuite `GET /clotures-z/{id}` (avec `caisse.voir_z`), *alors* il retrouve
  l'attendu, le compté et l'écart calculés et persistés — la donnée n'a jamais été perdue, seulement
  non exposée au caissier.
- **CA-3 (US-CAISSEZ-02, RG-CAISSEZ-05/06/07)** — *Étant donné* un point de vente avec
  `toleranceEcartCaisse = 0,00 €`, *quand* une clôture produit un `ecartTotal` non nul (ex. `-5,00 €`),
  *alors* une `AlerteEcartCaisse` est créée dans la même opération, portant l'établissement, le
  montant d'écart, la clôture liée, et une entrée d'audit `caisse.alerte_ecart` est enregistrée.
- **CA-4 (US-CAISSEZ-02, RG-CAISSEZ-05bis)** — *Étant donné* un point de vente avec
  `toleranceEcartCaisse = 5,00 €`, *quand* une clôture produit un `ecartTotal` de `3,00 €`,
  *alors* **aucune** `AlerteEcartCaisse` n'est créée.
- **CA-5 (US-CAISSEZ-03, RG-CAISSEZ-09)** — *Étant donné* un régisseur portant `caisse.voir_z`,
  *quand* il clôture sa session, *alors* la réponse contient le Z complet (attendu, compté, écart,
  détail par moyen, état de régie) — comportement identique à celui d'aujourd'hui (CA-14 de
  `spec-vente.md`).
- **CA-6 (US-CAISSEZ-03, RG-CAISSEZ-03)** — *Étant donné* la `ClotureZ` du CA-5, *quand* un caissier
  sans `caisse.voir_z` appelle `GET /clotures-z/{id}` ou `GET /clotures-z/{id}/etat-regie`, *alors*
  la réponse est **403**.
- **CA-7 (US-CAISSEZ-04, RG-CAISSEZ-06)** — *Étant donné* une alerte d'écart créée pour
  l'établissement A, *quand* un administrateur de l'établissement B (sans affectation sur A) liste
  `GET /alertes-ecart-caisse`, *alors* cette alerte **n'apparaît pas** dans sa collection
  (cloisonnement RG-SOCLE-05).
- **CA-8 (RG-M2-06, non-régression)** — *Étant donné* une session déjà close, *quand* n'importe quel
  rôle tente de la re-clôturer, *alors* la réponse est **409** — inchangé quel que soit le rôle.
- **CA-9 (RG-CAISSEZ-04, multi-moyens)** — *Étant donné* un caissier sans `caisse.voir_z` qui compte
  plusieurs moyens (espèces + un moyen manuel), *quand* il clôture, *alors* le calcul de l'écart porte
  sur l'ensemble des moyens comptés (comme aujourd'hui, RG-M2-06) et la réponse reste minimale
  (aucune fuite, quel que soit le nombre de lignes de comptage).
- **CA-10 (RG-CAISSEZ-04, cas limite)** — *Étant donné* un caissier sans `caisse.voir_z` qui clôture
  **sans** transmettre de ligne `comptages` pour `especes`, *alors* la clôture est refusée (422,
  « comptage espèces requis ») — ⚠ HYPOTHÈSE (cf. §4.1).
- **CA-11 (RG-CAISSEZ-08)** — *Étant donné* un point de vente sans tolérance explicitement
  paramétrée, *quand* on interroge `PointDeVente.toleranceEcartCaisse`, *alors* la valeur vaut
  `0.00` par défaut.

## 7. Cas limites
- **Fond de caisse** — Le calcul de l'attendu (incluant le fond de caisse et les mouvements
  d'espèces) ne change pas ; un caissier bas niveau peut toujours consulter son **propre** fond de
  caisse via `GET /sessions-caisse/{id}` (`caisse.lire`/`vente.lire`, hors périmètre `ClotureZ`) — ce
  champ n'est pas un « attendu de clôture » et reste visible comme aujourd'hui.
- **Réouverture après alerte** — `RouvrirCaisseProcessor` (code régisseur requis sur caisse
  sécurisée, CA-2) n'est **pas modifié** : une alerte d'écart non consultée n'empêche pas la
  réouverture. L'alerte reste consultable indépendamment de l'état ultérieur de la caisse.
- **Clôture déjà faite** — Comportement inchangé (409, RG-M2-06) ; aucune alerte n'est réévaluée sur
  une session déjà close.
- **Utilisateur avec `caisse.voir_z` mais sans `caisse.cloturer`** — Peut consulter tout Z existant
  mais ne peut pas déclencher l'endpoint `.../cloturer` (gardé par `caisse.cloturer`, inchangé) :
  rôle de simple superviseur/consultation.
- **Utilisateur avec `caisse.voir_ecart` mais sans `caisse.voir_z`** — Voit la liste des alertes
  (montant d'écart, session, horodatage) mais ne peut pas consulter le détail `ClotureZ` associé
  (403 sur `GET /clotures-z/{id}`) : permet un profil « supervision multi-site des écarts » sans accès
  au détail comptable fin de chaque caisse — ⚠ HYPOTHÈSE, cas d'usage non explicité par le client mais
  cohérent avec la distinction demandée entre les deux permissions.
- **Une seule alerte par clôture** — Pas de ré-évaluation ni de doublon : l'alerte est créée une fois,
  au moment de la clôture, jamais recalculée a posteriori (cohérent avec l'irréversibilité de
  `ClotureZ`).
- **Écart nul exact** — `ecartTotal = 0,00` avec `toleranceEcartCaisse = 0,00` → `|0| > 0` est faux →
  pas d'alerte (comparaison stricte, RG-CAISSEZ-05).

## 8. Dépendances
- Dépend de : `spec-vente.md` (L2, `CloturerSessionProcessor`, `ClotureZ`, `SessionCaisse`, RG-M2-01/06)
  — cette spec l'étend sans la réécrire.
- Dépend de : socle M8 (`App\Securite`, permissions `module × action`, `Affectation`, cloisonnement
  `PerimetreVenteExtension` — à étendre à `AlerteEcartCaisse` pour appliquer RG-CAISSEZ-06).
- Dépend de : `App\Audit\Service\JournalAudit` (RG-SOCLE-07) pour la traçabilité de l'alerte
  (RG-CAISSEZ-07).
- S'inspire de (sans en dépendre techniquement) : `App\Autorisation` (escalade d'opérations sensibles)
  pour le principe « établissement porté par l'objet + visibilité par permission » et de l'alerte
  « gros retrait » déjà existante (`MouvementCaisse.alerteRegisseur`, `PointDeVente.seuilAlerteRetrait`,
  cahier M2-§8) pour le principe « seuil paramétrable par point de vente ».
- Le NF525 (scellement, chaînage, irréversibilité de `ClotureZ`) n'est **pas** touché par cette spec
  (RG-CAISSEZ-09).

## 9. Points ouverts
- **Canal de l'alerte** : in-app uniquement dans cette version (aucun mécanisme e-mail/notification
  transverse trouvé dans le code). Si le client exige un e-mail immédiat au régisseur, il faudra soit
  un module `Notification` transverse (à créer), soit un envoi direct via le mailer Symfony —
  décision à prendre avant le plan d'implémentation.
- **Défaut de tolérance** : fixé à `0,00 €` par cette spec (tout écart déclenche une alerte tant que
  personne n'a paramétré de tolérance) ; à confirmer avec le client — un défaut trop strict peut
  générer beaucoup d'alertes si l'arrondi de caisse est courant.
- **Portée de la tolérance** (point de vente vs établissement) : cf. ⚠ HYPOTHÈSE RG-CAISSEZ-08.
- **Composition des rôles M8** : quelles permissions concrètes portent les rôles « Caissier » et
  « Régisseur »/« Administrateur du site » aujourd'hui paramétrés chez les clients — à vérifier/ajuster
  en configuration, pas dans le code (hors périmètre de cette spec).
- **Acquittement des alertes** : non demandé, non spécifié ; évolution future si le client veut un
  suivi (« traitée par… », commentaire).
