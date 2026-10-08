<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\JaugeFmi;
use App\Acces\Entity\Passage;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ModeSeuil;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeEquipement;
use App\Acces\Service\SynchroPassageHandler;
use App\Acces\Service\ValidationPassageHandler;
use App\Platform\Entity\Notification;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\SnapshotDeltaTrait;
use Symfony\Component\Uid\Uuid;

/**
 * Une entrée faite sur une borne hors ligne, remontée sur une jauge FMI pleine, est comptée et
 * signalée (D121, décision de Maxime du 07/10).
 *
 * La borne coupée ne connaît pas la jauge : la personne est entrée. Le rejeu la refusait pourtant
 * (`seuil_fmi`) et la jauge restait au seuil, une personne en dessous de la réalité. Elle est
 * désormais comptée, quitte à dépasser le seuil ; le passage est accepté avec le code
 * `seuil_fmi_depasse_hors_ligne`, et la cloche prévient ceux qui supervisent l'accès, une fois par
 * espace et par lot. Seul le chemin hors ligne change : en ligne, la jauge pleine refuse toujours.
 */
final class OfflineEntryOnFullGaugeTest extends AccesApiTestCase
{
    use SnapshotDeltaTrait;

    private const ALERT = 'access.capacity_exceeded';

    public function testAnOfflineEntryOnAFullGaugeIsCountedTracedAndAlertedOnce(): void
    {
        $jauge = $this->fullGauge();
        $lot = [$this->batchLine($this->idEquipement())];

        [$passage] = $this->sync($lot);
        self::assertSame(2, $this->stored($jauge), 'Seuil 1, une présente, une entrée hors ligne : 2 présents. 1 = l\'entrée n\'est pas comptée.');
        self::assertSame(ResultatPassage::Valide, $passage->getResultat(), (string) $passage->getMotif());
        self::assertSame(CodeMotifRefus::SeuilFmiDepasseHorsLigne, $passage->getCodeMotif());
        self::assertTrue($passage->isOrigineHorsLigne());

        $alertes = $this->alertsPerRecipient();
        self::assertNotEmpty($alertes, 'témoin : le site a quelqu\'un qui supervise l\'accès, il doit être prévenu');
        self::assertSame([1], array_values(array_unique($alertes)), 'Une alerte par destinataire.');
        $une = $this->snapshotEm()->getRepository(Notification::class)->findOneBy(['source' => self::ALERT]);
        self::assertSame(['supervision', ['espace' => (string) $this->snapshotFixtureSpace()->getId()]], [$une?->getEcran(), $une?->getParams()]);

        self::assertSame(['doublon'], array_column($this->synchro()->synchroniserPourTerminal($lot)['details'], 'statut'));
        self::assertSame(2, $this->stored($jauge), 'Le même lot rejoué ne compte rien de plus.');
        self::assertSame($alertes, $this->alertsPerRecipient(), 'Le même lot rejoué n\'alerte pas une seconde fois.');

        $this->sync([$this->batchLine($this->idEquipement()), $this->batchLine($this->idEquipement())]);
        self::assertSame(4, $this->stored($jauge));
        self::assertSame([2], array_values(array_unique($this->alertsPerRecipient())), 'Deux entrées dans un même lot : une seule alerte de plus.');
    }

    public function testAnOnlineEntryOnAFullGaugeIsStillRefused(): void
    {
        $jauge = $this->fullGauge();

        self::assertSame(CodeMotifRefus::SeuilFmi, $this->online()->getCodeMotif());
        self::assertSame(1, $this->stored($jauge));
        self::assertSame([], $this->alertsPerRecipient());
    }

    public function testAnOfflineExitDecrementsWithoutGoingBelowZero(): void
    {
        $jauge = $this->fullGauge();
        $sortie = $this->exitEquipment();

        $passages = $this->sync([$this->batchLine($sortie), $this->batchLine($sortie)]);
        self::assertSame([ResultatPassage::Valide, ResultatPassage::Valide], array_map(static fn (Passage $p) => $p->getResultat(), $passages));
        self::assertSame(0, $this->stored($jauge), 'Une présente, deux sorties hors ligne : 0, jamais -1.');
    }

    /** La jauge de démonstration au seuil 1, remplie par une entrée en ligne. */
    private function fullGauge(): JaugeFmi
    {
        $espace = $this->snapshotFixtureSpace()->setSeuilFmi(1);
        $this->snapshotEm()->flush();
        $jauge = $this->snapshotEm()->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espace]);
        self::assertInstanceOf(JaugeFmi::class, $jauge);
        self::assertSame([0, 1, ModeSeuil::Blocage], [$jauge->getValeurCourante(), $jauge->getSeuil(), $jauge->getMode()], 'témoin : jauge vide, seuil 1, mode blocage');
        self::assertSame(ResultatPassage::Valide, $this->online()->getResultat(), 'témoin : la première entrée passe');
        self::assertSame(1, $this->stored($jauge), 'témoin : la jauge est pleine');

        return $jauge;
    }

    private function online(): Passage
    {
        /** @var ValidationPassageHandler $handler */
        $handler = static::getContainer()->get(ValidationPassageHandler::class);
        $identifiant = $this->createPairedRight(TypeDroitAcces::Billet)[1][0];

        return $handler->valider(new EvenementPassageDto(Uuid::fromString($this->idEquipement()), $identifiant, null, new \DateTimeImmutable(), Uuid::v4()));
    }

    /**
     * @param list<array<string, string>> $lot
     *
     * @return list<Passage>
     */
    private function sync(array $lot): array
    {
        return $this->synchro()->synchroniserPourTerminal($lot)['passages'];
    }

    private function synchro(): SynchroPassageHandler
    {
        /** @var SynchroPassageHandler $synchro */
        $synchro = static::getContainer()->get(SynchroPassageHandler::class);

        return $synchro;
    }

    /** @return array<string, string> une ligne de lot hors ligne, billet neuf */
    private function batchLine(string $equipementId): array
    {
        return [
            'cleIdempotence' => (string) Uuid::v4(),
            'equipementId' => $equipementId,
            'identifiantSupport' => $this->createPairedRight(TypeDroitAcces::Billet)[1][0],
            'horodatage' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
    }

    private function stored(JaugeFmi $jauge): int
    {
        return (int) $this->snapshotEm()->getConnection()->fetchOne(
            'SELECT valeur_courante FROM acces_jauge_fmi WHERE id = UNHEX(:h)',
            ['h' => bin2hex($jauge->getId()->toBinary())],
        );
    }

    /** @return array<string, int> nombre d'alertes de dépassement par destinataire */
    private function alertsPerRecipient(): array
    {
        $parDestinataire = [];
        foreach ($this->snapshotEm()->getRepository(Notification::class)->findBy(['source' => self::ALERT]) as $notification) {
            $email = (string) $notification->getDestinataire()->getEmail();
            $parDestinataire[$email] = ($parDestinataire[$email] ?? 0) + 1;
        }
        ksort($parDestinataire);

        return $parDestinataire;
    }

    private function exitEquipment(): string
    {
        $em = $this->snapshotEm();
        $sortie = (new Equipement())->setLibelle('Tourniquet Sortie A1')->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Sortie)
            ->setControleur($em->getRepository(Controleur::class)->findOneBy(['libelle' => AccesFixtures::CONTROLEUR_LIBELLE]));
        $em->persist($sortie);
        $em->flush();

        return (string) $sortie->getId();
    }
}
