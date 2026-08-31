# -*- coding: utf-8 -*-
#
# LIRE LE MARQUEUR DE VERSION SANS SE FAIRE AVOIR PAR LE REPLI SPA.
#
# `location / { try_files $uri $uri/ /index.html; }` rend `index.html` avec un code **200** pour
# toute URL inconnue. Un `version.json` absent ne repond donc pas 404 : il repond une page.
#
# ⚠ UN CONTROLE ECRIT UN PEU VITE LIRAIT 200 ET CONCLURAIT « LE MARQUEUR REPOND ». Il rendrait alors
# un verdict sur rien -- exactement ce que ce marqueur existe pour empecher. Signale par
# allaccess-b8, temoin de controle a l'appui : une URL inventee rend elle aussi 200 text/html.
#
# La garde cote serveur (`location = /version.json` avec `try_files $uri =404`) est ecrite dans
# `infra/nginx/billetterie-preprod.conf`, mais ce fichier N'EST PAS DEPLOYE AUTOMATIQUEMENT et
# diverge du serveur sur le Basic Auth : le recopier remettrait une authentification que Maxime a
# fait lever. L'application de ce seul bloc demande un geste privilegie qui ne m'appartient pas.
#
# Donc la garde est ICI, dans l'outil qui conclut : on exige du JSON qui porte un `commit`. Ca marche
# que nginx soit corrige ou non, et ca ne devient jamais faux -- seulement redondant.
import io
import json
import sys
import urllib.error
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else "https://smartaccess.hector-conseil.com").rstrip("/")


def version_servie(base):
    """
    Le commit servi, ou une explication de pourquoi on ne le sait pas.

    Rend un couple (version|None, explication). On ne rend JAMAIS un identifiant devine.
    """
    requete = urllib.request.Request(base + "/version.json", headers={"Accept": "application/json"})

    try:
        with urllib.request.urlopen(requete, timeout=15) as reponse:
            brut = reponse.read().decode("utf-8", "replace")
            type_contenu = reponse.headers.get("Content-Type", "")
    except urllib.error.HTTPError as e:
        return None, "le marqueur repond %d — il n'est pas publie sur cette machine" % e.code
    except Exception as e:
        return None, "injoignable : %s" % e

    # ⚠ LE CODE 200 NE SUFFIT PAS, ET C'EST TOUT LE SUJET.
    if "json" not in type_contenu.lower():
        return None, (
            "le marqueur rend du %s, pas du JSON — c'est le repli SPA qui a servi index.html. "
            "Cette preproduction ne publie pas encore version.json." % (type_contenu or "contenu inconnu")
        )

    try:
        donnees = json.loads(brut)
    except ValueError:
        return None, "le marqueur n'est pas du JSON lisible"

    if not isinstance(donnees, dict) or not donnees.get("commit"):
        return None, "le marqueur ne porte pas de `commit` : il ne repond pas a la question posee"

    # ⚠ `boucle` EST OBLIGATOIRE, ET C'EST LE COEUR DE CE CONTROLE.
    #
    # Ce champ n'est ecrit qu'apres que le deploiement a relu SON PROPRE URL PUBLIQUE et y a retrouve
    # le commit qu'il venait de construire. Son absence signifie l'une de deux choses, et aucune ne
    # permet de conclure : la verification a echoue, ou elle a ete sautee.
    #
    # C'est le remede a une ligne de controle qui disparait sans crier -- un `git reset --hard` en a
    # emporte une, et le deploiement suivant est passe en silence. Une ligne FAUSSE crie ; une ligne
    # ABSENTE ne crie pas. En rendant la verification necessaire a la conclusion, on transforme son
    # absence en reponse. Formule par allaccess-8e.
    if not donnees.get("boucle"):
        return None, (
            "le marqueur ne porte pas de `boucle` : le deploiement n'a pas relu son URL publique, "
            "ou l'a relue sans y retrouver son commit. Le commit annonce n'est donc pas garanti "
            "etre celui que cette URL sert."
        )

    return donnees, None


if __name__ == "__main__":
    donnees, explication = version_servie(BASE)

    if donnees is None:
        print("%s — VERSION SERVIE INCONNUE" % BASE)
        print("  %s" % explication)
        print("  ⚠ Ne concluez rien sur ce qui est deploye : l'absence de reponse n'est pas une reponse.")
        sys.exit(2)

    # ⚠ ON NOMME CE QU'ON A INTERROGE, SANS PRETENDRE LE PROUVER. L'adresse resolue est une
    # observation du CLIENT sur sa propre connexion : le fichier servi ne peut pas l'ecrire. Elle ne
    # prouve pas qu'on parle a la bonne machine -- rien ne le prouve depuis l'exterieur -- mais elle
    # rend le cas « mauvaise machine » visible au lieu de silencieux.
    import socket
    from urllib.parse import urlparse

    hote = urlparse(BASE).hostname or "?"
    try:
        adresse = socket.gethostbyname(hote)
    except Exception:
        adresse = "non resolue"

    print("%s sert %s (%s)" % (BASE, donnees["commit"], donnees.get("branche", "branche inconnue")))
    print("  interroge : %s → %s" % (hote, adresse))
    print("  boucle verifiee par le deploiement sur %s" % donnees["boucle"])
    if donnees.get("sujet"):
        print("  %s" % donnees["sujet"])
    if donnees.get("construit"):
        print("  construit %s" % donnees["construit"])
