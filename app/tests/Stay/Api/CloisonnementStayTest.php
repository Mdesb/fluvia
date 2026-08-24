<?php

declare(strict_types=1);

namespace App\Tests\Stay\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Stay\DataFixtures\StayFixtures;
use App\Stay\Entity\Stay;
use App\Tests\Stay\StayApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cloisonnement de `App\Stay` (RG-SOCLE-05, D3/D8) — le test promis à l'intégrateur.
 *
 * Un séjour porte le nom d'un client, ses dates, et tout ce qu'il consomme. Le voir depuis un autre
 * établissement n'est pas une fuite technique, c'est une fuite de vie privée.
 *
 * **La réponse attendue est 404, jamais 403.** Une référence de séjour est courte et lisible ; un 403
 * confirmerait son existence et suffirait à énumérer la clientèle d'un établissement voisin.
 *
 * **Pourquoi un utilisateur dédié et non l'administrateur socle** — la première version de ce test
 * utilisait l'admin, et les quatre assertions ont échoué. Ce n'était pas une faille : l'administrateur
 * du groupe est **affecté aux deux** établissements, il voit donc légitimement les deux (RG-SOCLE-05
 * cloisonne par affectation, pas par l'en-tête `X-Etablissement`, qui n'est qu'un sélecteur envoyé par
 * le client). Un test de cloisonnement écrit avec un utilisateur multi-établissements ne prouve rien
 * — au mieux il échoue à tort, au pire il passe au vert pour une raison qui n'a rien à voir. D'où le
 * `receptionnisteDeA()` ci-dessous, affecté au seul établissement A, comme le fait
 * `App\Tests\Ocr\Api\CloisonnementOcrTest`.
 */
final class CloisonnementStayTest extends StayApiTestCase
{
    public function testLeSejourDeBEstInvisibleParSonIdentifiantDepuisA(): void
    {
        $sejourB = $this->entite(Stay::class, ['reference' => StayFixtures::REFERENCE_B]);
        [$client, $entete] = $this->receptionnisteDeA();

        $client->request('GET', '/api/stays/' . $sejourB->getId(), $entete);

        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));
    }

    public function testLaCollectionVueDepuisANeContientPasLeSejourDeB(): void
    {
        $sejourB = $this->entite(Stay::class, ['reference' => StayFixtures::REFERENCE_B]);
        [$client, $entete] = $this->receptionnisteDeA();

        $client->request('GET', '/api/stays', $entete);

        self::assertResponseIsSuccessful();
        $corps = (string) $client->getResponse()->getContent();

        // Les deux assertions comptent. La seconde seule passerait au vert sur une collection vide,
        // c'est-à-dire sur un filtre trop large qui casserait le module sans qu'on le voie.
        self::assertStringContainsString(StayFixtures::REFERENCE_A, $corps, 'Le séjour de A doit rester visible depuis A.');
        self::assertStringNotContainsString((string) $sejourB->getId(), $corps, 'Le séjour de B ne doit pas fuiter dans la collection de A.');
    }

    public function testOnNePeutPasPorterUneLigneAuSejourDunAutreEtablissement(): void
    {
        $sejourB = $this->entite(Stay::class, ['reference' => StayFixtures::REFERENCE_B]);
        [$client, $entete] = $this->receptionnisteDeA();

        // Le cas qui coûte le plus cher : facturer le client d'un autre établissement.
        $client->request('POST', '/api/stays/' . $sejourB->getId() . '/charges', $entete + [
            'json' => ['label' => 'Tentative hors perimetre', 'amount' => '99.00'],
        ]);

        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));
    }

    public function testOnNePeutPasCloreLeSejourDunAutreEtablissement(): void
    {
        $sejourB = $this->entite(Stay::class, ['reference' => StayFixtures::REFERENCE_B]);
        [$client, $entete] = $this->receptionnisteDeA();

        $client->request('POST', '/api/stays/' . $sejourB->getId() . '/close', $entete + ['json' => []]);

        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));
        self::assertNull(
            $this->entite(Stay::class, ['reference' => StayFixtures::REFERENCE_B])->getClosedAt(),
            'Le séjour de B ne doit pas avoir été clos par un refus mal placé.',
        );
    }

    public function testLeReceptionnisteDeAVoitBienLeSejourDeA(): void
    {
        // **Le témoin.** Sans lui, un filtre qui refuserait TOUT passerait les quatre tests
        // précédents au vert tout en rendant le module inutilisable.
        $sejourA = $this->entite(Stay::class, ['reference' => StayFixtures::REFERENCE_A]);
        [$client, $entete] = $this->receptionnisteDeA();

        $client->request('GET', '/api/stays/' . $sejourA->getId(), $entete);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(StayFixtures::REFERENCE_A, (string) $client->getResponse()->getContent());
    }

    /**
     * Un réceptionniste doté de toutes les permissions `stay.*`, affecté au **seul** établissement A.
     *
     * @return array{0: Client, 1: array<string, mixed>}
     */
    private function receptionnisteDeA(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etabA);

        $role = (new Role())->setNom('Receptionniste (scope A, test cloisonnement sejour)');
        foreach (['read', 'write', 'charge', 'settle'] as $action) {
            $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'stay', 'action' => $action]);
            self::assertNotNull($permission, sprintf('Permission stay.%s absente des fixtures.', $action));
            $role->addPermission($permission);
        }
        $em->persist($role);

        $email = 'receptionniste.stay.scope-a@itcotation.com';
        $motDePasse = 'ReceptionnisteStay#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Receptionniste Scope A')->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);

        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etabA));
        $em->flush();

        $client = static::createClient();
        $token = $this->jeton($client, $email, $motDePasse);

        return [$client, [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => (string) $etabA->getId()],
        ]];
    }
}
