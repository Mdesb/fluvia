<?php

declare(strict_types=1);

namespace App\Tests\Sport\Unit;

use App\Membership\Entity\EcheanceSepa;
use App\Membership\Enum\StatutEcheanceSepa;
use App\Membership\Service\ScheduledDebitReductionHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * UNE OFFRE SUR UN PRÉLÈVEMENT À VENIR — et surtout, tout ce qu'elle refuse.
 *
 * ⚠ CE FICHIER PORTE PLUS DE REFUS QUE D'ACCEPTATIONS, ET C'EST LE POINT. Réduire un prélèvement
 * est une écriture sur de l'argent qu'on va prendre chez quelqu'un : le cas nominal est le plus
 * facile à écrire et le moins utile à prouver. Ce qui compte, c'est qu'on ne puisse pas réduire une
 * échéance déjà prélevée, la réduire deux fois, ou la vider en croyant faire un geste.
 */
final class ScheduledDebitReductionTest extends TestCase
{
    private function handler(): ScheduledDebitReductionHandler
    {
        return new ScheduledDebitReductionHandler($this->createStub(EntityManagerInterface::class));
    }

    private function echeance(int $centimes, StatutEcheanceSepa $statut = StatutEcheanceSepa::AVenir): EcheanceSepa
    {
        return (new EcheanceSepa())->setMontantCentimes($centimes)->setStatut($statut);
    }

    public function testLaReductionDiminueLeMontantEtGardeLOriginal(): void
    {
        $echeance = $this->handler()->reduce($this->echeance(3990), 1000, 'Parrainage', null);

        self::assertSame(2990, $echeance->getMontantCentimes());
        self::assertSame(3990, $echeance->getMontantInitialCentimes(), 'l’original doit rester lisible');
        self::assertSame('Parrainage', $echeance->getReductionMotif());
        self::assertNotNull($echeance->getReductionAt());
    }

    /** Le motif est exigé : c'est ce qu'on relit six mois plus tard devant un relevé. */
    public function testUnMotifVideEstRefuse(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessageMatches('/motif/');

        $this->handler()->reduce($this->echeance(3990), 1000, '   ', null);
    }

    /**
     * CE QUI EST DÉJÀ PRÉLEVÉ NE SE RÉDUIT PAS.
     *
     * L'argent est parti. Diminuer le montant ici ne le rendrait à personne : ça rendrait seulement
     * l'échéancier faux, et le rapprochement bancaire incompréhensible.
     */
    public function testUneEcheanceDejaPrelevEeEstRefusee(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessageMatches('/prelevee|prélevée|à venir/i');

        $this->handler()->reduce($this->echeance(3990, StatutEcheanceSepa::Prelevee), 1000, 'Parrainage', null);
    }

    /**
     * ⚠ UNE ÉCHÉANCE GELÉE EST REFUSÉE, MAIS LE MESSAGE DIT QUE CE N'EST PAS DÉFINITIF.
     *
     * Elle l'est par une pause d'abonnement, et elle REVIENDRA à la reprise. Dire « impossible »
     * sans le préciser enverrait chercher un défaut là où il n'y a qu'à attendre.
     */
    public function testUneEcheanceGeleeEstRefuseeMaisLeMessageDitQuElleReviendra(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessageMatches('/reviendra/');

        $this->handler()->reduce($this->echeance(3990, StatutEcheanceSepa::Gelee), 1000, 'Parrainage', null);
    }

    /** Une seule réduction par échéance : la trace tient en trois champs plats, la seconde effacerait la première. */
    public function testUneSecondeReductionEstRefusee(): void
    {
        $handler = $this->handler();
        $echeance = $handler->reduce($this->echeance(3990), 1000, 'Parrainage', null);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessageMatches('/déjà été réduite/');

        $handler->reduce($echeance, 500, 'Encore', null);
    }

    public function testUneReductionNulleOuNegativeEstRefusee(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->handler()->reduce($this->echeance(3990), 0, 'Parrainage', null);
    }

    /**
     * ⚠ RÉDUIRE À ZÉRO EST REFUSÉ, ET LE MESSAGE RENVOIE À `annuler`.
     *
     * Une échéance à 0,00 € encore « à venir » partirait dans la remise bancaire et serait annoncée
     * au client, pour rien. `annuler` dit déjà « on ne prélève pas ce mois-ci », avec son statut et
     * son motif : deux écritures pour un seul fait, c'est la duplication qu'on retire ailleurs.
     */
    public function testReduireLaTotaliteEstRefuseEtRenvoieAAnnuler(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessageMatches('/annulez/');

        $this->handler()->reduce($this->echeance(3990), 3990, 'Mois offert', null);
    }

    /** Et au-delà du montant, à plus forte raison. */
    public function testReduirePlusQueLeMontantEstRefuse(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->handler()->reduce($this->echeance(3990), 5000, 'Erreur de saisie', null);
    }

    /** Un centime de moins que le total reste licite : c'est le cas limite du geste maximal. */
    public function testUnCentimeDeMoinsQueLeTotalPasse(): void
    {
        $echeance = $this->handler()->reduce($this->echeance(3990), 3989, 'Geste exceptionnel', null);

        self::assertSame(1, $echeance->getMontantCentimes());
    }
}
