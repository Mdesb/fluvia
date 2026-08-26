<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\RegieRecettes;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `POST /compta/periodes/{id}/simuler-cloture` — montrer l'arrêté avant de le figer.
 *
 * **Ce que ces tests protègent.** La clôture est définitive, et l'exploitant la validait sans avoir vu
 * les montants qu'elle allait figer. Une simulation qui annoncerait autre chose que ce que la clôture
 * enregistre serait pire que pas de simulation du tout : la divergence se découvrirait sur un arrêté,
 * c'est-à-dire trop tard et sur le document qui fait foi. C'est **l'égalité** entre les deux qui est
 * l'objet du lot, pas la présence de la route.
 */
final class SimulerClotureTest extends ComptaApiTestCase
{
    /** **Le test central : la simulation annonce exactement ce que la clôture enregistre.** */
    public function testLaSimulationAnnonceExactementCeQueLaClotureEnregistre(): void
    {
        [$client, $entete] = $this->adminSurA();

        $vente = $this->creerVenteValidee($client, $entete, quantite: 1);
        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);

        $periodeId = $this->periodeDeLaVente($vente['id']);

        $simule = $client->request('POST', '/api/compta/periodes/' . $periodeId . '/simuler-cloture', $entete)->toArray();

        self::assertSame('ouverte', $simule['statut'], 'simuler ne cloture pas');
        self::assertTrue($simule['definitive']);
        self::assertSame([], $simule['pointsBloquants']);
        self::assertGreaterThan(0, $simule['arrete']['nbEcritures']);

        $cloture = $client->request('POST', '/api/compta/periodes/' . $periodeId . '/cloturer', $entete)->toArray();

        foreach (['produitsCentimes', 'tvaCentimes', 'encaissementsCentimes', 'nbEcritures'] as $poste) {
            self::assertSame(
                $simule['arrete'][$poste],
                $cloture['etatCloture'][$poste],
                sprintf('« %s » annoncé et « %s » enregistré doivent être le même nombre.', $poste, $poste),
            );
        }
    }

    /** Simuler ne fige rien : la période reste ouverte, et le profil non verrouillé. */
    public function testSimulerNeFigeRien(): void
    {
        [$client, $entete] = $this->adminSurA();

        $vente = $this->creerVenteValidee($client, $entete, quantite: 1);
        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);
        $periodeId = $this->periodeDeLaVente($vente['id']);

        $client->request('POST', '/api/compta/periodes/' . $periodeId . '/simuler-cloture', $entete);
        $client->request('POST', '/api/compta/periodes/' . $periodeId . '/simuler-cloture', $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $periode = $em->getRepository(PeriodeComptable::class)->find($periodeId);
        self::assertNotNull($periode);
        self::assertTrue($periode->estOuverte(), 'deux simulations laissent la periode ouverte');
        self::assertNull($periode->getEtatCloture(), 'aucun arrete n est enregistre par une simulation');
    }

    /**
     * **La simulation annonce le refus au lieu de le laisser survenir.**
     *
     * Montrer des montants crédibles sans dire que la clôture refusera encore ferait promettre un
     * geste qui échouera : l'exploitant lirait l'arrêté, cliquerait, et recevrait un 409 qu'il aurait
     * pu voir venir.
     */
    public function testLaSimulationAnnonceLesPointsBloquants(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $regie = $this->entite(RegieRecettes::class, ['libelle' => \App\Compta\DataFixtures\ComptaFixtures::REGIE_LIBELLE]);
        $regie->setSoldeEncaisseCentimes($regie->getPlafondEncaisseCentimes() + 1000);

        $periode = new PeriodeComptable();
        $periode->setProfilExploitant($this->profilExploitant());
        $periode->setDateDebut(new \DateTimeImmutable('first day of this month'));
        $periode->setDateFin(new \DateTimeImmutable('last day of this month'));
        $em->persist($periode);
        $em->flush();

        $simule = $client->request('POST', '/api/compta/periodes/' . $periode->getId() . '/simuler-cloture', $entete)->toArray();

        self::assertNotSame([], $simule['pointsBloquants'], 'le refus est annonce avant le clic');

        $refus = $client->request('POST', '/api/compta/periodes/' . $periode->getId() . '/cloturer', $entete);
        self::assertSame(409, $refus->getStatusCode(), 'et la cloture refuse bien pour la meme raison');
    }

    /**
     * Générer deux fois de suite ne double pas le journal.
     *
     * L'idempotence séquentielle existait déjà ; ce test l'épingle, parce que le verrou pessimiste
     * posé sur le profil et la transaction qui l'entoure pourraient la casser sans qu'on s'en aperçoive
     * — et un journal comptable doublé se corrige par extourne, pas en effaçant des lignes.
     */
    public function testGenererDeuxFoisNeDoublePasLeJournal(): void
    {
        [$client, $entete] = $this->adminSurA();

        $this->creerVenteValidee($client, $entete, quantite: 1);
        $corps = $entete + ['json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()]];

        $premier = $client->request('POST', '/api/compta/ecritures/generer', $corps)->toArray();
        $second = $client->request('POST', '/api/compta/ecritures/generer', $corps)->toArray();

        self::assertGreaterThan(0, $premier['ecrituresGenerees']);
        self::assertSame(0, $second['ecrituresGenerees'], 'la seconde execution ne retrouve rien a comptabiliser');
    }

    private function periodeDeLaVente(string $venteId): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ecriture = $em->getRepository(EcritureComptable::class)->findOneBy(['venteOrigine' => $venteId]);
        self::assertNotNull($ecriture, 'la vente doit avoir produit une ecriture');
        $periodeId = $ecriture->getPeriode()?->getId();
        self::assertNotNull($periodeId);

        return (string) $periodeId;
    }
}
