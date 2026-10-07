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

# Certains contrôles doivent LAISSER PASSER tout en disant quelque chose — l'avertissement
# d'obsolescence du hook (D28) en est un. `essai` ne juge que le code de sortie ; celui-ci lit
# aussi ce que le push a imprimé, sinon un avertissement muet passerait pour un succès.
essai_avertissement() { # essai_avertissement <libellé> <motif attendu dans la sortie>
    local libelle="$1" motif="$2" code=0
    local sortie="$ESSAI/sortie-push.txt"
    git push "$BARE" main >"$sortie" 2>&1 || code=$?

    if [ "$code" -ne 0 ]; then
        printf '  \033[31m✗\033[0m %-52s refus (attendu : acceptation avec avertissement)\n' "$libelle"
        KO=$((KO + 1))
    elif grep -q "$motif" "$sortie"; then
        printf '  \033[32m✓\033[0m %-52s acceptation + avertissement\n' "$libelle"
        OK=$((OK + 1))
    else
        printf '  \033[31m✗\033[0m %-52s accepté SANS avertissement (attendu : « %s »)\n' "$libelle" "$motif"
        KO=$((KO + 1))
    fi

    git fetch -q "$BARE" main
    git reset -q --hard FETCH_HEAD
    git clean -qfd
}

# Le pendant du precedent : la poussee doit passer et NE PAS dire une certaine chose. Sert a
# prouver qu'un avertissement a cessé — donc que ce qui le causait a été réparé.
essai_sans_avertissement() { # essai_sans_avertissement <libellé> <motif qui ne doit PAS sortir>
    local libelle="$1" motif="$2" code=0
    local sortie="$ESSAI/sortie-push.txt"
    git push "$BARE" main >"$sortie" 2>&1 || code=$?

    if [ "$code" -ne 0 ]; then
        printf '  \033[31m✗\033[0m %-52s refus (attendu : acceptation silencieuse)\n' "$libelle"
        KO=$((KO + 1))
    elif grep -q "$motif" "$sortie"; then
        printf '  \033[31m✗\033[0m %-52s avertit encore (« %s »)\n' "$libelle" "$motif"
        KO=$((KO + 1))
    else
        printf '  \033[32m✓\033[0m %-52s acceptation silencieuse\n' "$libelle"
        OK=$((OK + 1))
    fi

    git fetch -q "$BARE" main
    git reset -q --hard FETCH_HEAD
    git clean -qfd
}

# Le push qui échoue EST le comportement attendu dans la moitié des cas : on capture son code sans
# laisser `set -e` interrompre le banc — sinon le premier refus, qui est une réussite, arrête tout.
essai() { # essai <libellé> <refus|acceptation> [fragment attendu dans le refus]
    local code=0
    local sortie="$ESSAI/sortie-essai.txt"
    git push "$BARE" main >"$sortie" 2>&1 || code=$?

    # ── Un refus ne prouve rien tant qu'on ne sait pas POURQUOI il a eu lieu ────────────────────
    #
    # Mesure du 01/09 : avec une image docker inexistante, le hook refuse TOUT — et les onze cas de
    # refus de ce banc restaient verts. Ils ne prouvaient pas que le garde-fou visé avait mordu,
    # seulement que quelque chose avait refusé. Le banc lui-même donnait un faux vert.
    if [ "$2" = "refus" ] && [ "$code" -ne 0 ] && [ -n "${3:-}" ]; then
        if ! grep -qF "$3" "$sortie"; then
            printf '  \033[31m✗\033[0m %-52s refusé, mais PAS pour la bonne raison\n' "$1"
            echo "       attendu dans la sortie : « $3 »"
            KO=$((KO + 1))
            git fetch -q "$BARE" main
            git reset -q --hard FETCH_HEAD
            git clean -qfd
            return
        fi
    fi

    verdict "$1" "$2" "$code"

    # Chaque cas repart de l'état réel du dépôt, quelle que soit l'issue du précédent. Sans ça, un cas
    # accepté à tort fait avancer le distant, la copie locale diverge, et **tous les cas suivants sont
    # rejetés en non-fast-forward** — donc comptés comme des refus qui n'en sont pas. Un banc doit
    # échouer sur un seul cas quand un seul cas est cassé.
    git fetch -q "$BARE" main
    git reset -q --hard FETCH_HEAD
    git clean -qfd
}

commiter() {
    git add -A >/dev/null
    # `--allow-empty` : quand tout le travail est déjà fusionné dans le dépôt, copier l'arbre courant
    # dans le clone ne produit aucune différence. Sans cette option le commit échoue, `set -e` tue le
    # banc, et on croit à une régression alors que tout va bien. Constaté le 23/08, une fois le lot de
    # garde-fous intégré à `main` — le banc supposait que mon worktree diverge, ce qui n'est vrai que
    # tant que le travail n'est pas fusionné.
    git commit -q --allow-empty -m "$1"
}

# ─────────────────────────────────────────────────────────── préparation

# —— `main` porte-t-il des garde-fous que cet arbre n'a pas ? —————————————————————————
#
# C'est la première cause des échecs de masse de ce banc, constatée trois fois. Le filet de
# complétude refuse alors la poussée, et TOUS les cas d'acceptation tombent — « commit anodin sur
# un dépôt sain » compris. On cherche le défaut dans le code qu'on vient d'écrire ; il n'y est pas.
#
# La condition ne se mesure pas en nombre de commits de retard — on peut en avoir vingt sans
# conséquence. Elle se vérifie : un garde-fou présent sur `main` et absent ici.
if git rev-parse --verify -q origin/main >/dev/null 2>&1; then
    ABSENTS="$(comm -23         <(git ls-tree --name-only origin/main bin/ | grep -E "bin/garde-fou-" | xargs -r -n1 basename | sort)         <(git ls-tree --name-only HEAD bin/       | grep -E "bin/garde-fou-" | xargs -r -n1 basename | sort))"
    if [ -n "$ABSENTS" ]; then
        echo
        printf '
  [31m⚠ Cet arbre est en retard sur `main`, et ça suffit à faire tomber le banc.[0m

'
        echo
        echo "  \`main\` déclare des garde-fous que tu n'as pas :"
        printf "    %s
" $ABSENTS
        echo
        echo "  Le filet de complétude refusera la poussée, et les cas d'ACCEPTATION tomberont pour"
        echo "  cette raison-là — pas à cause de ce que tu viens d'écrire. Ne cherche pas dans ton code."
        echo
        echo "    git fetch origin && git merge --no-edit origin/main"
        echo
    fi
fi

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
essai "cloisonnement — aucun contrôle de périmètre" refus "résolution par identifiant client sans contrôle de périmètre"

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
essai "C19 — contrôle non lié à l'entité résolue" refus "le contrôle de périmètre ne porte pas sur l'entité résolue"

# C19, croisement avec la déclaration de l'opération. Quand celle-ci est `read: false`, `$data` vient
# du corps de la requête et non du provider Doctrine : il redevient une entrée client. Les deux cas
# qui suivent sont le même code, à `read:` près — c'est ce qui prouve que le croisement discrimine et
# ne se contente pas de tout signaler.
mkdir -p app/src/Offre/Entity
cat > app/src/Offre/Entity/BancSondeResource.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Offre\State\BancSansLectureProcessor;
/**
 * ⚠ SONDE DE BANC : elle n'aura JAMAIS d'ecran, et ce n'est pas une dette.
 *
 * @sans-ecran: sonde du banc d'essai des garde-fous, jamais appelee par une interface.
 * @sans-suppression: sonde du banc, aucune donnee reelle n'est creee, rien a supprimer.
 *
 * ⚠ CES DECLARATIONS SONT UNE DEPENDANCE VIVANTE, pas une formalite. Le montage doit rester propre
 * au regard de TOUS les garde-fous, y compris ceux ecrits par d'autres sessions apres ce cas.
 * Sinon un cas d'ACCEPTATION vire au rouge pour une raison etrangere a ce qu'il teste, et il masque
 * alors exactement ce qu'il devait prouver. C'est arrive deux fois : `@sans-ecran` pour le cliquet
 * d'ecart, puis `@sans-suppression` pour le garde-fou des creations irreversibles.
 *
 * Sans cette declaration, le cliquet d'ecart n15 -- gele a la valeur courante -- compte cette
 * operation comme une inatteignable de plus et fait ECHOUER le cas, quel que soit le garde-fou que
 * le cas visait. Un banc rouge en permanence ne se lit plus : on s'habitue a l'echec connu, et le
 * prochain, reel, se range dans la meme case.
 */
#[ApiResource(operations: [
    new Post(
        uriTemplate: '/banc/sonde',
        read: false,
        input: false,
        processor: BancSansLectureProcessor::class,
    ),
])]
class BancSondeResource {}
PHP
cat > app/src/Offre/State/BancSansLectureProcessor.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\State;
use Doctrine\ORM\EntityManagerInterface;
final class BancSansLectureProcessor
{
    public function __construct(private readonly EntityManagerInterface $em) {}
    public function process(mixed $data): mixed
    {
        return $this->em->getRepository(\App\Offre\Entity\Produit::class)->find($data->getProduitRef());
    }
}
PHP
commiter "banc : C19 read false"
essai "C19 — \$data d'une opération read: false" refus "le contrôle de périmètre ne porte pas sur l'entité résolue"

mkdir -p app/src/Offre/Entity app/src/Offre/State
cat > app/src/Offre/Entity/BancSondeResource.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Offre\State\BancSansLectureProcessor;
/**
 * ⚠ SONDE DE BANC : elle n'aura JAMAIS d'ecran, et ce n'est pas une dette.
 *
 * @sans-ecran: sonde du banc d'essai des garde-fous, jamais appelee par une interface.
 * @sans-suppression: sonde du banc, aucune donnee reelle n'est creee, rien a supprimer.
 *
 * ⚠ CES DECLARATIONS SONT UNE DEPENDANCE VIVANTE, pas une formalite. Le montage doit rester propre
 * au regard de TOUS les garde-fous, y compris ceux ecrits par d'autres sessions apres ce cas.
 * Sinon un cas d'ACCEPTATION vire au rouge pour une raison etrangere a ce qu'il teste, et il masque
 * alors exactement ce qu'il devait prouver. C'est arrive deux fois : `@sans-ecran` pour le cliquet
 * d'ecart, puis `@sans-suppression` pour le garde-fou des creations irreversibles.
 *
 * Sans cette declaration, le cliquet d'ecart n15 -- gele a la valeur courante -- compte cette
 * operation comme une inatteignable de plus et fait ECHOUER le cas, quel que soit le garde-fou que
 * le cas visait. Un banc rouge en permanence ne se lit plus : on s'habitue a l'echec connu, et le
 * prochain, reel, se range dans la meme case.
 */
#[ApiResource(operations: [
    new Post(
        uriTemplate: '/banc/sonde',
        read: true,
        input: false,
        processor: BancSansLectureProcessor::class,
    ),
])]
class BancSondeResource {}
PHP
cat > app/src/Offre/State/BancSansLectureProcessor.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\State;
use Doctrine\ORM\EntityManagerInterface;
final class BancSansLectureProcessor
{
    public function __construct(private readonly EntityManagerInterface $em) {}
    public function process(mixed $data): mixed
    {
        return $this->em->getRepository(\App\Offre\Entity\Produit::class)->find($data->getProduitRef());
    }
}
PHP
commiter "banc : C19 read true"
essai "C19 — le même code en read: true n'est pas signalé" acceptation

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
essai "nommage — identifiant français dans un fichier ajouté" refus "identifiant français dans un fichier nouvellement ajouté"

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
essai "secrets — clé cryptographique en valeur par défaut" refus "secret cryptographique en valeur par défaut"

# Couverture de perimetre : une entite exposee que rien ne peut filtrer. C'est le trou par lequel
# `GET /ecritures-comptables` renvoyait le grand livre de tous les etablissements.
mkdir -p app/src/Offre/Entity
cat > app/src/Offre/Entity/BancSansTenant.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ApiResource(operations: [new GetCollection(security: "is_granted('PERM', 'offre.lire')")])]
class BancSansTenant
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 0;
}
PHP
commiter "banc : entite sans tenant"
essai "couverture — entité exposée sans cloisonnement possible" refus "entité exposée sans cloisonnement possible"

# Garde-fou n°35 : une entite RATTACHABLE (relation vers un ancrage) exposee, que l'extension de son
# module n'enumere pas. C'est le cas d'`OperationScellee` — le n°5 l'avait gelee en vrac, le n°35 la
# refuse parce que le rattachement prouve qu'elle DOIT etre filtree. Offre porte une extension a liste
# (`PerimetreProduitExtension`) ; cette entite n'y figure pas.
mkdir -p app/src/Offre/Entity
cat > app/src/Offre/Entity/BancRattachableHorsListe.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ApiResource(operations: [new GetCollection(security: "is_granted('PERM', 'offre.lire')")])]
class BancRattachableHorsListe
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 0;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;
}
PHP
commiter "banc : entite rattachable hors liste blanche"
essai "rattachable — exposée, absente de la liste blanche du module" refus

# --- garde-fou n°6, règle A : un abonné que rien ne déclenchera -------------------------------
# `sale.completed` est au catalogue et figure dans la ligne de base des orphelins : personne ne
# l'émet. Un fichier qui l'écoute est donc du code mort silencieux.
#
# ⚠ Ce fichier ne doit PAS contenir le mot « DomainEvent » : le garde-fou reconnaît un émetteur à ce
# motif, et la signature naturelle d'un écouteur (`__invoke(DomainEvent $e)`) le ferait basculer du
# côté des publications. D'où le paramètre typé `object`.
mkdir -p app/src/Offre/EventListener
cat > app/src/Offre/EventListener/BancAbonneInerte.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\EventListener;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
#[AsEventListener(event: 'sale.completed')]
final class BancAbonneInerte
{
    public function __invoke(object $evenement): void
    {
    }
}
PHP
commiter "banc : abonne a un fait que personne n emet"
essai "événements — abonné à un fait que personne n'émet" refus "abonné qui ne se déclenchera jamais"

# --- garde-fou n°6, règle C : un fait publié hors du contrat -----------------------------------
mkdir -p app/src/Offre/Service
cat > app/src/Offre/Service/BancEmetteurHorsContrat.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\Service;
use App\Platform\Event\DomainEvent;
final class BancEmetteurHorsContrat
{
    public function go(object $bus, object $tenant, object $sujet): void
    {
        $bus->publish(new DomainEvent('banc.invente', $tenant, $sujet, []));
    }
}
PHP
commiter "banc : fait publie hors du catalogue"
essai "événements — fait publié hors du catalogue" refus "événement publié hors du contrat"

# --- garde-fou n°7 : charge utile qui ne respecte pas le contrat -------------------------------
# `sale.completed` EST au catalogue (donc la règle C se taît) et y annonce « amount, lines,
# customer? ». On l'émet avec une clé que le contrat n'annonce pas.
mkdir -p app/src/Offre/Service
cat > app/src/Offre/Service/BancChargeHorsContrat.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\Service;
use App\Platform\Event\DomainEvent;
final class BancChargeHorsContrat
{
    public function go(object $bus, object $tenant, object $sujet): void
    {
        $bus->publish(new DomainEvent('sale.completed', $tenant, $sujet, [
            'bancCleInventee' => 1,
        ]));
    }
}
PHP
commiter "banc : charge utile hors contrat"
essai "charges utiles — clé absente du contrat" refus "charge utile émise hors contrat"

# --- garde-fou n°8 : écriture qui traverse la frontière ----------------------------------------
#
# ⚠ La cible est choisie À L'EXÉCUTION, et ce n'est pas de la coquetterie. Ce cas visait `Promotion`
# en dur ; elle a été cloisonnée le 26/08, et le cas est alors devenu vert **en ne testant plus rien**.
# C'est la panne la plus insidieuse d'un banc : il ne casse pas, il ment.
#
# On prend donc la première entité encore non cloisonnée qui expose une écriture, et on ÉCHOUE
# bruyamment si l'on n'en trouve aucune — un banc sans cible doit le dire, pas se taire.
python3 <<'PY'
import io, json, os, re, sys

base = json.load(io.open('bin/couverture-perimetre.ligne-de-base.json', encoding='utf-8'))
for cle in base['entrees']:
    chemin = os.path.join('app/src', cle)
    if not os.path.isfile(chemin):
        continue
    src = io.open(chemin, encoding='utf-8', errors='replace').read()
    if '#[ApiResource' not in src or not re.search(r'new (Post|Patch|Put)\(', src):
        continue
    m = re.search(r"denormalizationContext:\s*\[\s*'groups'\s*=>\s*\[\s*'([^']+)'", src)
    if not m:
        continue
    groupe = m.group(1)
    ajout = (
        '\n    #[ORM' + chr(92) + 'ManyToOne(targetEntity: Ressource::class)]\n'
        "    #[Groups(['" + groupe + "'])]\n"
        '    private $bancRessource = null;\n'
    )
    i = src.rindex('}')
    io.open(chemin, 'w', encoding='utf-8').write(src[:i] + ajout + src[i:])
    print('  cible du banc : ' + cle + ' (groupe ' + groupe + ')')
    sys.exit(0)

sys.stderr.write("✗ Banc : aucune entite non cloisonnee exposant une ecriture — le cas du n8 ne teste\n")
sys.stderr.write("  plus rien. Choisis une autre forme de cas plutot que de le laisser passer.\n")
sys.exit(1)
PY
commiter "banc : relation ecrivable vers du cloisonne"
essai "écriture transfrontière — relation écrivable vers du cloisonné" refus "écriture qui traverse la frontière"

# --- garde-fou n°55 : l'énumération et le référentiel de repli qui divergent ---------------------
#
# ⚠ CE CAS ÉCRIT SES DEUX FICHIERS, il ne mute pas ceux de l'arbre — et c'est la seule forme qui
#   tienne. Le banc travaille sur un clone dont `app/src` vient de `main` : tant que le lot
#   « referentiel-metiers » n'y est pas, `TradeFallback.php` n'existe pas du tout. Le garde-fou se
#   tairait alors de lui-même (voir son en-tête), le push serait accepté, et le cas de REFUS
#   tomberait en accusant un garde-fou parfaitement sain.
#
# Ce qui est éprouvé ici est donc la RÈGLE, pas le contenu du jour : deux fichiers minimaux que le
# contrôle lit exactement comme les vrais. Aucune remise en état n'est nécessaire — `essai` fait un
# `reset --hard` + `clean` après chaque cas, et les deux cas sont des refus.

ecrire_metiers() { # ecrire_metiers <codes de l'énumération> <codes du repli>
    mkdir -p app/src/Fonctionnalite/Enum app/src/Website/Config

    {
        printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Fonctionnalite\\Enum;\n\nenum Metier: string\n{\n'
        for code in $1; do printf "    case Banc%s = '%s';\n" "$code" "$code"; done
        printf '}\n'
    } > app/src/Fonctionnalite/Enum/Metier.php

    {
        printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Website\\Config;\n\nfinal class TradeFallback\n{\n'
        printf '    public const NOMS = [\n'
        for code in $2; do printf "        '%s' => [],\n" "$code"; done
        printf '    ];\n\n    private const ACTIVITES = [\n'
        for code in $2; do printf "        '%s' => [],\n" "$code"; done
        printf '    ];\n}\n'
    } > app/src/Website/Config/TradeFallback.php
}

CINQ="piscine sport padel patinoire musee"

ecrire_metiers "$CINQ bowling" "$CINQ"
commiter "banc : un cas de Metier sans son entree de repli"
essai "métiers — un cas de l'énumération sans entrée de repli" refus "sans entrée dans"

ecrire_metiers "$CINQ" "$CINQ bowling"
commiter "banc : une entree de repli sans son cas de Metier"
essai "métiers — une entrée de repli sans cas dans l'énumération" refus "sans cas dans"

# --- garde-fou n°57 : une classe PHP citée qu'aucun fichier ne déclare ------------------------
# Comme pour le n°55, on écrit plutôt qu'on ne retire : un appelant minimal vers une classe qui
# n'existe pas est, pour le contrôle, l'état exact d'une classe supprimée et toujours citée.
mkdir -p app/src/Offre/Service
cat > app/src/Offre/Service/GhostCaller.php <<'PHP'
<?php
declare(strict_types=1);
namespace App\Offre\Service;
use App\Offre\Service\RemovedHelper;
final class GhostCaller
{
    public function run(): string
    {
        return RemovedHelper::class;
    }
}
PHP
commiter "banc : une classe citee dont le fichier n'existe pas"
essai "classes PHP — citée, déclarée nulle part" refus "qu'aucun fichier ne déclare"

# --- le filet de complétude lui-même ----------------------------------------------------------
# C'est le mécanisme qui protège tous les autres : un garde-fou ajouté sans être appelé par le
# hook doit faire refuser le push qui l'ajoute. Sans ce cas, le filet serait la seule pièce de
# l'outillage dont personne ne vérifie qu'elle fonctionne — exactement la situation qui a laissé
# les n°6, n°7 et n°8 muets pendant trois heures.
cat > bin/garde-fou-banc-jamais-lance.php <<'PHP'
<?php
declare(strict_types=1);
// Garde-fou factice : le hook ne l'appelle pas, le filet doit donc refuser le push.
exit(0);
PHP
commiter "banc : garde-fou ajoute sans appel dans le hook"
essai "filet — garde-fou présent mais jamais lancé" refus "présent dans l'arbre mais appelé par aucun hook"

# ─────────────────────────────────────────────────────────── remise en état

echo
echo "Et il doit laisser passer ce qui est propre :"
echo "// banc encore" >> README.md; commiter "banc : commit propre"
essai "commit propre après la série de refus" acceptation

# --- D28 : le hook installé doit signaler qu'il n'est plus celui de main ------------------------
# On périme volontairement le hook INSTALLÉ sur le bare. La poussée doit être acceptée — refuser
# bloquerait justement celle qui apporte la mise à jour — et dire qu'elle ne contrôle peut-être
# pas ce que le dépôt croit.
printf '\n# ligne qui périme le hook installé (banc D28)\n' >> "$BARE/hooks/pre-receive"
echo "// banc d28" >> README.md; commiter "banc : hook installe perime"
essai_avertissement "D28 — le hook installé signale son obsolescence" "LE HOOK INSTALLÉ"

# ...et `post-receive` l'a réinstallé pendant cette même poussée, après son acceptation. La
# poussée suivante doit donc être SILENCIEUSE. C'est ce cas qui prouve que la boucle se referme
# sans personne : sans lui, on saurait seulement que le hook sait se plaindre.
#
# Aucune remise en état manuelle ici, volontairement — la rétablir masquerait une panne de
# `post-receive` en faisant le travail à sa place.
echo "// banc d28 bis" >> README.md; commiter "banc : la poussee suivante doit etre silencieuse"
essai_sans_avertissement "D28 — post-receive a réinstallé, plus d'avertissement" "LE HOOK INSTALLÉ"

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
