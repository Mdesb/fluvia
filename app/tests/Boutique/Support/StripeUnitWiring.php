<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Support;

use App\Boutique\Paiement\Stripe\StripeAccountResolver;
use App\Compta\Service\StripeKeyEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Câblage d'un `StripeAccountResolver` pour les tests unitaires : aucune clé d'exploitant en base (dépôt vide),
 * clé globale et secret de webhook d'environnement passés à la main. Les valeurs sont celles du test appelant.
 */
final class StripeUnitWiring
{
    public static function resolver(TestCase $test, ?string $globalKey = null, ?string $webhookSecret = null, ?LoggerInterface $logger = null): StripeAccountResolver
    {
        $create = new \ReflectionMethod($test, 'createStub');
        $repository = $create->invoke($test, EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $em = $create->invoke($test, EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return new StripeAccountResolver($em, new StripeKeyEncryptor(null), $logger ?? new NullLogger(), $globalKey, null, $webhookSecret);
    }
}
