<?php

declare(strict_types=1);

namespace App\Tests\Marketing\Api;

use App\Caisse\Entity\PointDeVente;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\Marketing\Entity\Campaign;
use App\Marketing\Entity\CampaignRecipient;
use App\Marketing\Entity\Segment;
use App\Marketing\Entity\SegmentCriteria;
use App\Marketing\Enum\CampaignStatus;
use App\Marketing\Enum\RecipientOutcome;
use App\Tests\Marketing\MarketingApiTestCase;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;

/**
 * L'ATTRIBUTION — ce que ces tests protègent est la HONNÊTETÉ d'un chiffre, pas son existence.
 *
 * Une mesure d'attribution est facile à produire et facile à truquer sans le vouloir : il suffit de
 * compter les revenus du groupe contacté et de les appeler « effet de la campagne ». Le chiffre sera
 * flatteur, il sera faux, et rien dans l'écran ne le contredira.
 *
 * Chaque test ci-dessous ferme une de ces sorties-là.
 */
final class AttributionTest extends MarketingApiTestCase
{
    /**
     * **CA-6 : la fenêtre est une borne, pas une décoration.**
     *
     * Une vente conclue avant l'envoi ne peut pas avoir été provoquée par un message pas encore
     * parti ; une vente conclue trois mois après ne lui doit plus rien. Sans ces deux bornes, une
     * campagne finirait par s'attribuer l'année entière.
     */
    public function testSeulesLesVentesDeLaFenetreSontAttribuees(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$destinataire] = $this->clients(1);

        $this->vente($destinataire, '-70 days', '99.00');   // avant l'envoi
        $this->vente($destinataire, '-45 days', '40.00');   // dans la fenêtre
        $this->vente($destinataire, '-20 days', '99.00');   // après la fenêtre

        $campagne = $this->campagneEnvoyee([$destinataire], []);

        $mesure = $client->request('GET', $this->url($campagne), $entete)->toArray();

        self::assertSame(1, $mesure['contactes']['revenus']);
        self::assertSame(1, $mesure['contactes']['visites']);
        self::assertSame('40.00', $mesure['contactes']['ca'], 'Seule la vente de la fenêtre compte.');
        self::assertTrue($mesure['fenetre']['close']);
    }

    /**
     * **Une vente non validée n'est pas un retour.**
     *
     * Un panier abandonné et une vente annulée se lisent comme des visites si on ne regarde que la
     * table. Les compter rendrait la campagne rentable sur de l'argent que personne n'a encaissé.
     */
    public function testUnPanierAbandonneNEstPasUnRetour(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$destinataire] = $this->clients(1);

        $this->vente($destinataire, '-45 days', '60.00', StatutVente::EnCours);
        $this->vente($destinataire, '-44 days', '60.00', StatutVente::Annulee);

        $campagne = $this->campagneEnvoyee([$destinataire], []);

        $mesure = $client->request('GET', $this->url($campagne), $entete)->toArray();

        self::assertSame(0, $mesure['contactes']['revenus']);
        self::assertSame('0.00', $mesure['contactes']['ca']);
    }

    /**
     * **LE TEST CENTRAL : on compare des taux, jamais des totaux.**
     *
     * Ici les deux groupes se comportent exactement pareil — un client sur deux revient de chaque
     * côté — mais le groupe contacté est deux fois et demie plus gros, donc son chiffre d'affaires
     * l'est aussi. Un module qui comparerait les totaux annoncerait un triomphe.
     *
     * La bonne réponse est **zéro** : cette campagne n'a rien produit.
     */
    public function testUnGroupeContactePlusGrosNeVautPasUneCampagneEfficace(): void
    {
        [$client, $entete] = $this->adminSurA();
        $tous = $this->clients(14);
        $contactes = \array_slice($tous, 0, 10);
        $temoins = \array_slice($tous, 10, 4);

        foreach (\array_slice($contactes, 0, 5) as $acheteur) {
            $this->vente($acheteur, '-45 days', '40.00');
        }
        foreach (\array_slice($temoins, 0, 2) as $acheteur) {
            $this->vente($acheteur, '-45 days', '40.00');
        }

        $campagne = $this->campagneEnvoyee($contactes, $temoins);

        $mesure = $client->request('GET', $this->url($campagne), $entete)->toArray();

        self::assertSame('200.00', $mesure['contactes']['ca']);
        self::assertSame('80.00', $mesure['temoins']['ca'], 'Les totaux sont très différents…');
        self::assertSame(50.0, (float) $mesure['contactes']['tauxRetour']);
        self::assertSame(50.0, (float) $mesure['temoins']['tauxRetour'], '…et les taux, identiques.');
        self::assertSame(0.0, (float) $mesure['ecartPoints'], 'La campagne n’a rien produit : l’écart est nul.');
        self::assertFalse(
            $mesure['concluant'],
            'Sur 4 témoins, aucun écart ne se distingue du bruit — et le module doit le dire.',
        );
    }

    /**
     * **Sans groupe témoin, on refuse de conclure.**
     *
     * C'est le mensonge le plus facile du module : rendre le taux de retour brut en le nommant
     * « effet de la campagne ». Une part de l'audience revient toujours d'elle-même ; sans point de
     * comparaison, ce taux mesure la saison, pas le message.
     */
    public function testSansTemoinLeModuleRefuseDeConclure(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$destinataire] = $this->clients(1);
        $this->vente($destinataire, '-45 days', '40.00');

        $campagne = $this->campagneEnvoyee([$destinataire], []);

        $mesure = $client->request('GET', $this->url($campagne), $entete)->toArray();

        self::assertFalse($mesure['comparable']);
        self::assertArrayNotHasKey('ecartPoints', $mesure);
        self::assertArrayNotHasKey('visitesGagnees', $mesure);
        self::assertStringContainsString('témoin', $mesure['raison']);
    }

    /**
     * **Une campagne pas encore partie n'a rien produit — et ce n'est pas zéro.**
     *
     * Rendre des zéros se lirait « la campagne n'a rien donné ». Il faut dire qu'elle n'a pas encore
     * eu lieu : la même valeur affichée, deux conclusions opposées.
     */
    public function testUneCampagneNonEnvoyeeNEstPasMesurable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $campagne = $this->campagne();

        $mesure = $client->request('GET', $this->url($campagne), $entete)->toArray();

        self::assertFalse($mesure['mesurable']);
        self::assertArrayNotHasKey('contactes', $mesure);
    }

    /**
     * **L'attribution d'un autre établissement est introuvable, pas interdite.**
     *
     * Cette réponse expose le chiffre d'affaires d'une part du fichier client. Distinguer « hors
     * périmètre » de « inexistante » permettrait de confirmer l'existence d'une campagne voisine en
     * essayant des identifiants.
     */
    public function testLAttributionDUnAutreEtablissementEstIntrouvable(): void
    {
        $campagne = $this->campagneEnvoyee($this->clients(1), []);

        [$clientB, $enteteB] = $this->agentSurGroupeB();
        $reponse = $clientB->request('GET', $this->url($campagne), $enteteB);

        self::assertSame(404, $reponse->getStatusCode());
    }

    // --- outillage --------------------------------------------------------------------------------

    private function url(Campaign $campagne): string
    {
        return '/api/marketing/campagnes/' . $campagne->getId() . '/attribution';
    }

    /**
     * @param list<Client> $contactes
     * @param list<Client> $temoins
     */
    private function campagneEnvoyee(array $contactes, array $temoins): Campaign
    {
        $em = $this->em();
        $campagne = $this->campagne();
        $campagne->setStatus(CampaignStatus::Envoyee)->setSentAt(new \DateTimeImmutable('-60 days'));

        foreach ([[RecipientOutcome::Journalise, $contactes], [RecipientOutcome::Temoin, $temoins]] as [$issue, $liste]) {
            foreach ($liste as $destinataire) {
                $em->persist(new CampaignRecipient($campagne, $destinataire, $issue));
            }
        }
        $em->flush();

        return $campagne;
    }

    private function campagne(): Campaign
    {
        $em = $this->em();
        $campagne = (new Campaign())
            ->setEstablishment($this->etablissementA())
            ->setSegment($this->segment())
            ->setLabel('Retour de saison')
            ->setSubject('Un message')
            ->setBody('Nous serions heureux de vous revoir.');
        $em->persist($campagne);
        $em->flush();

        return $campagne;
    }

    private function segment(): Segment
    {
        $em = $this->em();
        $segment = (new Segment())
            ->setEstablishment($this->etablissementA())
            ->setLabel('Cible d’attribution')
            ->setCriteria([SegmentCriteria::TYPE => 'physique']);
        $em->persist($segment);
        $em->flush();

        return $segment;
    }

    /** @return list<Client> */
    private function clients(int $combien): array
    {
        $em = $this->em();
        $modele = $em->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertInstanceOf(Client::class, $modele);

        $clients = [];
        for ($i = 0; $i < $combien; ++$i) {
            $client = (new Client())
                ->setType(TypeClient::Physique)
                ->setGroupe($modele->getGroupe())
                ->setEtablissementCreation($this->etablissementA())
                ->setNom('Attribution ' . $i)
                ->setPrenom('Test');
            $em->persist($client);
            $clients[] = $client;
        }
        $em->flush();

        return $clients;
    }

    private function vente(
        Client $client,
        string $quand,
        string $montant,
        StatutVente $statut = StatutVente::Validee,
    ): void {
        $em = $this->em();
        $em->persist(
            (new Vente())
                ->setNumero('V-' . bin2hex(random_bytes(6)))
                ->setPointDeVente($this->pointDeVente())
                ->setEtablissement($this->etablissementA())
                ->setClient($client->getId())
                ->setDate(new \DateTimeImmutable($quand))
                ->setStatut($statut)
                ->setTotal($montant)
        );
        $em->flush();
    }

    private function pointDeVente(): PointDeVente
    {
        $em = $this->em();
        $existant = $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => 'Caisse d’attribution']);
        if ($existant instanceof PointDeVente) {
            return $existant;
        }

        $pdv = (new PointDeVente())
            ->setLibelle('Caisse d’attribution')
            ->setEtablissement($this->etablissementA())
            ->setMoyensAutorises(['especes']);
        $em->persist($pdv);
        $em->flush();

        return $pdv;
    }
}
