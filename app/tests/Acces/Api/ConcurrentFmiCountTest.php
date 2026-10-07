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
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\SensPassage;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeEquipement;
use App\Acces\Service\ComptageNonNominatifHandler;
use App\Acces\Service\SynchroPassageHandler;
use App\Acces\Service\ValidationPassageHandler;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\SnapshotDeltaTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Uid\Uuid;

/**
 * Un passage concurrent ne fait plus perdre de comptage à la jauge FMI (#267).
 *
 * L'occupation s'écrit par un `UPDATE ... SET valeur_courante = valeur_courante ± 1` : correct sous
 * concurrence. Mais les handlers recopiaient ensuite `valeur_en_mémoire ± 1` sur l'objet, et le
 * `flush()` réécrivait cette valeur ABSOLUE. Un passage compté entre la lecture de la jauge et cet
 * `UPDATE` était effacé : la jauge sous-comptait, et une jauge pleine laissait entrer au-delà du seuil.
 *
 * Concurrence simulée sans fil d'exécution. Le premier test rejoue la course telle qu'elle arrive :
 * une entrée validée sur une AUTRE connexion pendant la transaction du handler, juste après sa lecture
 * de la jauge (REPEATABLE READ). Les suivants reprennent le patron de `ConcurrentCreditDecrementTest` :
 * jauge chargée dans la map d'identité, entrées concurrentes écrites en base, puis le vrai handler, qui
 * retrouve l'instance périmée. Le lot hors ligne est le plus exposé : la même instance y sert d'un bout
 * à l'autre du lot.
 */
final class ConcurrentFmiCountTest extends AccesApiTestCase
{
    use SnapshotDeltaTrait;

    public function testOnlineEntryKeepsACountCommittedDuringItsTransactionAndAFullGaugeStillRefuses(): void
    {
        $jauge = $this->gaugeInMemory(2);
        [, [$identifiant]] = $this->createPairedRight(TypeDroitAcces::Billet);
        $em = $this->snapshotEm();
        $autre = DriverManager::getConnection($em->getConnection()->getParams());
        $course = new class($em->getConnection(), fn () => $this->concurrentEntries($jauge, 1, $autre)) {
            public ?bool $inTransaction = null;

            public function __construct(private readonly Connection $nous, private readonly \Closure $entree)
            {
            }

            public function postLoad(PostLoadEventArgs $args): void
            {
                if ($this->inTransaction === null && $args->getObject() instanceof JaugeFmi) {
                    $this->inTransaction = $this->nous->isTransactionActive();
                    ($this->entree)();
                }
            }
        };
        $em->getEventManager()->addEventListener(Events::postLoad, $course);
        $em->clear();

        $passage = $this->pass($this->idEquipement(), $identifiant);
        self::assertTrue($course->inTransaction, 'témoin : l\'entrée concurrente est validée pendant la transaction du handler, après sa lecture');
        self::assertSame(ResultatPassage::Valide, $passage->getResultat(), (string) $passage->getMotif());
        self::assertSame([2, 2], $this->stored($jauge), 'Deux entrées : 2 présents. 1 = un comptage perdu.');
        self::assertSame(2, $em->find(JaugeFmi::class, $jauge->getId())?->getValeurCourante(), 'L\'objet en mémoire dit la valeur de la base.');

        $refus = $this->pass($this->idEquipement());
        self::assertSame(CodeMotifRefus::SeuilFmi, $refus->getCodeMotif(), 'Jauge pleine (2/2) : la troisième entrée est refusée.');
        $autre->close();
    }

    public function testOnlineExitKeepsAConcurrentCount(): void
    {
        $sortie = $this->exitEquipment();
        $jauge = $this->gaugeInMemory();
        self::assertSame(ResultatPassage::Valide, $this->pass($this->idEquipement())->getResultat(), 'témoin : une entrée, 1 en mémoire');
        $this->concurrentEntries($jauge, 1);

        $passage = $this->pass($sortie);
        self::assertSame(ResultatPassage::Valide, $passage->getResultat(), (string) $passage->getMotif());
        self::assertSame([1, 2], $this->stored($jauge), 'Deux entrées, une sortie : 1 présent. 0 = un comptage perdu.');
    }

    public function testUncountedPassagesKeepAConcurrentCount(): void
    {
        $em = $this->snapshotEm();
        $sortie = $em->find(Equipement::class, Uuid::fromString($this->exitEquipment()));
        $entree = $em->find(Equipement::class, Uuid::fromString($this->idEquipement()));
        $jauge = $this->gaugeInMemory();
        /** @var ComptageNonNominatifHandler $handler */
        $handler = static::getContainer()->get(ComptageNonNominatifHandler::class);

        $this->concurrentEntries($jauge, 1);
        $handler->compter($entree, SensPassage::Entree, 'bébé');
        self::assertSame([2, 2], $this->stored($jauge), 'Comptage non nominatif en entrée.');

        $this->concurrentEntries($jauge, 1);
        $handler->compter($sortie, SensPassage::Sortie, 'accompagnant');
        self::assertSame([2, 3], $this->stored($jauge), 'Comptage non nominatif en sortie.');
    }

    public function testOfflineBatchNeitherLosesNorDoublesACount(): void
    {
        $lot = [$this->batchEntry(), $this->batchEntry()];
        $jauge = $this->gaugeInMemory();
        $this->concurrentEntries($jauge, 1);
        /** @var SynchroPassageHandler $synchro */
        $synchro = static::getContainer()->get(SynchroPassageHandler::class);

        $premier = $synchro->synchroniserPourTerminal($lot);
        self::assertSame([null, null], array_column($premier['details'], 'codeMotif'), 'témoin : les deux passages remontés sont validés');
        self::assertSame([3, 3], $this->stored($jauge), 'Une entrée en ligne et deux remontées : 3 présents.');

        $second = $synchro->synchroniserPourTerminal($lot);
        self::assertSame(['doublon', 'doublon'], array_column($second['details'], 'statut'));
        self::assertSame([3, 3], $this->stored($jauge), 'Le même lot rejoué ne compte pas deux fois.');
    }

    /** La jauge de l'espace de démonstration, chargée dans la map d'identité, vide. */
    private function gaugeInMemory(int $seuil = AccesFixtures::SEUIL_FMI): JaugeFmi
    {
        $espace = $this->snapshotFixtureSpace()->setSeuilFmi($seuil);
        $this->snapshotEm()->flush();
        $jauge = $this->snapshotEm()->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espace]);
        self::assertInstanceOf(JaugeFmi::class, $jauge);
        self::assertSame([0, $seuil], [$jauge->getValeurCourante(), $jauge->getSeuil()], 'témoin : jauge vide en mémoire');

        return $jauge;
    }

    /** Des entrées comptées ailleurs : la base avance, l'objet en mémoire non. */
    private function concurrentEntries(JaugeFmi $jauge, int $nombre, ?Connection $connexion = null): void
    {
        ($connexion ?? $this->snapshotEm()->getConnection())->executeStatement(
            'UPDATE acces_jauge_fmi SET valeur_courante = valeur_courante + :n, cumul_jour = cumul_jour + :n WHERE id = UNHEX(:h)',
            ['n' => $nombre, 'h' => bin2hex($jauge->getId()->toBinary())],
        );
    }

    /** @return list<int> [valeur_courante, cumul_jour] lus en base */
    private function stored(JaugeFmi $jauge): array
    {
        $ligne = $this->snapshotEm()->getConnection()->fetchNumeric(
            'SELECT valeur_courante, cumul_jour FROM acces_jauge_fmi WHERE id = UNHEX(:h)',
            ['h' => bin2hex($jauge->getId()->toBinary())],
        );

        return array_map('intval', (array) $ligne);
    }

    /** Un passage par le vrai handler, d'un billet neuf par défaut (pas d'anti-passback). */
    private function pass(string $equipementId, ?string $identifiant = null): Passage
    {
        $identifiant ??= $this->createPairedRight(TypeDroitAcces::Billet)[1][0];
        /** @var ValidationPassageHandler $handler */
        $handler = static::getContainer()->get(ValidationPassageHandler::class);

        return $handler->valider(new EvenementPassageDto(Uuid::fromString($equipementId), $identifiant, null, new \DateTimeImmutable(), Uuid::v4()));
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

    /** @return array<string, string> une entrée de lot hors ligne, billet neuf, sur la borne d'entrée */
    private function batchEntry(): array
    {
        [, [$identifiant]] = $this->createPairedRight(TypeDroitAcces::Billet);

        return [
            'cleIdempotence' => (string) Uuid::v4(),
            'equipementId' => $this->idEquipement(),
            'identifiantSupport' => $identifiant,
            'horodatage' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
    }
}
