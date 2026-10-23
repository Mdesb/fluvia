# Installer l'imprimante de caisse (ticket 80 mm, sans boîte de dialogue)

Pour : le poste de caisse sous Windows 11 et une imprimante thermique 80 mm. Exemple : Aures ODP 333
au CIAP Casa Xanxo.
But : qu'une vente imprime son billet ou son ticket **tout de suite**, sans la fenêtre « Imprimer ».

## Ce que fait Fluvia

- Après une vente **gratuite**, le billet part à l'impression tout seul, dès que son QR code est
  dessiné.
- Après une vente payante, « Imprimer le ticket » et « Imprimer le billet » envoient le document au
  rouleau.
- Le document est mis en page pour du papier de **80 mm**, dont **72 mm** imprimables. Il est en noir
  sur blanc, sans fond. Sa longueur est celle du document : le pilote coupe à la fin, sans papier
  blanc.

La fenêtre « Imprimer » ne disparaît que si le navigateur est lancé avec l'option
`--kiosk-printing`. Le document part alors directement à **l'imprimante par défaut** de Windows.

## 1. Installer l'imprimante

1. Branchez l'imprimante en USB et allumez-la.
2. Installez le pilote Windows du fabricant. Pour l'ODP 333, prenez-le sur le site d'Aures, pas un
   pilote « générique / texte seul ».
3. Dans **Paramètres › Bluetooth et appareils › Imprimantes et scanners** :
   - désactivez **« Autoriser Windows à gérer mon imprimante par défaut »** ;
   - ouvrez l'imprimante et choisissez **« Définir par défaut »**.
4. Dans **Options d'impression** (préférences du pilote) :
   - papier : **rouleau 80 mm** (par exemple « 80 × 297 mm » ou « Roll paper 80 mm ») ;
   - coupe : **en fin de document** ;
   - marges : aucune, si le pilote le propose.
5. Imprimez une page de test Windows pour vérifier le câble et le papier.

## 2. Créer le raccourci de la caisse

Sur le bureau : clic droit › **Nouveau › Raccourci**, puis collez **une** des deux cibles. Remplacez
l'adresse par celle de votre Fluvia.

Google Chrome :

```
"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing --user-data-dir="C:\FluviaCaisse" --app=https://votre-adresse-fluvia/caisse
```

Microsoft Edge :

```
"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" --kiosk-printing --user-data-dir="C:\FluviaCaisse" --app=https://votre-adresse-fluvia/caisse
```

Nommez le raccourci « Caisse Fluvia ».

> **Pourquoi `--user-data-dir` ?** Une option de lancement ne vaut que pour un navigateur qui
> démarre. Si Chrome est déjà ouvert pour autre chose, le raccourci rejoint cette fenêtre et
> `--kiosk-printing` est ignoré : la boîte de dialogue revient. Un dossier de profil à part donne
> à la caisse sa propre instance, qui démarre toujours avec l'option.

Pour démarrer la caisse avec Windows, copiez le raccourci dans le dossier `shell:startup`
(touche Windows + R, tapez `shell:startup`).

## 3. Vérifier

1. Ouvrez la caisse par le raccourci et connectez-vous.
2. Vendez un billet gratuit : « Valider la vente ». **Le billet sort seul.**
3. Vendez un article et réglez-le, puis « Imprimer le ticket ». Le ticket porte la raison sociale,
   l'adresse, le SIRET, la date et l'heure, les articles, le total TTC et la TVA par taux.

## Si ça ne marche pas

| Ce qu'on voit | Cause probable | Que faire |
|---|---|---|
| La fenêtre « Imprimer » s'ouvre | Le navigateur était déjà ouvert sans l'option, ou le raccourci n'a pas `--kiosk-printing` | Fermez **toutes** les fenêtres du navigateur, relancez par le raccourci |
| Le billet sort sur une autre imprimante | L'imprimante de caisse n'est pas celle par défaut, ou Windows la change tout seul | Étape 1.3 |
| Le billet est minuscule ou coupé à droite | Papier A4 ou Letter choisi dans le pilote | Étape 1.4 : rouleau 80 mm |
| Un long ruban blanc après le billet | Coupe réglée sur « page » et papier de 297 mm | Étape 1.4 : coupe en fin de document |
| Le carré du QR code est vide | Le QR n'était pas encore dessiné (impression manuelle trop rapide) | Réimprimez avec « Imprimer le billet » |
| La SIRET ou l'adresse manquent sur le ticket | L'identité légale de l'établissement n'est pas renseignée | Menu « Mentions légales » : raison sociale, adresse, SIRET |

Le ticket de caisse ne s'imprime plus systématiquement : il est remis **à la demande** du client
(article L541-15-10 du code de l'environnement). Un affichage au point de paiement doit le dire
(economie.gouv.fr, « Ticket de caisse : professionnels, quelles sont vos obligations ? »).
