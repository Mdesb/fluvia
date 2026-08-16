<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Unit;

use App\Boutique\Service\SessionSystemeBoutiqueResolver;
use App\Caisse\Entity\SessionCaisse;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Session de caisse système Boutique (§0 décision n°9 du plan) : une seule session technique
 * permanente par établissement (idempotence).
 */
final class SessionSystemeBoutiqueResolverTest extends BoutiqueApiTestCase
{
    public function testUneSeuleSessionSystemeParEtablissement(): void
    {
        static::createClient(); // boot du kernel pour accéder au container.
        $resolver = static::getContainer()->get(SessionSystemeBoutiqueResolver::class);
        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $session1 = $resolver->sessionSysteme($etab);
        $session2 = $resolver->sessionSysteme($etab);

        self::assertTrue($session1->getId()->equals($session2->getId()));

        $toutes = $this->em()->getRepository(SessionCaisse::class)->findBy(['etablissement' => $etab]);
        $systeme = array_values(array_filter($toutes, static fn (SessionCaisse $s): bool => str_starts_with($s->getNumero(), 'SYS-BOU-')));
        self::assertCount(1, $systeme, 'Idempotence : une seule session système Boutique par établissement.');
    }
}
