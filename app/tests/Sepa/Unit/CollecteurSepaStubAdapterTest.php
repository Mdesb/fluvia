<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Unit;

use App\Sepa\Adapter\CollecteurSepaStubAdapter;
use App\Sepa\Entity\RemiseSepa;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * UN ADAPTATEUR DE SUBSTITUTION NE REND PAS UN SUCCES POUR UNE OPERATION QUI LAISSE UNE TRACE.
 *
 * `CollecteurSepaStubAdapter` rendait `'TRANSMISSION-' . hash(...)` sans rien transmettre a aucune
 * banque. L'appelant lisait ce retour comme un accuse de reception : la remise passait en
 * `Transmise`, les echeances etaient marquees collectees, et le compteur `nbCollectesReussies` de
 * chaque mandat etait incremente — compteur dont `SeqTpResolver` deduit `RCUR` plutot que `FRST`.
 *
 * ── ⚠ LES DEUX BRANCHES SONT TESTEES, ET C'EST LE POINT ─────────────────────────────────────────
 *
 * Un test qui ne verifierait que le refus laisserait passer un adaptateur qui refuse TOUJOURS —
 * donc un parcours SEPA impossible a derouler en preprod, ce que Maxime a explicitement demande de
 * conserver. Un test qui ne verifierait que la simulation laisserait passer l'ancien comportement.
 * Chaque branche a besoin de son propre temoin.
 *
 * ── POURQUOI UN DRAPEAU INJECTE ET NON L'ENVIRONNEMENT ──────────────────────────────────────────
 *
 * Mesure faite avant d'ecrire : la preprod tourne sous `APP_ENV=prod`. Une porte sur
 * l'environnement Symfony ferait refuser le bouchon precisement la ou on veut derouler le parcours.
 * Le drapeau est donc explicite — et son defaut est le REFUS, pour qu'une installation qui l'oublie
 * echoue fermee.
 *
 * C'est aussi ce qui rend ce test possible sans manipuler d'environnement : on instancie
 * l'adaptateur avec la valeur qu'on veut mesurer.
 *
 * ── ⚠ CE FICHIER EN REMPLACE UN AUTRE, ET LE REMPLACEMENT A UN COUT ────────────────────────────
 *
 * La version precedente tenait en un test : la reference est deterministe et commence par
 * `TRANSMISSION-`. Sa substance est conservee ici — c'est le troisieme test, avec le meme
 * `setNbTxs(2)`.
 *
 * Mais son assertion sur le prefixe est DELIBEREMENT invalidee : `TRANSMISSION-` se lit, en base
 * et dans un ecran, comme une transmission qui a eu lieu. `SIMULATION-` ne se confond avec rien.
 *
 * ⚠ En ecrivant par-dessus, j'ai supprime le test qui aurait vire au rouge sur ce changement — et
 * ce rouge aurait ete JUSTE : il m'aurait dit que je cassais une attente existante, et m'aurait
 * force a la justifier avant de passer. Le fichier n'a pas d'autre perte, mais le geste en a une :
 * un test qu'on remplace ne proteste pas, la ou un test qu'on laisse echouer oblige a decider.
 */
final class CollecteurSepaStubAdapterTest extends TestCase
{
    public function testSansAutorisationDeSimulationIlRefuseEtLeDitEnClair(): void
    {
        $adaptateur = new CollecteurSepaStubAdapter(false);

        try {
            $adaptateur->transmettre(new RemiseSepa());
            self::fail('un adaptateur non raccorde ne doit pas rendre de reference de transmission');
        } catch (ServiceUnavailableHttpException $e) {
            $message = $e->getMessage();
        }

        // ⚠ LE MESSAGE COMPTE AUTANT QUE LE REFUS. Un appelant qui recoit une exception muette ne
        // sait pas si sa remise est perdue, a rejouer, ou deja partie. Les trois lui coutent des
        // gestes differents.
        self::assertStringContainsString(
            'N\'A PAS ete transmise',
            $message,
            'le refus doit dire que rien n\'est parti, pas seulement echouer',
        );
        self::assertStringContainsString(
            'reste transmissible',
            $message,
            'le refus doit dire que la remise est rejouable : sans ca, l\'exploitant croit devoir la refaire',
        );
    }

    /**
     * ⚠ LE TEMOIN DE L'AUTRE BRANCHE. Sans lui, un adaptateur qui refuserait TOUJOURS passerait ce
     * fichier au vert — et le parcours SEPA deviendrait indeployable en preprod, ce qui est
     * exactement ce que l'arbitrage voulait eviter.
     */
    public function testAvecAutorisationIlSimuleEtLaReferenceDitQuElleEstSimulee(): void
    {
        $reference = (new CollecteurSepaStubAdapter(true))->transmettre(new RemiseSepa());

        self::assertStringStartsWith(
            'SIMULATION-',
            $reference,
            'la reference doit se distinguer d\'une reference bancaire : `TRANSMISSION-…` se lit, '
            . 'en base et dans un ecran, comme une transmission qui a eu lieu',
        );
    }

    /**
     * La reference est deterministe : deux appels sur la meme remise rendent la meme valeur.
     *
     * ⚠ Ce n'est pas un detail d'implementation. Un rejeu de la generation ne doit pas produire une
     * seconde reference pour une remise qui n'est partie ni la premiere ni la seconde fois — sinon
     * la trace laisse croire a deux transmissions distinctes.
     */
    public function testLaReferenceSimuleeEstDeterministe(): void
    {
        $remise = new RemiseSepa();
        $remise->setNbTxs(2);
        $adaptateur = new CollecteurSepaStubAdapter(true);

        self::assertSame($adaptateur->transmettre($remise), $adaptateur->transmettre($remise));
    }
}
