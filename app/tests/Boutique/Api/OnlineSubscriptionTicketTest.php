<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\ProductAccessZone;
use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\DataFixtures\SocleFixtures;
use App\Membership\Entity\Membership;
use App\Membership\Entity\StatutAccesFitness;
use App\Membership\Service\DemanderResiliationHandler;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Vente\Entity\BilletSupport;
use Symfony\Component\Uid\Uuid;

/**
 * EN LIGNE AUSSI, LE BILLET DE L'ABONNEMENT EST LIÉ À L'ABONNEMENT (décision de Maxime du 07/10).
 *
 * La souscription en ligne valide la vente, dont le billet QR est affiché au client, puis souscrit.
 * Mesuré le 07/10 sur main : ce billet a un droit de source `billet`, sans fin, que la résiliation
 * ne coupait pas, alors qu'elle coupait le QR de l'abonnement, que le client n'a jamais reçu.
 */
final class OnlineSubscriptionTicketTest extends BoutiqueApiTestCase
{
    public function testTheOnlineTicketStopsOpeningOnceTheSubscriptionIsTerminated(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_ABONNEMENT_CODE]);
        $espace = $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE]);
        $etablissement = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $this->em()->persist((new ProductAccessZone($produit->getId(), $espace))->setEstablishment($etablissement));
        $this->entite(Equipement::class, ['libelle' => AccesFixtures::EQUIPEMENT_LIBELLE])->setAntiPassbackActif(false);
        $this->em()->flush();

        $client = static::createClient();
        $token = $this->jeton($client, BoutiqueFixtures::CLIENT_EMAIL, BoutiqueFixtures::CLIENT_MDP);
        $client->request('POST', '/api/boutique/abonnements/souscrire', [
            'auth_bearer' => $token,
            'json' => ['produit' => (string) $produit->getId(), 'iban' => 'FR7630006000011234567890189', 'bicDebiteur' => 'AGRIFRPP', 'debiteurNom' => 'Camille Martin'],
        ]);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $billets = $this->em()->getRepository(BilletSupport::class)->findAll();
        self::assertCount(1, $billets, 'Témoin : la vente en ligne émet le billet affiché au client.');
        $code = (string) $billets[0]->getIdentifiantSupport();

        self::assertSame(['valide', null], $this->gate($code), 'Pendant l\'abonnement, le billet ouvre.');

        // Relu après la requête : le noyau a redémarré.
        $abonnement = $this->em()->getRepository(Membership::class)->findOneBy([]);
        self::assertInstanceOf(Membership::class, $abonnement);
        $resiliations = static::getContainer()->get(DemanderResiliationHandler::class);
        $resiliations->executerEffet($resiliations->demander($abonnement, $abonnement->getDateFinEngagement()->modify('+1 day'), 'départ', false, null));

        self::assertSame(['refuse', 'droit_invalide'], $this->gate($code), 'Abonnement résilié : le billet affiché en ligne n\'ouvre plus.');
        $statut = $this->em()->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnement->getId()]);
        self::assertSame($code, $statut?->getSupportIdentifiant(), 'Un seul accès : la fiche abonnement montre le billet du client.');
    }

    /** @return array{0: string, 1: ?string} le résultat du passage à la borne de démonstration, et son motif */
    private function gate(string $code): array
    {
        $equipement = $this->entite(Equipement::class, ['libelle' => AccesFixtures::EQUIPEMENT_LIBELLE]);
        $reponse = static::createClient()->request('POST', '/api/terminal/passages', [
            'headers' => ['Authorization' => 'Bearer ' . AccesFixtures::TERMINAL_SECRET],
            'json' => ['equipementId' => (string) $equipement->getId(), 'identifiantSupport' => $code, 'cleIdempotence' => (string) Uuid::v4()],
        ]);
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        $passage = $reponse->toArray();

        return [$passage['resultat'], $passage['codeMotif']];
    }
}
