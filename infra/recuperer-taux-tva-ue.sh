#!/usr/bin/env bash
#
# RÉCUPÉRER LES TAUX DE TVA DES ÉTATS MEMBRES DEPUIS TEDB (Commission européenne, DG TAXUD).
#
# ── ⚠ POURQUOI CE GESTE EST SÉPARÉ DE L'IMPORT ────────────────────────────────────────────────
#
# L'interface REST de TEDB n'est pas documentée publiquement. Je l'ai découverte le 02/09 en lisant
# le JavaScript de l'application et en interceptant sa propre requête dans un navigateur. Elle
# répond, elle est servie par la Commission — mais rien ne garantit que son chemin ou la forme de son
# corps survivront à la prochaine version.
#
# Une commande qui l'appellerait à chaud casserait un jour sans prévenir, au milieu d'une mise en
# service. Ici la récupération est un geste délibéré : elle écrit un fichier DATÉ, qui devient la
# preuve de ce qui a été importé. `vat:import-tedb` lit ce fichier et rien d'autre.
#
# ── LA FORME DE LA REQUÊTE, ET CE QUI M'A COÛTÉ DU TEMPS ──────────────────────────────────────
#
# Trois choses ne se devinent pas, et j'ai tourné une heure autour avant de les capter :
#
#   1. le corps est enveloppé dans `searchForm` — un POST à plat rend 500 ;
#   2. les dates s'écrivent `AAAA/MM/JJ` avec des barres obliques, pas des tirets ;
#   3. les pays se désignent par un identifiant NUMÉRIQUE, pas par leur code ISO.
#
# Les identifiants viennent de `rest-api/configurations`, qui est public et répond en GET. On les lit
# donc à chaque exécution plutôt que de les figer : une liste figée deviendrait fausse le jour d'un
# élargissement, et personne ne le verrait.
set -euo pipefail

BASE="https://ec.europa.eu/taxation_customs/tedb/rest-api"
SORTIE="${1:-/home/debian/validateurs/tva-ue-$(date +%Y-%m-%d).json}"

echo "── Identifiants des États membres (rest-api/configurations) ──"

IDS="$(curl -sf --max-time 60 "$BASE/configurations" | python3 -c '
import json, sys
d = json.load(sys.stdin)
ids = [c["id"] for c in (d.get("countries") or [])]
if len(ids) < 20:
    sys.exit("moins de vingt pays lus : la configuration est inexploitable")
print(",".join(str(i) for i in ids))
')"

echo "  ${IDS}"
NB="$(echo "$IDS" | tr ',' '\n' | wc -l)"
echo "  ${NB} pays"

# ⚠ PLANCHER. Un `configurations` tronqué produirait une requête sur trois pays, et l'import
# suivant rendrait « 6 taux importés » sans que rien ne dise que vingt-quatre pays manquent.
if [ "$NB" -lt 20 ]; then
    echo "✗ INSTRUMENT MORT : ${NB} pays lus. Un export partiel a l'air d'un export." >&2
    exit 1
fi

echo
echo "── Taux en vigueur au $(date +%Y/%m/%d) ──"

CORPS="$(python3 -c "
import json, sys, datetime
ids = [int(x) for x in '${IDS}'.split(',')]
print(json.dumps({
    'searchForm': {
        'selectedMemberStates': ids,
        'dateFrom': None,
        'dateTo': datetime.date.today().strftime('%Y/%m/%d'),
        'selectedCategories': None,
        'selectedCnCodes': [],
        'selectedCpaCodes': [],
    },
    'availableFacets': None,
    'selectedFacets': None,
}))")"

curl -sf --max-time 180 -X POST \
    -H 'Content-Type: application/json' \
    --data-binary "$CORPS" \
    "$BASE/vatSearch" -o "$SORTIE"

TAILLE="$(wc -c < "$SORTIE")"
ENTREES="$(python3 -c "
import json
d = json.load(open('$SORTIE'))
print(len(d.get('result') or []))
")"

echo "  $SORTIE  (${TAILLE} octets, ${ENTREES} entrées)"

# ⚠ SECOND PLANCHER, SUR CE QU'ON A REÇU. Le premier gardait la requête ; celui-ci garde la réponse.
# Un `result` vide serait un fichier valide, de belle taille, et sans une seule ligne de taux.
if [ "$ENTREES" -lt 20 ]; then
    echo "✗ INSTRUMENT MORT : ${ENTREES} entrées reçues pour ${NB} pays." >&2
    echo "  L'interface a peut-être changé de forme. Ne pas importer ce fichier." >&2
    exit 1
fi

echo
echo "  ✓ Fichier écrit."
echo
# ── ⚠ DEUX PIEGES, ET J'AI PRIS LES DEUX LE 02/09 ───────────────────────────────────
#
# 1. LE CHEMIN. Le conteneur monte `../app:/app` — mais AUSSI `app_var:/app/var`, un volume Docker
#    qui RECOUVRE le `app/var` de l'hôte. Un fichier déposé dans `app/var/` depuis l'hôte y est
#    donc INVISIBLE, et la commande répond « Fichier introuvable » : ça se lit comme un export
#    raté alors que l'export est parfait. D'où `app/imports/`, monté et ignoré par git — cinq
#    mégaoctets n'ont pas leur place dans l'historique.
#
# 2. L'UTILISATEUR. `exec php bin/console` tourne en ROOT, et tout ce qu'il écrit dans
#    `/app/var/cache` cesse d'appartenir à `www-data`. PHP-FPM peut encore LIRE ces entrées, donc
#    l'API répond tant que le cache est complet : la préprod est armée, pas cassée, et le premier
#    défaut de cache rend 500. C'est arrivé, quarante minutes. D'où `-u www-data`.
#    Le contrôle : `./infra/verifier-droits-var.sh`, qui répare avec `--reparer`.
echo "  L'importer, en le rendant d'abord visible du conteneur :"
echo "      cp $SORTIE /home/debian/billetterie/app/imports/tva-ue.json"
echo "      docker compose -f infra/compose.preprod.yaml --env-file infra/.env.preprod \\"
echo "        exec -T -u www-data php php bin/console vat:import-tedb /app/imports/tva-ue.json --a-blanc"
echo
echo "  ⚠ CE QUI SERA IMPORTÉ EST PLUS ÉTROIT QUE CE FICHIER, à dessein :"
echo "      — seuls les taux STANDARD. TEDB donne jusqu'à six taux réduits par pays sans dire"
echo "        lequel est le second réduit, le super réduit ou le parking ;"
echo "      — pas l'Espagne : elle rend 7 % et 21 % à la même date (Canaries et péninsule, un seul"
echo "        code ISO), et notre référentiel n'accepte qu'une valeur par (pays, catégorie, date) ;"
echo "      — pas ce qui contredit une ligne déjà sourcée à la main."
echo
echo "    La commande compte et nomme chacun de ces écarts. Un import silencieux rendrait un"
echo "    référentiel d'apparence complète dont personne ne connaîtrait les trous."
