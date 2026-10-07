<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\TypeDroitAcces;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\Port\BlockingExemptionLookup;
use App\Recouvrement\Port\RedevablePort;
use App\Recouvrement\Service\PropagationAccesHandler;
use App\Recouvrement\Service\RedevableRegistry;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\SnapshotDeltaTrait;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;

/**
 * Les chemins qui modifient un droit PAR L'ORM font avancer la version du snapshot.
 *
 * ⚠ AVANT LE 04/10, ILS MODIFIAIENT LE DROIT SANS TOUCHER `Support.versionMaj`. La base disait
 * « suspendu pour impayé », « crédit consommé », « zone retirée » ; la borne synchronisée en delta
 * gardait l'état d'avant jusqu'au prochain snapshot complet. Le mécanisme central
 * (`AccessProjectionVersionListener`) les couvre tous ; chaque test passe par le VRAI chemin, pas par
 * un `setStatutProjection()` du test — un test qui écrirait lui-même la colonne prouverait
 * l'écouteur, pas le chemin.
 */
final class SnapshotDeltaOrmPathsTest extends AccesApiTestCase
{
    use SnapshotDeltaTrait;

    // --- Recouvrement : suspension pour impayé, puis réouverture --------------------------------

    public function testRecouvrementSuspensionEtReouvertureAtteignentLeDelta(): void
    {
        [$droitId, [$identifiant]] = $this->createPairedRight(TypeDroitAcces::Abonnement);

        $curseur = $this->snapshotCursor();
        $this->recouvrement($droitId)->desactiver('test.contrat', 'ref-delta');
        self::assertTrue($this->assertInDelta($curseur, $identifiant, 'PropagationAccesHandler::desactiver')['revoque'], 'Impayé : la borne doit fermer.');

        $curseur = $this->snapshotCursor();
        $this->recouvrement($droitId)->activer('test.contrat', 'ref-delta');
        self::assertFalse($this->assertInDelta($curseur, $identifiant, 'PropagationAccesHandler::activer')['revoque'], 'Régularisation : la borne doit rouvrir.');
    }

    // --- Contrôle manuel : crédit décrémenté --------------------------------------------------------

    public function testControleManuelDecrementeLeCreditVuParLaBorne(): void
    {
        [, [$identifiant]] = $this->createPairedRight(TypeDroitAcces::CarteQuota, 1, 5);
        $curseur = $this->snapshotCursor();

        [$client, $entete] = $this->adminSurA();
        $reponse = $client->request('POST', '/api/acces/controle-billet', $entete + ['json' => ['identifiantSupport' => $identifiant]]);
        self::assertSame('valide', $reponse->toArray()['resultat'], 'témoin : le contrôle doit consommer');

        self::assertSame(4, $this->assertInDelta($curseur, $identifiant, 'ControleBilletProcessor')['compostagesRestants']);
    }

    public function testRetraitDUneZoneAtteintLeDelta(): void
    {
        [$droitId, [$identifiant]] = $this->createPairedRight(TypeDroitAcces::Abonnement);
        $curseur = $this->snapshotCursor();

        $em = $this->snapshotEm();
        $droit = $this->findRight($droitId);
        $droit->removeAuthorisedSpace($this->snapshotFixtureSpace());
        $em->flush();

        self::assertSame([], $this->assertInDelta($curseur, $identifiant, 'retrait de zone')['portesEligibles']);
    }

    // --- updatedAt : avance à chaque modification projetée, jamais sur une lecture ---------------

    public function testUpdatedAtAvanceSurModificationProjeteeEtPasSurUneLecture(): void
    {
        [$droitId, [$identifiant]] = $this->createPairedRight(TypeDroitAcces::Abonnement);
        $em = $this->snapshotEm();
        $this->findRight($droitId)->setFenetreFin(new \DateTimeImmutable('2026-12-31T23:00:00+00:00'));
        $em->flush();
        $connexion = $this->snapshotEm()->getConnection();
        $hexDroit = bin2hex(Uuid::fromString($droitId)->toBinary());
        $connexion->executeStatement("UPDATE acces_droit_acces SET updated_at = '2020-01-01 00:00:00' WHERE id = UNHEX(:h)", ['h' => $hexDroit]);
        $connexion->executeStatement("UPDATE acces_support SET updated_at = '2020-01-01 00:00:00' WHERE identifiant = :i", ['i' => $identifiant]);
        $versionAvant = (int) $connexion->fetchOne('SELECT version_maj FROM acces_support WHERE identifiant = :i', ['i' => $identifiant]);

        // Lectures, et une réécriture À L'IDENTIQUE (nouvelle instance de date, même instant) plus
        // un horodatage de synchronisation : rien de ce que la borne décide n'a changé.
        $this->fullSnapshotEntry($identifiant);
        [$client, $entete] = $this->adminSurA();
        $client->request('GET', '/api/droit_acces/' . $droitId, $entete);
        self::assertResponseIsSuccessful();
        $em = $this->snapshotEm();
        $droit = $this->findRight($droitId);
        $droit->setFenetreFin(new \DateTimeImmutable('2026-12-31T23:00:00+00:00'))
            ->setSynchroniseLe(new \DateTimeImmutable());
        $em->flush();

        $connexion = $this->snapshotEm()->getConnection();
        self::assertSame('2020-01-01 00:00:00', $connexion->fetchOne('SELECT updated_at FROM acces_droit_acces WHERE id = UNHEX(:h)', ['h' => $hexDroit]), 'Une lecture ne date pas le droit.');
        self::assertSame('2020-01-01 00:00:00', $connexion->fetchOne('SELECT updated_at FROM acces_support WHERE identifiant = :i', ['i' => $identifiant]), 'Une lecture ne date pas le support.');
        self::assertSame($versionAvant, (int) $connexion->fetchOne('SELECT version_maj FROM acces_support WHERE identifiant = :i', ['i' => $identifiant]), 'Une lecture ne fait pas avancer la version.');

        // Une vraie modification projetée.
        $em = $this->snapshotEm();
        $this->findRight($droitId)->setFenetreFin(new \DateTimeImmutable('2027-01-01T00:00:00+00:00'));
        $em->flush();

        $connexion = $this->snapshotEm()->getConnection();
        self::assertGreaterThan('2020-01-01 00:00:00', $connexion->fetchOne('SELECT updated_at FROM acces_droit_acces WHERE id = UNHEX(:h)', ['h' => $hexDroit]), 'updatedAt du droit doit avancer.');
        self::assertGreaterThan('2020-01-01 00:00:00', $connexion->fetchOne('SELECT updated_at FROM acces_support WHERE identifiant = :i', ['i' => $identifiant]), 'updatedAt du support doit avancer.');
        self::assertGreaterThan($versionAvant, (int) $connexion->fetchOne('SELECT version_maj FROM acces_support WHERE identifiant = :i', ['i' => $identifiant]));
    }

    // --- Échafaudages ---------------------------------------------------------------------------

    private function recouvrement(string $droitId): PropagationAccesHandler
    {
        $em = $this->snapshotEm();
        $port = new class($em, $droitId) implements RedevablePort {
            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly string $droitId)
            {
            }

            public function typeRedevable(): string
            {
                return 'test.contrat';
            }

            public function droitAcces(string $referenceRedevable): ?DroitAcces
            {
                return $this->em->getRepository(DroitAcces::class)->find(Uuid::fromString($this->droitId));
            }

            public function etablissement(string $referenceRedevable): ?Etablissement
            {
                return null;
            }

            public function estLieA(string $referenceRedevable, mixed $utilisateur): bool
            {
                return false;
            }
        };
        $aucuneExemption = new class implements BlockingExemptionLookup {
            public function estExempte(string $typeRedevable, string $referenceRedevable): bool
            {
                return false;
            }
        };

        return new PropagationAccesHandler($em, new RedevableRegistry([$port]), new EventDispatcher(), $aucuneExemption);
    }
}
