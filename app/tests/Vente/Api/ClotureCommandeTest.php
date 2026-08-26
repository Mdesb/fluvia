<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Command\CloseBusinessDayCommand;
use App\Vente\Nf525\Entity\DailyClosure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `vente:cloture:journee` — la commande sans laquelle la clôture reste un geste qu'on oublie.
 *
 * **Pourquoi ce test existe alors que le handler est déjà couvert.** La commande n'est qu'une boucle,
 * et c'est exactement le raisonnement qui laisse une commande **inerte pendant des mois** :
 * `claude-D` vient de le vivre sur `sepa:preavis:annoncer`, où le mécanisme existait entièrement et où
 * personne ne l'appelait — cent pour cent des échéances auraient été écartées, indéfiniment, sans que
 * rien ne casse. Le motif du dépôt à son sommet : le mécanisme existe, l'appel manque.
 *
 * Ici l'enjeu est le même en pire : une clôture qui ne tourne pas ne se découvre **qu'au contrôle**.
 */
final class ClotureCommandeTest extends VenteApiTestCase
{
    private ?string $session = null;

    /** Le chemin nominal : la veille est arrêtée, et l'arrêté existe en base. */
    public function testElleArreteLaVeille(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->venteDatee($client, $entete, 'yesterday 10:00');

        $testeur = $this->lancer([]);

        self::assertSame(0, $testeur->getStatusCode());
        self::assertStringContainsString('journée(s) arrêtée(s)', $testeur->getDisplay());
        self::assertNotNull($this->clotureDu('yesterday'), 'La journée doit être arrêtée en base, pas seulement annoncée.');
    }

    /**
     * **`--dry-run` ne scelle rien.** Sur un geste irréversible, montrer avant de faire n'est pas un
     * confort : un exploitant qui découvre trois semaines d'arriéré doit pouvoir voir ce qui partirait.
     */
    public function testLaSimulationNeScelleRien(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->venteDatee($client, $entete, 'yesterday 10:00');

        $testeur = $this->lancer(['--dry-run' => true]);

        self::assertSame(0, $testeur->getStatusCode());
        self::assertStringContainsString('Rien n\'a été scellé', $testeur->getDisplay());
        self::assertNull($this->clotureDu('yesterday'), 'Une simulation qui écrit n\'est pas une simulation.');
    }

    /**
     * **Ce qui échoue ne disparaît pas dans un journal.**
     *
     * Une journée plus ancienne reste ouverte : le handler refuse de sauter, la commande le dit, et
     * elle rend un code d'échec — l'ordonnanceur le voit, et la journée reste dans la file. Une clôture
     * manquée *en silence* serait le défaut que cette fonctionnalité existe pour empêcher, reproduit un
     * cran plus haut : on échangerait un oubli visible contre un oubli invisible.
     */
    public function testUneJourneeQuiNePeutPasEtreArreteeEstNommeeEtLaCommandeEchoue(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->venteDatee($client, $entete, '-4 days 10:00');
        $this->venteDatee($client, $entete, 'yesterday 10:00');

        $testeur = $this->lancer([]);

        self::assertSame(1, $testeur->getStatusCode(), 'Un échec doit se voir de l\'ordonnanceur.');
        $sortie = $testeur->getDisplay();
        self::assertStringContainsString('NON close', $sortie);
        self::assertStringContainsString((new \DateTimeImmutable('-4 days'))->format('Y-m-d'), $sortie, 'Le refus nomme la journée à traiter.');
        self::assertNull($this->clotureDu('yesterday'), 'Et surtout : rien n\'est scellé de travers.');
    }

    /**
     * **Le test qu'on n'ecrit jamais parce qu'on croit le connaitre** (claude-A) : le planificateur
     * REFUSE de lancer cette commande toute seule a son premier passage.
     *
     * Une cloture SCELLE. Un premier passage sur un arriere de trois semaines produirait vingt et un
     * arretes d'un coup, irreversibles. `safeOnFirstRun: false` n'est donc pas une precaution de
     * confort, et ce test verifie le COMPORTEMENT du planificateur, pas seulement la declaration —
     * une constante juste dans un catalogue que personne ne lit ne protege rien.
     */
    public function testLePlanificateurRefuseDeLaLancerSeuleAuPremierPassage(): void
    {
        $application = new Application(static::$kernel);
        $planificateur = $application->find('platform:scheduler:run');

        $testeur = new CommandTester($planificateur);
        $testeur->execute(['--dry-run' => true]);
        $sortie = $testeur->getDisplay();

        self::assertStringContainsString('premier passage', $sortie);
        self::assertStringContainsString('vente:cloture:journee', $sortie);
        self::assertStringContainsString(
            '--only=vente:cloture:journee',
            $sortie,
            'Le refus doit donner la commande qui la lance sous supervision, sinon il est un mur.',
        );
    }

    /** @param array<string, mixed> $options */
    private function lancer(array $options): CommandTester
    {
        /** @var CloseBusinessDayCommand $commande */
        $commande = static::getContainer()->get(CloseBusinessDayCommand::class);
        $testeur = new CommandTester($commande);
        $testeur->execute($options);

        return $testeur;
    }

    private function clotureDu(string $quand): ?DailyClosure
    {
        $this->em()->clear();

        return $this->em()->getRepository(DailyClosure::class)->findOneBy([
            'businessDay' => (new \DateTimeImmutable($quand))->setTime(0, 0),
        ]);
    }

    /** @param array<string, mixed> $entete */
    private function venteDatee(object $client, array $entete, string $quand): void
    {
        $session = $this->sessionOuverte($client, $entete);
        $vente = $this->creerVente($client, $entete, $session);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'cb']]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        // Antidatée en SQL : `InalterabiliteListener` fige la date d'une vente scellée, et il a raison.
        // Ce n'est pas un contournement du garde-fou, c'est la mise en place du décor.
        $em = $this->em();
        $em->getConnection()->executeStatement(
            'UPDATE vente_vente SET date = ? WHERE id = UNHEX(REPLACE(?, "-", ""))',
            [(new \DateTimeImmutable($quand))->format('Y-m-d H:i:s'), $vente['id']],
        );
        $em->clear();
    }

    /**
     * La session du test courant, ouverte une seule fois.
     *
     * **Propriete d'instance et non `static`** : le harnais recree le schema a chaque methode de test,
     * donc une session memorisee entre deux methodes designe une ligne qui n'existe plus — « Session
     * introuvable », a l'endroit ou l'on croyait economiser une requete. Et il en faut une seule par
     * methode : `uniq_session_active_pdv` interdit deux sessions actives sur le meme point de vente.
     *
     * @param array<string, mixed> $entete
     */
    private function sessionOuverte(object $client, array $entete): string
    {
        if ($this->session === null) {
            $this->session = (string) $this->ouvrirSession($client, $entete)['id'];
        }

        return $this->session;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
