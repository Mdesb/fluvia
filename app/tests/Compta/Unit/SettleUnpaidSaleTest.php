<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Compta\Entity\VenteImpayeeRegie;
use App\Compta\Service\UnpaidSaleSettlement;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * LE RÈGLEMENT D'UNE VENTE MARQUÉE « IMPAYÉE RÉGIE » — et ses deux refus.
 *
 * Ce geste fait rentrer une recette dans une déclaration fiscale : ses refus comptent autant que son
 * effet. Un motif vide rendrait le rapprochement impossible ; un second règlement effacerait qui a
 * réellement constaté l'encaissement.
 *
 * ⚠ CES RÈGLES ONT DÛ SORTIR DU PROCESSEUR POUR ÊTRE TESTABLES. `LecteurCorps` et `Security` sont
 * `final`, donc immockables — l'impossibilité de tester n'était pas une contrainte technique, c'était
 * le symptôme d'un mauvais rangement : ce sont des règles de domaine.
 */
final class SettleUnpaidSaleTest extends TestCase
{
    private function reglement(): UnpaidSaleSettlement
    {
        return new UnpaidSaleSettlement($this->createStub(EntityManagerInterface::class));
    }

    private function marquage(): VenteImpayeeRegie
    {
        return (new VenteImpayeeRegie())->setVenteOrigine(Uuid::v4())->setMotif('Chèque rejeté');
    }

    public function testLeReglementDateEtMotiveLaVente(): void
    {
        $marquage = $this->reglement()->settle($this->marquage(), 'Chèque représenté', null);

        self::assertTrue($marquage->estReglee());
        self::assertSame('Chèque représenté', $marquage->getMotifReglement());
        self::assertNotNull($marquage->getRegleLe());
    }

    /** Une vente fraîchement marquée n'est évidemment pas réglée — le témoin négatif du précédent. */
    public function testUnMarquageNeufNEstPasRegle(): void
    {
        self::assertFalse($this->marquage()->estReglee());
    }

    /**
     * ⚠ SANS MOTIF, LE RAPPROCHEMENT SE FERAIT DE MÉMOIRE. Cette vente rentre dans la déclaration
     * e-reporting par un geste manuel ; ce qu'on écrit ici est tout ce qu'on relira.
     */
    public function testUnMotifVideEstRefuse(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->reglement()->settle($this->marquage(), '   ', null);
    }

    /**
     * ⚠ UN SECOND RÈGLEMENT EFFACERAIT LE PREMIER AUTEUR. La vente ne reviendrait pas deux fois dans
     * la déclaration — mais l'écran afficherait la seconde date et le second motif, et on ne saurait
     * plus qui a constaté l'encaissement. Le refus dit l'état plutôt que d'écraser.
     */
    public function testUnSecondReglementEstRefuseEtDitLEtat(): void
    {
        $reglement = $this->reglement();
        $marquage = $reglement->settle($this->marquage(), 'Espèces au guichet', null);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessageMatches('/déjà été réglée/');

        $reglement->settle($marquage, 'Encore', null);
    }

    /**
     * ⚠ L'ORDRE DES GARDES COMPTE. Une vente déjà réglée est refusée AVANT qu'on regarde le motif :
     * sinon un second règlement sans motif rendrait 422 (« motif requis ») là où la vraie raison est
     * qu'elle est déjà réglée — et on irait chercher un champ à remplir au lieu de lire l'état.
     */
    public function testLEtatDejaRegleePrimeSurLeMotifManquant(): void
    {
        $reglement = $this->reglement();
        $marquage = $reglement->settle($this->marquage(), 'Espèces au guichet', null);

        $this->expectException(ConflictHttpException::class);

        $reglement->settle($marquage, '', null);
    }
}
