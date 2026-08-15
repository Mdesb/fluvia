<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\MappingComptable;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Categorie;
use App\Offre\Enum\AxeCategorie;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RG-M6-01, CA-2 : une catégorie vendue sans mapping comptable (compte + taux TVA actifs) bloque la
 * génération d'écriture ; la vente M2 reste possible côté caisse.
 */
final class MappingIncompletTest extends ComptaApiTestCase
{
    public function testMappingIncompletBloqueLaGenerationSansBloquerLaVente(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Supprime le mapping comptable existant pour rendre la catégorie « Billetterie » incomplète.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['libelle' => OffreFixtures::CAT_COMPTABLE, 'axe' => AxeCategorie::Comptable]);
        self::assertNotNull($categorie);
        $mapping = $em->getRepository(MappingComptable::class)->findOneBy(['categorie' => $categorie->getId()]);
        self::assertNotNull($mapping);
        $em->remove($mapping);
        $em->flush();

        // La vente M2 reste possible (non modifiée, ⚠ HYPOTHÈSE §9 du plan).
        $vente = $this->creerVenteValidee($client, $entete, quantite: 1);
        self::assertSame('validee', $vente['statut']);

        $reponse = $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ])->toArray();

        self::assertSame(0, $reponse['ecrituresGenerees'], 'Mapping incomplet : génération bloquée (CA-2).');
        self::assertNotEmpty($reponse['anomalies']);

        /** @var EntityManagerInterface $emApres */
        $emApres = static::getContainer()->get('doctrine')->getManager();
        $ecriture = $emApres->getRepository(EcritureComptable::class)->findOneBy(['venteOrigine' => $vente['id']]);
        self::assertNull($ecriture, 'Aucune écriture ne doit être créée tant que le mapping est incomplet.');
    }
}
