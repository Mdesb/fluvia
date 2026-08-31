# -*- coding: utf-8 -*-
#
# TROISIEME RESSERREMENT, ET CELUI-CI VIENT D'UN FAUX POSITIF VERIFIE A LA MAIN.
#
# `Offre/Entity/Produit.php $libelle NotBlank <- DupliquerProcessor` etait signale. En ouvrant :
#
#     new Post(uriTemplate: '/produits/{id}/dupliquer', read: true, input: false, ...)
#     $copie->setLibelle($this->suffixerLibelle($data->getLibelle()));
#
# `input: false` -- aucun corps n'est deserialise, donc la validation d'entree ne s'applique pas --
# et le processeur ecrit sur une COPIE NEUVE, pas sur l'objet valide. Aucun piege.
#
# ⚠ « Le processeur appelle setX » ne suffit donc pas. Ce qui compte est : IL ECRIT SUR `$data`,
# c'est-a-dire sur l'objet meme que la validation vient d'examiner. C'est le seul cas ou la
# contrainte et l'ecriture portent sur la meme chose -- et donc le seul ou l'ordre les separe.
#
# Historique des trois erreurs de cet instrument, toutes trouvees par un temoin ou une lecture :
#   1. appariement par NOM DE PROPRIETE a travers tout le depot      -> produit cartesien
#   2. cle par NOM DE FICHIER, neuf classes homonymes                -> huit invisibles (par 8e)
#   3. n'importe quel `->setX(`, y compris sur un objet fabrique     -> faux positifs
import os
import re
import sys

SRC = "/home/debian/billetterie/app/src"


def espace(c):
    m = re.search(r"^namespace\s+([\w\\]+);", c, re.M)
    return m.group(1) if m else ""


fichiers = []
for base, _, noms in os.walk(SRC):
    for n in noms:
        if n.endswith(".php"):
            fichiers.append(os.path.join(base, n))
contenus = {f: open(f, encoding="utf-8", errors="replace").read() for f in fichiers}

# ── Ce que chaque processeur ecrit SUR `$data` ──────────────────────────────────────────────────
sur_data, lus = {}, 0
for f, c in contenus.items():
    court = os.path.basename(f)[:-4]
    if not court.endswith("Processor"):
        continue
    lus += 1
    sur_data[espace(c) + "\\" + court] = {
        p[0].lower() + p[1:] for p in re.findall(r"\$data->set([A-Z]\w*)\(", c)
    }

if len(sur_data) != lus or any("\\" not in k for k in sur_data):
    print("✗ INSTRUMENT CASSE : %d fichiers, %d cles" % (lus, len(sur_data)))
    sys.exit(1)
print("✓ instrument : %d processeurs, %d cles qualifiees distinctes" % (lus, len(sur_data)))

ASSERT = re.compile(r"#\[Assert\\(\w+)")
suspects, temoin_vu = [], False

for f, c in contenus.items():
    if "#[ORM\\Entity" not in c or "ApiResource" not in c:
        continue
    courts = set(re.findall(r"processor:\s*(\w+)::class", c))
    if not courts:
        continue

    imp = {u.rsplit("\\", 1)[-1]: u for u in re.findall(r"^use\s+([\w\\]+);", c, re.M)}
    ns = espace(c)
    designes = {imp.get(x, ns + "\\" + x) for x in courts}
    entite = os.path.basename(f)[:-4]

    def poses(prop):
        return sorted(p.rsplit("\\", 1)[-1] for p in designes if prop in sur_data.get(p, ()))

    for bloc in re.finditer(
        r"((?:^[ \t]*#\[[^\n]*\]\n)+)[ \t]*(?:private|protected|public)[^\n]*\$(\w+)\s*[;=]",
        c, re.M
    ):
        attributs, prop = bloc.group(1), bloc.group(2)
        contraintes, poseurs = ASSERT.findall(attributs), poses(prop)
        if contraintes and poseurs:
            suspects.append((os.path.relpath(f, SRC), prop, contraintes, poseurs))

    if "Assert\\Callback" in c:
        for chemin in set(re.findall(r"->atPath\('(\w+)'\)", c)):
            poseurs = poses(chemin)
            if entite == "Vitrine" and chemin == "slug" and poseurs:
                temoin_vu = True
            if poseurs:
                e = (os.path.relpath(f, SRC), chemin, ["Callback"], poseurs)
                if e not in suspects:
                    suspects.append(e)

print("temoin Vitrine::slug : %s" % ("✓ vu" if temoin_vu else "✗ RATE"))
if not temoin_vu:
    sys.exit("✗ le compte ne vaut rien sans le temoin.")

print("\n── SUSPECTS : %d ──" % len(suspects))
for fichier, prop, contraintes, poseurs in sorted(suspects):
    print("  %-46s $%-18s %-14s <- %s"
          % (fichier, prop, "/".join(contraintes), ", ".join(poseurs)))
