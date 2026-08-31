<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Securite\Entity\Utilisateur;
use App\Support\DataFixtures\SupportFixtures;
use App\Support\Entity\MessageTicket;
use App\Support\Entity\PieceJointeTicket;
use App\Support\Entity\TicketSupport;
use App\Organisation\Entity\Etablissement;
use App\Tests\Support\SupportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LES PIECES JOINTES DES TICKETS D'UN AUTRE CLIENT SONT-ELLES LISIBLES ?
 *
 * ⚠ UNE PORTE FERMEE, SA VOISINE OUVERTE — LE MOTIF DE LA JOURNEE.
 *
 * `MessageTicket` porte un `provider: MessageTicketProvider::class` sur sa collection : les messages
 * sont donc cloisonnes par un fournisseur sur mesure. `PieceJointeTicket` n'en a AUCUN, et sa
 * `GetCollection` est gardee par cinq permissions dont `support.ouvrir_ticket` — celle que porte
 * tout client capable d'ouvrir un ticket.
 *
 * Le groupe `piece_jointe_ticket:read` expose `nomFichier`, `typeMime`, `taille` et `url`. Un client
 * depose dans un ticket ce qu'il veut : capture d'ecran, export, fichier de donnees.
 *
 * C'est la meme forme que `OperationScellee` (le verificateur de chaine cloisonne, la collection
 * non) et que `EditorSupportAccess` (le processeur verifie, la garde declarative non). Trois fois
 * le meme jour : le travail a ete fait avec soin sur une porte, et la voisine est restee ouverte.
 *
 * ⚠ NI ECRAN NI TEST N'APPELAIT CETTE COLLECTION. `grep piece_jointe` ne rend rien dans
 * `frontend/src` ni dans `app/tests`. Une ressource exposee que personne n'utilise reste une
 * ressource exposee — c'est deja ce qui avait laisse `ParametreFacturationEtablissement` ouverte.
 */
final class CloisonnementPiecesJointesTest extends SupportApiTestCase
{
    private const FICHIER_ETRANGER = 'export-confidentiel-du-voisin.csv';
    private const FICHIER_SIEN = 'capture-de-mon-ecran.png';

    public function testLesPiecesJointesDunAutreClientNeSontPasListees(): void
    {
        $idSien = $this->pieceJointeSur(SupportFixtures::ETAB_A_NOM, SupportFixtures::EMAIL_EXPLOITANT_A, self::FICHIER_SIEN);
        $idEtranger = $this->pieceJointeSur(SupportFixtures::ETAB_B_NOM, SupportFixtures::EMAIL_EXPLOITANT_B, self::FICHIER_ETRANGER);

        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_A, SupportFixtures::ETAB_A_NOM);

        $client->request('GET', '/api/piece_jointe_tickets', $entete + ['query' => ['itemsPerPage' => 100]]);

        // ⚠ CETTE ASSERTION EST AUSSI LE TEMOIN DE LA ROUTE. Le pluriel des ressources est fabrique
        // mecaniquement par API Platform (`SousReseau` donne `sous_reseaus`), et je me suis deja
        // trompe dessus aujourd'hui : une route inexistante rend 404, ce qui ressemble a un refus.
        self::assertResponseIsSuccessful('temoin : la route doit exister et repondre');

        $corps = $client->getResponse()->getContent(false);

        self::assertStringContainsString(
            self::FICHIER_SIEN,
            $corps,
            'temoin : la piece jointe de MON ticket doit etre lisible, sinon la mesure suivante '
            . 'porte sur une collection vide et ne prouve rien',
        );

        self::assertStringNotContainsString(
            self::FICHIER_ETRANGER,
            $corps,
            'la collection expose `nomFichier`, `typeMime`, `taille` et `url` : les pieces jointes '
            . 'des tickets d\'un autre client ne doivent pas y figurer',
        );

        self::assertStringNotContainsString(
            $idEtranger,
            $corps,
            'l\'identifiant de la piece jointe etrangere ne doit pas non plus etre publie',
        );
        self::assertNotSame('', $idSien);
    }


    /**
     * LE FILTRE `message` DISCRIMINE-T-IL ENCORE ?
     *
     * ⚠ EN POSANT UN FOURNISSEUR SUR MESURE, J'AI DESACTIVE UN FILTRE DECLARE.
     *
     * `PieceJointeTicket` porte `#[ApiFilter(SearchFilter::class, properties: ['message' =>
     * 'exact'])]`. Ce filtre est applique par les extensions Doctrine d'API Platform, qu'un
     * fournisseur court-circuite entierement. `?message=...` etait donc accepte, ignore, et rendait
     * TOUTES les pieces jointes du perimetre.
     *
     * Pas d'erreur, pas de refus : de mauvaises donnees. Un filtre casse ne leve rien, il rend trop.
     *
     * ⚠ LES DEUX PIECES SONT SUR LE MEME TICKET, DELIBEREMENT. Si elles etaient sur deux
     * etablissements, le cloisonnement suffirait a faire passer ce test sans qu'aucun filtre ne
     * s'applique — et il mesurerait autre chose que son nom.
     */
    public function testLeFiltreParMessageDiscrimineEncore(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SupportFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);
        $demandeur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SupportFixtures::EMAIL_EXPLOITANT_A]);
        self::assertInstanceOf(Utilisateur::class, $demandeur);

        $ticket = (new TicketSupport())->setSujet('Ticket a deux messages')->setDescription('Mesure du filtre.');
        $ticket->setEtablissement($etab)->setDemandeur($demandeur);
        $em->persist($ticket);

        $ids = [];
        foreach (['premier' => 'piece-du-premier.png', 'second' => 'piece-du-second.png'] as $rang => $fichier) {
            $message = (new MessageTicket())->setContenu('Message ' . $rang);
            $message->setTicket($ticket)->setAuteur($demandeur);
            $em->persist($message);

            $piece = (new PieceJointeTicket())
                ->setNomFichier($fichier)
                ->setTypeMime('image/png')
                ->setTaille(10)
                ->setUrl('https://exemple.invalid/' . $fichier);
            $piece->setMessage($message);
            $em->persist($piece);

            $ids[$rang] = ['message' => $message, 'fichier' => $fichier];
        }
        $em->flush();

        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_A, SupportFixtures::ETAB_A_NOM);

        $client->request('GET', '/api/piece_jointe_tickets', $entete + [
            'query' => ['message' => '/api/message_tickets/' . $ids['premier']['message']->getId()],
        ]);
        self::assertResponseIsSuccessful();

        $corps = $client->getResponse()->getContent(false);

        self::assertStringContainsString(
            $ids['premier']['fichier'],
            $corps,
            'temoin : la piece du message demande doit etre rendue',
        );
        self::assertStringNotContainsString(
            $ids['second']['fichier'],
            $corps,
            'la piece d\'un AUTRE message du meme ticket ne doit pas etre rendue : sans quoi le '
            . 'filtre `message` est inoperant et la collection rend tout le perimetre',
        );
    }

    /**
     * UN AGENT DE SUPPORT PEUT-IL LIRE LES MESSAGES D'UN TICKET D'UN AUTRE ETABLISSEMENT ?
     *
     * ⚠ CE TEST NE PORTE PAS SUR LES PIECES JOINTES, ET IL EST ICI PARCE QUE C'EST EN LES FERMANT
     * QUE LA QUESTION EST APPARUE.
     *
     * `MessageTicketProvider` charge le ticket par identifiant, calcule les droits de l'appelant
     * pour l'ETABLISSEMENT ACTIF, et conclut « agent » ou « demandeur ». Rien ne compare
     * l'etablissement du ticket a l'actif : les droits sont calcules pour A, l'objet appartient a B,
     * et les deux ne se rencontrent jamais.
     *
     * La borne : `PerimetreSupportExtension` cloisonne `TicketSupport`, donc l'identifiant d'un
     * ticket voisin n'est pas listable. C'est une protection par secret d'identifiant, pas par
     * verification — et jusqu'a ce matin la collection des pieces jointes publiait l'IRI du message,
     * ce qui donnait precisement ce secret.
     *
     * Le test emprunte donc le chemin d'un appelant qui detient l'identifiant.
     */
    public function testUnAgentNeLitPasLesMessagesDunTicketDunAutreEtablissement(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $ticketB = $this->ticketSur(SupportFixtures::ETAB_B_NOM, SupportFixtures::EMAIL_EXPLOITANT_B, 'Ticket du voisin');
        $ticketA = $this->ticketSur(SupportFixtures::ETAB_A_NOM, SupportFixtures::EMAIL_EXPLOITANT_A, 'Ticket a moi');
        $em->flush();

        // Un agent de support rattache au site A.
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_AGENT_N1, SupportFixtures::ETAB_A_NOM);

        // ⚠ TEMOIN : L'AGENT LIT BIEN LES MESSAGES DE SON PROPRE SITE.
        //
        // Sans lui, un refus ci-dessous se confondrait avec « cet agent ne lit rien du tout » — un
        // compte mal configure, une route fausse, un fournisseur qui rend toujours vide.
        $client->request('GET', '/api/support/tickets/' . $ticketA . '/messages', $entete);
        self::assertResponseIsSuccessful('temoin : l\'agent doit lire les messages de SON site');
        self::assertNotEmpty(
            $client->getResponse()->toArray()['member'] ?? $client->getResponse()->toArray()['hydra:member'] ?? [],
            'temoin : la liste de son propre ticket ne doit pas etre vide',
        );

        $client->request('GET', '/api/support/tickets/' . $ticketB . '/messages', $entete);
        $rendu = $client->getResponse()->toArray(false);
        $membres = $rendu['member'] ?? $rendu['hydra:member'] ?? [];

        self::assertEmpty(
            $membres,
            'un agent du site A ne doit pas lire les messages d\'un ticket du site B : les droits '
            . 'sont calcules pour l\'etablissement ACTIF, et rien ne verifie que le ticket en vient',
        );
    }

    /** Cree un ticket et un message sur l'etablissement donne ; rend l'id du ticket. */
    private function ticketSur(string $etablissementNom, string $emailDemandeur, string $sujet): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $etablissementNom]);
        self::assertInstanceOf(Etablissement::class, $etab);
        $demandeur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => $emailDemandeur]);
        self::assertInstanceOf(Utilisateur::class, $demandeur);

        $ticket = (new TicketSupport())->setSujet($sujet)->setDescription('Mesure de cloisonnement.');
        $ticket->setEtablissement($etab)->setDemandeur($demandeur);
        $em->persist($ticket);

        $message = (new MessageTicket())->setContenu('Contenu du message.');
        $message->setTicket($ticket)->setAuteur($demandeur);
        $em->persist($message);

        return (string) $ticket->getId();
    }

    /** Cree un ticket, son message et une piece jointe sur l'etablissement donne ; rend l'id de la piece. */
    private function pieceJointeSur(string $etablissementNom, string $emailDemandeur, string $nomFichier): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $etablissementNom]);
        self::assertInstanceOf(Etablissement::class, $etab, 'temoin : ' . $etablissementNom . ' doit exister');

        $demandeur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => $emailDemandeur]);
        self::assertInstanceOf(Utilisateur::class, $demandeur, 'temoin : ' . $emailDemandeur . ' doit exister');

        $ticket = (new TicketSupport())
            ->setSujet('Ticket ' . $etablissementNom)
            ->setDescription('Cree pour mesurer le cloisonnement des pieces jointes.');
        $ticket->setEtablissement($etab)->setDemandeur($demandeur);
        $em->persist($ticket);

        $message = (new MessageTicket())->setContenu('Message portant une piece jointe.');
        $message->setTicket($ticket)->setAuteur($demandeur);
        $em->persist($message);

        $piece = (new PieceJointeTicket())
            ->setNomFichier($nomFichier)
            ->setTypeMime('application/octet-stream')
            ->setTaille(1024)
            ->setUrl('https://exemple.invalid/' . $nomFichier);
        $piece->setMessage($message);
        $em->persist($piece);

        $em->flush();

        return (string) $piece->getId();
    }
}
