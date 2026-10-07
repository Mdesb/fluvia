<?php

declare(strict_types=1);

namespace App\Tests\Securite\Unit;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\EstablishmentReachability;
use App\Tests\Securite\SecuriteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ÉQUIPE PLATEFORME (déploiement mono-propriétaire, exception assumée à RG-ED-07) : un compte marqué
 * `plateforme` atteint N'IMPORTE quel établissement et porte tous les droits, sans affectation.
 * Ce témoin prouve les trois coutures (reachability + calcul des droits) ; un compte ordinaire, lui,
 * reste cloisonné (baseline).
 */
final class PlatformScopeTest extends SecuriteApiTestCase
{
    public function testUnMembrePlateformeAtteintTousLesSitesEtPorteTousLesDroits(): void
    {
        static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var CalculateurDroits $calculateur */
        $calculateur = static::getContainer()->get(CalculateurDroits::class);
        /** @var EstablishmentReachability $reachability */
        $reachability = static::getContainer()->get(EstablishmentReachability::class);

        $lecteur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::LECTEUR_EMAIL]);
        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($lecteur);
        self::assertNotNull($etabA);
        self::assertNotNull($etabB);
        $maintenant = new \DateTimeImmutable();

        // ── BASELINE (compte ordinaire) : cloisonné ──────────────────────────────────────────────
        self::assertFalse(
            $reachability->canReachEstablishment($lecteur, $etabB, $maintenant),
            'un compte ordinaire n\'atteint pas un site où il n\'est pas affecté',
        );
        self::assertNotContains('*.*', $calculateur->codesEffectifs($lecteur, $etabA->getId()));

        // ── ON LE PASSE MEMBRE PLATEFORME ────────────────────────────────────────────────────────
        $lecteur->setPlateforme(true);
        $em->flush();

        // Il atteint désormais n'importe quel établissement (même sans affectation) et porte `*.*`.
        self::assertTrue(
            $reachability->canReachEstablishment($lecteur, $etabB, $maintenant),
            'un membre plateforme atteint tout établissement',
        );
        self::assertSame(['*.*'], $calculateur->codesEffectifs($lecteur, $etabB->getId()));
        self::assertSame(['*.*'], $calculateur->codesEffectifs($lecteur, null));
    }
}
