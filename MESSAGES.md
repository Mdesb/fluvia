# Contournements de garde-fou, justifiés

Ce fichier existe parce que `hooks/pre-commit` le demande : *« Si tu dois vraiment
passer outre : `git commit --no-verify`, et dis pourquoi dans MESSAGES.md. »*

Un contournement non écrit est indiscernable d'un oubli. Écrit, il se relit, se
conteste, et se retire quand sa raison disparaît.

---

## 30/08 — garde-fou n°11 (formats d'écriture), contourné une fois

**Par** allaccess-c2, **avec l'accord explicite de Maxime**, sur le lot du
compostage de billet.

**Ce que le contrôle disait :**

    ✗ Formats : POST /api/acces/controle-billet sans `ld: true`.
      Cette opération est standard : elle désérialise le corps…

**Pourquoi il avait tort.** Il collecte les `uriTemplate` déclarés côté serveur et
traite comme « standard » tout chemin client qui n'en correspond à aucun. La route
n'existait pas encore — elle arrive dans le lot d'allaccess-8e — donc il la
classait standard **par défaut**. Il affirmait une propriété de la route qu'il
déduisait de son absence.

Vérifié auprès de 8e : sa route est en `uriTemplate` sur mesure + `input: false`,
comme les quatre autres `POST` de son module. Suivre le conseil aurait posé un
`ld: true` que sa route refuse en 415 — le contrôle n'aurait pas signalé un
défaut, il en aurait fait poser un.

**Pourquoi ne pas attendre.** Deux cliquets corrects se verrouillaient l'un
l'autre : le n°15 exige qu'une route ait un écran, le n°11 exige qu'un appel
client ait une route. Un lot coupé en deux moitiés qui se conditionnent ne peut
entrer que si l'une passe d'abord. `@route-a-venir:` est le mécanisme prévu pour
cette fenêtre — il n'était câblé que dans le n°15. Le correctif du n°11 existait
depuis deux heures, non publié.

**Ce qui reste vrai** : les 27 autres garde-fous étaient verts, le marqueur
`@route-a-venir:` est en place, et il redevient sans objet dès que la route
existe. Ce contournement n'a plus de raison d'être après l'intégration du lot de
8e — si vous le relisez après, il est périmé.
