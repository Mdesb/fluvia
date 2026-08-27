#!/usr/bin/env bash
#
# CE QUE LE SERVEUR SAIT FAIRE ET QU'AUCUN ÉCRAN NE DÉCLENCHE — nommé, pas compté.
#
# ── POURQUOI CE SCRIPT EXISTE ───────────────────────────────────────────────────────────────────
#
# `mesurer-ecart` rend un nombre : 748 opérations inatteignables. Un nombre ne se travaille pas. Il
# dit qu'il y a du travail, pas lequel, et surtout pas par quoi commencer.
#
# Pire, il compte des occurrences de `new Get(` dans le code source : il ne sait donc pas dire de
# QUELLE opération il parle. Ce script part de l'autre bout — le routeur, qui est la vérité sur ce
# que le serveur expose réellement — et retranche ce que les clients appellent.
#
#   > Un compteur dit qu'il y a du travail. Une liste dit par où le prendre.
#
# ── CE QU'IL NE FAIT PAS ────────────────────────────────────────────────────────────────────────
#
# Il ne juge pas. Une opération sans écran peut être :
#
#   · un ÉCRAN MANQUANT — c'est le cas le plus fréquent, et huit ont été trouvées le 27/08, dont la
#     boutique sans inscription et les badges qu'on ne pouvait pas révoquer ;
#   · une PORTE DE SERVICE — les terminaux de contrôle d'accès envoient leurs passages tout seuls,
#     il n'y aura jamais d'écran pour ça, et c'est très bien.
#
# Rien ici ne distingue les deux : c'est une décision de produit, pas une mesure. Quand la réponse
# est « porte de service », on l'écrit dans le fichier PHP avec `@sans-ecran: <raison>` — et le
# garde-fou n°15 cesse de la compter.
#
# ── USAGE ───────────────────────────────────────────────────────────────────────────────────────
#
#   ./bin/operations-sans-ecran.sh              par module, du plus fourni au moins fourni
#   ./bin/operations-sans-ecran.sh Support      le détail d'un module
#
set -uo pipefail

RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FILTRE="${1:-}"
CONTENEUR="${CONTENEUR_PHP:-billetterie-preprod-php-1}"

if ! sudo docker inspect "$CONTENEUR" >/dev/null 2>&1; then
    echo "✗ Conteneur « $CONTENEUR » introuvable." >&2
    echo "  Ce script lit le ROUTEUR, pas le code source : il lui faut l'application démarrée." >&2
    exit 2
fi

ROUTES="$(sudo docker exec "$CONTENEUR" php bin/console debug:router --env=prod 2>/dev/null \
    | grep -E '^\s+_api_' || true)"

if [ -z "$ROUTES" ]; then
    echo "✗ Le routeur n'a rendu aucune route d'API." >&2
    exit 2
fi

# ⚠ LES ROUTES PASSENT PAR UN FICHIER, PAS PAR UN TUBE.
#
# `printf … | python3 - <<'PY'` semble naturel et ne marche pas : le heredoc qui porte le programme
# ÉCRASE l'entrée standard. `sys.stdin` ne rend alors rien, et le script annonce « 0 opération
# exposée » avec un aplomb parfait — un rapport qui compte zéro se lit comme un dépôt propre.
ROUTES_FICHIER="$(mktemp)"
trap 'rm -f "$ROUTES_FICHIER"' EXIT
printf '%s\n' "$ROUTES" > "$ROUTES_FICHIER"

RACINE="$RACINE" FILTRE="$FILTRE" ROUTES_FICHIER="$ROUTES_FICHIER" python3 - <<'PY'
import io
import os
import re
import sys
from collections import defaultdict

RACINE = os.environ["RACINE"]
FILTRE = os.environ["FILTRE"]

# ── 1. CE QUE LE SERVEUR EXPOSE ─────────────────────────────────────────────────────────────────
#
# Une ligne de `debug:router` : nom, méthodes, chemin. Le `.{_format}` est un artefact d'API
# Platform (négociation de contenu) : il ne fait pas partie du chemin qu'un client appelle.
LIGNE = re.compile(r"^\s+(\S+)\s+(\S+)\s+(\S+)\s*$")

expose = {}
for ligne in io.open(os.environ["ROUTES_FICHIER"], encoding="utf-8"):
    m = LIGNE.match(ligne.rstrip("\n"))
    if m is None:
        continue
    nom, methodes, chemin = m.groups()
    chemin = chemin.replace(".{_format}", "").replace("{._format}", "")
    for methode in methodes.split("|"):
        if methode in ("ANY", ""):
            methode = "GET"
        expose[(methode, chemin)] = nom

# ── 2. CE QUE LES CLIENTS APPELLENT ─────────────────────────────────────────────────────────────
#
# Les deux fronts ont chacun leur transport. En oublier un ferait compter comme absent ce qui marche
# — le défaut corrigé le 27/08, où la boutique en ligne entière passait pour inatteignable.
CLIENTS = [
    os.path.join(RACINE, "frontend", "src", "api", "client.js"),
    os.path.join(RACINE, "frontend", "src", "public", "api", "boutiqueClient.js"),
]

APPEL = re.compile(r"request\(\s*[`'\"]([^`'\"]+)[`'\"]([^\n]*)")
INTERPOLATION = re.compile(r"\$\{[^}]*\}")
METHODE = re.compile(r"method:\s*'([A-Z]+)'")

appeles = set()
for chemin_client in CLIENTS:
    try:
        source = io.open(chemin_client, encoding="utf-8").read()
    except OSError:
        continue
    for m in APPEL.finditer(source):
        chemin = INTERPOLATION.sub("{id}", m.group(1))
        if not chemin.startswith("/api/"):
            continue
        suite = METHODE.search(m.group(2))
        appeles.add(((suite.group(1) if suite else "GET"), chemin))

# ── 3. LA SOUSTRACTION ──────────────────────────────────────────────────────────────────────────
#
# Un chemin du routeur porte ses paramètres sous leur vrai nom (`{produitId}`), le client les a tous
# ramenés à `{id}`. On compare donc sur une forme normalisée — sans quoi tout paraîtrait
# inatteignable, ce qui serait un rapport spectaculaire et faux.
def normaliser(chemin):
    return re.sub(r"\{[^}]+\}", "{}", chemin)

appeles_normalises = {(m, normaliser(c)) for m, c in appeles}

# Le marqueur qui déclare une surface volontairement sans écran, lu dans les fichiers PHP.
declares = set()
for racine, _, fichiers in os.walk(os.path.join(RACINE, "app", "src")):
    for f in fichiers:
        if not f.endswith(".php"):
            continue
        texte = io.open(os.path.join(racine, f), encoding="utf-8").read()
        if "@sans-ecran:" not in texte:
            continue
        for m in re.finditer(r"shortName:\s*'(\w+)'", texte):
            declares.add(m.group(1))

sans_ecran = defaultdict(list)
for (methode, chemin), nom in sorted(expose.items(), key=lambda x: x[0][1]):
    if (methode, normaliser(chemin)) in appeles_normalises:
        continue
    # Le module se lit du premier segment significatif du chemin.
    segment = chemin.split("/")[2] if len(chemin.split("/")) > 2 else "(racine)"
    module = segment.split("_")[0]
    sans_ecran[module].append("%-7s %s" % (methode, chemin))

# UN ROUTEUR QUI NE REND RIEN N'EST PAS UN DÉPÔT SANS API : C'EST UNE LECTURE RATÉE.
# Sans ce refus, le premier essai de ce script annonçait « 0 opération exposée, 0 sans écran » —
# la plus rassurante des réponses fausses.
if not expose:
    print("Aucune route lue depuis le routeur : la mesure serait fausse, pas rassurante.")
    sys.exit(2)

total = sum(len(v) for v in sans_ecran.values())

if FILTRE:
    cle = FILTRE.lower()
    trouve = [m for m in sans_ecran if m.lower().startswith(cle)]
    if not trouve:
        print("Aucune opération sans écran pour « %s »." % FILTRE)
        sys.exit(0)
    for module in sorted(trouve):
        print("── %s ── %d opération(s)" % (module, len(sans_ecran[module])))
        for op in sans_ecran[module]:
            print("   " + op)
    sys.exit(0)

print("Opérations exposées par le routeur : %d" % len(expose))
print("Appelées par un client             : %d" % len(appeles))
print("SANS ÉCRAN                         : %d" % total)
if declares:
    print("Déclarées « @sans-ecran: »         : %s" % ", ".join(sorted(declares)))
print()
print("Par module, du plus fourni au moins fourni — le détail avec le nom du module en argument :")
print()
for module, ops in sorted(sans_ecran.items(), key=lambda x: -len(x[1])):
    print("  %4d  %s" % (len(ops), module))
PY
