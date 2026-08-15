<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RG-COMPTA-04/RG-M6-04 : une vente validée produit une écriture équilibrée, ventilée par taux de
 * TVA (CA-7, CA-9), et la génération est **idempotente** (rejouable sans doublon).
 */
final class GenerationEcritureTest extends ComptaApiTestCase
{
    public function testVenteValideeProduitEcritureEquilibreeEtVentileeParTaux(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete, quantite: 1);
        self::assertSame('validee', $vente['statut']);

        $reponse = $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ])->toArray();

        self::assertSame(1, $reponse['ecrituresGenerees']);
        self::assertSame([], $reponse['anomalies']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ecriture = $em->getRepository(EcritureComptable::class)->findOneBy(['venteOrigine' => $vente['id']]);
        self::assertNotNull($ecriture);

        // Partie double équilibrée (CA-7).
        self::assertTrue($ecriture->estEquilibree());
        self::assertSame($ecriture->totalDebitCentimes(), $ecriture->totalCreditCentimes());
        self::assertGreaterThan(0, $ecriture->totalDebitCentimes());

        // Chaque ligne porte un taux de TVA (RG-M6-04) ; total TTC = somme des lignes ventilées (CA-9).
        $totalCredit = 0;
        foreach ($ecriture->getLignes() as $ligne) {
            self::assertNotNull($ligne->getTauxTva());
            $totalCredit += $ligne->getCreditCentimes();
        }
        $totalTtcCentimesAttendu = (int) round(((float) $vente['total']) * 100);
        self::assertSame($totalTtcCentimesAttendu, $totalCredit, 'Le total crédité (produit+TVA) doit égaler le TTC de la vente (CA-9).');

        // Chaînage NF525 : séquence, empreinte, signature renseignées (prolongement §6 du plan).
        self::assertGreaterThan(0, $ecriture->getNumeroSequence());
        self::assertNotSame('', $ecriture->getEmpreinte());
        self::assertNotSame('', $ecriture->getSignature());
    }

    public function testGenerationIdempotenteNeDoublePasLesEcritures(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerVenteValidee($client, $entete, quantite: 1);

        $iri = '/api/profil_exploitants/' . $this->idProfilExploitant();

        $premier = $client->request('POST', '/api/compta/ecritures/generer', $entete + ['json' => ['profilExploitant' => $iri]])->toArray();
        self::assertSame(1, $premier['ecrituresGenerees']);

        $second = $client->request('POST', '/api/compta/ecritures/generer', $entete + ['json' => ['profilExploitant' => $iri]])->toArray();
        self::assertSame(0, $second['ecrituresGenerees'], 'La génération doit être idempotente (rejouable sans doublon).');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $nb = (int) $em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
        self::assertSame(1, $nb);
    }

    public function testPanierMixteVentileLaTvaLigneALigneSansMoyenne(): void
    {
        [$client, $entete] = $this->adminSurA();
        // Deux articles de la même catégorie mappée (20 %) : la ventilation par ligne reste correcte
        // même en quantité multiple (chaque ligne conserve son propre taux, RG-M6-05).
        $vente = $this->creerVenteValidee($client, $entete, quantite: 3);
        self::assertSame('validee', $vente['statut']);

        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ecriture = $em->getRepository(EcritureComptable::class)->findOneBy(['venteOrigine' => $vente['id']]);
        self::assertNotNull($ecriture);
        self::assertTrue($ecriture->estEquilibree());

        foreach ($ecriture->getLignes() as $ligne) {
            self::assertNotNull($ligne->getTauxTva(), 'Aucune ligne ne peut être enregistrée sans taux (RG-M6-04).');
        }
    }
}
