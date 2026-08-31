<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Unit;

use App\Acces\Entity\DroitAcces;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\Event\AccesRedevableChangeEvent;
use App\Recouvrement\Port\RedevablePort;
use App\Recouvrement\Port\BlockingExemptionLookup;
use App\Recouvrement\Service\PropagationAccesHandler;
use App\Recouvrement\Service\RedevableRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * C12 (RG-PLAT-03) — `AccesRedevableChangeEvent` porte désormais l'établissement du `DroitAcces`
 * résolu, pour devenir pontable (le pont legacy en dérive le tenant, D6). Test sans base de données :
 * port factice (`RedevablePort`, aucune dépendance à une verticale), `EntityManagerInterface` mocké,
 * `EventDispatcher` réel qui capture l'événement dispatché.
 */
final class PropagationAccesHandlerTest extends TestCase
{
    public function testEvenementPorteLEtablissementDuDroitResolu(): void
    {
        $etablissement = new Etablissement();

        $droit = $this->createStub(DroitAcces::class);
        $droit->method('getEtablissement')->willReturn($etablissement);

        $registre = new RedevableRegistry([$this->portQuiResout('demo.contrat', 'ref-1', $droit)]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        [$dispatcher, $capture] = $this->dispatcherCapteur();

        (new PropagationAccesHandler($em, $registre, $dispatcher, $this->exemptionsVides()))->desactiver('demo.contrat', 'ref-1');

        self::assertInstanceOf(AccesRedevableChangeEvent::class, $capture->evenement);
        self::assertSame(
            (string) $etablissement->getId(),
            $capture->evenement->etablissementId,
            'RG-PLAT-03 : l\'événement doit porter l\'établissement du droit résolu.',
        );
        self::assertFalse($capture->evenement->actif);
        self::assertSame('demo.contrat', $capture->evenement->typeRedevable);
        self::assertSame('ref-1', $capture->evenement->referenceRedevable);
    }

    public function testEtablissementNullQuandAucunDroitResolu(): void
    {
        $registre = new RedevableRegistry([$this->portQuiResout('demo.contrat', 'ref-1', null)]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush'); // aucun droit résolu -> aucune écriture.

        [$dispatcher, $capture] = $this->dispatcherCapteur();

        (new PropagationAccesHandler($em, $registre, $dispatcher, $this->exemptionsVides()))->activer('demo.contrat', 'ref-inconnue');

        self::assertInstanceOf(AccesRedevableChangeEvent::class, $capture->evenement);
        self::assertNull(
            $capture->evenement->etablissementId,
            'Aucun droit résolu -> etablissementId null ; l\'événement reste dispatché (comportement existant).',
        );
    }

    /**
     * Un port d'exemption qui n'exempte personne.
     *
     * ⚠ CES CAS TESTENT LA PROPAGATION, PAS L'EXEMPTION. Ils doivent donc fournir une reponse, pas
     * une infrastructure : le registre reel irait chercher en base, ce qui rendrait ces tests
     * unitaires dependants d'un schema et masquerait ce qu'ils mesurent.
     *
     * J'y avais d'abord mis un double qui LEVAIT une exception, en supposant que ces cas ne
     * consultaient pas les exemptions. Il l'a levee, et il avait raison de le faire : 
     * les consulte, evidemment, puisque c'est la qu'on refuse de bloquer un exempte. La supposition
     * etait fausse ; c'est ce qui a fait extraire le port.
     */
    private function exemptionsVides(): BlockingExemptionLookup
    {
        return new class implements BlockingExemptionLookup {
            public function estExempte(string $typeRedevable, string $referenceRedevable): bool
            {
                return false;
            }
        };
    }

    private function portQuiResout(string $type, string $ref, ?DroitAcces $droit): RedevablePort
    {
        return new class($type, $ref, $droit) implements RedevablePort {
            public function __construct(
                private readonly string $type,
                private readonly string $ref,
                private readonly ?DroitAcces $droit,
            ) {
            }

            public function typeRedevable(): string
            {
                return $this->type;
            }

            public function droitAcces(string $referenceRedevable): ?DroitAcces
            {
                return $referenceRedevable === $this->ref ? $this->droit : null;
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
    }

    /**
     * @return array{0: EventDispatcher, 1: object{evenement: ?AccesRedevableChangeEvent}}
     */
    private function dispatcherCapteur(): array
    {
        $capture = new class {
            public ?AccesRedevableChangeEvent $evenement = null;
        };
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            AccesRedevableChangeEvent::class,
            static function (AccesRedevableChangeEvent $evenement) use ($capture): void {
                $capture->evenement = $evenement;
            },
        );

        return [$dispatcher, $capture];
    }
}
