#!/usr/bin/env python3
"""
LIT LE VERDICT D'UNE SECTION DU RAPPORT DE VALIDATION FACTUR-X.

⚠ PAR SECTION, JAMAIS LE RESUME GLOBAL. Mesure du 02/09 : le rapport rendait
`<summary status="valid"/>` au niveau document alors que sa section `<pdf>` disait `invalid` avec
huit erreurs. Un outil tiers peut mentir comme un test peut mentir ; on interroge la partie qui
repond a la question qu'on pose.

⚠ ET IL DIT « absente » PLUTOT QUE DE RENDRE VIDE. Une section manquante et une section sans verdict
sont deux etats differents : le premier veut dire que le validateur n'est pas alle jusque-la (un PDF
ordinaire n'a pas de section XML), le second qu'il a repondu quelque chose qu'on ne sait pas lire.
Les confondre ferait passer un rapport tronque pour un rapport vide.

usage : section.py <pdf|xml> < rapport.txt
"""
import re
import sys

if len(sys.argv) != 2:
    print("absente")
    sys.exit(0)

section = sys.argv[1]
texte = sys.stdin.read()

bloc = re.search(r"<%s>(.*?)</%s>" % (re.escape(section), re.escape(section)), texte, re.S)
if bloc is None:
    print("absente")
    sys.exit(0)

verdicts = re.findall(r'<summary status="([a-z]+)"', bloc.group(1))
print(verdicts[0] if verdicts else "illisible")
