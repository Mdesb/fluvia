<?php

declare(strict_types=1);

namespace App\Tests\Platform\Pdf;

use App\Platform\Pdf\PoliceDeclaree;
use Dompdf\Dompdf;
use Dompdf\Options;
use PHPUnit\Framework\TestCase;

/**
 * ⚠ LA POLICE D'UN PDF DÉPENDAIT DE CE QUE LE PROCESSUS AVAIT RENDU AVANT LUI.
 *
 * `Dompdf\FontMetrics::getFont()` porte un `static $cache` de fonction, et la clé d'une famille
 * `null` est la constante `0` — indépendante de la police par défaut de l'instance. Le premier
 * rendu du processus remplit cette case ; tous les suivants la relisent.
 *
 * **Ces tests polluent eux-mêmes, et c'est ce qui les rend concluants.** Le défaut ne se voyait
 * qu'en suite complète, `Boutique` précédant `Facturation` dans l'ordre alphabétique : vert sur les
 * 99 tests du module, rouge sur les 2233. Un test qui dépend d'un ordre ne dit pas ce qu'il mesure.
 *
 * ⚠ ET CHAQUE CAS PORTE SON TÉMOIN ISOLÉ. Sans lui, on ne saurait pas si la valeur observée après
 * pollution est la bonne : on saurait seulement qu'elle est différente de l'autre.
 */
final class PoliceDeclareeTest extends TestCase
{
    /**
     * Sens 1 : un rendu antérieur sans police imposait Times à un document PDF/A, qui le refuse.
     */
    public function testUnDocumentPdfAResisteAUnRenduAnterieurSansPolice(): void
    {
        $temoinIsole = $this->rendre($this->optionsPdfA(), PoliceDeclaree::dans('<p>facture</p>', PoliceDeclaree::DEJAVU));
        self::assertStringStartsWith('%PDF-', $temoinIsole, 'Témoin : sans pollution, le PDF/A se produit.');

        // Ce que fait `GenerateurPdfBillet` : aucun `setDefaultFont`, donc `serif` → Times, mis en
        // cache sous la clé partagée.
        $this->rendre(new Options(), '<p>billet</p>');

        $pdf = $this->rendre($this->optionsPdfA(), PoliceDeclaree::dans('<p>facture</p>', PoliceDeclaree::DEJAVU));
        self::assertStringStartsWith(
            '%PDF-',
            $pdf,
            'Un PDF rendu ailleurs dans le processus a imposé sa police, et PDF/A l’a refusée.',
        );
    }

    /**
     * Sens 2 : et il n'était pas le seul lésé. Un billet rendu après une facture Factur-X changeait
     * de typographie et quintuplait de taille, sans que rien ne le signale.
     */
    public function testUnBilletGardeSaPoliceApresUnRenduEnDejaVu(): void
    {
        $isole = $this->rendre(new Options(), PoliceDeclaree::dans('<p>Billet</p>', PoliceDeclaree::BASE));
        self::assertStringContainsString('Times', $isole, 'Témoin : seul, le billet est bien rendu en Times.');
        self::assertStringNotContainsString('DejaVuSans', $isole);

        // Une facture Factur-X passe avant, dans le même processus.
        $pdfA = $this->optionsPdfA();
        $this->rendre($pdfA, PoliceDeclaree::dans('<p>facture</p>', PoliceDeclaree::DEJAVU));

        $apres = $this->rendre(new Options(), PoliceDeclaree::dans('<p>Billet</p>', PoliceDeclaree::BASE));
        self::assertStringContainsString('Times', $apres, 'Le billet a changé de police à cause d’un autre PDF.');
        self::assertStringNotContainsString(
            'DejaVuSans',
            $apres,
            'Le billet embarque DejaVu Sans : il pèse cinq fois plus et ne ressemble plus à celui d’hier.',
        );
    }

    /**
     * ⚠ TÉMOIN NÉGATIF DU HELPER : il doit refuser ce qui sortirait de la règle CSS.
     *
     * La famille finit dans une feuille de style ; une accolade y ouvrirait une autre règle. Sans
     * ce refus, la garde serait invisible — les appels légitimes passeraient de la même façon.
     */
    public function testIlRefuseUneFamilleQuiSortiraitDeLaRegle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PoliceDeclaree::dans('<p>x</p>', 'serif;} body{display:none');
    }

    /**
     * La déclaration s'insère DANS le document quand il en est un : préposer un `<style>` devant un
     * `<!DOCTYPE>` ferait basculer le rendu en quirks mode.
     */
    public function testElleSInsereDansLEnTeteQuandLeDocumentEnAUn(): void
    {
        $document = '<!DOCTYPE html><html><head><title>x</title></head><body><p>y</p></body></html>';
        $resultat = PoliceDeclaree::dans($document, PoliceDeclaree::BASE);

        self::assertStringStartsWith('<!DOCTYPE html>', $resultat, 'Le doctype doit rester en tête.');
        self::assertStringContainsString('<head><style>html{font-family:serif;}</style>', $resultat);

        // Un fragment, lui, n'a rien à préserver : la déclaration le précède.
        self::assertStringStartsWith('<style>', PoliceDeclaree::dans('<p>y</p>', PoliceDeclaree::BASE));
    }

    /**
     * TOUT PRODUCTEUR DE PDF DU DEPOT DECLARE SA POLICE — Y COMPRIS CEUX QUI N'EXISTENT PAS ENCORE.
     *
     * ⚠ DEUX DES TROIS SITES ECHOUENT EN SILENCE. Retirer la declaration de `FacturXAssembler` leve
     * une exception : PDF/A refuse une police non embarquable. La retirer de `GenerateurPdfBillet`
     * ne leve rien — le billet sort dans la police de quelqu'un d'autre, cinq fois plus lourd, et
     * personne ne l'apprend. C'est le defaut le plus durable qui soit : correct la plupart du temps,
     * faux selon ce que le processus a rendu avant.
     *
     * Un commentaire ne protege personne. Ce controle est le garde-fou que ce commentaire annoncait.
     */
    public function testChaqueProducteurDePdfDuDepotDeclareSaPolice(): void
    {
        $racine = \dirname(__DIR__, 3) . '/src';
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));

        $sites = [];
        $nus = [];
        foreach ($iterateur as $fichier) {
            if (!$fichier->isFile() || 'php' !== $fichier->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($fichier->getPathname());
            if (!str_contains($code, '->loadHtml(')) {
                continue;
            }
            preg_match_all('/->loadHtml\(([^,)]*)/', $code, $trouves);
            foreach ($trouves[1] as $argument) {
                $sites[] = $fichier->getPathname();
                if (!str_contains($argument, 'PoliceDeclaree::')) {
                    $nus[] = substr($fichier->getPathname(), \strlen($racine) + 1) . ' : ' . trim($argument);
                }
            }
        }

        // ⚠ TEMOIN POSITIF, SANS LEQUEL CE TEST SERAIT VERT EN NE LISANT RIEN. Un chemin faux, une
        // extension oubliee, et `$nus` reste vide pour une raison qui n'a aucun rapport.
        self::assertGreaterThanOrEqual(
            3,
            \count($sites),
            'Le balayage n’a pas trouvé les producteurs de PDF connus : c’est lui qu’il faut corriger, pas le code.',
        );

        self::assertSame(
            [],
            $nus,
            "Ces appels chargent du HTML sans déclarer de police. La police rendue dépendra alors de "
            ."ce que le processus a produit avant — voir PoliceDeclaree.",
        );
    }

    private function optionsPdfA(): Options
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPdfAEnabled(true);
        $options->setDefaultFont('DejaVu Sans');

        return $options;
    }

    private function rendre(Options $options, string $html): string
    {
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
