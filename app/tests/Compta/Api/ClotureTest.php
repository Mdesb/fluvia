<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\RegieRecettes;
use App\Compta\Enum\StatutPeriode;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-L4-10, RG-CLOTURE-10 (CA-14) : la clôture est refusée si journal déséquilibré ou versements de
 * régie non soldés ; une fois validée, elle fige les écritures et produit un état récapitulatif.
 */
final class ClotureTest extends ComptaApiTestCase
{
    public function testClotureRefuseeSiRegieDepassePlafond(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $profil = $this->profilExploitant();
        $regie = $this->entite(RegieRecettes::class, ['libelle' => \App\Compta\DataFixtures\ComptaFixtures::REGIE_LIBELLE]);
        $regie->setSoldeEncaisseCentimes($regie->getPlafondEncaisseCentimes() + 1000);
        $em->flush();

        $periode = new PeriodeComptable();
        $periode->setProfilExploitant($profil);
        $periode->setDateDebut(new \DateTimeImmutable('first day of this month'));
        $periode->setDateFin(new \DateTimeImmutable('last day of this month'));
        $em->persist($periode);
        $em->flush();

        $reponse = $client->request('POST', '/api/compta/periodes/' . $periode->getId() . '/cloturer', $entete);
        self::assertSame(409, $reponse->getStatusCode(), 'Clôture refusée tant que la régie dépasse le plafond sans versement (CA-14).');
    }

    public function testClotureValideeFigeLesEcrituresEtProduitUnEtatRecapitulatif(): void
    {
        [$client, $entete] = $this->adminSurA();

        $vente = $this->creerVenteValidee($client, $entete, quantite: 1);
        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ecriture = $em->getRepository(\App\Compta\Entity\EcritureComptable::class)->findOneBy(['venteOrigine' => $vente['id']]);
        self::assertNotNull($ecriture);
        $periodeId = $ecriture->getPeriode()?->getId();
        self::assertNotNull($periodeId);

        $reponse = $client->request('POST', '/api/compta/periodes/' . $periodeId . '/cloturer', $entete)->toArray();
        self::assertSame('cloturee', $reponse['statut']);
        self::assertArrayHasKey('produitsCentimes', $reponse['etatCloture']);
        self::assertArrayHasKey('tvaCentimes', $reponse['etatCloture']);
        self::assertArrayHasKey('encaissementsCentimes', $reponse['etatCloture']);

        // Re-fetch : le client de test reboote le kernel à chaque requête.
        /** @var EntityManagerInterface $emApres */
        $emApres = static::getContainer()->get('doctrine')->getManager();
        $periode = $emApres->getRepository(PeriodeComptable::class)->find($periodeId);
        self::assertNotNull($periode);
        self::assertSame(StatutPeriode::Cloturee, $periode->getStatut());

        // Le référentiel comptable du profil est verrouillé à la 1ʳᵉ clôture (RG-COMPTA-01).
        $profil = $this->profilExploitant();
        self::assertTrue($profil->isVerrouille());
    }
}
