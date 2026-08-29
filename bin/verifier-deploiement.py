#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
LE DEPLOIEMENT DIT-IL LA VERITE SUR LUI-MEME ?

Le 29/08, `POST /api/notifications/tout-lu` rendait 500 sur la preproduction :

    Uncaught Error: Failed opening required
    '/app/var/cache/prod/ContainerBGgx6du/getMarkAllNotificationsReadProcessorService.php'

Le fichier existait dans DEUX autres repertoires de conteneur compile ; le processus qui servait
l'application en referencait un troisieme, disparu du disque. Le cache avait ete reconstruit pendant
qu'un processus gardait l'ancien conteneur en memoire, et les services paresseux de cet ancien
conteneur sont partis avec son repertoire. Signale par allaccess-8e, qui a lu les hachages plutot que
de relire le code neuf.

⚠ ET LE SYMPTOME ACCUSE TOUJOURS CE QUI A ETE ECRIT EN DERNIER. Trois fois dans la journee. A chaque
fois la route neuve semblait fautive alors que le defaut etait dans l'etat du serveur -- ce qui
envoie relire un code sain pendant que la vraie cause attend.

── CE QUE CHAQUE REPONSE PROUVE, ET CE QU'ELLE NE PROUVE PAS ───────────────────────────────────

On appelle en ANONYME : aucun identifiant ne traverse ce script, donc il peut tourner a chaque
deploiement sans qu'un secret y circule.

    401 / 403   la route existe et le noyau demarre.  ⚠ RIEN DE PLUS : le pare-feu refuse AVANT
                qu'API Platform ne construise le fournisseur de l'operation. Un conteneur incoherent
                rend 401 exactement comme un conteneur sain -- mesure, pas suppose : la panne du
                29/08 a ete reproduite, et les 401 n'ont pas bouge.
    200         la route a ete servie : le fournisseur s'est construit, ses dependances aussi.
                C'EST LA SEULE REPONSE QUI PROUVE QUELQUE CHOSE SUR LE CONTENEUR.
    5xx         le serveur ne sait pas repondre.

D'ou la forme du controle : on sonde tout pour attraper les 5xx, et l'on EXIGE qu'au moins une route
publique rende 200. Sans ce temoin, « aucune erreur serveur » se lit comme « tout va bien » alors
qu'on n'a mesure qu'un pare-feu.

── CE QUE CE CONTROLE N'ATTRAPE PAS, ET IL FAUT LE SAVOIR ──────────────────────────────────────

⚠ IL N'AURAIT PAS ATTRAPE LA PANNE DU 29/08 QUI L'A FAIT NAITRE. Eprouve : le repertoire du
conteneur compile a ete supprime sous le processus qui sert la preproduction, et les soixante
routes ont continue de repondre normalement. opcache garde en memoire ce qui est DEJA charge ; seul
un service **jamais encore instancie** disparait avec son fichier -- c'est-a-dire exactement un
service neuf, celui du lot qu'on vient de deployer.

Sonder les routes ANCIENNES ne peut donc pas reveler ce defaut-la. Ce controle atteste qu'une
application est debout ; il n'atteste pas qu'un service neuf se construit.

Le remede de cette famille est procedural, pas detective, et le script de deploiement l'applique
deja : **redemarrer php APRES le warmup**, jamais avant. La panne du 29/08 vient d'un `cache:clear`
lance a la main sur le conteneur en marche, hors du script -- c'est ce geste-la qu'il faut ne plus
faire.

⚠ ON N'APPELLE QUE DES LECTURES. Sonder les ecritures donnerait un signal plus large, mais une seule
route mal protegee suffirait a produire un effet de bord a chaque deploiement. Le prix d'un controle
ne doit jamais etre paye par les donnees.
"""
import json
import subprocess
import sys
import urllib.error
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else "https://smartaccess.hector-conseil.com").rstrip("/")
COMPOSE = [
    "docker", "compose",
    "-f", "infra/compose.preprod.yaml",
    "--env-file", "infra/.env.preprod",
    "exec", "-T", "php",
    "php", "bin/console", "debug:router", "--format=json",
]


def collections():
    """Les GET d'API sans parametre d'URL : sondables telles quelles."""
    brut = subprocess.run(COMPOSE, capture_output=True, text=True).stdout
    debut = brut.find("{")
    if debut == -1:
        return []

    try:
        table = json.loads(brut[debut:])
    except ValueError:
        return []

    chemins = set()
    for nom, route in table.items():
        if not nom.startswith("_api_"):
            continue
        methode = route.get("method", "")
        if "GET" not in methode and methode not in ("ANY", ""):
            continue
        chemin = route.get("path", "").replace("{._format}", "")
        if "{" in chemin:
            continue
        chemins.add(chemin)

    return sorted(chemins)


def code(chemin):
    requete = urllib.request.Request(BASE + chemin, headers={"Accept": "application/ld+json"})
    try:
        with urllib.request.urlopen(requete, timeout=20) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code
    except Exception:
        return 0


chemins = collections()

if not chemins:
    print("⚠ AUCUNE ROUTE N'A PU ETRE SONDEE : ce rapport ne prouve rien.")
    print("  Le routeur n'a rien rendu — c'est l'instrument qu'il faut regarder, pas le deploiement.")
    sys.exit(2)

casses = []
servies = []

for chemin in chemins:
    c = code(chemin)
    if c >= 500:
        casses.append((chemin, c))
    elif 200 <= c < 300:
        servies.append(chemin)

print("%s — %d collection(s) sondée(s) en anonyme, %d réellement servie(s)" % (
    BASE, len(chemins), len(servies),
))

if casses:
    print("\n%d route(s) rendent une erreur serveur :" % len(casses))
    for chemin, c in casses:
        print("  %s  →  %d" % (chemin, c))
    print("\n⚠ Ce n'est pas le code de la route qui est en cause en premier lieu. Un conteneur")
    print("  compilé incohérent produit exactement ce symptôme, et il accuse toujours ce qui a été")
    print("  écrit en dernier : redémarrer php APRÈS le warmup, puis re-sonder.")
    sys.exit(1)

# ── LE TEMOIN ───────────────────────────────────────────────────────────────────────────────────
if not servies:
    print("\n⚠ AUCUNE ROUTE PUBLIQUE N'A ÉTÉ SERVIE : ce rapport ne prouve rien.")
    print("  Les refus (401/403) arrivent AVANT la construction des fournisseurs : ils ne disent")
    print("  rien de la cohérence du conteneur. Sans au moins un 200, « aucune erreur serveur »")
    print("  ne mesure qu'un pare-feu.")
    sys.exit(2)

print("\nAucune erreur serveur, et %d route(s) publique(s) réellement servie(s)." % len(servies))
print("Le déploiement répond de lui-même.")
