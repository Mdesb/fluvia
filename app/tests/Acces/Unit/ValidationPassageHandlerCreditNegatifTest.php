<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\JournalReconciliation;
use App\Acces\Entity\Support;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\Acces\Service\ValidationPassageHandler;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `ValidationPassageHandler` — réconciliation gracieuse du crédit épuisé hors-ligne (CA-8,
 * plan-acces-terminal.md §4.2) : crédit négatif borné, `enConflit=true`, `JournalReconciliation` créé,
 * **uniquement** si `EvenementPassageDto::autoriserCreditNegatifSiHorsLigne = true`. Le flux en ligne
 * (flag `false`, défaut) reste strictement inchangé — non-régression explicite.
 */
final class ValidationPassageHandlerCreditNegatifTest extends AccesApiTestCase
{
    public function testCa8CreditNegatifBorneUniquementSiFlagHorsLigneActif(): void
    {
        [$droit, $supportA, $supportB] = $this->creerDroitPartageAUnCredit(1);
        $handler = static::getContainer()->get(ValidationPassageHandler::class);
        $equipementId = Uuid::fromString($this->idEquipement());

        $evt1 = new EvenementPassageDto(
            equipementId: $equipementId,
            identifiantSupport: $supportA->getIdentifiant(),
            sens: SensPassage::Entree,
            horodatage: new \DateTimeImmutable('2026-06-01T08:00:00+00:00'),
            cleIdempotence: Uuid::v4(),
        );
        $passage1 = $handler->valider($evt1);
        self::assertSame(ResultatPassage::Valide, $passage1->getResultat());
        self::assertFalse($passage1->isEnConflit());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $droitFrais = $em->getRepository(DroitAcces::class)->find($droit->getId());
        self::assertSame(0, $droitFrais->getCreditRestant(), '1er passage (flag=false) décrémente normalement 1 -> 0.');

        // 2e passage sur le même droit, crédit déjà épuisé : SEUL le flag hors-ligne autorise
        // l'acceptation avec crédit négatif borné.
        $evt2 = new EvenementPassageDto(
            equipementId: $equipementId,
            identifiantSupport: $supportB->getIdentifiant(),
            sens: SensPassage::Entree,
            horodatage: new \DateTimeImmutable('2026-06-01T08:05:00+00:00'),
            cleIdempotence: Uuid::v4(),
            autoriserCreditNegatifSiHorsLigne: true,
        );
        $passage2 = $handler->valider($evt2);
        self::assertSame(ResultatPassage::Valide, $passage2->getResultat(), 'Accepté malgré dépassement (réconciliation gracieuse hors-ligne, CA-8).');
        self::assertTrue($passage2->isEnConflit());
        self::assertSame(CodeMotifRefus::CreditEpuiseHorsLigneLitige, $passage2->getCodeMotif());

        $em->clear();
        $droitFinal = $em->getRepository(DroitAcces::class)->find($droit->getId());
        self::assertSame(-1, $droitFinal->getCreditRestant(), 'Crédit négatif borné au plancher configuré (défaut -1).');

        $journal = $em->getRepository(JournalReconciliation::class)->findOneBy(['passage' => $passage2->getId()]);
        self::assertNotNull($journal, 'Un JournalReconciliation doit être créé (statut ouvert).');
        self::assertSame(-1, $journal->getEcart());
        self::assertSame('ouvert', $journal->getStatut()->value);
    }

    public function testNonRegressionCreditEpuiseEnLigneResteRefuseFlagFalseParDefaut(): void
    {
        [$droit, $supportA, $supportB] = $this->creerDroitPartageAUnCredit(1);
        $handler = static::getContainer()->get(ValidationPassageHandler::class);
        $equipementId = Uuid::fromString($this->idEquipement());

        $evt1 = new EvenementPassageDto(
            equipementId: $equipementId,
            identifiantSupport: $supportA->getIdentifiant(),
            sens: SensPassage::Entree,
            horodatage: new \DateTimeImmutable('2026-06-01T08:00:00+00:00'),
            cleIdempotence: Uuid::v4(),
        );
        $handler->valider($evt1);

        // 2e passage SANS le flag (comportement en ligne — jamais activé par ce chemin) : refus strict,
        // exactement comme avant ce lot (aucune régression observable).
        $evt2 = new EvenementPassageDto(
            equipementId: $equipementId,
            identifiantSupport: $supportB->getIdentifiant(),
            sens: SensPassage::Entree,
            horodatage: new \DateTimeImmutable('2026-06-01T08:05:00+00:00'),
            cleIdempotence: Uuid::v4(),
        );
        $passage2 = $handler->valider($evt2);

        self::assertSame(ResultatPassage::Refuse, $passage2->getResultat());
        self::assertSame(CodeMotifRefus::CreditEpuise, $passage2->getCodeMotif());
        self::assertFalse($passage2->isEnConflit());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $droitFrais = $em->getRepository(DroitAcces::class)->find($droit->getId());
        self::assertSame(0, $droitFrais->getCreditRestant(), 'Jamais négatif quand le flag est désactivé (comportement historique, zéro régression).');

        $journal = $em->getRepository(JournalReconciliation::class)->findOneBy(['passage' => $passage2->getId()]);
        self::assertNull($journal, 'Aucun JournalReconciliation créé pour un refus en ligne classique.');
    }

    /** @return array{0: DroitAcces, 1: Support, 2: Support} */
    private function creerDroitPartageAUnCredit(int $credit): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $droit = new DroitAcces();
        $droit->setSourceType(TypeDroitAcces::CarteQuota)
            ->setCreditRestant($credit)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setEtablissement($etab);
        $em->persist($droit);

        $supportA = (new Support())->setIdentifiant('CN-A-' . substr((string) Uuid::v4(), 0, 8))->setType(TypeSupport::Qr)->setEtablissement($etab);
        $supportB = (new Support())->setIdentifiant('CN-B-' . substr((string) Uuid::v4(), 0, 8))->setType(TypeSupport::Qr)->setEtablissement($etab);
        $em->persist($supportA);
        $em->persist($supportB);

        $em->persist((new Appairage())->setSupport($supportA)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab));
        $em->persist((new Appairage())->setSupport($supportB)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab));

        $em->flush();

        return [$droit, $supportA, $supportB];
    }
}
