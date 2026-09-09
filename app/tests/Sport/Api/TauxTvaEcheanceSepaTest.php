<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Port\EcheanceSepaSource;
use App\Sport\Sepa\SportEcheanceSepaSource;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * G-1bis (chaine-encaissement) — l'échéance transporte le taux de TVA du produit de sa formule.
 *
 * ⚠ POURQUOI CE TEST EXISTE, ET CE QU'IL PROTÈGE EXACTEMENT.
 *
 * La première version de `SportEcheanceSepaSource::tauxParFormule()` écrivait
 * `->where('p.formule IN (:formules)')` avec des `Uuid`. `setParameter` ne convertit pas les éléments
 * d'un tableau : la requête rendait une liste **VIDE, sans lever**. Le taux n'aurait jamais été
 * résolu, et le symptôme aurait été « aucun produit ne porte de taux » — un défaut déguisé en donnée
 * manquante, que la suite Sport entière traversait au vert.
 *
 * Le garde-fou des références libres l'a attrapé ; ce test le retient. Aucun autre test ne pouvait le
 * voir, parce qu'AUCUNE fixture ne pose de taux sur un produit d'abonnement — c'est ce test qui en
 * pose un, précisément pour que l'absence cesse d'être indiscernable du défaut.
 */
final class TauxTvaEcheanceSepaTest extends SportApiTestCase
{
    public function testLEcheanceTransporteLeTauxDuProduitDeSaFormule(): void
    {
        [$em, $source, $etab] = $this->contexte();

        $this->poserTauxSurLeProduitGold($em, '5.50');

        $dues = $source->echeancesDues($etab, new \DateTimeImmutable('+2 years'));

        self::assertNotSame([], $dues, 'Les fixtures Sport souscrivent un abonnement : il doit exister des échéances dues.');
        $tauxRendus = array_values(array_unique(array_map(
            static fn (object $due): ?string => $due->tauxTvaValeur,
            $dues,
        )));
        self::assertSame(['5.50'], $tauxRendus, 'Le taux du produit qui porte la formule doit remonter jusqu\'à l\'échéance.');
    }

    /**
     * ⚠ LE TÉMOIN QUI DISTINGUE « PAS DE TAUX » DE « REQUÊTE CASSÉE ». Sans lui, une implémentation
     * qui rend toujours `null` passerait le test ci-dessus dès qu'on aurait oublié d'y poser un taux —
     * et c'est exactement la forme qu'avait le défaut.
     */
    public function testSansTauxSurLeProduitLEcheanceNEnInventeAucun(): void
    {
        [$em, $source, $etab] = $this->contexte();

        $this->poserTauxSurLeProduitGold($em, null);

        $dues = $source->echeancesDues($etab, new \DateTimeImmutable('+2 years'));

        self::assertNotSame([], $dues);
        foreach ($dues as $due) {
            self::assertNull($due->tauxTvaValeur, 'Un produit sans taux ne doit produire aucune valeur — surtout pas une plausible.');
        }
    }

    /** @return array{0: EntityManagerInterface, 1: EcheanceSepaSource, 2: Etablissement} */
    private function contexte(): array
    {
        // `SportApiTestCase::setUp()` a déjà recréé le schéma et chargé les huit jeux de fixtures ;
        // il ne reste qu'à rouvrir un noyau pour atteindre le conteneur.
        self::bootKernel();
        $conteneur = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $conteneur->get('doctrine')->getManager();

        /** @var SportEcheanceSepaSource $source */
        $source = $conteneur->get(SportEcheanceSepaSource::class);

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);

        return [$em, $source, $etab];
    }

    private function poserTauxSurLeProduitGold(EntityManagerInterface $em, ?string $taux): void
    {
        $produit = $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_GOLD]);
        self::assertInstanceOf(Produit::class, $produit, 'Le produit d\'abonnement de démonstration doit exister.');
        self::assertNotNull($produit->getFormule(), 'Ce produit doit porter la facette « formule » — c\'est elle qui relie l\'abonnement au taux.');

        $produit->setTauxTva($taux);
        $em->flush();
        $em->clear();
    }
}
