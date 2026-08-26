<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Support\DataFixtures\SupportFixtures;
use App\Tests\Support\SupportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Non-régression du cloisonnement (D3/D8) des actions de ticket qui résolvent une entité depuis le
 * corps par un `find()` direct, hors des extensions Doctrine : `reaffecter`/`escalader` (agent) et
 * `lier-article` (article d'aide). Le ticket ($data) est confronté par `read: true`, mais l'entité du
 * corps ne l'était pas — on pouvait rattacher au ticket un agent ou un article d'un autre
 * établissement. Attendu : 404 (anti-oracle).
 */
final class TicketCloisonnementTest extends SupportApiTestCase
{
    public function testReaffecterVersUnAgentDunAutreEtablissementRenvoie404(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_AGENT_N2, SupportFixtures::ETAB_A_NOM);
        $ticket = $this->ouvrirTicketSurA();
        $agentB = $this->creerUtilisateurAffecteAB();

        $reponse = $client->request('POST', '/api/support/tickets/' . $ticket['id'] . '/reaffecter', $entete + [
            'json' => ['affecteA' => '/api/utilisateurs/' . $agentB],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    public function testEscaladerVersUnAgentDunAutreEtablissementRenvoie404(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_AGENT_N1, SupportFixtures::ETAB_A_NOM);
        $ticket = $this->ouvrirTicketSurA();
        $client->request('POST', '/api/support/tickets/' . $ticket['id'] . '/prendre-en-charge', $entete);
        $agentB = $this->creerUtilisateurAffecteAB();

        $reponse = $client->request('POST', '/api/support/tickets/' . $ticket['id'] . '/escalader', $entete + [
            'json' => ['affecteA' => '/api/utilisateurs/' . $agentB],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    public function testLierUnArticleLocalDunAutreEtablissementRenvoie404(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_AGENT_N1, SupportFixtures::ETAB_A_NOM);
        $ticket = $this->ouvrirTicketSurA();
        $client->request('POST', '/api/support/tickets/' . $ticket['id'] . '/prendre-en-charge', $entete);
        $articleLocalB = $this->creerArticleLocalSurB();

        $reponse = $client->request('POST', '/api/support/tickets/' . $ticket['id'] . '/lier-article', $entete + [
            'json' => ['articleId' => '/api/article_aides/' . $articleLocalB],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /** @return array<string, mixed> le ticket créé sur l'établissement A par un exploitant A. */
    private function ouvrirTicketSurA(): array
    {
        [$clientDemandeur, $enteteDemandeur] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_A, SupportFixtures::ETAB_A_NOM);

        return $clientDemandeur->request('POST', '/api/support/tickets', $enteteDemandeur + [
            'json' => [
                'sujet' => 'Cloisonnement — ticket A',
                'description' => 'Ticket de test cloisonnement.',
                'priorite' => 'normale',
                'moduleConcerne' => 'vente',
            ],
        ])->toArray();
    }

    /** @return string l'UUID d'un utilisateur affecté UNIQUEMENT à l'établissement B. */
    private function creerUtilisateurAffecteAB(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SupportFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => 'Support Agent N1']);
        self::assertInstanceOf(Role::class, $role);

        $user = (new Utilisateur())->setEmail('agent-b-' . uniqid() . '@itcotation.com')->setNom('Agent B')->setActif(true);
        $user->setMotDePasse($hasher->hashPassword($user, 'aaa'));
        $em->persist($user);
        $em->persist((new Affectation())->setUtilisateur($user)->setRole($role)->setEtablissement($etabB));
        $em->flush();

        return (string) $user->getId();
    }

    /** @return string l'UUID d'un article d'aide LOCAL à l'établissement B, créé via l'API. */
    private function creerArticleLocalSurB(): string
    {
        // Catégorie (globale) par le rédacteur global — seul à porter `gerer_categorie`.
        [$clientGlobal, $enteteGlobal] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);
        $categorie = $clientGlobal->request('POST', '/api/categorie_aides', $enteteGlobal + [
            'json' => ['nom' => 'Cloisonnement ' . uniqid()],
        ])->toArray();

        // Article LOCAL de B, créé par le rédacteur local de B connecté sur B (RG-SUP-04 : l'établissement
        // de l'article local doit égaler l'établissement actif).
        [$clientLocalB, $enteteLocalB] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_LOCAL_B, SupportFixtures::ETAB_B_NOM);
        $article = $clientLocalB->request('POST', '/api/article_aides', $enteteLocalB + [
            'json' => [
                'titre' => 'Article local B (cloisonnement)',
                'categorie' => '/api/categorie_aides/' . $categorie['id'],
                'contenu' => 'Contenu local B.',
                'portee' => 'local',
                'publicCible' => 'agent',
                'etablissement' => '/api/etablissements/' . $this->idEtablissement(SupportFixtures::ETAB_B_NOM),
            ],
        ])->toArray();
        self::assertSame(
            $this->idEtablissement(SupportFixtures::ETAB_B_NOM),
            basename((string) ($article['etablissement'] ?? '')),
            "L'article de test doit être local à l'établissement B.",
        );

        return $article['id'];
    }
}
