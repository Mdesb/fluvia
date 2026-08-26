<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D45 — corriger un moyen de paiement n'est pas une modification, et la date est celle du geste.
 *
 * Le cas réel : un caissier saisit « espèces » alors que le client a payé par carte. Le montant de la
 * vente n'est pas en cause, seule sa ventilation l'est — donc ni modification (la chaîne NF525 la
 * refuserait, et elle aurait raison), ni avoir (il annulerait et rejouerait le chiffre d'affaires
 * pour une erreur qui n'a rien changé au montant).
 */
final class CorrectionReglementTest extends VenteApiTestCase
{
    /** La correction s'ajoute, la vente ne bouge pas, et la chaîne reste vérifiable. */
    public function testLaCorrectionSAjouteSansToucherLaVente(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->venteReglee($client, $entete, 'especes');

        $avant = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray();

        $correction = $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + [
            'json' => [
                'moyenDebite' => 'especes',
                'moyenCredite' => 'cb',
                'montant' => '5.50',
                'motif' => 'Saisi en especes, regle par carte',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('especes', $correction['moyenDebite']);
        self::assertSame('cb', $correction['moyenCredite']);

        // La vente d'origine est intacte : c'est toute la différence avec une modification.
        $apres = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray();
        self::assertSame($avant['total'] ?? null, $apres['total'] ?? null, 'Le montant de la vente ne bouge pas.');
        self::assertSame($avant['statut'], $apres['statut']);
    }

    /** La date est celle du geste, jamais celle de la vente (D45). */
    public function testLaCorrectionEstDateeDuJourDuGeste(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        // La vente est antidatée AVANT sa validation : une fois validée, `InalterabiliteListener`
        // refuse d'y toucher — je l'ai constaté en écrivant ce test, qui échouait sur
        // « Modification interdite : la vente est validée (NF525) ; champ "date" figé ». C'est
        // précisément la garantie sur laquelle repose D45, et elle m'a arrêtée moi aussi.
        //
        // Si la correction reprenait la date de la vente, elle atterrirait dans un Z déjà tiré —
        // c'est-à-dire qu'il faudrait refaire l'histoire du fonds de caisse.
        $vente = $this->venteReglee($client, $entete, 'especes', new \DateTimeImmutable('2026-01-05 10:00:00'));

        $correction = $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + [
            'json' => ['moyenDebite' => 'especes', 'moyenCredite' => 'cb', 'montant' => '1.00', 'motif' => 'Erreur de saisie'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        self::assertStringStartsNotWith('2026-01-05', $correction['dateHeure'], 'La correction ne reprend pas la date de la vente.');
        self::assertStringStartsWith((new \DateTimeImmutable())->format('Y-m-d'), $correction['dateHeure']);
    }

    /**
     * On ne déplace pas plus que ce qui a été réglé sur le moyen — et c'est le **net des corrections
     * déjà passées** qui borne, pas le paiement d'origine.
     *
     * Sans ce contrôle, deux corrections successives déplaceraient deux fois la même somme, et on
     * créditerait la carte depuis des espèces qui n'ont jamais été encaissées.
     */
    public function testOnNeDeplacePasPlusQueCeQuiAEteRegle(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->venteReglee($client, $entete, 'especes');

        $corps = ['moyenDebite' => 'especes', 'moyenCredite' => 'cb', 'montant' => '5.50', 'motif' => 'Erreur de saisie'];

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + ['json' => $corps]);
        self::assertResponseIsSuccessful();

        // Deuxième passage : il ne reste plus rien en espèces sur cette vente.
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + ['json' => $corps]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('disponible', $client->getResponse()->toArray(false)['detail'] ?? '');
    }

    /** Motif obligatoire : ce geste déplace de l'argent, il doit rester défendable en contrôle. */
    public function testLeMotifEstObligatoire(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->venteReglee($client, $entete, 'especes');

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + [
            'json' => ['moyenDebite' => 'especes', 'moyenCredite' => 'cb', 'montant' => '1.00', 'motif' => '   '],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** Deux fois le même moyen : il n'y a rien à corriger, et l'écriture serait du bruit dans la chaîne. */
    public function testDeuxMoyensIdentiquesSontRefuses(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->venteReglee($client, $entete, 'especes');

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + [
            'json' => ['moyenDebite' => 'especes', 'moyenCredite' => 'especes', 'montant' => '1.00', 'motif' => 'Test'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function venteReglee(object $client, array $entete, string $moyen, ?\DateTimeImmutable $date = null): array
    {
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => $moyen, 'montant' => '5.50'],
        ]);
        self::assertResponseIsSuccessful();

        if ($date !== null) {
            /** @var EntityManagerInterface $em */
            $em = static::getContainer()->get('doctrine')->getManager();
            $entite = $em->getRepository(Vente::class)->find($vente['id']);
            self::assertNotNull($entite);
            $entite->setDate($date);
            $em->flush();
        }

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray();
    }
}
