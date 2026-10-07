<?php

declare(strict_types=1);

namespace App\Tests\Securite\Unit;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\DelegationDroit;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutDelegation;
use App\Securite\Port\NoSupportAccessScope;
use App\Securite\Port\SupportAccessScopeInterface;
use App\Securite\Service\EstablishmentReachability;
use App\Securite\Service\PlatformScope;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Les TROIS portes qui rendent un établissement atteignable, et le refus quand aucune n'est ouverte.
 *
 * Chaque porte a son test parce que chacune a un appelant qui compte dessus : l'affectation pour tout
 * le monde, la délégation pour `DelegationTest` (le voter accorde des droits au délégué — lui fermer
 * l'en-tête casserait ce que le voter autorise), l'accès d'assistance pour RG-ED-07. Le refus est
 * testé à part : les trois tests positifs passeraient sur une règle qui dirait toujours oui.
 */
final class EstablishmentReachabilityTest extends TestCase
{
    public function testUneAffectationOuvreLEtablissement(): void
    {
        $etablissement = new Etablissement();
        $regle = $this->regle($etablissement, affectation: new Affectation(), delegations: [], assistance: []);

        self::assertTrue($regle->canReach(new Utilisateur(), $etablissement->getId(), new \DateTimeImmutable()));
    }

    public function testUneDelegationActiveOuvreLEtablissementSansAffectation(): void
    {
        $etablissement = new Etablissement();
        $delegation = (new DelegationDroit())
            ->setEtablissement($etablissement)
            ->setStatut(StatutDelegation::Active)
            ->setDateDebut(new \DateTimeImmutable('-1 day'))
            ->setDateFin(new \DateTimeImmutable('+1 day'));
        $regle = $this->regle($etablissement, affectation: null, delegations: [$delegation], assistance: []);

        self::assertTrue($regle->canReach(new Utilisateur(), $etablissement->getId(), new \DateTimeImmutable()));
    }

    /** Une délégation EXPIRÉE n'ouvre rien, même si son statut n'a pas encore été mis à jour par la tâche planifiée. */
    public function testUneDelegationExpireeNOuvreRien(): void
    {
        $etablissement = new Etablissement();
        $delegation = (new DelegationDroit())
            ->setEtablissement($etablissement)
            ->setStatut(StatutDelegation::Active)
            ->setDateDebut(new \DateTimeImmutable('-3 days'))
            ->setDateFin(new \DateTimeImmutable('-1 day'));
        $regle = $this->regle($etablissement, affectation: null, delegations: [$delegation], assistance: []);

        self::assertFalse($regle->canReach(new Utilisateur(), $etablissement->getId(), new \DateTimeImmutable()));
    }

    public function testUnAccesDAssistanceOuvreLEtablissementSansAffectation(): void
    {
        $etablissement = new Etablissement();
        $regle = $this->regle($etablissement, affectation: null, delegations: [], assistance: [$etablissement->getId()]);

        self::assertTrue($regle->canReach(new Utilisateur(), $etablissement->getId(), new \DateTimeImmutable()));
    }

    /** Le refus — sans lui, les trois tests ci-dessus passeraient sur une règle qui dit toujours oui. */
    public function testSansAucunePorteLEtablissementEstHorsDePortee(): void
    {
        $etablissement = new Etablissement();
        $regle = $this->regle($etablissement, affectation: null, delegations: [], assistance: []);

        self::assertFalse($regle->canReach(new Utilisateur(), $etablissement->getId(), new \DateTimeImmutable()));
    }

    /** Un identifiant qui ne désigne aucun établissement rend « non » — et ne lève pas. */
    public function testUnIdentifiantInconnuEstHorsDePortee(): void
    {
        $regle = $this->regle(null, affectation: new Affectation(), delegations: [], assistance: []);

        self::assertFalse($regle->canReach(new Utilisateur(), Uuid::v4(), new \DateTimeImmutable()));
    }

    /**
     * @param list<DelegationDroit> $delegations
     * @param list<Uuid>            $assistance
     */
    private function regle(?Etablissement $etablissement, ?Affectation $affectation, array $delegations, array $assistance): EstablishmentReachability
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn($etablissement);
        $repository->method('findOneBy')->willReturn($affectation);
        $repository->method('findBy')->willReturn($delegations);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $scope = $assistance === [] ? new NoSupportAccessScope() : new class($assistance) implements SupportAccessScopeInterface {
            /** @param list<Uuid> $ids */
            public function __construct(private readonly array $ids)
            {
            }

            public function reachableEstablishmentIds(Utilisateur $agent, \DateTimeImmutable $at): array
            {
                return $this->ids;
            }
        };

        // Un `PlatformScope` réel : les utilisateurs de ce test ne sont pas marqués « plateforme »,
        // donc `isGlobal()` rend false et le comportement de cloisonnement testé ici est inchangé.
        return new EstablishmentReachability($em, $scope, new PlatformScope());
    }
}
