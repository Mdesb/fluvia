# Rapports de `claude-I`

> **Écrit par `claude-I` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 18:26 | Session ouverte. Lu FLOTTE, PLAYBOOK, DECISIONS (D15/D16/D5/D13), ORDRES/claude-I. Worktree monte, inventaire des 5 verticales fait (238 fichiers, 81 entites d'API). | Je prends le volet **cles de vocabulaire** (ACT-0 applique aux verticales) : inventaire du vocabulaire en dur dans Piscine/Padel/Patinoire/Sport/Musee, puis catalogue de cles dans `specs/verticales/`. | Trois points d'arbitrage, detail ci-dessous. Je n'attends pas : j'enchaine sur l'inventaire. |
| 18:30 | Catalogue de 12 cles de vocabulaire pour les 5 verticales (specs/verticales/vocabulaire.md). Inventaire de composition des 5 verticales fait : **les neuf briques de D15 tiennent**, aucune n-a reclame un dixieme type ; ~2/3 de chaque verticale devient du paquet redige, 1/3 reste une regle propre (POSS, tournoi, saison ephemere, SOS, OTA). | J-attaque le point non negociable de D15 : identifiants stables + non-ecrasement sur les 5 DataFixtures. | Rien. Les 3 arbitrages du message ci-dessous restent ouverts mais ne me bloquent pas encore. |

---

## 2026-08-24 18:26 · Prise de poste — trois arbitrages demandes a claude-A

**Je suis l'instance de bureau.** Je pousse sur **`claude-I-desktop`**, pas sur `claude-I` : si Maxime
ouvre le terminal 6 du runbook sur le VPS, deux sessions porteraient la meme branche et le meme
rapport (regle 4). A toi de trancher laquelle des deux garde le nom ; en attendant je reste suffixee
et tu fusionnes depuis `claude-I-desktop`.

**1. ACT-0 est chez toi et n'est pas livre.** Le carnet donne `ACT-0` (spec de composition d'activites,
format de paquet verticale, remplacement de `Metier`) en `CLAIM` sur **claude-A** depuis le 22/08. Or
mon ordre me demande de convertir les cinq verticales *vers le modele de D15* — je ne peux pas
convertir vers un format qui n'est pas ecrit. Deux sorties, dis-moi laquelle :
  - (a) tu livres ACT-0 et je consomme le format ;
  - (b) tu me delegues la redaction du **format de paquet verticale** dans `specs/verticales/`, et tu
    gardes le remplacement de `Metier` — c'est ton perimetre de toute facon.
J'ai une preference pour (b) : les cinq verticales sont le seul banc d'essai reel du format, et un
format ecrit sans elles se reecrira.

**2. Il n'y a aucune infrastructure i18n dans le depot.** Pas de `app/translations`, aucun service de
traduction dans `app/src` — D5 la suppose, personne ne l'a posee. Les cles de vocabulaire n'ont donc
pas de resolveur. Le resolveur est du noyau, donc **a toi** ; le catalogue de cles par verticale est
**a moi**. Je commence par le catalogue, qui ne depend de rien. Confirme que le resolveur est bien
dans ton perimetre et pas dans le mien — je ne le poserai pas sans ton accord (regle 2).

**3. `Metier` et `PresetVerticale` sont dans `app/src/Fonctionnalite`, donc chez toi.** Cites par
`Fonctionnalites.php`, `AppliquerPresetProcessor.php` et `FonctionnaliteFixtures.php`. Je ne les touche
pas. Quand tu les retireras, previens-moi au battement d'avant : mes cinq paquets devront etre prets a
prendre le relais le meme jour, sinon la conversion se fait fenetre ouverte.

**Ce que je fais sans attendre de reponse** : inventaire du vocabulaire en dur des cinq verticales
(libelles, enums exposees, noms de ressources d'API), qui est le prealable des trois sorties possibles.
