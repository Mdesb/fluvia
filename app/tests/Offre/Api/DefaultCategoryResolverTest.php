<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Categorie;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Offre\Enum\AxeCategorie;
use App\Offre\Enum\StatutProduit;
use App\Offre\Service\DefaultCategoryResolver;
use App\Organisation\Entity\Etablissement;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ACT-5 — les catégories tombent toutes seules, ou ne tombent pas du tout.
 *
 * **Pourquoi ces tests plutôt qu'un essai à l'écran.** Un remplissage automatique qui ne remplit rien
 * ne casse rien : le produit est créé, l'écran ne dit rien, et le défaut n'apparaît qu'au moment de
 * publier — *« l'axe comptable est obligatoire »* — sur un produit qu'on croyait complet.
 *
 * > **Une automatisation muette qui échoue ressemble à une automatisation qu'on a oublié de brancher.**
 *
 * Les quatre cas couverts sont les quatre façons dont celle-ci peut se tromper : ne rien faire quand
 * elle devrait, faire quelque chose quand elle ne devrait pas, écraser un choix humain, ou inventer
 * une catégorie qui n'existe pas.
 */
final class DefaultCategoryResolverTest extends OffreApiTestCase
{
    /** **Le cas nominal** : l'axe comptable se remplit depuis le type. */
    public function testLAxeComptableSeRemplitDepuisLeType(): void
    {
        $type = $this->typeAvecDefauts(['comptable' => OffreFixtures::CAT_COMPTABLE]);
        $produit = $this->produit($type);

        $this->resolver()->appliquer($produit);

        self::assertSame(
            [OffreFixtures::CAT_COMPTABLE],
            $this->libelles($produit),
            'Le produit doit porter la categorie comptable declaree par son type.',
        );
    }

    /**
     * **Un axe déjà renseigné n'est jamais écrasé.**
     *
     * Un défaut n'est pas une règle : si l'utilisateur a choisi, il a raison. Sans ce test, la
     * première version aurait pu ajouter une seconde catégorie sur le même axe — ce que RG-M1-05
     * interdit, et que rien n'aurait signalé à la création.
     */
    public function testUnAxeDejaRenseigneNEstPasEcrase(): void
    {
        $em = $this->em();
        $choisie = (new Categorie())->setAxe(AxeCategorie::Comptable)->setLibelle('Choix de l’exploitant');
        $em->persist($choisie);
        $em->flush();

        $type = $this->typeAvecDefauts(['comptable' => OffreFixtures::CAT_COMPTABLE]);
        $produit = $this->produit($type);
        $produit->addCategorie($choisie);

        $this->resolver()->appliquer($produit);

        self::assertSame(
            ['Choix de l’exploitant'],
            $this->libelles($produit),
            'Un defaut ne remplace pas un choix : une seule categorie, celle de l utilisateur.',
        );
    }

    /**
     * **Un libellé inconnu n'invente rien, et le dit.**
     *
     * Fabriquer la catégorie manquante paraîtrait serviable et polluerait le référentiel comptable de
     * chaque exploitant avec des libellés qu'il n'a pas décidés — dans un plan de comptes, ça se
     * découvre à l'export FEC.
     */
    public function testUnLibelleInconnuNInventeAucuneCategorie(): void
    {
        $type = $this->typeAvecDefauts(['comptable' => 'Catégorie qui n’existe pas']);
        $produit = $this->produit($type);

        $manquants = $this->resolver()->appliquer($produit);

        self::assertSame([], $this->libelles($produit), 'Aucune categorie ne doit avoir ete fabriquee.');
        self::assertSame(['comptable'], $manquants, 'L axe non rempli doit etre nomme a l appelant.');
        self::assertNull(
            $this->em()->getRepository(Categorie::class)->findOneBy(['libelle' => 'Catégorie qui n’existe pas']),
            'Le referentiel ne doit pas avoir grossi.',
        );
    }

    /** **Un type sans défauts ne fait rien** — et surtout ne lève pas. */
    public function testUnTypeSansDefautsNeFaitRien(): void
    {
        $produit = $this->produit($this->typeAvecDefauts(null));

        self::assertSame([], $this->resolver()->appliquer($produit));
        self::assertSame([], $this->libelles($produit));
    }

    // --- outillage ------------------------------------------------------------------------------

    private function resolver(): DefaultCategoryResolver
    {
        /** @var DefaultCategoryResolver $r */
        $r = static::getContainer()->get(DefaultCategoryResolver::class);

        return $r;
    }

    /** @param array<string, string>|null $categories */
    private function typeAvecDefauts(?array $categories): TypeProduit
    {
        $em = $this->em();
        $type = (new TypeProduit())
            ->setCode('act5_' . bin2hex(random_bytes(3)))
            ->setLibelle('Type ACT-5')
            ->setDefauts($categories === null ? null : ['categories' => $categories]);
        $em->persist($type);
        $em->flush();

        return $type;
    }

    private function produit(TypeProduit $type): Produit
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $code = 'ACT5-' . bin2hex(random_bytes(3));
        $produit = (new Produit())
            ->setType($type)
            ->setLibelle(['fr' => $code])
            ->setLibelleRecherche($code)
            ->setCode($code)
            ->setCanaux(['guichet'])
            ->setStatut(StatutProduit::Brouillon);
        $produit->addEtablissement($etablissement);
        $em->persist($produit);
        $em->flush();

        return $produit;
    }

    /** @return list<string> */
    private function libelles(Produit $produit): array
    {
        $l = [];
        foreach ($produit->getCategories() as $categorie) {
            $l[] = $categorie->getLibelle();
        }
        sort($l);

        return $l;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
