<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\Crm\Entity\Famille;
use App\Vente\Enum\TypeSupport as TypeSupportVendu;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client as CrmClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use App\Caisse\Entity\PointDeVente;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\Vente;
use App\Vente\Entity\LigneVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * CE QUE L'AGENT LIT QUAND IL CONTRÔLE UN BILLET (R19).
 *
 * Maxime, à la revue : « nom du produit, date de validité, nom de la personne, peut-être le prix,
 * et pour une carte de 10 le nombre de compostages restants ».
 *
 * ⚠ DEUX DES CINQ FAISAIENT SEMBLANT D'EXISTER. Le processeur rendait
 * `'produit' => $droit->getSourceType()->value` — l'ENUM de source, donc le mot « billet » — et
 * `'porteur' => null` EN DUR. L'écran, lui, avait déjà la branche pour afficher un porteur : elle
 * n'a jamais pu s'exécuter. L'agent lisait « billet » là où il attendait « Entrée adulte ».
 *
 * ⚠ LE NOM N'EST RENDU QUE POUR UN TITRE NOMINATIF, et c'est l'arbitrage de Maxime après ma
 * réserve : un nom sur un écran de contrôle est exposé à qui passe derrière l'agent. Le marqueur
 * n'est pas une case à cocher, c'est la présence d'un bénéficiaire sur la ligne de vente — un
 * billet vendu au comptoir n'en a pas.
 */
final class ControleBilletDetailTest extends AccesApiTestCase
{
    public function testUnTitreNominatifRendLeProduitLaValiditeLePrixEtLePorteur(): void
    {
        [$http, $entete] = $this->adminSurA();
        $identifiant = $this->creerBillet(nominatif: true);

        $corps = $http->request('POST', '/api/acces/controle-billet', $entete + [
            'json' => ['identifiantSupport' => $identifiant],
        ])->toArray();

        self::assertSame('valide', $corps['resultat'] ?? null, 'témoin : le billet passe');

        $billet = $corps['billet'] ?? [];
        self::assertSame('Entrée adulte', $billet['produit'] ?? null, 'le libellé figé à la vente, pas l’enum de source');
        self::assertSame('Plein tarif', $billet['tarif'] ?? null);
        self::assertSame('12.50', $billet['prix'] ?? null);
        self::assertSame('Camille Duroc', $billet['porteur'] ?? null, 'un titre nominatif nomme son porteur');

        self::assertNotNull($billet['validite']['debut'] ?? null, 'la date de validité tranche un litige à la porte');
        self::assertNotNull($billet['validite']['fin'] ?? null);
    }

    /**
     * ⚠ LE TÉMOIN QUI PROTÈGE LA DONNÉE PERSONNELLE.
     *
     * Sans ce cas, un `porteur` toujours renseigné passerait le test ci-dessus — et afficherait le
     * nom de quelqu'un sur un billet anonyme. C'est le contrôle qui prouve ce que le mécanisme
     * ÉPARGNE, pas seulement ce qu'il rend.
     */
    public function testUnBilletANONYMENeNommePersonne(): void
    {
        [$http, $entete] = $this->adminSurA();
        $identifiant = $this->creerBillet(nominatif: false);

        $corps = $http->request('POST', '/api/acces/controle-billet', $entete + [
            'json' => ['identifiantSupport' => $identifiant],
        ])->toArray();

        $billet = $corps['billet'] ?? [];
        self::assertSame('valide', $corps['resultat'] ?? null, 'témoin : ce billet passe aussi');
        self::assertSame('Entrée adulte', $billet['produit'] ?? null, 'témoin : le produit est bien lu');
        self::assertArrayHasKey('porteur', $billet, 'la clé doit exister, même vide');
        self::assertNull($billet['porteur'], 'un billet sans bénéficiaire ne nomme personne');
    }

    /** Un droit sans vente derrière : on ne casse pas, on se replie sur ce qu'on rendait avant. */
    public function testUnDroitSansLigneDeVenteSeReplieSurLeTypeDeSource(): void
    {
        [$http, $entete] = $this->adminSurA();
        $identifiant = $this->creerBillet(nominatif: false, avecLigne: false);

        $corps = $http->request('POST', '/api/acces/controle-billet', $entete + [
            'json' => ['identifiantSupport' => $identifiant],
        ])->toArray();

        self::assertSame('billet', $corps['billet']['produit'] ?? null, 'repli sur le type de source');
        self::assertArrayHasKey('prix', $corps['billet'] ?? [], 'la clé doit exister, même vide');
        self::assertNull($corps['billet']['prix'], 'sans ligne de vente, il n’y a pas de prix à citer');
    }

    private function creerBillet(bool $nominatif, bool $avecLigne = true): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);

        $droit = (new DroitAcces())
            ->setSourceType(TypeDroitAcces::Billet)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setFenetreDebut(new \DateTimeImmutable('-1 hour'))
            ->setFenetreFin(new \DateTimeImmutable('+1 day'))
            ->setEtablissement($etab);

        if ($avecLigne) {
            $ligne = (new LigneVente())
                ->setProduit(Uuid::v4())
                ->setTypeTarif(Uuid::v4())
                ->setLibelleProduit(['fr' => 'Entrée adulte'])
                ->setLibelleTypeTarif('Plein tarif')
                ->setPrixUnitaire('12.50');

            if ($nominatif) {
                $ligne->setBeneficiaire($this->idBeneficiaire($em, $etab));
            }

            // ⚠ `LigneVente::$vente` est `?Vente` EN PHP ET `NOT NULL` EN BASE. Le type dit
            // « facultatif », la colonne dit le contraire, et l'ecart ne se voit qu'au `flush()`.
            // ⚠ DEUX COLONNES `vente_id` NOT NULL, PAS UNE. La ligne ET le support en portent
            // une, et le message d'erreur est le meme mot a mot : la premiere correction n'a rien
            // change, parce qu'elle visait la mauvaise table. C'est le numero de ligne du `flush()`
            // qui a designe la bonne.
            $vente = $this->venteMinimale($em, $etab);
            $ligne->setVente($vente);
            $em->persist($ligne);

            $support = (new BilletSupport())->setType(TypeSupportVendu::Billet)->setLigne($ligne)->setVente($vente);
            $em->persist($support);
            $em->flush();

            $droit->setBilletSupportRef($support->getId());
        }

        $em->persist($droit);

        $identifiant = 'R19-' . substr((string) Uuid::v4(), 0, 8);
        $sup = (new Support())->setIdentifiant($identifiant)->setType(TypeSupport::Qr)->setEtablissement($etab);
        $em->persist($sup);
        $em->persist(
            (new Appairage())->setSupport($sup)->setDroit($droit)->setMode(ModeAppairage::Caisse)
                ->setActif(true)->setEtablissement($etab),
        );
        $em->flush();

        return $identifiant;
    }

    /** Une vente juste assez complete pour porter une ligne : point de vente et site exiges. */
    private function venteMinimale(EntityManagerInterface $em, Etablissement $etab): Vente
    {
        $pdv = $em->getRepository(PointDeVente::class)->findOneBy([]);
        self::assertNotNull($pdv, 'témoin : les fixtures posent un point de vente');

        $vente = (new Vente())->setPointDeVente($pdv)->setEtablissement($etab);
        $em->persist($vente);

        return $vente;
    }

    private function idBeneficiaire(EntityManagerInterface $em, Etablissement $etab): Uuid
    {
        $groupe = $etab->getRegion()?->getGroupe();
        self::assertNotNull($groupe, 'témoin : le site d’épreuve est rattaché à un groupe');

        $client = (new CrmClient())
            ->setNom('Duroc')
            ->setPrenom('Camille')
            ->setGroupe($groupe)
            ->setEtablissementCreation($etab);
        $em->persist($client);

        // ⚠ UN BENEFICIAIRE EXIGE UNE FAMILLE AUTANT QU'UN CLIENT (deux `nullable: false`), et une
        // famille exige un groupe et un payeur principal. Rien de tout cela ne se devine depuis le
        // nom de la classe : l'echec arrive au `flush()`, pas a l'ecriture.
        $famille = (new Famille())
            ->setGroupe($groupe)
            ->setLibelle('Famille d epreuve')
            ->setPayeurPrincipal($client);
        $em->persist($famille);

        $beneficiaire = (new Beneficiaire())->setClient($client)->setFamille($famille);
        $em->persist($beneficiaire);
        $em->flush();

        return $beneficiaire->getId();
    }
}
