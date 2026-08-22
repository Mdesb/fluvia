#!/usr/bin/env bash
#
# Banc d'essai des garde-fous et du hook `pre-receive` (C4/C16/C19).
#
#   ./bin/essai-garde-fous.sh
#
# **Pourquoi ce banc existe.** Les garde-fous sont du code qui juge du code : s'ils se trompent, ils
# le font silencieusement, dans le sens qui rassure. Deux défauts réels l'ont montré pendant leur
# écriture, et aucun des deux n'était visible à la relecture :
#
#   1. Le hook lançait le contrôle de nommage dans un conteneur, sans lui transmettre la quarantaine
#      d'objets de `pre-receive`. Le commit poussé y était invisible, le garde-fou annonçait « aucun
#      fichier ajouté » et **laissait passer**. Installé tel quel, il n'aurait jamais rien attrapé.
#   2. Un `2>/dev/null` confondait « git a échoué » et « rien à signaler ». Le premier cas rendait un
#      vert.
#
# Un garde-fou qu'on n'a jamais vu échouer ne prouve rien. Ce banc le fait échouer exprès, une fois
# par règle, et vérifie le **code de sortie** du push — pas la présence d'un texte : un `grep` qui ne
# correspond à rien ressemble à un succès, ce qui a déjà faussé un diagnostic ici.
#
# Il travaille sur un clone JETABLE : le dépôt vivant n'est jamais touché.

set -euo pipefail

DEPOT_SOURCE="${DEPOT_SOURCE:-/home/debian/billetterie.git}"
IMAGE="${IMAGE:-billetterie-preprod-php}"
# Même système de fichiers que le dépôt : `git clone --local` fait des liens durs, et /tmp est
# ailleurs (« Invalid cross-device link »).
ESSAI="${ESSAI:-$HOME/.essai-garde-fous}"

RACINE_SCRIPT="$(cd "$(dirname "$0")/.." && pwd)"
BARE="$ESSAI/depot.git"
COPIE="$ESSAI/copie"
OK=0; KO=0

nettoyer() { cd /; rm -rf "$ESSAI"; }
trap nettoyer EXIT

verdict() { # verdict <libellé> <refus|acceptation> <code>
    local libelle="$1" attendu="$2" code="$3" obtenu
    [ "$code" -eq 0 ] && obtenu=acceptation || obtenu=refus
    if [ "$obtenu" = "$attendu" ]; then
        printf '  \033[32m✓\033[0m %-52s %s\n' "$libelle" "$obtenu"
        OK=$((OK + 1))
    else
        printf '  \033[31m✗\033[0m %-52s %s (attendu : %s)\n' "$libelle" "$obtenu" "$attendu"
        KO=$((KO + 1))
    fi
}

# Le push qui échoue EST le comportement attendu dans la moitié des cas : on capture son code sans
# laisser `set -e` interrompre le banc — sinon le premier refus, qui est une réussite, arrête tout.
essai() { # essai <libellé> <refus|acceptation>
    local code=0
    git push "$BARE" main >/dev/null 2>&1 || code=$?
    verdict "$1" "$2" "$code"
}

commiter() { git add -A >/dev/null; git commit -q -m "$1"; }

# ─────────────────────────────────────────────────────────── préparation

echo "Banc d'essai des garde-fous — clone jetable, le dépôt vivant n'est pas touché."
echo

rm -rf "$ESSAI"; mkdir -p "$ESSAI"
git clone --bare --no-hardlinks -q "$DEPOT_SOURCE" "$BARE"
[ -d "$BARE/hooks" ] || { echo "✗ clone bare raté"; exit 1; }

git clone --no-hardlinks -q "$BARE" "$COPIE"
cd "$COPIE" || { echo "✗ cd raté"; exit 1; }
[ "$(pwd)" = "$COPIE" ] || { echo "✗ mauvais répertoire de travail"; exit 1; }

git config user.name "Banc"; git config user.email "banc@local"

# On teste les garde-fous du répertoire courant, pas ceux figés dans le dépôt : sinon le banc
# validerait la version d'hier et laisserait passer une régression écrite aujourd'hui.
cp -r "$RACINE_SCRIPT/bin" "$RACINE_SCRIPT/hooks" .
chmod +x bin/*.sh bin/*.php hooks/pre-receive
commiter "banc : garde-fous de l'arbre de travail"
git push -q "$BARE" main --force
bash bin/installer-hooks.sh "$BARE" >/dev/null

echo "État de départ (doit être accepté — si ce cas échoue, c'est \`main\` qui n'est pas vert) :"
echo "// banc" >> README.md; commiter "banc : commit anodin"
essai "commit anodin sur un dépôt sain" acceptation

# ─────────────────────────────────────────────────────────── cas de refus

echo
echo "Chaque règle doit refuser ce qu'elle prétend refuser :"

# Règle n°1 — résolution par identifiant client, aucun contrôle de périmètre dans le fichier.
mkdir -p app/src/Offre/State
cat > app/src/Offre/State/BancRegleUnProcessor.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\State;
use Doctrine\ORM\EntityManagerInterface;
final class BancRegleUnProcessor
{
    public function __construct(private readonly EntityManagerInterface $em) {}
    public function process(array $uriVariables): mixed
    {
        return $this->em->getRepository(\App\Offre\Entity\Produit::class)->find($uriVariables['id']);
    }
}
PHP
commiter "banc : règle 1"
essai "cloisonnement — aucun contrôle de périmètre" refus
git reset -q --hard HEAD~1

# Règle n°2 (C19) — un marqueur de périmètre EXISTE, mais il ne porte pas sur l'entité résolue.
# C'est le motif exact de l'IDOR d'appairage du 22/08 : la règle n°1 accepte ce fichier.
cat > app/src/Offre/State/BancRegleDeuxProcessor.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\State;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
final class BancRegleDeuxProcessor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {}
    public function process(array $corps): mixed
    {
        $actif = $this->contexte->idActif();   // marqueur présent — mais pour tout autre chose
        $produit = $this->em->getRepository(\App\Offre\Entity\Produit::class)->find($corps['produit']);
        return [$actif, $produit];
    }
}
PHP
commiter "banc : règle 2"
essai "C19 — contrôle non lié à l'entité résolue" refus
git reset -q --hard HEAD~1

# Nommage anglais (D5) — c'est ce cas que la quarantaine d'objets faisait passer en silence.
cat > app/src/Offre/State/BancFactureRemiseProcessor.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\State;
final class BancFactureRemiseProcessor
{
}
PHP
commiter "banc : nommage"
essai "nommage — identifiant français dans un fichier ajouté" refus
git reset -q --hard HEAD~1

# Secrets — une clé en valeur par défaut.
mkdir -p app/src/Offre/Service
cat > app/src/Offre/Service/BancSignataire.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\Service;
final class BancSignataire
{
    public function __construct(private readonly string $signingKey = 'cle-en-dur-oubliee') {}
}
PHP
commiter "banc : secret"
essai "secrets — clé cryptographique en valeur par défaut" refus
git reset -q --hard HEAD~1

# ─────────────────────────────────────────────────────────── remise en état

echo
echo "Et il doit laisser passer ce qui est propre :"
echo "// banc encore" >> README.md; commiter "banc : commit propre"
essai "commit propre après quatre refus" acceptation

echo "// banc interrupteur" >> README.md
cat > app/src/Offre/Service/BancSignataire.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\Service;
final class BancSignataire
{
    public function __construct(private readonly string $signingKey = 'encore-une-cle-en-dur') {}
}
PHP
commiter "banc : interrupteur"
touch "$BARE/hooks/GARDE-FOUS-DESACTIVES"
essai "interrupteur de désactivation respecté" acceptation
rm -f "$BARE/hooks/GARDE-FOUS-DESACTIVES"

# ─────────────────────────────────────────────────────────── verdict

echo
echo "─────────────────────────────────────────────"
if [ "$KO" -eq 0 ]; then
    printf '  \033[32m%d cas, tous conformes.\033[0m\n' "$OK"
    exit 0
fi
printf '  \033[31m%d échec(s) sur %d cas.\033[0m\n' "$KO" "$((OK + KO))"
exit 1
