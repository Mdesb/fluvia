<?php

declare(strict_types=1);

namespace App\Tests\Organisation\Unit;

use App\Organisation\Service\EditorTenantResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `EditorTenantResolver` : ce qu'on vérifie ici n'est pas qu'il résout — c'est qu'il **refuse**.
 *
 * Un repli silencieux ferait porter les souscriptions de tous les clients par un établissement pris au
 * hasard, et personne ne le verrait avant la facturation. Ces trois cas sont donc le contrat, pas des
 * cas d'erreur : désignation absente, désignation illisible, désignation qui ne correspond à rien.
 */
final class EditorTenantResolverTest extends TestCase
{
    public function testDesignationAbsenteEchoueEtNeReplieSurRien(): void
    {
        $resolver = new EditorTenantResolver($this->entityManagerInutilise(), '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/EDITOR_TENANT_ID/');

        $resolver->resolveId();
    }

    public function testDesignationVideDEspacesEchoueAussi(): void
    {
        $resolver = new EditorTenantResolver($this->entityManagerInutilise(), "   \n  ");

        $this->expectException(\RuntimeException::class);

        $resolver->resolveId();
    }

    public function testDesignationIllisibleEchoue(): void
    {
        $resolver = new EditorTenantResolver($this->entityManagerInutilise(), 'pas-un-uuid');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/UUID/');

        $resolver->resolveId();
    }

    public function testDesignationValideEstRendueTelleQuelle(): void
    {
        $attendu = '0192f3a4-5b6c-7d8e-9f01-234567890abc';
        $resolver = new EditorTenantResolver($this->entityManagerInutilise(), $attendu);

        self::assertSame($attendu, $resolver->resolveId()->toRfc4122());
    }

    /**
     * `isEditor` sert aux contrôles d'accès : il doit répondre **faux** quand la désignation manque,
     * jamais lever. Une exception dans un contrôle d'accès se transforme en 500, et un 500 sur un
     * chemin d'autorisation finit toujours par être contourné par quelqu'un.
     */
    public function testIsEditorRepondFauxQuandLaDesignationManque(): void
    {
        $resolver = new EditorTenantResolver($this->entityManagerInutilise(), '');

        self::assertFalse($resolver->isEditor(null));
    }

    private function entityManagerInutilise(): EntityManagerInterface
    {
        // Aucun de ces cas ne doit toucher la base. Un stub et non un mock : on ne vérifie pas des appels,
        // on affirme seulement que la résolution échoue avant d’avoir besoin de la base.
        return $this->createStub(EntityManagerInterface::class);
    }
}
