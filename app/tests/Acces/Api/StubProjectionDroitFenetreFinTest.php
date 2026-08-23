<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\DroitAcces;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Tests\Acces\AccesApiTestCase;
use App\Vente\Entity\BilletSupport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * T6, CQ-1 — bundle de cohérence (`StubProjectionDroit`, RG-CQ1-04 appliqué à l'émission, cf. §3.6 du
 * plan). Signalé pour arbitrage à l'intégrateur : changement de comportement observable (les cartes
 * `validiteDuree`/`dateButoir` commencent désormais à expirer dès l'émission, pas seulement à la
 * première recharge).
 */
final class StubProjectionDroitFenetreFinTest extends AccesApiTestCase
{
    public function testPremiereProjectionEcritFenetreFin(): void
    {
        [$client, $entete] = $this->adminSurA();
        $em = $this->em();
        $this->activerValiditeCarteDemo($em);

        $session = $this->ouvrirSession($client, $entete);
        [$identifiant, $billetSupportId] = $this->venteCarteEtEmission($client, $entete, $session['id']);

        $reponse = $client->request('POST', '/api/acces/appairages', $entete + [
            'json' => [
                'identifiantSupport' => $identifiant,
                'typeSupport' => 'QR',
                'billetSupportRef' => $billetSupportId,
                'mode' => 'caisse',
            ],
        ]);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));
        $idDroit = (string) $reponse->toArray()['droit']['id'];

        $em->clear();
        $droit = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droit);
        self::assertNotNull($droit->getFenetreFin(), 'Première projection : fenetreFin doit être écrite (T6).');
    }

    public function testReprojectionNeReinitialisePasFenetreFin(): void
    {
        [$client, $entete] = $this->adminSurA();
        $em = $this->em();
        $this->activerValiditeCarteDemo($em);

        $session = $this->ouvrirSession($client, $entete);
        [$identifiant, $billetSupportId] = $this->venteCarteEtEmission($client, $entete, $session['id']);

        $reponse = $client->request('POST', '/api/acces/appairages', $entete + [
            'json' => [
                'identifiantSupport' => $identifiant,
                'typeSupport' => 'QR',
                'billetSupportRef' => $billetSupportId,
                'mode' => 'caisse',
            ],
        ]);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));
        $appairage = $reponse->toArray();
        $idAppairage = basename((string) $appairage['id']);
        $idDroit = (string) $appairage['droit']['id'];

        // Valeur custom, distincte de ce que la projection calculerait à nouveau (J + 1 an) — la
        // re-projection ne doit PAS l'écraser (§3.6 du plan, point ouvert n°9 de la spec).
        $droit = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droit);
        $valeurCustom = new \DateTimeImmutable('+3 days');
        $droit->setFenetreFin($valeurCustom);
        $em->flush();

        // Ré-appairage après révocation (perte/vol, §1.3 point ouvert n°9 de la spec) : la re-projection
        // repasse par le même billetSupportRef, donc par la branche `!$estNouveau`.
        $client->request('POST', '/api/acces/appairages/' . $idAppairage . '/revoquer', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $reponse2 = $client->request('POST', '/api/acces/appairages', $entete + [
            'json' => [
                'identifiantSupport' => $identifiant,
                'typeSupport' => 'QR',
                'billetSupportRef' => $billetSupportId,
                'mode' => 'caisse',
            ],
        ]);
        self::assertResponseIsSuccessful((string) $reponse2->getContent(false));

        $em->clear();
        $droitApres = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droitApres);
        // Comparaison à la seconde : la colonne `datetime_immutable` ne conserve pas les microsecondes.
        self::assertSame(
            $valeurCustom->format('Y-m-d H:i:s'),
            $droitApres->getFenetreFin()?->format('Y-m-d H:i:s'),
            'Re-projection : fenetreFin ne doit PAS être réinitialisée.',
        );
    }

    private function activerValiditeCarteDemo(EntityManagerInterface $em): void
    {
        $produit = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_CARTE]);
        $carte = $produit->getCarte();
        self::assertNotNull($carte);
        $carte->setValiditeDuree(new \DateInterval('P1Y'))->setDateButoir(null);
        $em->flush();
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array{0: string, 1: string} identifiant du support, id du BilletSupport
     */
    private function venteCarteEtEmission(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $sessionId): array
    {
        $vente = $this->creerVente($client, $entete, $sessionId);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $valide = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []])->toArray();
        self::assertResponseIsSuccessful();
        $identifiant = $valide['supports'][0]['identifiantSupport'];
        $billetSupport = $this->entite(BilletSupport::class, ['identifiantSupport' => $identifiant]);

        return [$identifiant, (string) $billetSupport->getId()];
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
