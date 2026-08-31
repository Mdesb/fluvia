#!/usr/bin/env python3
"""Ce que nous venons de livrer est-il SERVI ?

    python3 bin/verifier-visibilite.py [https://…]

Telecharge l'index du site, suit TOUS les paquets JavaScript qu'il cite — y compris les differes,
atteints par leurs imports relatifs — et cherche dedans une empreinte de chaque livraison.

── POURQUOI CET OUTIL EXISTE ───────────────────────────────────────────────────────────────────

Le 29/08/2026, quatre ecrans annonces comme livres etaient invisibles : tout etait pousse sur une
branche, rien n'etait dans `main`. On a meme demande a l'exploitant de cliquer un bouton sur un
ecran dont l'entree de menu n'existait pas chez lui.

La verification n'etait pas laxiste — chaque ecran avait ete eprouve contre l'API reelle par un
serveur de developpement local. Elle avait le mauvais PERIMETRE : elle prouvait que le code marche,
pas qu'il soit arrive. Plus la verification est bonne, plus l'illusion est solide.

── DEUX REGLES DE CONCEPTION, ET ELLES ONT CHACUNE COUTE UNE MESURE FAUSSE ──────────────────────

1. UN TEMOIN POSITIF EST OBLIGATOIRE. Le tableau doit contenir au moins une chose qu'on s'attend a
   TROUVER. Sans elle, « absent partout » ne se distingue pas de « je cherche mal ». Premiere
   version de ce script : elle ne suivait que les paquets cites dans le principal, en a lu UN au
   lieu de vingt-neuf, et rendait ABSENT sur tout — y compris sur ce qui etait bel et bien servi.

2. L'EMPREINTE COMPTE AUTANT QUE LE PARCOURS. Une chaine partagee entre modules — un chemin de
   route, un nom de champ d'API — vit dans le client d'API, donc dans le paquet principal. La
   chercher prouve que le CLIENT est servi, jamais que l'ecran l'est : tout ressort VISIBLE, et
   tout dans le meme paquet. Il faut une chaine que SEUL cet ecran peut produire — un libelle
   affiche, une classe CSS qui lui appartient.

   (Trouve par claude-A en verifiant une integration : ses empreintes etaient des chemins de route,
   tout ressortait vert depuis un unique paquet.)
"""
import re
import sys
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else "https://smartaccess.hector-conseil.com").rstrip("/")

# Une empreinte par livraison. UN LIBELLE, PAS UNE ROUTE — voir la regle 2 ci-dessus.
# Le temoin est une livraison ANCIENNE, deja integree : s'il ressort absent, c'est l'instrument
# qui est en cause, pas le deploiement, et le script le dit au lieu de rendre un rapport faux.
EMPREINTES = [
    ("TEMOIN — saisie de facture fournisseur", "Numéro de la facture"),
    ("ecran RGPD", "la loi laisse un mois"),
    ("dialogue de fin de vente", "Souhaitez-vous un ticket"),
    ("zone de depot photo", "Déposez une photo ici"),
    ("favoris de caisse", "Épingler en tête"),
    ("echelle d'espacement", "--esp-section"),
    ("zones desservies par un lecteur", "Emplacement \u2014 la porte physique"),
    ("bouton retour dans l'application", "Revenir \u00e0 l\u2019\u00e9cran pr\u00e9c\u00e9dent"),
    ("insertion de variables de campagne", "endroit du curseur"),
]


def lire(url):
    req = urllib.request.Request(url, headers={"Accept": "*/*"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read().decode("utf-8", "replace")


def cites(texte):
    """Tous les paquets cites : balises, prechargement, ET imports relatifs des differes."""
    sortie = set()
    for brut in re.findall(r'["\'\(]([^"\'\(\)]*?[-\w]+\.(?:js|css))["\'\)]', texte):
        brut = brut.strip()
        if brut.startswith("http"):
            continue
        if "/assets/" in brut or brut.startswith(("./", "assets/")):
            sortie.add("/assets/" + brut.split("/")[-1])
    return sortie


index = lire(BASE + "/")
a_visiter, deja, lus = sorted(cites(index)), set(), 0
trouves = {nom: None for nom, _ in EMPREINTES}

while a_visiter:
    chemin = a_visiter.pop(0)
    url = BASE + chemin
    if url in deja:
        continue
    deja.add(url)
    try:
        corps = lire(url)
    except Exception:
        continue
    lus += 1
    for nom, aiguille in EMPREINTES:
        if trouves[nom] is None and aiguille in corps:
            trouves[nom] = chemin
    # LA DESCENTE. Les paquets differes ne sont cites nulle part dans l'index : ils le sont dans le
    # paquet d'entree, sous des chemins RELATIFS. Sans cette boucle on ne lit que l'entree — et le
    # rapport rend ABSENT sur tout, y compris sur ce qui est bel et bien servi.
    # (Perdu une fois en reecrivant ce script, et rattrape par le temoin. C'est a ca qu'il sert.)
    for autre in cites(corps):
        if BASE + autre not in deja:
            a_visiter.append(autre)

print("%s — %d paquet(s) lu(s)\n" % (BASE, lus))
for nom, _ in EMPREINTES:
    ou = trouves[nom]
    print(("  VISIBLE  %-34s " % ou if ou else "  ABSENT   " + " " * 34) + nom)

temoin = EMPREINTES[0][0]
if trouves[temoin] is None:
    print("\n⚠ LE TEMOIN EST ABSENT : ce rapport ne prouve rien.")
    print("  Une livraison deja integree devrait ressortir. Si elle ne ressort pas, c'est le")
    print("  parcours ou l'empreinte qui est en cause, pas le deploiement. Corrigez l'instrument")
    print("  avant de conclure quoi que ce soit sur le reste.")
    sys.exit(2)

manquants = [n for n, _ in EMPREINTES[1:] if trouves[n] is None]
if manquants:
    print("\n%d livraison(s) non servie(s) : %s" % (len(manquants), ", ".join(manquants)))
    print("Poussé n'est pas visible. Ne l'annoncez pas comme disponible.")
    sys.exit(1)

print("\nTout est servi. C'est ce qu'on peut annoncer comme disponible.")
