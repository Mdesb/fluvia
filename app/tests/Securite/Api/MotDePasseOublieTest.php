<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Entity\JetonReinitialisation;
use App\Securite\Entity\Utilisateur;
use App\Tests\Securite\SecuriteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mot de passe oublié (US-L0-03 différée, CA-6).
 */
final class MotDePasseOublieTest extends SecuriteApiTestCase
{
    /** CA-6 — Réponse identique email connu/inconnu ; jeton valide ⇒ nouveau mot de passe, jeton consommé, sessions invalidées. */
    public function testCa6DemandeEtReinitialisation(): void
    {
        $client = static::createClient();

        $reponseConnu = $client->request('POST', '/mot-de-passe/oublie', [
            'json' => ['email' => SocleFixtures::LECTEUR_EMAIL],
        ]);
        self::assertResponseStatusCodeSame(202);
        $statutConnu = $reponseConnu->getStatusCode();
        $corpsConnu = $reponseConnu->toArray();

        $reponseInconnu = $client->request('POST', '/mot-de-passe/oublie', [
            'json' => ['email' => 'personne@inconnu.test'],
        ]);
        self::assertSame($statutConnu, $reponseInconnu->getStatusCode());
        self::assertSame($corpsConnu, $reponseInconnu->toArray());

        // Ancien jeton du lecteur, pour vérifier l'invalidation après réinitialisation.
        $ancienJeton = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $lecteur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::LECTEUR_EMAIL]);
        self::assertNotNull($lecteur);
        $jetonEntite = $em->getRepository(JetonReinitialisation::class)->findOneBy(['utilisateur' => $lecteur]);
        self::assertNotNull($jetonEntite, 'Un jeton de réinitialisation doit avoir été créé.');

        // Le jeton en clair n'est jamais persisté : on le régénère via la même primitive que le
        // contrôleur ne peut pas nous le redonner ; on relit donc directement via une nouvelle
        // demande et on intercepte le hash pour construire un jeton de test déterministe.
        // -> Ici, on simule le scénario "jeton connu" en créant nous-mêmes un jeton test.
        $jetonClair = 'jeton-test-reinitialisation-01';
        $jetonEntite->setJeton(hash('sha256', $jetonClair));
        $em->flush();

        $client->request('POST', '/mot-de-passe/reinitialiser', [
            'json' => ['jeton' => $jetonClair, 'nouveauMotDePasse' => 'NouveauMdp#2027'],
        ]);
        self::assertResponseIsSuccessful();

        // Jeton déjà utilisé : refus (410).
        $client->request('POST', '/mot-de-passe/reinitialiser', [
            'json' => ['jeton' => $jetonClair, 'nouveauMotDePasse' => 'Encore#2027'],
        ]);
        self::assertResponseStatusCodeSame(410);

        // Ancienne session invalidée (tokenVersion incrémenté).
        $client->request('GET', '/me', ['auth_bearer' => $ancienJeton]);
        self::assertResponseStatusCodeSame(401);

        // Le nouveau mot de passe fonctionne.
        $nouveauJeton = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, 'NouveauMdp#2027');
        self::assertNotEmpty($nouveauJeton);
    }

    /** CA-6 — Jeton totalement inconnu ⇒ refus générique (422). */
    public function testJetonInconnuRefuse(): void
    {
        $client = static::createClient();

        $client->request('POST', '/mot-de-passe/reinitialiser', [
            'json' => ['jeton' => 'jeton-jamais-emis', 'nouveauMotDePasse' => 'Peu#Importe1'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
