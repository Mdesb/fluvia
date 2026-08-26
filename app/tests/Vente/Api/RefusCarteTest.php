<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\CardRejection;
use App\Vente\Enum\StatutTPE;
use App\Vente\Service\CardRejectionRecorder;
use App\Vente\Tpe\TpeMock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PAY-3 — **un refus de carte est un fait, et il ne laissait aucune trace.**
 *
 * `CA-10` veut qu'un refus TPE ne crée aucun `Paiement`, et c'est juste : rien n'a été encaissé. Mais
 * la conséquence était qu'il ne restait **rien** — un code de statut dans une réponse HTTP que
 * personne ne conserve.
 *
 * Le contrat demandé par `claude-D` ne portait qu'un message sur le bus. Elle a elle-même établi que
 * sa bascule carte → prélèvement **n'a aucun client aujourd'hui** : aucun débit récurrent sur carte
 * n'existe dans le produit. Son abonné est donc le seul consommateur et n'agira sur aucun refus.
 * Publier sans écrire n'aurait laissé aucune trace de la **totalité** des refus — pas en cas de panne,
 * mais en fonctionnement normal, dès le premier jour.
 */
final class RefusCarteTest extends VenteApiTestCase
{
    /** Un refus laisse une ligne — avec de quoi le compter sans jointure. */
    public function testUnRefusEstConsigne(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->forcerTpe(StatutTPE::Refuse);

        $vente = $this->panier($client, $entete);
        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'cb'],
        ])->toArray();

        // Le comportement d'origine ne bouge pas : aucun règlement, reste dû inchangé (CA-10).
        self::assertFalse($reponse['reglementEnregistre']);
        self::assertSame('refuse', $reponse['statutTPE']);

        $refus = $this->dernierRefus();
        self::assertNotNull($refus, 'Sans trace, un refus de carte n\'a jamais existé.');
        self::assertSame('cb', $refus->getMoyenCode());
        self::assertSame(StatutTPE::Refuse, $refus->getStatutTpe());
        self::assertGreaterThan(0, $refus->getMontantCentimes(), 'Le montant doit être comptable sans relire la vente.');
        self::assertNotNull($refus->getEtablissement());
        self::assertNotNull($refus->getPointDeVente());
    }

    /** **Un paiement accepté ne consigne rien** : la trace dit un refus, pas une tentative. */
    public function testUnPaiementAccepteNeConsigneRien(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $vente = $this->panier($client, $entete);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'cb']]);
        self::assertResponseIsSuccessful();

        self::assertNull($this->dernierRefus(), 'Une table qui se remplit aussi des succès ne se compte plus.');
    }

    /**
     * Le montant est en **centimes entiers**, et c'est ce qui voyagera jusqu'au préavis SEPA.
     *
     * `DebitPreNotifier::covers()` compare le montant annoncé et le montant prélevé : un arrondi en
     * route ferait reconnaître « une annonce qui ressemble à la bonne sans en être une » — tout serait
     * vert et rien ne serait couvert.
     */
    public function testLeMontantEstEnCentimesEntiers(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->forcerTpe(StatutTPE::Refuse);

        $vente = $this->panier($client, $entete);
        $lu = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'cb']]);

        $refus = $this->dernierRefus();
        self::assertNotNull($refus);
        self::assertSame(
            (int) round((float) $lu['resteAPayer'] * 100),
            $refus->getMontantCentimes(),
            'Le montant refusé est celui qui restait dû, au centime.',
        );
    }

    /** Un timeout est un refus comme un autre : rien n'est encaissé, et personne ne sait pourquoi. */
    public function testUnTimeoutEstConsigneAussi(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->forcerTpe(StatutTPE::Timeout);

        $vente = $this->panier($client, $entete);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'cb']]);

        $refus = $this->dernierRefus();
        self::assertNotNull($refus);
        self::assertSame(StatutTPE::Timeout, $refus->getStatutTpe());
    }

    /** La lecture est cloisonnée comme le reste, et exposée sans aucune écriture. */
    public function testLesRefusSeLisentEtNeSecriventPas(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->forcerTpe(StatutTPE::Refuse);

        $vente = $this->panier($client, $entete);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'cb']]);

        $liste = $client->request('GET', '/api/refus_cartes', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($liste['member'] ?? $liste);

        // Aucune opération d'écriture n'est déclarée : une ligne naît d'un fait constaté par le
        // terminal, jamais d'une saisie.
        $client->request('POST', '/api/refus_cartes', $entete + ['json' => ['moyenCode' => 'cb']]);
        self::assertResponseStatusCodeSame(405);
    }

    /** Le nom de l'événement émis est celui du catalogue — une faute de frappe n'appellerait personne. */
    public function testLeNomDeLEvenementEstCeluiDuCatalogue(): void
    {
        self::assertSame('sale.card_payment_rejected', CardRejectionRecorder::EVENEMENT);
    }

    private function forcerTpe(StatutTPE $statut): void
    {
        $tpe = static::getContainer()->get(TpeMock::class);
        self::assertInstanceOf(TpeMock::class, $tpe);
        $tpe->forcerIssue($statut);
    }

    private function dernierRefus(): ?CardRejection
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(CardRejection::class)->findOneBy([], ['dateHeure' => 'DESC']);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function panier(object $client, array $entete): array
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

        return $vente;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
