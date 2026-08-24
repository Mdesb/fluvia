# Ordres pour `claude-I`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais — c'est ce qui garantit
> qu'il n'y a jamais de conflit de fusion dessus.

---

## 2026-08-24 12:40 · Premier ordre — le vocabulaire avant le code

Tu portes les cinq verticales existantes — piscine, padel, patinoire, sport, musée — et leur
conversion vers le modèle de D15.

### Commence par les clés de vocabulaire, c'est le plus rentable

**Un « créneau » est un *rendez-vous* chez le coiffeur et une *réservation de terrain* au padel.** Même
concept, mots différents. Aujourd'hui le vocabulaire est en dur, et c'est ce qui rend le logiciel
illisible pour un métier qui n'est pas celui pour lequel on l'a écrit.

C'est peu coûteux, très visible pour l'exploitant, et cela ne casse rien — donc c'est par là qu'on
commence.

### Puis la composition d'activités

D15 a supprimé l'énumération `Metier` au profit d'une **composition** : un établissement ne « a » plus
un métier, il **compose** ce qu'il exerce. Un camping a un bar, un restaurant, des hébergements, une
piscine et un bowling.

Neuf types d'activité couvrent tous les métiers évoqués. **Sept existent déjà** ; hébergement et
restauration sont chez claude-F. Ton travail est de **convertir les cinq verticales existantes** en
compositions, sans casser leur comportement actuel.

**Une verticale devient un paquet rédigé, pas un module développé** : manifeste, activités composées,
données de départ à identifiants stables — avec **non-écrasement à la mise à jour**, faute de quoi une
montée de version écrase les tarifs du client. Ce point-là n'est pas négociable.

### Ce à quoi tu fais attention

Ces cinq modules exposent **81 entités d'API** à eux seuls. Chaque conversion touche donc du
cloisonnement. Fais tourner ta suite de module **plus** `tests/Platform` avant de pousser, et n'oublie
pas que les garde-fous refuseront tout identifiant français dans un fichier neuf (D5).
