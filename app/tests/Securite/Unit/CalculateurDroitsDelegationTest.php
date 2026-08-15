<?php

declare(strict_types=1);

namespace App\Tests\Securite\Unit;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\DataFixtures\L7Fixtures;
use App\Securite\Entity\DelegationDroit;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutDelegation;
use App\Securite\Service\CalculateurDroits;
use App\Tests\Securite\SecuriteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * §2.5 plan-backoffice.md — `CalculateurDroits` étendu aux délégations actives non expirées, SANS
 * régression sur le comportement socle (union des affectations seules).
 */
final class CalculateurDroitsDelegationTest extends SecuriteApiTestCase
{
    /** Non-régression : sans délégation, le calcul reste identique au socle (union des affectations). */
    public function testNonRegressionSansDelegation(): void
    {
        static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var CalculateurDroits $calculateur */
        $calculateur = static::getContainer()->get(CalculateurDroits::class);

        $lecteur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::LECTEUR_EMAIL]);
        self::assertNotNull($lecteur);
        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etabA);

        $codes = $calculateur->codesEffectifs($lecteur, $etabA->getId());
        self::assertSame(['*.lire'], $codes);
    }

    /** Une délégation active enrichit les droits effectifs du bénéficiaire ; expirée, elle disparaît. */
    public function testDelegationActiveIncluseExpireeExclue(): void
    {
        static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var CalculateurDroits $calculateur */
        $calculateur = static::getContainer()->get(CalculateurDroits::class);

        $lecteur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::LECTEUR_EMAIL]);
        $admin = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => L7Fixtures::ROLE_DELEGATION_NOM]);
        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($lecteur);
        self::assertNotNull($admin);
        self::assertNotNull($role);
        self::assertNotNull($etabA);

        $delegationActive = (new DelegationDroit())
            ->setDelegant($admin)->setBeneficiaire($lecteur)->setRole($role)->setEtablissement($etabA)
            ->setDateDebut(new \DateTimeImmutable('-1 hour'))->setDateFin(new \DateTimeImmutable('+1 day'));
        $em->persist($delegationActive);
        $em->flush();

        $codes = $calculateur->codesEffectifs($lecteur, $etabA->getId());
        self::assertContains('organisation.gerer', $codes);
        self::assertContains('*.lire', $codes);

        // Statut « expiree » (déjà traité par la commande) : n'apparaît plus.
        $delegationActive->setStatut(StatutDelegation::Expiree);
        $em->flush();
        $codesApresExpiration = $calculateur->codesEffectifs($lecteur, $etabA->getId());
        self::assertNotContains('organisation.gerer', $codesApresExpiration);

        // Garde défensive : statut encore « active » en base mais dateFin dépassée (retard cron).
        $delegationActive->setStatut(StatutDelegation::Active);
        $delegationActive->setDateFin(new \DateTimeImmutable('-1 minute'));
        $em->flush();
        $codesDateFinDepassee = $calculateur->codesEffectifs($lecteur, $etabA->getId());
        self::assertNotContains('organisation.gerer', $codesDateFinDepassee);
    }
}
