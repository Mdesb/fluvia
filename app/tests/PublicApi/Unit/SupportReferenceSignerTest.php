<?php

declare(strict_types=1);

namespace App\Tests\PublicApi\Unit;

use App\PublicApi\Service\SupportReferenceSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * La clé de la référence opaque : le marqueur versionné dans `app/.env` n'est pas une clé. Hors
 * environnement de test, il est refusé — sinon une installation qui l'aurait oublié signerait avec une
 * valeur publique, et la référence redeviendrait calculable par n'importe qui.
 */
final class SupportReferenceSignerTest extends TestCase
{
    private const MARKER = 'A_GENERER_PAR_LE_DEPLOIEMENT_VOIR_infra_env.preprod.example';

    public function testLeMarqueurEstRefuseHorsDuTest(): void
    {
        foreach (['prod', 'dev'] as $environment) {
            try {
                (new SupportReferenceSigner(self::MARKER, $environment))->reference(Uuid::v7(), Uuid::v4());
                self::fail(sprintf('Le marqueur a été accepté comme clé en environnement « %s ».', $environment));
            } catch (\LogicException $e) {
                self::assertStringContainsString('PUBLIC_API_SUPPORT_REF_KEY', $e->getMessage());
            }
        }
    }

    public function testUneVraieCleSigneEtLeTestAccepteLeMarqueur(): void
    {
        $application = Uuid::v7();
        $support = Uuid::v4();

        $ref = (new SupportReferenceSigner('cle-installation-generee', 'prod'))->reference($application, $support);
        self::assertMatchesRegularExpression('/^sup_[0-9a-f]{64}$/', $ref);
        self::assertNotSame($ref, (new SupportReferenceSigner(self::MARKER, 'test'))->reference($application, $support));
    }
}
