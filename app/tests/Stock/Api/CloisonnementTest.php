<?php

declare(strict_types=1);

namespace App\Tests\Stock\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Securite\Entity\Utilisateur;
use App\Tests\Stock\StockApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cloisonnement multi-entités `App\Stock` (RG-SOCLE-05, RG-STOCK-13) : un utilisateur affecté
 * uniquement sur l'établissement A ne voit que les `ArticleStock` de A ; un `TransfertStock` liant A
 * et B reste visible dès lors que l'utilisateur est affecté sur l'un des deux ; un utilisateur sans
 * aucune affectation n'a accès à rien.
 */
final class CloisonnementTest extends StockApiTestCase
{
    public function testArticleStockFiltrePerEtablissement(): void
    {
        [$clientAdmin, $enteteAdminA] = $this->adminSurA();
        $etabAIri = '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $etabBIri = '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $clientAdmin->request('POST', '/api/article_stocks', $enteteAdminA + [
            'json' => ['codeEAN' => '5901234123457', 'libelle' => 'Article A', 'unite' => 'piece', 'prixAchatHT' => '1.0000', 'tauxTvaAchat' => '20.00', 'seuilMin' => '0.000', 'seuilMax' => '0.000'],
        ]);
        self::assertResponseIsSuccessful();
        // D41 : on se place chez B au lieu de le nommer dans le corps — l'administrateur du groupe y
        // est affecte, c'est la facon legitime de faire ce que ce test faisait deja.
        $clientAdmin->request('POST', '/api/article_stocks', $this->enteteSur($enteteAdminA, SocleFixtures::ETAB_B_NOM) + [
            'json' => ['codeEAN' => '40170725', 'libelle' => 'Article B', 'unite' => 'piece', 'prixAchatHT' => '1.0000', 'tauxTvaAchat' => '20.00', 'seuilMin' => '0.000', 'seuilMax' => '0.000'],
        ]);
        self::assertResponseIsSuccessful();

        // Lecteur : affecté uniquement sur l'établissement A (SocleFixtures) → ne voit que « Article A ».
        $clientLecteur = static::createClient();
        $token = $this->jeton($clientLecteur, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $enteteLecteur = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]];

        $collection = $clientLecteur->request('GET', '/api/article_stocks', $enteteLecteur)->toArray();
        $libelles = array_map(static fn (array $a): string => $a['libelle'], $collection['member'] ?? $collection['hydra:member'] ?? []);
        self::assertContains('Article A', $libelles);
        self::assertNotContains('Article B', $libelles, 'RG-STOCK-13 : un article de B ne doit jamais apparaître pour un utilisateur affecté uniquement sur A.');
    }

    public function testTransfertVisibleParAffectationSurUnSeulCote(): void
    {
        [$clientAdmin, $enteteAdmin] = $this->adminSurA();
        $etabAIri = '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $etabBIri = '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $articleA = $clientAdmin->request('POST', '/api/article_stocks', $enteteAdmin + [
            'json' => ['codeEAN' => '5901234123457', 'libelle' => 'Article A', 'unite' => 'piece', 'prixAchatHT' => '1.0000', 'tauxTvaAchat' => '20.00', 'seuilMin' => '0.000', 'seuilMax' => '0.000'],
        ])->toArray();
        // D41 : on se place chez B plutot que de le nommer dans le corps.
        $articleB = $clientAdmin->request('POST', '/api/article_stocks', $this->enteteSur($enteteAdmin, SocleFixtures::ETAB_B_NOM) + [
            'json' => ['codeEAN' => '40170725', 'libelle' => 'Article B', 'unite' => 'piece', 'prixAchatHT' => '1.0000', 'tauxTvaAchat' => '20.00', 'seuilMin' => '0.000', 'seuilMax' => '0.000'],
        ])->toArray();

        $transfert = $clientAdmin->request('POST', '/api/stock_transferts', $enteteAdmin + [
            'json' => [
                'articleStockSource' => '/api/article_stocks/' . $articleA['id'],
                'articleStockDestination' => '/api/article_stocks/' . $articleB['id'],
                'quantite' => '1.000',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        // Lecteur affecté uniquement sur A : voit le transfert (OR sur source/destination, RG-STOCK-13).
        $clientLecteur = static::createClient();
        $token = $this->jeton($clientLecteur, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $enteteLecteur = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]];

        $vu = $clientLecteur->request('GET', '/api/stock_transferts/' . $transfert['id'], $enteteLecteur);
        self::assertResponseIsSuccessful();

        // Un utilisateur sans aucune affectation ne voit rien (RG-SOCLE-05).
        $isole = $this->creerUtilisateurSansAffectation();
        $clientIsole = static::createClient();
        $tokenIsole = $this->jeton($clientIsole, $isole['email'], $isole['motDePasse']);
        $enteteIsole = ['auth_bearer' => $tokenIsole, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]];

        // Sans affectation, l'utilisateur n'a même pas le droit `stock.lire` (RG-SOCLE-04) : 403.
        $clientIsole->request('GET', '/api/stock_transferts/' . $transfert['id'], $enteteIsole);
        self::assertResponseStatusCodeSame(404, 'Aucune affectation : aucun droit stock.lire, accès refusé (RG-SOCLE-04/05).');
    }

    /** @return array{email: string, motDePasse: string} */
    private function creerUtilisateurSansAffectation(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $email = 'isole.stock@itcotation.com';
        $motDePasse = 'IsoleStock#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Isolé Stock')->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);
        $em->flush();

        return ['email' => $email, 'motDePasse' => $motDePasse];
    }
}
