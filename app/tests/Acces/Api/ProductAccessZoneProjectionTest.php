<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\ProductAccessZone;
use App\Acces\Service\ProductAccessZoneResolver;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LA DÉCLARATION FAITE SUR LE PRODUIT ARRIVE-T-ELLE JUSQU'AU DROIT ?
 *
 * `ZoneAutoriseeTest` prouve que la règle REFUSE correctement une zone non autorisée. Il ne prouve
 * pas qu'on puisse la déclarer : il pose les espaces à la main sur le droit, ce qu'aucun écran ni
 * aucun code de production ne faisait. Toute la chaîne tenait sur un `addAuthorisedSpace()` que seul
 * un test appelait.
 *
 * Ces trois cas couvrent le chaînon manquant : produit → déclaration → droit projeté.
 *
 * ⚠ AUCUNE DES TROIS ASSERTIONS NE PEUT PASSER À VIDE, et c'est construit exprès.
 *
 * Une assertion de non-appartenance est vraie quand la règle marche ET quand rien ne s'est produit —
 * la deuxième raison est bien plus fréquente que la première. Chaque test porte donc son propre
 * témoin :
 *
 *   - le premier déclare une zone pour un AUTRE produit et vérifie qu'elle, est bien trouvée : la
 *     collection vide prouve alors que la requête FILTRE, pas qu'elle ne rend jamais rien ;
 *   - le deuxième compare l'identifiant de la zone reçue, pas seulement le nombre ;
 *   - le troisième vérifie la zone PRÉSENTE avant de vérifier qu'elle a disparu.
 */
final class ProductAccessZoneProjectionTest extends AccesApiTestCase
{
    /**
     * UNE DÉCLARATION CHEZ LE VOISIN N'OUVRE RIEN CHEZ NOUS.
     *
     * Le témoin est la seconde assertion : sans elle, une requête qui ne rendrait JAMAIS rien —
     * l'oubli du type `uuid` au paramètre (D58) en est la cause la plus courante — passerait ce
     * test en donnant l'impression que le filtrage fonctionne.
     */
    public function testUnProduitSansDeclarationNOuvreRienDeParticulier(): void
    {
        $em = $this->em();
        $resolver = $this->resolver();

        $droit = $this->droit($em);
        $etablissement = $droit->getEtablissement();
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $autreProduit = Uuid::v4();
        $this->declarer($em, $autreProduit, $this->unAutreEspace($em), $etablissement);

        $notreProduit = Uuid::v4();
        $resolver->applyTo($droit, $notreProduit, $etablissement);

        self::assertCount(0, $droit->getAuthorisedSpaces(), 'aucune déclaration pour ce produit : le droit doit continuer d’ouvrir tout');

        // ── LE TÉMOIN ───────────────────────────────────────────────────────────────────────────
        // La même requête, sur le produit qui a bien une déclaration, doit rendre quelque chose.
        // Sans ce contrôle, « zéro parce que ça filtre » et « zéro parce que ça ne trouve jamais
        // rien » seraient indiscernables — et c'est le second cas qu'on redoute.
        self::assertCount(1, $resolver->spacesFor($autreProduit, $etablissement), 'témoin : la requête sait trouver une déclaration existante');
    }

    /**
     * LA DÉCLARATION DESCEND SUR LE DROIT, ET C'EST BIEN LA BONNE ZONE.
     */
    public function testLaZoneDeclareeEstRecopieeSurLeDroit(): void
    {
        $em = $this->em();

        $droit = $this->droit($em);
        $etablissement = $droit->getEtablissement();
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $espace = $this->unAutreEspace($em);
        $produit = Uuid::v4();
        $this->declarer($em, $produit, $espace, $etablissement);

        $this->resolver()->applyTo($droit, $produit, $etablissement);

        self::assertCount(1, $droit->getAuthorisedSpaces());
        // On compare l'identifiant, pas le compte : une recopie qui poserait n'importe quel espace
        // satisferait un simple `assertCount()`.
        self::assertSame(
            (string) $espace->getId(),
            (string) $droit->getAuthorisedSpaces()->first()->getId(),
        );
    }

    /**
     * RETIRER LA DÉCLARATION LA RETIRE AUSSI DU DROIT, À LA RE-PROJECTION.
     *
     * C'est la moitié de la synchronisation qu'on oublie. Recopier sans retirer laisserait vivre
     * indéfiniment une zone que l'exploitant a explicitement enlevée du produit : son geste
     * n'aurait aucun effet sur les titres déjà émis — et il n'en aurait aucun SANS RIEN DIRE.
     */
    public function testRetirerLaDeclarationRetireLaZoneDuDroit(): void
    {
        $em = $this->em();
        $resolver = $this->resolver();

        $droit = $this->droit($em);
        $etablissement = $droit->getEtablissement();
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $produit = Uuid::v4();
        $declaration = $this->declarer($em, $produit, $this->unAutreEspace($em), $etablissement);

        $resolver->applyTo($droit, $produit, $etablissement);
        // ── LE TÉMOIN ───────────────────────────────────────────────────────────────────────────
        // Vérifier la disparition n'a de sens que si la zone était là. Sans cette ligne, un
        // `applyTo()` qui ne ferait jamais rien passerait le test.
        self::assertCount(1, $droit->getAuthorisedSpaces(), 'témoin : la zone est bien posée avant qu’on la retire');

        $em->remove($declaration);
        $em->flush();

        $resolver->applyTo($droit, $produit, $etablissement);

        self::assertCount(0, $droit->getAuthorisedSpaces(), 'la zone retirée du produit doit quitter le droit à la re-projection');
    }

    private function declarer(EntityManagerInterface $em, Uuid $produit, EspaceAcces $espace, Etablissement $etablissement): ProductAccessZone
    {
        $declaration = (new ProductAccessZone($produit, $espace))->setEstablishment($etablissement);
        $em->persist($declaration);
        $em->flush();

        return $declaration;
    }

    /**
     * Un espace DISTINCT de celui de l'équipement des fixtures : sans cela, on ne saurait pas
     * distinguer « la zone déclarée a été recopiée » de « il n'y a qu'un espace, tout coïncide ».
     *
     * ⚠ `espaceSocle` est obligatoire en base — un espace d'accès est toujours la déclinaison d'un
     * espace du socle. On reprend celui de l'espace existant : le test porte sur la déclaration,
     * pas sur la topologie.
     */
    private function unAutreEspace(EntityManagerInterface $em): EspaceAcces
    {
        $existant = $em->getRepository(EspaceAcces::class)->find($this->idEspaceAcces());
        self::assertInstanceOf(EspaceAcces::class, $existant);

        $espace = (new EspaceAcces())
            ->setLibelle('Zone déclarée par le produit')
            ->setEspaceSocle($existant->getEspaceSocle());
        $em->persist($espace);

        return $espace;
    }

    private function droit(EntityManagerInterface $em): DroitAcces
    {
        $droit = $em->getRepository(DroitAcces::class)->find($this->idDroit());
        self::assertInstanceOf(DroitAcces::class, $droit);

        return $droit;
    }

    private function resolver(): ProductAccessZoneResolver
    {
        /** @var ProductAccessZoneResolver $resolver */
        $resolver = static::getContainer()->get(ProductAccessZoneResolver::class);

        return $resolver;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
