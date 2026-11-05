---
titre: "Mettre en service une borne automatique de vente"
categorie: caisse-vente
publicCible: administrateur
portee: global
moduleLie: vente
statut: publie
resume: "Une borne vend seule des entrées et recharge les cartes, avec paiement par carte bancaire sur un terminal relié. Elle a son propre point de vente, un code de sortie pour le personnel, et s'appaire par un code court."
motsCles: [borne, borne automatique, libre-service, kiosque, recharge de carte, terminal de paiement, appairage, code de sortie]
---
## Ce que fait une borne

L'usager choisit, paie et repart avec son titre, sans passer au guichet. Chaque parcours tient en
**trois écrans** : **Choisir**, **Payer**, **Retirer**.

- **Acheter une entrée** : il choisit les entrées et leur nombre, puis paie.
- **Recharger ma carte** : il présente sa carte devant le lecteur, choisit la recharge, puis paie.
  La carte est vérifiée **avant** le paiement : une carte inconnue ne se paie pas.
- Le paiement se fait **uniquement par carte bancaire**, sur le terminal relié à la borne.
- Une fois le paiement accepté, la borne imprime le **ticket** (avec le QR code qui ouvre l'accès),
  ou remet un **badge** si elle est réglée ainsi.

**Un paiement refusé ne délivre rien** : ni ticket, ni recharge. L'usager peut réessayer avec une autre
carte, ou abandonner ; la borne revient alors à l'accueil. Sans geste pendant le délai réglé, elle y
revient aussi, toute seule.

## Avant de commencer

1. **Un point de vente dédié à la borne.** Dans **Paramètres › Caisse**, créez un point de vente
   (par exemple « Borne du hall ») **sans caisse**. Les ventes de la borne y sont enregistrées et
   clôturées à part : elles ne se mêlent jamais à celles du guichet.
2. **Un terminal de paiement relié** sur ce point de vente (voir « Payer par carte sur un terminal
   relié »). Le terminal « externe », où le caissier recopie le résultat, ne convient pas : à une
   borne, personne ne le ferait. Sans terminal relié, la borne affiche **« Borne hors service »**.
3. **Les produits à proposer** : dans la fiche de chaque produit, cochez le canal **« borne »**. La
   borne propose les tarifs visibles sur ce canal. Un tarif réduit qui demande un justificatif se règle
   « guichet » seulement. Les produits nominatifs (abonnements, par exemple) restent au guichet.

## Créer et appairer la borne

1. Dans **Paramètres › Caisse › Bornes automatiques de vente**, cliquez **Ajouter une borne**.
2. Donnez-lui un nom, choisissez son point de vente, et posez un **code de sortie** de 4 à 8
   chiffres pour le personnel. Enregistrez.
3. Cliquez **Appairer** : un code s'affiche (par exemple `K7QM-3XPA`). Il est valable **un quart
   d'heure** et ne sert **qu'une fois**.
4. Sur la borne, ouvrez l'adresse de Fluvia suivie de **`/borne`**, saisissez le code et touchez
   **Appairer cette borne**. La borne affiche l'accueil : elle est en service.

Pour remplacer l'appareil, cliquez **Remplacer l'appareil** et appairez le nouveau. Pour couper une
borne volée ou hors d'usage, cliquez **Désappairer** : elle cesse aussitôt de vendre.

## Quitter l'écran de la borne (personnel)

1. Appuyez **trois secondes** dans le **coin haut gauche** de l'écran.
2. Tapez le code de sortie, puis **OK**.
3. Choisissez **Reprendre la vente**, **Quitter le plein écran** (maintenance) ou **Oublier cet
   appareil** (il faudra l'appairer de nouveau).

Après **cinq codes faux**, le déverrouillage est fermé **cinq minutes**.

## Verrouiller le poste lui-même

La page de la borne bloque le retour arrière, le rechargement, le menu du clic droit et les touches de
sortie du navigateur. Elle **ne peut pas** bloquer Ctrl+Alt+Suppr, Alt+F4 ou la touche Windows : cela se
règle sur le poste.

- Lancez le navigateur en **mode kiosque** (Chrome ou Edge avec l'option `--kiosk`) sur l'adresse
  `/borne`, avec `--kiosk-printing` pour que le ticket sorte sans boîte de dialogue.
- Sous Windows, utilisez une session à **accès attribué** (kiosque à application unique).
- Ne laissez **aucun clavier physique** accessible à l'usager : l'écran tactile suffit, et le lecteur
  de carte se branche comme un clavier.

Même si quelqu'un sortait du navigateur, la borne ne donne accès à **rien d'autre** : son appairage
n'ouvre que la vente en libre-service, jamais le back-office.

## Si un paiement reste sans réponse

Si le terminal ne répond plus, la borne affiche **« Votre carte a peut-être été débitée :
adressez-vous à l'accueil »** avec le numéro de la vente. Retrouvez cette vente dans la caisse et
déclarez ce qu'affiche le terminal. Si le paiement est accepté, rééditez le ticket depuis la vente : il
portera la mention **DUPLICATA**, puisque la borne l'a déjà imprimé.

## Remise d'un badge

Le réglage **Badge encodé** demande un encodeur de badges raccordé à la borne. Tant qu'il ne l'est
pas, la borne le dit à l'usager et **imprime le ticket à la place** : l'usager repart toujours avec un
titre.
