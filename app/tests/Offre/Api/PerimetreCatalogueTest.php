<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Offre\Enum\StatutProduit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le catalogue montre **le site où l'on travaille, plus le socle** — et rien du voisin.
 *
 * **CE FICHIER EXISTE PARCE QUE LES DEUX DÉFAUTS SONT SORTIS ENSEMBLE, LE 27/08, SUR LE MÊME ÉCRAN.**
 *
 * `PerimetreProduitExtension` filtrait sur les **affectations de l'utilisateur** — toutes — par une
 * jointure **interne** sur `produit.etablissements`. Résultat constaté en préproduction, sur le
 * catalogue de Piscine A ouvert par une administratrice affectée à Piscine A *et* Patinoire B :
 *
 * - le produit de **Patinoire B** s'affichait, et **a été encaissé** sur la caisse de Piscine A, où il
 *   est entré dans une chaîne de scellement NF525 qui est tenue *par point de vente* ;
 * - les **quatorze produits sans établissement** — le socle — n'apparaissaient nulle part.
 *
 * Le catalogue de Piscine A contenait donc exactement un produit : celui d'un autre site.
 *
 * **Les deux défauts ont la même ligne pour cause, et des symptômes opposés.** L'un montre trop,
 * l'autre pas assez. C'est ce qui les a rendus invisibles si longtemps : la liste n'était ni vide ni
 * manifestement fausse, seulement *plausible*. Personne ne compte les lignes d'un catalogue.
 *
 * > **Une liste vide se remarque. Une liste qui a la bonne taille et le mauvais contenu, non.**
 *
 * **Ce que ce fichier ne teste pas, et qui n'est pas un oubli.** L'en-tête `X-Etablissement` n'est pas
 * vérifié par l'extension, et n'a pas à l'être : `PermissionVoter` calcule les droits comme *l'union
 * des permissions des affectations de l'utilisateur **sur l'établissement actif***, si bien qu'un
 * en-tête forgé vers un site sans affectation ne rend aucun droit — donc 403 avant toute requête.
 * Le dernier test de ce fichier le montre, précisément pour que personne n'ajoute « par prudence » un
 * second contrôle d'appartenance dans l'extension : ce serait le second patron que D51 interdit.
 */
final class PerimetreCatalogueTest extends OffreApiTestCase
{
    private const PRODUIT_DE_A = 'CLOISON-A';
    private const PRODUIT_DE_B = 'CLOISON-B';
    private const PRODUIT_SOCLE = 'CLOISON-SOCLE';

    /**
     * **Le défaut qui a fait vendre un produit d'un autre site.**
     *
     * L'administratrice est affectée à A *et* à B. Elle travaille sur A. Le produit de B ne doit pas
     * lui être proposé — non parce qu'elle n'y aurait pas droit, mais parce qu'elle n'est pas là.
     *
     * C'est le raisonnement déjà écrit dans `ScopedReferenceQuery` pour les référentiels tarifaires :
     * *un responsable affecté à A et à B qui vend au guichet de A ne doit pas voir ce qui vient de B —
     * il poserait un prix sur un tarif qui n'existe pas là où il encaisse, et le défaut ne se verrait
     * qu'à la facture.* Ce qui vaut pour un type de tarif vaut a fortiori pour le produit qu'il tarife.
     */
    public function testLeProduitDUnAutreSiteNEstPasProposeMemeAQuiYEstAffecte(): void
    {
        $this->poserLesTroisProduits();
        [$client, $token, $idA] = $this->adminSurA();

        $codes = $this->codesDuCatalogue($client, $token, $idA);

        self::assertNotContains(
            self::PRODUIT_DE_B,
            $codes,
            'Un produit de Patinoire B est proposé au guichet de Piscine A : il sera encaissé sur le '
            . 'mauvais point de vente, et scellé là (NF525).',
        );
        self::assertContains(self::PRODUIT_DE_A, $codes, 'Le produit du site actif a disparu du catalogue.');
    }

    /**
     * **Le défaut jumeau, opposé, et plus discret : le socle avait disparu.**
     *
     * Un produit sans établissement est la façon dont ce dépôt écrit « partagé par tous » pour cette
     * entité — `Produit` n'a ni discriminant `portee` ni colonne `etablissement`, donc ni l'un ni
     * l'autre des deux patrons de D51. Avec une jointure **interne**, une telle ligne ne satisfait
     * aucune association : elle n'était visible de personne.
     *
     * Le test nomme le produit attendu plutôt que de compter. Un décompte se lirait comme un réglage à
     * ajuster ; l'absence d'un produit nommé dit que le guichet n'a plus rien à vendre.
     */
    public function testLeProduitSansEtablissementResteVisibleDeTous(): void
    {
        $this->poserLesTroisProduits();
        [$client, $token, $idA] = $this->adminSurA();

        self::assertContains(
            self::PRODUIT_SOCLE,
            $this->codesDuCatalogue($client, $token, $idA),
            'Le socle a disparu du catalogue : un produit sans établissement n\'appartient à personne, '
            . 'donc il devrait appartenir à tous.',
        );
    }

    /**
     * La même liste, vue depuis l'autre site, doit s'inverser — et garder le socle.
     *
     * Sans ce test, une extension qui rendrait *tout* passerait les deux précédents dès lors qu'elle
     * rendrait aussi le produit de A : c'est le contrôle qui distingue « filtré » de « permissif ».
     */
    public function testDepuisLAutreSiteLaListeSInverseEtLeSocleDemeure(): void
    {
        $this->poserLesTroisProduits();
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $codes = $this->codesDuCatalogue($client, $token, $idB);

        self::assertContains(self::PRODUIT_DE_B, $codes);
        self::assertContains(self::PRODUIT_SOCLE, $codes, 'Le socle doit être visible des deux sites.');
        self::assertNotContains(self::PRODUIT_DE_A, $codes, 'Le cloisonnement ne joue que dans un sens.');
    }

    /**
     * **Pourquoi l'extension n'a pas à vérifier l'appartenance de l'en-tête.**
     *
     * Le lecteur n'est affecté qu'à A. Il réclame le catalogue de B en changeant simplement son en-tête
     * `X-Etablissement` — l'attaque évidente contre un filtre qui fait confiance à un en-tête.
     *
     * `PermissionVoter` ferme la porte en amont : les droits sont l'union des permissions des
     * affectations **sur l'établissement actif**, et il n'en a aucune sur B. La requête n'atteint
     * jamais la couche Doctrine.
     *
     * Ce test est ici pour être lu par la prochaine personne tentée d'ajouter un contrôle
     * d'appartenance « par prudence » dans l'extension. Le contrôle existe, il est ailleurs, et le
     * dupliquer créerait deux endroits à corriger le jour où la règle change.
     */
    public function testUnEnTeteForgeNOuvrePasLeCatalogueDuVoisin(): void
    {
        $this->poserLesTroisProduits();
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $client->request('GET', '/api/produits', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'X-Etablissement' => $idB],
        ]);

        self::assertResponseStatusCodeSame(
            404,
            'Un en-tête forgé vers un site sans affectation doit être refusé avant toute lecture.',
        );

        // TÉMOIN DU VOTER (07/09) : l'en-tête EST dans la portée (le listener laisse passer),
        // mais la permission d'écriture manque (LECTEUR n'a que *.lire) → le voter doit refuser.
        // Sans ce cas, depuis e915c94e ce test ne prouve plus que le refus du listener (404).
        $client->request('POST', '/api/categories', ['headers' => ['Authorization' => 'Bearer ' . $token, 'X-Etablissement' => $idA], 'json' => []]);
        self::assertResponseStatusCodeSame(403, 'Le voter refuse une écriture dans la portée sans la permission requise (le listener, lui, a laissé passer l\'en-tête).');
    }

    /** @return list<string> */
    private function codesDuCatalogue(object $client, string $token, string $idEtablissement): array
    {
        $reponse = $client->request('GET', '/api/produits', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'X-Etablissement' => $idEtablissement],
            'query' => ['itemsPerPage' => 200],
        ]);
        self::assertResponseIsSuccessful();

        $membres = $reponse->toArray()['member'] ?? $reponse->toArray()['hydra:member'] ?? [];

        return array_values(array_filter(array_map(
            static fn (array $p): string => (string) ($p['code'] ?? ''),
            $membres,
        )));
    }

    /**
     * Trois produits qui couvrent les trois appartenances possibles : le site actif, l'autre site, et
     * aucun. La troisième n'est pas un cas limite — c'est la moitié du catalogue de démonstration.
     */
    private function poserLesTroisProduits(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $type = $em->getRepository(TypeProduit::class)->findOneBy(['code' => OffreFixtures::TYPE_ENTREE]);
        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(TypeProduit::class, $type);
        self::assertInstanceOf(Etablissement::class, $etabA);
        self::assertInstanceOf(Etablissement::class, $etabB);

        foreach ([self::PRODUIT_DE_A => $etabA, self::PRODUIT_DE_B => $etabB, self::PRODUIT_SOCLE => null] as $code => $etab) {
            $produit = (new Produit())
                ->setType($type)
                ->setLibelle(['fr' => $code])
                ->setLibelleRecherche($code)
                ->setCode($code)
                ->setCanaux(['guichet'])
                ->setStatut(StatutProduit::Publie);

            if ($etab instanceof Etablissement) {
                $produit->addEtablissement($etab);
            }

            $em->persist($produit);
        }

        $em->flush();
    }
}
