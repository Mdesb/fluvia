<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Securite\Port\NoSupportAccessScope;
use App\Securite\Service\EstablishmentReachability;
use App\Securite\Service\PlatformScope;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Platform\Security\EstablishmentScopeWriteGuard;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ce qu'on vérifie ici, c'est que le garde **refuse** (D41).
 *
 * Les 262 tests d'intégration qui passent avec lui prouvent qu'il ne casse rien. Ils ne prouvent pas
 * qu'il fait quelque chose : un garde qui laisserait tout passer les aurait passés aussi. Sans les
 * quatre cas ci-dessous, quelqu'un peut retirer le mécanisme sans qu'une seule ligne rouge n'apparaisse
 * — et c'est exactement ainsi que le dépôt s'est retrouvé avec trente-cinq entités non protégées.
 */
final class EstablishmentScopeWriteGuardTest extends TestCase
{
    public function testRefuseUneEcritureVersUnEtablissementHorsPerimetre(): void
    {
        $guard = $this->guard(utilisateurConnecte: true, affecte: false);

        $this->expectException(NotFoundHttpException::class);

        $guard->process($this->entiteAvecEtablissement(), $this->operation());
    }

    public function testLaisseEcrireDansSonPropreEtablissement(): void
    {
        $guard = $this->guard(utilisateurConnecte: true, affecte: true);
        $entite = $this->entiteAvecEtablissement();

        self::assertSame($entite, $guard->process($entite, $this->operation()));
    }

    /**
     * La limite assumée de D41 : sans utilisateur, il n'y a pas de périmètre auquel comparer. Le test
     * existe pour que cette limite soit **choisie** et non découverte — si quelqu'un la referme un jour,
     * ce test tombera et l'obligera à décider ce que devient la boutique publique.
     */
    public function testSansUtilisateurAuthentifieLeControleEstIgnore(): void
    {
        $guard = $this->guard(utilisateurConnecte: false, affecte: false);
        $entite = $this->entiteAvecEtablissement();

        self::assertSame($entite, $guard->process($entite, $this->operation()));
    }

    /** Une entité sans établissement n'a rien à confronter : le garde ne doit pas la gêner. */
    public function testUneEntiteSansEtablissementPasseSansControle(): void
    {
        $guard = $this->guard(utilisateurConnecte: true, affecte: false);
        $entite = new \stdClass();

        self::assertSame($entite, $guard->process($entite, $this->operation()));
    }

    private function guard(bool $utilisateurConnecte, bool $affecte): EstablishmentScopeWriteGuard
    {
        $decore = $this->createStub(ProcessorInterface::class);
        $decore->method('process')->willReturnArgument(0);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($utilisateurConnecte ? new Utilisateur() : null);

        // `EstablishmentReachability` est finale : on ne la bouchonne pas, on la construit sur un
        // gestionnaire d'entités bouchonné — une affectation trouvée ou non, aucune délégation, aucun
        // accès d'assistance. C'est la règle réelle qui tourne, sur des données choisies.
        // `PlatformScope` (3ᵉ argument depuis #252) est réel aussi : un `Utilisateur` neuf n'est pas
        // membre de l'équipe plateforme, c'est donc bien l'affectation qui décide.
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($affecte ? new Affectation() : null);
        $repository->method('findBy')->willReturn([]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return new EstablishmentScopeWriteGuard($decore, $security, new EstablishmentReachability($em, new NoSupportAccessScope(), new PlatformScope()));
    }

    private function entiteAvecEtablissement(): object
    {
        return new class {
            private Etablissement $etablissement;

            public function __construct()
            {
                $this->etablissement = new Etablissement();
            }

            public function getEtablissement(): Etablissement
            {
                return $this->etablissement;
            }
        };
    }

    private function operation(): Operation
    {
        return new Post();
    }
}
