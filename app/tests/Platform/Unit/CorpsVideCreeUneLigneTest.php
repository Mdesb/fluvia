<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use PHPUnit\Framework\TestCase;

/**
 * QUELLES RESSOURCES UN CORPS VIDE SUFFIRAIT-IL A CREER ?
 *
 * Le 30/08, un POST au corps vide sur `/api/patinoire_parc_patins` a rendu 201 et cree une ligne
 * definitive sur un etablissement de demonstration. Toutes les proprietes avaient une valeur par
 * defaut, et les deux seules contraintes — `Range(28, 48)` et `PositiveOrZero` — etaient satisfaites
 * par ces defauts. L'enregistrement obtenu avait l'air d'une saisie voulue : pointure 28, zero
 * paire, en service. Rien ne le distinguait.
 *
 * ⚠ CE CONTROLE EST UN CLIQUET, PAS UNE LISTE DE DEFAUTS.
 *
 * Les vingt entrees gelees ci-dessous ne sont PAS vingt trous confirmes : c'est une mesure statique,
 * et certaines ont peut-etre un processeur qui exige quelque chose ou une garde qui refuse. Ce que
 * ce test interdit, c'est la VINGT-ET-UNIEME — celle que personne n'a encore ecrite.
 *
 * Le plafond ne remonte pas. Corriger une entree (exiger ce qui doit l'etre, ou offrir une
 * suppression) la fait sortir du compte, et le plafond s'abaisse.
 *
 * ⚠ POURQUOI UN TEST STATIQUE ET NON UN APPEL REEL. Verifier par un POST vide reviendrait a creer
 * l'enregistrement qu'on redoute — la sonde supposerait ce qu'elle mesure. Sur vingt ressources, ce
 * seraient vingt traces indelebiles, dont une identite legale et un parametrage de facturation.
 * C'est exactement la faute qui a produit le cas connu.
 */
final class CorpsVideCreeUneLigneTest extends TestCase
{
    /**
     * Le plafond gele au 31/08/2026. Il s'abaisse quand une ressource est corrigee ; il ne remonte
     * pas. Une ressource neuve qui entre dans le critere fait echouer ce test, et c'est le but.
     *
     * ⚠ ABAISSE DE 20 A 17 LE 05/09, ET LA RAISON COMPTE. Le message d'echec offrait depuis
     * toujours deux sorties — « un Delete, OU un drapeau `actif` que l'ecran sait poser » — mais
     * `creableAVide()` ne detectait que la premiere. Quatre ressources portaient la seconde depuis
     * leur creation et etaient comptees a tort.
     *
     * Le controle a fini par accuser un pair : `Reporting/Entity/TableauDeBord`, dont le docbloc dit
     * « pas de Delete expose, seule Patch(actif=false) desactive » et dont le frontal appelle
     * exactement cela. Un controle qui nomme un critere sans l'appliquer fait pire que se tromper :
     * il envoie corriger du code qui n'a rien a se reprocher.
     */
    private const PLAFOND = 17;

    /** Le cas connu, qui sert de temoin positif : la mesure doit le voir ET le classer a risque. */
    private const TEMOIN = 'Patinoire/Entity/ParcPatins.php';

    public function testAucuneNouvelleRessourceCreableAVide(): void
    {
        $racine = \dirname(__DIR__, 3) . '/src';
        $concernes = [];
        $vues = 0;
        $temoinVu = false;

        foreach ($this->fichiersPhp($racine) as $chemin) {
            $source = file_get_contents($chemin);
            if (!\is_string($source) || !str_contains($source, '#[ApiResource') || !str_contains($source, 'new Post(')) {
                continue;
            }

            $proprietes = $this->proprietes($source);
            if ($proprietes === []) {
                continue;
            }

            ++$vues;
            $relatif = str_replace($racine . '/', '', $chemin);

            if ($this->creableAVide($source, $proprietes)) {
                $concernes[] = $relatif;
                if ($relatif === self::TEMOIN) {
                    $temoinVu = true;
                }
            }
        }

        // ⚠ DEUX TEMOINS, POUR DEUX FACONS DE SE TROMPER.
        //
        // Le premier : la mesure a-t-elle lu quelque chose ? Une expression reguliere trop stricte
        // rendrait « zero ressource concernee » en ayant tout lu et rien vu — un zero rassurant et
        // faux. C'est arrive : le motif initial s'arretait sur les crochets INTERNES d'un
        // `#[Groups(['a', 'b'])]` et ne retrouvait jamais `private`.
        //
        // Le second : le critere mesure-t-il ce qu'on croit ? Un critere trop strict verrait le cas
        // connu et le declarerait sain. Meme zero, autre cause.
        self::assertGreaterThan(100, $vues, 'la mesure ne lit presque rien : elle ne prouve pas ce qu elle affirme');

        // ⚠ LE TEMOIN ETAIT UNE ENTITE VIVANTE, ET IL A EXPIRE. `ParcPatins` devait etre classe a
        //   risque « sinon le critere ne mesure pas ce qu on croit ». Le jour ou la sortie par
        //   drapeau `actif` a ete detectee, ParcPatins en est sorti — et l'assertion aurait declare
        //   l'instrument casse alors qu'il venait d'etre repare.
        //
        //   Un temoin tire d'un defaut vivant meurt avec le defaut. Ceux-ci portent sur la CAPACITE
        //   A VOIR, pas sur l'etat du depot a une date : ils survivent a toute correction.
        $this->assertClassificationExercee();

        self::assertLessThanOrEqual(
            self::PLAFOND,
            \count($concernes),
            sprintf(
                "Une ressource de plus peut etre creee par un corps VIDE, sans suppression possible.\n\n%s\n\n"
                . "Deux sorties :\n"
                . "  - exiger ce qui doit l'etre (Assert\\NotBlank, Assert\\NotNull) sur ce sans quoi\n"
                . "    l'enregistrement n'a pas de sens ;\n"
                . "  - ou offrir une sortie : un Delete, ou un drapeau `actif` que l'ecran sait poser.\n\n"
                . "Si la ressource est corrigee, ABAISSE le plafond (%d) au nombre reel.",
                implode("\n", array_map(static fn (string $f): string => '  - ' . $f, $concernes)),
                self::PLAFOND,
            ),
        );
    }

    /**
     * ⚠ LE SECOND SENS, ET IL COMPTE AUTANT QUE LE PREMIER.
     *
     * Un critere trop LARGE ferait echouer le test sur des ressources parfaitement saines, et un
     * controle qui crie sur des innocents finit desarme. On verifie donc qu'une ressource qui exige
     * quelque chose n'est PAS comptee — sur un cas fabrique, pour ne dependre d'aucun fichier du
     * depot qui pourrait changer.
     */
    public function testUneRessourceQuiExigeQuelqueChoseNEstPasComptee(): void
    {
        $avecExigence = <<<'PHP'
            #[ApiResource(operations: [new Post()])]
            class Exemple
            {
                #[ORM\Column(length: 80)]
                #[Assert\NotBlank]
                #[Groups(['x:read', 'x:write'])]
                private string $libelle = '';
            }
            PHP;

        $sansExigence = <<<'PHP'
            #[ApiResource(operations: [new Post()])]
            class Exemple
            {
                #[ORM\Column(length: 80)]
                #[Groups(['x:read', 'x:write'])]
                private string $libelle = '';
            }
            PHP;

        self::assertNotEmpty($this->proprietes($sansExigence), 'temoin : la lecture des proprietes doit fonctionner sur ce cas');

        self::assertFalse(
            $this->creableAVide($avecExigence, $this->proprietes($avecExigence)),
            'une ressource qui exige une valeur ne doit pas etre comptee',
        );
        self::assertTrue(
            $this->creableAVide($sansExigence, $this->proprietes($sansExigence)),
            'une ressource dont tout est par defaut doit etre comptee — sinon ce controle ne refuse rien',
        );
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function proprietes(string $source): array
    {
        // Fenetre bornee plutot que « tout sauf un crochet » : un `#[Groups(['a', 'b'])]` porte des
        // crochets internes, et une classe negative s'y arrete.
        preg_match_all(
            '/#\[ORM\\\\Column[\s\S]{0,300}?private\s+(\??[\w\\\\]+)\s+\$(\w+)\s*(=)?/',
            $source,
            $trouves,
            PREG_SET_ORDER,
        );

        return $trouves;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $proprietes
     */
    /**
     * Les temoins du CLASSIFICATEUR — quatre sources fabriquees, quatre reponses attendues.
     *
     * ⚠ ILS PROUVENT CE QU'IL EPARGNE AUTANT QUE CE QU'IL ATTRAPE. Un critere trop large ne fait pas
     * monter le compte, il le fait BAISSER : il excuse tout et le plafond n'est plus jamais atteint.
     * Sans un cas qu'il doit compter ET trois qu'il doit epargner, un vert ne dit rien.
     */
    private function assertClassificationExercee(): void
    {
        $base = "#[ApiResource]\nnew Post(\n#[ORM\\Column] private string \$nom = '';\n";

        $cas = [
            'sans aucune sortie : doit etre COMPTE' => [$base, true],
            'avec new Delete( : doit etre EPARGNE' => [$base . "new Delete(\n", false],
            'avec un drapeau actif posable : doit etre EPARGNE' => [
                $base . "private bool \$actif = true;\npublic function setActif(bool \$a): self\n",
                false,
            ],
            // ⚠ LE CAS QUI SEPARE LES DEUX MOITIES DE LA REGLE : un booleen qu'aucun setter ne pose
            //   ne retire rien de la circulation. L'epargner serait declarer la porte fermee sans
            //   qu'elle le soit.
            'avec un drapeau actif SANS setter : doit rester COMPTE' => [
                $base . "private bool \$actif = true;\n",
                true,
            ],
        ];

        foreach ($cas as $quoi => [$source, $attendu]) {
            self::assertSame(
                $attendu,
                $this->creableAVide($source, $this->proprietes($source)),
                'le classificateur se trompe : ' . $quoi,
            );
        }
    }

    private function creableAVide(string $source, array $proprietes): bool
    {
        // `Range` et `PositiveOrZero` n'exigent RIEN : elles sont satisfaites par 28 et par 0. Seules
        // les contraintes de PRESENCE refusent un corps vide.
        if (preg_match('/#\[Assert\\\\(NotBlank|NotNull|Count|Valid)\b/', $source) === 1) {
            return false;
        }

        if (str_contains($source, 'new Delete(')) {
            return false;
        }

        // ⚠ LE DRAPEAU `actif`, QUE LE MESSAGE PROMETTAIT DEPUIS LE DEBUT SANS QUE LE CODE LE VOIE.
        //
        // Le message d'echec offre deux sorties : « un Delete, OU un drapeau `actif` que l'ecran
        // sait poser ». Seule la premiere etait detectee. Le 05/09, ce controle a donc accuse
        // `Reporting/Entity/TableauDeBord` — dont le docbloc dit noir sur blanc « pas de Delete
        // expose, seule Patch(actif=false) desactive » et dont le frontal appelle exactement ca.
        //
        // Un controle qui nomme un critere sans l'appliquer fait pire que se tromper : il envoie
        // corriger du code qui n'a rien a se reprocher, et celui qui le lit croit avoir compris.
        //
        // ⚠ CE QU'ON EXIGE : le champ ET son setter. Un booleen prive sans moyen de le poser ne
        //   retire rien de la circulation — ce serait rouvrir la porte en la declarant fermee.
        if (preg_match('/private bool \$actif\b/', $source) === 1
            && str_contains($source, 'function setActif(')) {
            return false;
        }

        // `$id` n'a pas de valeur par defaut mais est pose par le constructeur : on tolere un seul
        // champ sans defaut avant de considerer qu'une saisie est reellement exigee.
        $sansDefaut = 0;
        foreach ($proprietes as $p) {
            if (!isset($p[3]) || $p[3] === '') {
                ++$sansDefaut;
            }
        }

        return $sansDefaut <= 1;
    }

    /** @return list<string> */
    private function fichiersPhp(string $racine): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }
}
