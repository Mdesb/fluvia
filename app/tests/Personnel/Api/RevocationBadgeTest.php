<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Acces\Enum\StatutSupport;
use App\Personnel\DataFixtures\PersonnelFixtures;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Enum\StatutBadgeStaff;
use App\Tests\Personnel\PersonnelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Révocation d'accès (RG-PERSO-08, CA-10/CA-11, §4.8 spec) : réutilise `BlocageSupportHandler` tel
 * quel pour les 4 déclencheurs (fin de contrat, suspension, perte/vol, révocation manuelle).
 */
final class RevocationBadgeTest extends PersonnelApiTestCase
{
    public function testDateSortieRevoqueBadgeImmediatementEtPropage(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Sortant', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $this->rattacher($clientRh, $enteteRh, $idEmploye, $this->idEtablissementA());

        $badge = $clientRh->request('POST', '/api/personnel/employes/' . $idEmploye . '/badges', $enteteRh + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'modeHoraire' => 'permanent',
                'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
            ],
        ])->toArray();

        // Date de sortie déjà passée (saisie rétroactive, §4.8 spec).
        $clientRh->request('PATCH', '/api/employes/' . $idEmploye, $this->entetePatch($enteteRh) + [
            'json' => ['dateSortie' => '2020-01-01'],
        ]);
        self::assertResponseIsSuccessful();

        $this->executerCommandeEcheances();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $badgeApres = $em->getRepository(BadgeStaff::class)->find($badge['id']);
        self::assertSame(StatutBadgeStaff::Revoque, $badgeApres->getStatut());
        self::assertNotNull($badgeApres->getDateRevocation());
        self::assertSame(StatutSupport::Bloque, $badgeApres->getSupport()->getStatut(), 'CA-10 : le support est bloqué (propagation liste de révocation, RG-ACC-07).');
    }

    public function testDeclarationPerteVolBloqueImmediatementEtHorsLigne(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Perte', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $this->rattacher($clientRh, $enteteRh, $idEmploye, $this->idEtablissementA());

        $badge = $clientRh->request('POST', '/api/personnel/employes/' . $idEmploye . '/badges', $enteteRh + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'modeHoraire' => 'permanent',
                'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
            ],
        ])->toArray();

        [$clientAccueil, $enteteAccueil] = $this->accueilSurA();
        $clientAccueil->request('POST', '/api/personnel/badges/' . $badge['id'] . '/declarer-incident', $enteteAccueil + [
            'json' => ['motif' => 'Badge perdu par l\'employé'],
        ]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $badgeApres = $em->getRepository(BadgeStaff::class)->find($badge['id']);
        self::assertSame(StatutSupport::Bloque, $badgeApres->getSupport()->getStatut(), 'Blocage serveur immédiat (RG-ACC-07).');
        self::assertSame(StatutBadgeStaff::Suspendu, $badgeApres->getStatut());

        // Réversible par un rôle habilité (RH, personnel.gerer_badge).
        $declaration = $em->getRepository(\App\Acces\Entity\DeclarationPerteVol::class)->findOneBy(['support' => $badgeApres->getSupport()]);
        self::assertNotNull($declaration);

        $clientRh->request('POST', '/api/personnel/declarations-incident/' . $declaration->getId() . '/annuler', $enteteRh);
        self::assertResponseIsSuccessful();

        $em->clear();
        $badgeReactive = $em->getRepository(BadgeStaff::class)->find($badge['id']);
        self::assertSame(StatutBadgeStaff::Actif, $badgeReactive->getStatut());
        self::assertSame(StatutSupport::Actif, $badgeReactive->getSupport()->getStatut());
    }

    /**
     * **LA RÉVOCATION, ET SON IRRÉVERSIBILITÉ.**
     *
     * Ce fichier porte son nom et ne la parcourait pas : il exerçait l'incident, la suspension et la
     * réactivation. Or c'est la seule des quatre dont l'échec laisse **une porte ouverte** — un
     * badge révoqué qui continue d'ouvrir ne se signale nulle part, sinon par quelqu'un qui entre.
     *
     * Deux affirmations, et la seconde est celle qui compte :
     *
     *   1. révoquer marque le badge `revoque` et bloque son support physique ;
     *   2. **une révocation ne se rattrape pas.** Réactiver un badge révoqué est refusé en 409
     *        (§4.8 de la spec) : il faut un nouveau badge, donc un nouvel appairage. Si ce refus
     *        cédait, la révocation ne serait plus qu'une suspension déguisée.
     */
    public function testRevocationDefinitiveEtIrreversible(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Revoque', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $this->rattacher($clientRh, $enteteRh, $idEmploye, $this->idEtablissementA());

        $badge = $clientRh->request('POST', '/api/personnel/employes/' . $idEmploye . '/badges', $enteteRh + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'modeHoraire' => 'permanent',
                'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
            ],
        ])->toArray();

        $clientRh->request('POST', '/api/personnel/badges/' . $badge['id'] . '/revoquer', $enteteRh + [
            'json' => ['motif' => 'Départ immédiat'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('revoque', $clientRh->getResponse()->toArray()['statut']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $revoque = $em->getRepository(BadgeStaff::class)->find($badge['id']);
        self::assertSame(StatutBadgeStaff::Revoque, $revoque->getStatut());
        self::assertNotNull($revoque->getDateRevocation(), 'Une révocation sans date ne se prouve pas.');

        // LE REFUS QUI FAIT TOUT. Sans lui, révoquer et suspendre seraient le même geste.
        $reponse = $clientRh->request('POST', '/api/personnel/badges/' . $badge['id'] . '/reactiver', $enteteRh);
        self::assertSame(
            409,
            $reponse->getStatusCode(),
            'Une révocation est définitive : il faut un nouveau badge, donc un nouvel appairage.',
        );
    }

    public function testSuspensionReversibleSansReAppairage(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Suspendu', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $this->rattacher($clientRh, $enteteRh, $idEmploye, $this->idEtablissementA());

        $badge = $clientRh->request('POST', '/api/personnel/employes/' . $idEmploye . '/badges', $enteteRh + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'modeHoraire' => 'permanent',
                'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
            ],
        ])->toArray();

        /** @var EntityManagerInterface $emInitial */
        $emInitial = static::getContainer()->get('doctrine')->getManager();
        $idSupportInitial = (string) $emInitial->getRepository(BadgeStaff::class)->find($badge['id'])->getSupport()->getId();

        $clientRh->request('POST', '/api/personnel/badges/' . $badge['id'] . '/suspendre', $enteteRh + [
            'json' => ['motif' => 'Suspension temporaire'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('suspendu', $clientRh->getResponse()->toArray()['statut']);

        $clientRh->request('POST', '/api/personnel/badges/' . $badge['id'] . '/reactiver', $enteteRh);
        self::assertResponseIsSuccessful();
        $reactive = $clientRh->getResponse()->toArray();
        self::assertSame('actif', $reactive['statut']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $badgeFinal = $em->getRepository(BadgeStaff::class)->find($badge['id']);
        self::assertSame(StatutBadgeStaff::Actif, $badgeFinal->getStatut());
        // Le MÊME Support est réutilisé — aucun nouvel appairage (décision n°7 du plan).
        self::assertSame($idSupportInitial, (string) $badgeFinal->getSupport()->getId());
    }

    /**
     * RG-PERSO-09 : un badge ne peut être émis que pour un employé ayant un rattachement actif sur
     * l'établissement ciblé (correctif cloisonnement, cf. rapport de revue).
     *
     * @param array<string, mixed> $entete
     */
    private function rattacher(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $idEmploye, string $idEtablissement): void
    {
        $client->request('POST', '/api/rattachement_employes', $entete + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'etablissement' => '/api/etablissements/' . $idEtablissement,
                'debut' => '2024-01-01',
            ],
        ]);
        self::assertResponseIsSuccessful();
    }

    private function executerCommandeEcheances(): void
    {
        $container = static::getContainer();
        $command = $container->get(\App\Personnel\Command\TraiterEcheancesSortieCommand::class);
        $tester = new CommandTester($command);
        $tester->execute(['agentEmail' => PersonnelFixtures::EMAIL_RH]);
        self::assertSame(0, $tester->getStatusCode());
    }

    /** @param array<string, mixed> $entete */
    private function entetePatch(array $entete): array
    {
        $entete['headers']['Content-Type'] = 'application/merge-patch+json';

        return $entete;
    }
}
