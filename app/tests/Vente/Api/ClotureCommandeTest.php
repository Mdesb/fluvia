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
        self::assertStringContainsString($this->jourComptable('-4 days'), $sortie, 'Le refus nomme la journée à traiter.');
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

        // ⚠ `--only` SANS `--dry-run` : ON EXERCE LE REFUS, PAS LA SIMULATION.
        //
        // Ce test portait `--dry-run` seul jusqu'au 06/09, et exigeait que la sortie conseille
        // `--dry-run`. C'etait circulaire : le passage a blanc etait lui-meme retenu par le verrou,
        // donc le conseil renvoyait a ce qui ne marchait pas. Depuis, `--dry-run` traverse le verrou
        // et MONTRE — c'est l'inspection, plus un refus.
        //
        // L'exigence de ce test reste entiere, et elle porte sur le vrai refus : celui qu'on obtient
        // en lancant la tache pour de bon. `--only` le borne a la cloture, donc rien d'autre ne
        // tourne ; et si le verrou lachait, c'est cette invocation-la qui scellerait.
        $testeur = new CommandTester($planificateur);
        $testeur->execute(['--only' => 'vente:cloture:journee']);
        $sortie = $testeur->getDisplay();

        self::assertStringContainsString('premier passage', $sortie);
        self::assertStringContainsString('vente:cloture:journee', $sortie);

        // ⚠ CE TEST EXIGEAIT `--only=vente:cloture:journee` JUSQU'AU 01/09/2026, et son message
        // disait pourquoi : « sinon il est un mur ». L'exigence est juste — un refus qui ne donne
        // aucune suite EST un mur — mais D53 a tranche depuis : la suite offerte ne doit pas etre
        // le contournement lui-meme. Et ici la porte nommee etait precisement celle qui s'ouvrait
        // toute seule a chaque cycle de l'ordonnanceur (D109).
        //
        // On garde donc l'exigence d'actionnabilite, portee sur ce que D53 autorise : l'INSPECTION.
        self::assertStringContainsString(
            '--dry-run',
            $sortie,
            "Le refus doit dire ce qu'on peut regarder, sinon il est un mur.",
        );
        self::assertStringContainsString(
            '--status',
            $sortie,
            'Le refus doit dire ou lire ce qui a deja tourne.',
        );
        self::assertStringContainsString(
            'D109',
            $sortie,
            'Le refus doit renvoyer a la decision qui decrit la levee, faute de la nommer lui-meme.',
        );

        // ── LE TEMOIN NEGATIF, ET C'EST LUI QUI FAIT DE CE TEST UNE GARDE DE D53 ────────────────
        //
        // Sans ces deux lignes, ce test redeviendrait vert le jour ou quelqu'un remettrait la porte
        // de sortie dans le message « pour aider ». C'est exactement ce qui s'est passe la premiere
        // fois : la phrase avait ete ecrite avec les meilleures intentions.
        self::assertStringNotContainsString(
            '--only=',
            $sortie,
            "Un message d'echec ne met pas en avant son propre contournement (D53).",
        );
        self::assertStringNotContainsString(
            '--supervise',
            $sortie,
            "Un message d'echec ne nomme pas la levee : elle vit dans la documentation (D53).",
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
            'businessDay' => new \DateTimeImmutable($this->jourComptable($quand) . ' 00:00:00'),
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
            [$this->momentUtc($quand), $vente['id']],
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
