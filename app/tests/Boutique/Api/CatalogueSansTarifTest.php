<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\StatutProduit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * UN PRODUIT SANS TARIF RÉSOLU N'EST PAS SERVI AU PUBLIC.
 *
 * Constaté sur la boutique publique, accessible sans authentification : un billet s'ajoutait au
 * panier SANS AUCUN PRIX, sous la phrase « le tarif applicable est calculé et confirmé à l'étape de
 * paiement ». Il n'y avait aucun tarif du tout — donc rien à confirmer, et un client qui s'engage
 * sans savoir combien.
 *
 * ⚠ LA GARDE DE PUBLICATION EXISTE ET ELLE EST BONNE. `PublicationGuard` (RG-M1-09) refuse déjà de
 * publier un produit sans prix valide. On ne passait simplement pas par elle : une fixture posait
 * `StatutProduit::Publie` en dur sur l'entité, sans jamais appeler le processeur de publication.
 *
 * D'où un contrôle dans le FOURNISSEUR plutôt que dans la seule fixture : celle-ci referme le cas, un
 * import ou une reprise de données rouvriraient la famille. Et surtout pas côté écran — un filtre
 * dans la boutique compenserait la garde manquante en la rendant invisible, et on cesserait de la
 * chercher.
 */
final class CatalogueSansTarifTest extends BoutiqueApiTestCase
{
    /**
     * LE PRODUIT SANS TARIF DISPARAÎT, LES AUTRES RESTENT.
     *
     * ⚠ LES DEUX ASSERTIONS COMPTENT. Une garde qui viderait le catalogue entier satisferait la
     * première : c'est la seconde qui distingue « on filtre » de « on ne sert plus rien ».
     */
    public function testUnProduitPublieSansTarifNEstPasServi(): void
    {
        $em = $this->em();

        $orphelin = (new Produit())
            ->setType($this->unTypeDeProduit())
            ->setLibelle(['fr' => 'Billet sans tarif'])
            ->setLibelleRecherche('Billet sans tarif')
            ->setCode('PRD-SANS-TARIF')
            ->setCanaux(['en_ligne'])
            ->setTauxTva('10.00')
            ->setStatut(StatutProduit::Publie);
        $orphelin->addEtablissement($this->etablissementA());
        $em->persist($orphelin);
        $em->flush();

        $codes = $this->codesDuCatalogue();

        self::assertNotContains('PRD-SANS-TARIF', $codes, 'un produit dont aucun tarif ne se résout n’est pas vendable');
        self::assertNotEmpty($codes, 'témoin : le catalogue sert toujours les produits qui ont un prix');
    }

    /**
     * UN PRODUIT GRATUIT N'EST PAS UN PRODUIT SANS PRIX.
     *
     * C'est le piège de la correction : un tarif à 0,00 se résout et doit continuer d'être servi.
     * Confondre « gratuit » et « sans tarif » retirerait de la vente les entrées libres et les
     * invitations, et le catalogue se viderait sans que personne comprenne pourquoi.
     */
    public function testUnProduitGratuitResteServi(): void
    {
        $em = $this->em();

        $tarifPlein = $em->getRepository(TypeTarif::class)->findOneBy(['nom' => OffreFixtures::TARIF_PLEIN]);
        self::assertInstanceOf(TypeTarif::class, $tarifPlein);
        $saison = $em->getRepository(Saison::class)->findOneBy(['actif' => true]);
        self::assertInstanceOf(Saison::class, $saison, 'témoin : sans saison active, aucun tarif ne se résout et ce test ne mesurerait rien');

        $gratuit = (new Produit())
            ->setType($this->unTypeDeProduit())
            ->setLibelle(['fr' => 'Entrée libre'])
            ->setLibelleRecherche('Entrée libre')
            ->setCode('PRD-GRATUIT')
            ->setCanaux(['en_ligne'])
            ->setTauxTva('10.00')
            ->setStatut(StatutProduit::Publie);
        $gratuit->addEtablissement($this->etablissementA());
        // ⚠ La grille se persiste À PART : `Produit#grilles` ne cascade pas, et Doctrine refuse au
        // flush une entité neuve atteinte par une relation non cascadée.
        $grille = (new GrilleTarifaire())->setProduit($gratuit)->setTypeTarif($tarifPlein)->setSaison($saison)->setPrix('0.00');
        $gratuit->addGrille($grille);
        $em->persist($gratuit);
        $em->persist($grille);
        $em->flush();

        self::assertContains(
            'PRD-GRATUIT',
            $this->codesDuCatalogue(),
            'un tarif à 0,00 se résout : le produit reste vendable',
        );
    }

    // ── Montage ──────────────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function codesDuCatalogue(): array
    {
        $client = static::createClient();
        $catalogue = $client->request('GET', '/api/boutique/vitrines/' . $this->idVitrineA() . '/catalogue')->toArray();

        self::assertResponseIsSuccessful();

        return array_values(array_map(
            static fn (array $p): string => (string) ($p['code'] ?? ''),
            $catalogue['produits'] ?? [],
        ));
    }

    /** `Produit::type` est NOT NULL : un produit sans type n'atteint même pas la base. */
    private function unTypeDeProduit(): TypeProduit
    {
        $type = $this->em()->getRepository(TypeProduit::class)->findOneBy([]);
        self::assertInstanceOf(TypeProduit::class, $type);

        return $type;
    }

    private function etablissementA(): Etablissement
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->find($this->idEtablissement('Piscine A'));
        self::assertInstanceOf(Etablissement::class, $etablissement);

        return $etablissement;
    }
}
