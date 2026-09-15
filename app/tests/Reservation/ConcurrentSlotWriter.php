<?php

declare(strict_types=1);

namespace App\Tests\Reservation;

use App\Crm\Entity\Beneficiaire;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Service\JaugeCreneauGuard;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Joue une écriture CONCURRENTE sur la jauge d'un créneau, pour les tests de verrou.
 *
 * PHPUnit ne lance pas deux requêtes en même temps. La concurrence est donc jouée par un second
 * PROCESSUS, sur sa propre connexion : il verrouille la ligne du créneau, comme le fait une réservation
 * en cours, porte une réservation « de remplissage » à la quantité voulue, signale qu'il tient le
 * verrou, attend, puis valide. La requête testée part pendant cette attente.
 *
 * Ce qu'un test bâti dessus distingue :
 *  - sans verrou, la requête n'attend pas (ou attend sur la clé étrangère, mais après avoir compté),
 *    et accepte une place déjà prise ;
 *  - avec un verrou pris après une première lecture, elle attend, mais compte l'instantané d'avant ;
 *  - seul le verrou pris en première lecture la fait attendre ET compter ce qui a été validé.
 * D'où les deux assertions de chaque cas : le refus, ET l'attente.
 */
trait ConcurrentSlotWriter
{
    private const HOLD_MS = 2000;

    /** Une réservation d'une place sur le créneau, que le processus concurrent gonflera. */
    protected function persistFillerReservation(string $idCreneau, Beneficiaire $organisateur): string
    {
        $em = $this->slotEntityManager();
        $creneau = $em->getRepository(Creneau::class)->find($idCreneau);
        \assert($creneau instanceof Creneau);
        $organisateur = $em->getRepository(Beneficiaire::class)->find($organisateur->getId());
        \assert($organisateur instanceof Beneficiaire);

        $reservation = (new Reservation())
            ->setCreneau($creneau)
            ->setOrganisateur($organisateur)
            ->setEtablissement($creneau->getEtablissement())
            ->setModeDecompte(ModeDecompteReservation::Gratuit)
            ->setMontantDu('0.00')
            ->setQuantity(1);
        $em->persist($reservation);
        $em->flush();

        return (string) $reservation->getId();
    }

    /**
     * La quantité à donner à la réservation de remplissage pour qu'il reste `$restantes` places une fois
     * l'écriture concurrente validée. Lue par la jauge elle-même, pas recalculée.
     */
    protected function fillerQuantityLeaving(string $idCreneau, int $restantes): int
    {
        $em = $this->slotEntityManager();
        $creneau = $em->getRepository(Creneau::class)->find($idCreneau);
        \assert($creneau instanceof Creneau);
        $em->refresh($creneau);
        /** @var JaugeCreneauGuard $jauge */
        $jauge = static::getContainer()->get(JaugeCreneauGuard::class);
        $quantite = $jauge->placesRestantes($creneau) + 1 - $restantes;
        self::assertGreaterThanOrEqual(1, $quantite, 'Le créneau n\'a pas assez de places pour monter ce cas.');

        return $quantite;
    }

    /**
     * Lance le processus concurrent et rend la main quand il TIENT le verrou.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    protected function holdSlotLock(string $idCreneau, string $idReservation, int $quantite): array
    {
        /** @var Connection $connexion */
        $connexion = static::getContainer()->get('doctrine')->getConnection();
        $parametres = $connexion->getParams();
        self::assertNotEmpty($parametres['dbname'] ?? null, 'Paramètres de connexion introuvables.');

        $code = <<<'PHP'
            $args = array_values(array_filter(array_slice($argv, 1), static fn ($a) => $a !== '--'));
            [$hote, $port, $base, $utilisateur, $motDePasse, $creneau, $reservation, $quantite, $attente] = $args;
            // FOUND_ROWS : `rowCount()` compte les lignes TROUVÉES, pas les lignes modifiées. Sans lui, une
            // quantité déjà à la bonne valeur rendait 0 et faisait conclure à une réservation absente.
            $pdo = new PDO("mysql:host=$hote;port=$port;dbname=$base;charset=utf8mb4", $utilisateur, $motDePasse, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_FOUND_ROWS => true]);
            $pdo->beginTransaction();
            $verrou = $pdo->prepare('SELECT id FROM reservation_creneau WHERE id = UNHEX(?) FOR UPDATE');
            $verrou->execute([str_replace('-', '', $creneau)]);
            if ($verrou->fetchAll() === []) { fwrite(STDOUT, "NOSLOT\n"); exit(2); }
            $maj = $pdo->prepare('UPDATE reservation_reservation SET quantity = ? WHERE id = UNHEX(?)');
            $maj->execute([(int) $quantite, str_replace('-', '', $reservation)]);
            if ($maj->rowCount() !== 1) { fwrite(STDOUT, "NORESERVATION\n"); exit(2); }
            fwrite(STDOUT, "LOCKED\n");
            usleep((int) $attente * 1000);
            $pdo->commit();
            fwrite(STDOUT, "COMMITTED\n");
            PHP;

        $processus = proc_open(
            [
                \PHP_BINARY, '-r', $code, '--',
                (string) ($parametres['host'] ?? 'localhost'),
                (string) ($parametres['port'] ?? 3306),
                (string) $parametres['dbname'],
                (string) ($parametres['user'] ?? ''),
                (string) ($parametres['password'] ?? ''),
                $idCreneau,
                $idReservation,
                (string) $quantite,
                (string) self::HOLD_MS,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tuyaux,
        );
        self::assertIsResource($processus, 'Le processus concurrent n\'a pas démarré.');

        $ligne = $this->readConcurrentLine($tuyaux[1], 15.0);
        if ($ligne !== 'LOCKED') {
            $erreur = stream_get_contents($tuyaux[2]);
            proc_terminate($processus);
            proc_close($processus);
            self::fail(sprintf('Le processus concurrent ne tient pas le verrou : « %s » %s', $ligne, $erreur));
        }

        return [$processus, $tuyaux];
    }

    /** @param array{0: resource, 1: array<int, resource>} $concurrent */
    protected function releaseSlotLock(array $concurrent): void
    {
        [$processus, $tuyaux] = $concurrent;
        $sortie = stream_get_contents($tuyaux[1]);
        $erreur = stream_get_contents($tuyaux[2]);
        $code = proc_close($processus);

        // Si le concurrent n'a pas validé, le cas testé n'a pas eu lieu.
        self::assertSame(0, $code, 'Le processus concurrent a échoué : ' . $erreur);
        self::assertStringContainsString('COMMITTED', (string) $sortie);
    }

    /** Un refus obtenu SANS attendre viendrait d'un autre contrôle que le verrou testé. */
    protected function assertWaitedForTheConcurrentWrite(float $secondes): void
    {
        self::assertGreaterThanOrEqual(self::HOLD_MS / 1000 * 0.75, $secondes, 'La requête doit avoir attendu la validation concurrente.');
    }

    private function slotEntityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /** @param resource $flux */
    private function readConcurrentLine($flux, float $delai): ?string
    {
        stream_set_blocking($flux, false);
        $tampon = '';
        $limite = microtime(true) + $delai;
        while (microtime(true) < $limite) {
            $lus = [$flux];
            $rien = null;
            if (stream_select($lus, $rien, $rien, 0, 200000) > 0) {
                $morceau = fread($flux, 1024);
                if ($morceau === false || ($morceau === '' && feof($flux))) {
                    break;
                }
                $tampon .= $morceau;
                if (str_contains($tampon, "\n")) {
                    stream_set_blocking($flux, true);

                    return trim(strstr($tampon, "\n", true));
                }
            }
        }
        stream_set_blocking($flux, true);

        return $tampon === '' ? null : trim($tampon);
    }
}
