<?php

declare(strict_types=1);

namespace App\Tests\Vente\Unit;

use App\Vente\Port\MoyenPaiement;
use App\Vente\Port\ReferentielReglementStub;
use PHPUnit\Framework\TestCase;

/**
 * D44-bis — **aucun moyen de paiement ne peut entrer dans le référentiel sans qu'on ait dit s'il se
 * remet en main propre.**
 *
 * Le premier `estFiduciaire()` renvoyait `autoriseRendu`. Le raccourci avait la bonne réponse pour les
 * espèces et **la mauvaise pour les quatre chèques**, qui portent tous `autoriseRendu = false` :
 * une vente directe les aurait acceptés hors session, sans que personne ne détienne le papier. Le
 * défaut n'était pas une hypothèse d'avenir, il était déjà vrai dans `ComptaFixtures`.
 *
 * Ce test est ce qui empêche que ça recommence. Il ne vérifie pas une liste figée : il parcourt le
 * référentiel et **exige que chaque code soit classé**. Un moyen ajouté sans décision fait échouer ce
 * test, à l'endroit et au moment où la décision se prend — plutôt qu'en caisse six mois plus tard.
 *
 * C'est le même dispositif que `CanalContratTest` : le contrôle grandit tout seul avec la donnée qu'il
 * surveille. Un mécanisme qui dépend de la vigilance n'est pas un mécanisme.
 */
final class MoyenFiduciaireTest extends TestCase
{
    /**
     * Ne sont **pas** fiduciaires, et chacun pour une raison qu'on peut énoncer : `cb` et `payfip`
     * sont des transactions électroniques, `virement` un mouvement bancaire, `pmv` le débit d'un
     * compte client, `avoir` une écriture interne, `differe` une promesse et non un instrument.
     *
     * @var list<string>
     */
    private const DEMATERIALISES = ['cb', 'virement', 'pmv', 'avoir', 'differe', 'payfip'];

    /** Tout code du référentiel est classé d'un côté ou de l'autre — aucun ne reste sans réponse. */
    public function testChaqueMoyenDuReferentielEstClasse(): void
    {
        $connus = array_merge(MoyenPaiement::codesFiduciaires(), self::DEMATERIALISES);

        foreach ((new ReferentielReglementStub())->moyensDisponibles() as $code => $moyen) {
            self::assertContains($code, $connus, sprintf(
                'Moyen « %s » non classé : dit s\'il se remet en main propre. Un instrument papier '
                . 'exige quelqu\'un pour le détenir, donc une session de caisse (D44-bis).',
                $code,
            ));
            self::assertSame(
                \in_array($code, MoyenPaiement::codesFiduciaires(), true),
                $moyen->estFiduciaire(),
                sprintf('Classement incohérent pour « %s ».', $code),
            );
        }
    }

    /**
     * **Le cas qui a motivé la correction** : les quatre chèques sont fiduciaires alors qu'aucun
     * n'autorise le rendu de monnaie.
     *
     * Si ce test venait à passer avec `estFiduciaire()` défini comme `autoriseRendu`, c'est que le
     * référentiel aurait changé — pas que le raccourci serait devenu bon.
     */
    public function testLesChequesSontFiduciairesSansAutoriserLeRendu(): void
    {
        $referentiel = new ReferentielReglementStub();

        foreach (['cheque', 'cheque_vacances', 'cheque_culture', 'cheque_loisirs'] as $code) {
            $moyen = $referentiel->moyen($code);
            self::assertNotNull($moyen, $code);
            self::assertFalse($moyen->autoriseRendu, sprintf('« %s » n\'autorise pas le rendu.', $code));
            self::assertTrue($moyen->estFiduciaire(), sprintf(
                '« %s » est du papier : il se reçoit, se garde, se compte et se remet en banque.',
                $code,
            ));
        }
    }

    /** Les espèces restent le cas évident — et le seul où les deux propriétés coïncident. */
    public function testLesEspecesSontFiduciairesEtAutorisentLeRendu(): void
    {
        $especes = (new ReferentielReglementStub())->moyen('especes');

        self::assertNotNull($especes);
        self::assertTrue($especes->estFiduciaire());
        self::assertTrue($especes->autoriseRendu);
    }

    /** Une transaction électronique ne se détient pas : rien à compter le soir. */
    public function testLaCarteEtLeVirementNeSontPasFiduciaires(): void
    {
        $referentiel = new ReferentielReglementStub();

        foreach (['cb', 'virement', 'differe'] as $code) {
            $moyen = $referentiel->moyen($code);
            self::assertNotNull($moyen, $code);
            self::assertFalse($moyen->estFiduciaire(), sprintf('« %s » ne se remet pas en main propre.', $code));
        }
    }
}
