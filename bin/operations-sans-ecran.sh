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

# ⚠ LE VERBE N'EST PAS SUR LA LIGNE DE L'APPEL, ET PAS TOUJOURS ENTRE APOSTROPHES.
#
# Ces deux expressions cherchaient `method: 'VERBE'` en apostrophes simples, sur le reste de la
# LIGNE seulement. Le client ecrit couramment le verbe deux lignes plus bas : l'outil retombait
# alors sur GET, et le couple (GET, chemin) n'appariait jamais la route POST correspondante.
# Quinze operations etaient declarees sans ecran alors qu'un ecran monte les appelle.
#
# ⚠ ET LA BORNE COMPTE AUTANT QUE L'ELARGISSEMENT. Sans elle, un appel SANS methode ramasserait le
# `method:` de l'appel SUIVANT : un GET deviendrait un POST, et l'instrument mentirait dans l'autre
# sens. Un sur-comptage se voit moins qu'une absence -- le cliquet se mettrait a accepter du travail
# qui n'existe pas.
#
# C'est mot pour mot ce que `frontend/scripts/lib/ecart.mjs` fait DEPUIS LE 30/08. Les deux fichiers
# mesurent la meme chose ; celui-la avait ete repare, celui-ci non, et c'est celui-ci qu'on citait.
# Toute modification ici doit etre reportee la-bas, et reciproquement.
APPEL = re.compile(r"request\(\s*[`'\"]([^`'\"]+)[`'\"]")
INTERPOLATION = re.compile(r"\$\{[^}]*\}")
METHODE = re.compile(r"method:\s*['\"]([A-Z]+)['\"]")


def verbe_de(source, depart):
    """Le verbe de l'appel commence a `depart`, cherche jusqu'au prochain `request(`."""
    restant = source[depart:]
    prochain_appel = restant.find("request(", 8)
    corps = restant if prochain_appel == -1 else restant[:prochain_appel]
    trouve = METHODE.search(corps)
    return trouve.group(1) if trouve else "GET"


# ⚠ TEMOIN DE L'INSTRUMENT, PAS DU DEPOT.
#
# Il porte sur la CAPACITE A VOIR, jamais sur un chemin precis du client : un temoin tire d'un cas
# vivant tombe le jour ou quelqu'un renomme la route, et declare mort un instrument sain. On donne
# donc a l'apparieur une source fabriquee ici, dont on connait la reponse.
#
# Les deux sens sont necessaires. Sans le second, une expression qui rendrait POST pour tout
# passerait le premier -- et on aurait remplace la sous-estimation par une sur-estimation.
_T_ECRITURE = "  a: (id) =>\n    request(`/api/x/${id}/agir`, {\n      method: 'POST',\n    }),\n"
_T_LECTURE = "  b: () =>\n    request('/api/y'),\n\n  c: () =>\n    request('/api/z', {\n      method: 'PUT',\n    }),\n"

if verbe_de(_T_ECRITURE, _T_ECRITURE.index("request(")) != "POST":
    raise SystemExit(
        "✗ L'apparieur ne voit pas un `method:` place a la ligne suivante.\n"
        "  Il rendrait GET pour toute ecriture, et declarerait sans ecran des operations\n"
        "  qu'un ecran appelle. Ne pas se fier au rapport."
    )

if verbe_de(_T_LECTURE, _T_LECTURE.index("request(")) != "GET":
    raise SystemExit(
        "✗ L'apparieur ramasse le `method:` de l'appel SUIVANT.\n"
        "  Il rendrait une ecriture pour une lecture, et l'ecart serait sous-estime --\n"
        "  ce qui se voit moins qu'une absence. Ne pas se fier au rapport."
    )

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
        appeles.add((verbe_de(source, m.start()), chemin))

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
