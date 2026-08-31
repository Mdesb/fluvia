<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\CommercialDocument;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\DocumentLine;
use App\Facturation\Entity\Facture;
use App\Facturation\Enum\DocumentNature;
use App\Facturation\Enum\TypeDestinataire;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * FAC-1 par l'API — la chaîne telle qu'un club l'utilise.
 *
 * **Ce que ces tests protègent en plus du moteur, c'est la frontière.** Un devis appartient à
 * l'établissement qui l'a émis ; le voir depuis un autre serait lire le commerce du voisin — noms de
 * clients, prix consentis, remises. C'est la fuite la plus banale d'un logiciel multi-établissements,
 * et elle ne fait aucun bruit.
 */
final class DocumentApiTest extends FacturationApiTestCase
{
    /** La chaîne complète, du devis à la facture, par les routes. */
    public function testLaChaineCompleteSeParcourtParLesRoutes(): void
    {
        [$client, $entete] = $this->adminSurA();

        $devis = $this->creerDevis($client, $entete);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('quote', $devis['nature']);
        self::assertSame('draft', $devis['statut']);
        self::assertSame('360.00', $devis['totalHT']);

        $id = $this->identifiant($devis);

        $client->request('POST', "/api/billing/documents/{$id}/issue", $entete);
        self::assertResponseIsSuccessful();

        $accepte = $client->request('POST', "/api/billing/documents/{$id}/accept", $entete)->toArray();
        self::assertSame('accepted', $accepte['statut']);

        $commande = $client->request('POST', "/api/billing/documents/{$id}/derive", $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('sales_order', $commande['nature']);
        self::assertSame('360.00', $commande['totalHT'], 'la commande reprend le devis sans ressaisie');
    }

    /**
     * **L'API dit elle-même quels gestes une pièce accepte.**
     *
     * L'écran affiche ses boutons depuis `gestesPossibles` plutôt que de recopier la table des
     * transitions. Ce test est donc le contrat entre les deux : si le champ disparaît de la
     * sérialisation, l'écran n'affiche plus aucun bouton — sans erreur, sans trace, et sans que
     * quiconque le remarque avant qu'un exploitant appelle.
     */
    public function testLApiAnnonceLesGestesPossiblesAChaqueEtape(): void
    {
        [$client, $entete] = $this->adminSurA();

        $devis = $this->creerDevis($client, $entete);
        self::assertSame(['issue'], $devis['gestesPossibles'], 'un brouillon ne peut qu etre emis');

        $id = $this->identifiant($devis);
        $emis = $client->request('POST', "/api/billing/documents/{$id}/issue", $entete)->toArray();
        self::assertSame(['accept', 'reject'], $emis['gestesPossibles']);

        $accepte = $client->request('POST', "/api/billing/documents/{$id}/accept", $entete)->toArray();
        self::assertSame(['derive', 'invoice'], $accepte['gestesPossibles']);

        $commande = $client->request('POST', "/api/billing/documents/{$id}/derive", $entete)->toArray();

        // Le devis est converti : il ne reste rien a en faire, et l ecran n affichera aucun bouton.
        $relu = $client->request('GET', "/api/billing/documents/{$id}", $entete)->toArray();
        self::assertSame([], $relu['gestesPossibles']);

        // Un bon de livraison est la derniere nature : on le facture, on ne le derive plus.
        $idCommande = $this->identifiant($commande);
        $client->request('POST', "/api/billing/documents/{$idCommande}/issue", $entete);
        $accepteCommande = $client->request('POST', "/api/billing/documents/{$idCommande}/accept", $entete)->toArray();
        self::assertContains('invoice', $accepteCommande['gestesPossibles']);
    }

    /**
     * **Le test qui compte : un devis d'un autre établissement est introuvable.**
     *
     * Pas « masqué », pas « grisé » : introuvable. Le cloisonnement passe par l'extension Doctrine,
     * donc la pièce n'existe pas pour cet utilisateur — même en connaissant son identifiant.
     *
     * **Le témoin est le lecteur du socle, pas l'administrateur.** L'administrateur est affecté sur A
     * *et* sur B : lui montrer la pièce de B est le comportement correct, et un test monté sur lui
     * serait passé au vert en ne mesurant rien. Le lecteur n'est affecté que sur A et porte le joker
     * `*.lire` — il franchit donc le contrôle d'accès et bute uniquement sur le périmètre. C'est ce
     * qui rend le 404 attribuable au cloisonnement et à rien d'autre.
     */
    public function testUnePieceDunAutreEtablissementEstIntrouvable(): void
    {
        $pieceDeB = $this->devisSur(SocleFixtures::ETAB_B_NOM);

        [$client, $entete] = $this->lecteurSurA();

        $reponse = $client->request('GET', '/api/billing/documents/'.$pieceDeB->getId()->toRfc4122(), $entete);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertStringNotContainsString('Club du voisin', (string) $reponse->getContent(false));
    }

    /** Et la collection ne le liste pas davantage. */
    public function testLaCollectionNeListeQueSonEtablissement(): void
    {
        $this->devisSur(SocleFixtures::ETAB_B_NOM);
        $this->devisSur(SocleFixtures::ETAB_A_NOM, 'Club de chez nous');

        [$client, $entete] = $this->lecteurSurA();
        $reponse = $client->request('GET', '/api/billing/documents', $entete)->toArray();

        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertCount(1, $membres);
        self::assertSame('Club de chez nous', $membres[0]['destinataire']['raisonSociale'] ?? null);
    }

    /** Un devis sans ligne ne propose rien : on refuse plutôt que d'enregistrer un document vide. */
    public function testUnDevisSansLigneEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/billing/documents', $entete + [
            'json' => ['destinataire' => ['raisonSociale' => 'Club de Padel', 'siret' => '12345678900011', 'adresse' => ['rue' => '4 allee du Padel', 'cp' => '75015', 'ville' => 'Paris', 'pays' => 'FR']], 'lignes' => []],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Un taux de TVA d'un autre exploitant est refusé, même avec un identifiant valide.
     *
     * C'est la forme la plus discrète de la fuite inter-tenants : l'identifiant existe, la ligne
     * passerait, et la facture porterait le taux du voisin.
     */
    public function testUnTauxDunAutreExploitantEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $etranger = $this->tauxDunSecondExploitant();

        $client->request('POST', '/api/billing/documents', $entete + [
            'json' => [
                'destinataire' => ['raisonSociale' => 'Club de Padel', 'siret' => '12345678900011', 'adresse' => ['rue' => '4 allee du Padel', 'cp' => '75015', 'ville' => 'Paris', 'pays' => 'FR']],
                'lignes' => [['designation' => 'Stage', 'quantite' => 1, 'prixUnitaireHT' => '10.00', 'tauxTva' => $etranger]],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em()->getRepository(CommercialDocument::class)->findAll());
    }

    /** Un refus métier est un 422 lisible, jamais un 500. */
    public function testDeriverUnePieceNonAccepteeRendUnMessageLisible(): void
    {
        [$client, $entete] = $this->adminSurA();

        $devis = $client->request('POST', '/api/billing/documents', $entete + [
            'json' => [
                'destinataire' => ['raisonSociale' => 'Club de Padel', 'siret' => '12345678900011', 'adresse' => ['rue' => '4 allee du Padel', 'cp' => '75015', 'ville' => 'Paris', 'pays' => 'FR']],
                'lignes' => [['designation' => 'Stage', 'quantite' => 1, 'prixUnitaireHT' => '10.00', 'tauxTva' => $this->idTauxTva('Taux normal 20 %')]],
            ],
        ])->toArray();

        $reponse = $client->request('POST', '/api/billing/documents/'.$this->identifiant($devis).'/derive', $entete);

        self::assertSame(422, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertStringContainsString('acceptée', (string) $reponse->getContent(false));
    }


    /**
     * La chaîne va jusqu'au bout : la commande acceptée produit une facture numérotée.
     *
     * C'est l'assertion qui relie FAC-1 au reste — sans elle, le lot livrerait trois documents qui ne
     * débouchent sur rien de comptable.
     */
    public function testUneCommandeAccepteeProduitUneFactureNumerotee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $id = $this->identifiant($this->creerDevis($client, $entete));
        $client->request('POST', "/api/billing/documents/{$id}/issue", $entete);
        $client->request('POST', "/api/billing/documents/{$id}/accept", $entete);
        $commande = $client->request('POST', "/api/billing/documents/{$id}/derive", $entete)->toArray();

        $idCommande = $this->identifiant($commande);
        $client->request('POST', "/api/billing/documents/{$idCommande}/issue", $entete);
        $client->request('POST', "/api/billing/documents/{$idCommande}/accept", $entete);

        $facturee = $client->request('POST', "/api/billing/documents/{$idCommande}/invoice", $entete)->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('converted', $facturee['statut']);
        self::assertNotNull($facturee['factureId'] ?? null, 'la pièce garde la trace de la facture produite');

        $this->em()->clear();
        $facture = $this->em()->getRepository(Facture::class)->find($facturee['factureId']);
        self::assertNotNull($facture);
        self::assertNotNull($facture->getNumero(), 'une facture émise porte un numéro de séquence');
        self::assertSame('360.00', $facture->getTotalHT());
    }

    /**
     * **Un droit de lecture ne suffit pas à agir.**
     *
     * Le lecteur voit les pièces de son établissement — c'est acquis par le test de collection. Ce
     * test dit l'autre moitié : voir n'est pas émettre. Sans lui, les cinq routes de geste pourraient
     * n'exiger que `facturation.lire` sans qu'aucun test ne s'en aperçoive, puisque l'administrateur
     * du socle porte `ROLE_ADMIN` et franchit tout.
     */
    public function testLeLecteurVoitMaisNeGerePas(): void
    {
        $devis = $this->devisSur(SocleFixtures::ETAB_A_NOM, 'Club de chez nous');

        [$client, $entete] = $this->lecteurSurA();
        $id = $devis->getId()->toRfc4122();

        $client->request('GET', "/api/billing/documents/{$id}", $entete);
        self::assertResponseIsSuccessful('le lecteur voit la pièce de son établissement');

        $reponse = $client->request('POST', "/api/billing/documents/{$id}/issue", $entete);
        self::assertSame(403, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /**
     * **Un corps de requête ne déplace pas une pièce chez le voisin.**
     *
     * Le garde-fou D41 signale que `CommercialDocument` n a aucun `denormalizationContext` : tout
     * mutateur est donc exposé par défaut. Les huit opérations portent `input: false`, ce qui doit
     * suffire — mais « ce qui doit suffire » est une hypothèse tant que personne ne l a tirée.
     */
    public function testUnCorpsDeRequeteNeDeplacePasLaPieceChezLeVoisin(): void
    {
        $devis = $this->devisSur(SocleFixtures::ETAB_A_NOM, 'Club de chez nous');
        $idA = $devis->getEtablissement()->getId()->toRfc4122();
        $id = $devis->getId()->toRfc4122();

        [$client, $entete] = $this->adminSurA();

        $client->request('POST', "/api/billing/documents/{$id}/issue", $entete + [
            'json' => [
                'etablissement' => '/api/etablissements/'.$this->idEtablissement(SocleFixtures::ETAB_B_NOM),
                'numero' => 'FAUX-001',
                'totalHT' => '999999.00',
            ],
        ]);

        $this->em()->clear();
        $relue = $this->em()->getRepository(CommercialDocument::class)->find($id);
        self::assertNotNull($relue);
        self::assertSame($idA, $relue->getEtablissement()->getId()->toRfc4122(), 'la piece est restee sur son etablissement');
        self::assertNull($relue->getNumero(), 'le numero ne se pose pas depuis le corps');
        self::assertSame('10.00', $relue->getTotalHT(), 'les totaux ne se posent pas depuis le corps');
    }

    // ---------------------------------------------------------------- montage

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function creerDevis(Client $client, array $entete): array
    {
        return $client->request('POST', '/api/billing/documents', $entete + [
            'json' => [
                'destinataire' => ['raisonSociale' => 'Club de Padel', 'siret' => '12345678900011', 'adresse' => ['rue' => '4 allee du Padel', 'cp' => '75015', 'ville' => 'Paris', 'pays' => 'FR']],
                'lignes' => [
                    ['designation' => 'Stage de padel', 'quantite' => 8, 'prixUnitaireHT' => '45.00', 'tauxTva' => $this->idTauxTva('Taux normal 20 %')],
                ],
            ],
        ])->toArray();
    }

    /**
     * Un utilisateur affecté sur A seulement, porteur du joker `*.lire`.
     *
     * @return array{0: Client, 1: array<string, mixed>}
     */
    private function lecteurSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];

        return [$client, $entete];
    }

    /**
     * Un taux réel, valide, actif — mais rattaché à un second exploitant.
     *
     * Le monter pour de bon plutôt que d'inventer un identifiant est ce qui donne son sens au test :
     * un UUID fantaisiste serait refusé par le simple `find()`, et le contrôle d'appartenance ne
     * serait jamais atteint.
     */
    private function tauxDunSecondExploitant(): string
    {
        $profil = $this->profilExploitant();

        $second = (new ProfilExploitant())
            ->setType($profil->getType())
            ->setReferentielComptable($profil->getReferentielComptable())
            ->setSiren('918273645')
            ->setEtablissementPrincipal($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]));
        $this->em()->persist($second);

        $taux = (new TauxTva())
            ->setTaux('7.00')
            ->setLibelle('Taux d ailleurs')
            ->setActif(true)
            ->setProfilExploitant($second);
        $this->em()->persist($taux);
        $this->em()->flush();

        return (string) $taux->getId();
    }

    /** @param array<string, mixed> $ressource */
    private function identifiant(array $ressource): string
    {
        $id = $ressource['id'] ?? '';

        // API Platform rend parfois l'IRI plutôt que l'identifiant nu.
        return str_contains((string) $id, '/') ? basename((string) $id) : (string) $id;
    }

    private function devisSur(string $nomEtablissement, string $client = 'Club du voisin'): CommercialDocument
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        \assert($etablissement instanceof Etablissement);

        $destinataire = (new DestinataireFacturation())
            ->setType(TypeDestinataire::PersonneMorale)
            ->setRaisonSociale($client);
        $this->em()->persist($destinataire);

        $auteur = $this->em()->getRepository(Utilisateur::class)->find($this->idAdmin());
        \assert($auteur instanceof Utilisateur);

        $devis = (new CommercialDocument())
            ->setNature(DocumentNature::Quote)
            ->setEtablissement($etablissement)
            ->setProfilExploitant($this->profilExploitant())
            ->setDestinataire($destinataire)
            ->setCreePar($auteur);

        $devis->addLigne(
            (new DocumentLine())
                ->setDesignation('Stage')
                ->setQuantite(1)
                ->setPrixUnitaireHT('10.00'),
        );
        $devis->recalculerTotaux();

        $this->em()->persist($devis);
        $this->em()->flush();

        return $devis;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
