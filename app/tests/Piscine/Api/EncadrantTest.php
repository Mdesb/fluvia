<?php

declare(strict_types=1);

namespace App\Tests\Piscine\Api;

use App\DataFixtures\SocleFixtures;
use App\Piscine\Entity\AffectationEncadrant;
use App\Piscine\Entity\Bassin;
use App\Piscine\Entity\CreneauBassin;
use App\Piscine\Entity\QualificationEncadrant;
use App\Piscine\Enum\TypeEncadrement;
use App\Securite\Entity\Utilisateur;
use App\Tests\Piscine\PiscineApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Encadrant qualifié requis (RG-PISC-02, US-L6-04, CA-4) : un créneau exigeant un encadrement ne
 * peut être validé sans affectation qualifiée à diplôme valide.
 */
final class EncadrantTest extends PiscineApiTestCase
{
    public function testCa4ValidationRefuseeSansEncadrantQualifie(): void
    {
        [$client, $entete] = $this->adminSurA();

        $bassin = $this->entite(Bassin::class, ['libelle' => \App\Piscine\DataFixtures\PiscineFixtures::BASSIN_LIBELLE]);

        $client->request('POST', '/api/creneau_bassins', $entete + [
            'json' => [
                'bassin' => '/api/bassins/' . $bassin->getId(),
                'debut' => '2026-09-01T09:00:00+02:00',
                'fin' => '2026-09-01T10:00:00+02:00',
                'encadrantRequis' => 'MNS',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $creneau = $client->getResponse()->toArray();

        $client->request('POST', '/api/piscine/creneaux-bassin/' . $creneau['id'] . '/valider', $entete);
        self::assertResponseStatusCodeSame(422, 'RG-PISC-02 : aucun encadrant qualifié affecté.');
    }

    public function testCa4ValidationAcceptonAvecEncadrantValide(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $bassin = $this->entite(Bassin::class, ['libelle' => \App\Piscine\DataFixtures\PiscineFixtures::BASSIN_LIBELLE]);
        $admin = $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);
        $qualification = $em->getRepository(QualificationEncadrant::class)->findOneBy(['encadrant' => $admin, 'type' => TypeEncadrement::Mns]);
        self::assertNotNull($qualification);

        $creneau = (new CreneauBassin())->setBassin($bassin)
            ->setDebut(new \DateTimeImmutable('2026-09-01 09:00:00'))
            ->setFin(new \DateTimeImmutable('2026-09-01 10:00:00'))
            ->setEncadrantRequis(TypeEncadrement::Mns);
        $em->persist($creneau);
        $em->persist((new AffectationEncadrant())->setCreneauBassin($creneau)->setQualification($qualification));
        $em->flush();

        $client->request('POST', '/api/piscine/creneaux-bassin/' . $creneau->getId() . '/valider', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('valide', $client->getResponse()->toArray()['statut']);
    }

    public function testCa4QualificationExpireeNestPasPriseEnCompte(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $bassin = $this->entite(Bassin::class, ['libelle' => \App\Piscine\DataFixtures\PiscineFixtures::BASSIN_LIBELLE]);
        $admin = $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);
        $etab = $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $qualificationExpiree = (new QualificationEncadrant())
            ->setEncadrant($admin)
            ->setType(TypeEncadrement::Bnssa)
            ->setDateValidite(new \DateTimeImmutable('2020-01-01'))
            ->setEtablissement($etab);
        $em->persist($qualificationExpiree);

        $creneau = (new CreneauBassin())->setBassin($bassin)
            ->setDebut(new \DateTimeImmutable('2026-09-01 09:00:00'))
            ->setFin(new \DateTimeImmutable('2026-09-01 10:00:00'))
            ->setEncadrantRequis(TypeEncadrement::Bnssa);
        $em->persist($creneau);
        $em->persist((new AffectationEncadrant())->setCreneauBassin($creneau)->setQualification($qualificationExpiree));
        $em->flush();

        $client->request('POST', '/api/piscine/creneaux-bassin/' . $creneau->getId() . '/valider', $entete);
        self::assertResponseStatusCodeSame(422, 'Une qualification expirée n\'est plus prise en compte (cahier M5-04).');
    }
}
