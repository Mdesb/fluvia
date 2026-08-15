<?php

declare(strict_types=1);

namespace App\Tests\Crm\Unit;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Entity\MouvementPmv;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\EtatConsentement;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * §3.4 plan-crm.md — garde d'inaltérabilité : MouvementPmv/Consentement append-only.
 */
final class InalterabiliteTest extends CrmApiTestCase
{
    public function testMouvementPmvEstAppendOnly(): void
    {
        [$client] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        $pmv = $this->entite(\App\Crm\Entity\PorteMonnaieVirtuel::class, ['client' => $payeur]);
        $mouvement = $em->getRepository(MouvementPmv::class)->findOneBy(['pmv' => $pmv]);
        self::assertNotNull($mouvement);

        $mouvement->setMotif('Tentative de modification interdite');

        $this->expectException(ConflictHttpException::class);
        $em->flush();
    }

    public function testConsentementEstAppendOnly(): void
    {
        [$client] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);

        $consentement = new Consentement(CanalConsentement::Email, EtatConsentement::Accorde);
        $consentement->setClient($payeur);
        $consentement->setSource('guichet');
        $em->persist($consentement);
        $em->flush();

        $consentement->setEtat(EtatConsentement::Refuse);

        $this->expectException(ConflictHttpException::class);
        $em->flush();
    }
}
