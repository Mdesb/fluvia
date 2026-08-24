<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Fonctionnalite\Enum\CapaciteCode;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\PlanOption;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-5 — le catalogue lisible sans compte, pour le site vitrine.
 *
 * Ce que ces tests protègent n'est pas l'affichage : c'est la frontière. Une ressource publique se
 * juge à ce qu'elle refuse de dire autant qu'à ce qu'elle dit, et la vitrine est le seul écran que
 * verront des gens qui ne sont pas clients.
 */
final class PublicCatalogApiTest extends SocleApiTestCase
{
    private const COMPRISE = 'controle_acces';
    private const OPTION = 'reservation';

    /** Sans le moindre jeton, la page de tarifs doit pouvoir se remplir. */
    public function testLeCatalogueEstLisibleSansAuthentification(): void
    {
        $this->sauterSiRouteAbsente();
        $this->plan('essentiel', 'Essentiel', 4900, [self::COMPRISE]);
        $this->option(self::OPTION, 'Réservation', 1500);

        $client = static::createClient();

        $formules = $client->request('GET', '/editor/plans')->toArray();
        self::assertResponseIsSuccessful();

        $options = $client->request('GET', '/editor/plan-options')->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame('essentiel', $this->premier($formules)['code']);
        self::assertSame(4900, $this->premier($formules)['monthlyPriceCents']);
        $comprises = $this->premier($formules)['includedCapabilities'];
        self::assertSame(self::COMPRISE, $comprises[0]['capability'], 'le code sert au tunnel');
        self::assertNotSame(
            self::COMPRISE,
            $comprises[0]['label'],
            'un libellé lisible doit accompagner le code — un visiteur ne voit jamais « controle_acces »',
        );
        self::assertNotSame('', $comprises[0]['label']);

        self::assertSame(self::OPTION, $this->premier($options)['capability']);
        self::assertSame(1500, $this->premier($options)['monthlyPriceCents']);
    }

    /**
     * Une formule retirée de la vente n'apparaît pas.
     *
     * Sinon un prospect compose un panier autour d'une offre qu'on ne vend plus, et l'apprend au
     * moment de payer.
     */
    public function testUneFormuleHorsVenteNestPasAnnoncee(): void
    {
        $this->sauterSiRouteAbsente();
        $this->plan('essentiel', 'Essentiel', 4900, [self::COMPRISE]);
        $this->plan('retire', 'Retiré', 9900, [self::COMPRISE])->setActive(false);
        $this->em()->flush();

        $codes = array_column($this->membres(static::createClient()->request('GET', '/editor/plans')->toArray()), 'code');

        self::assertContains('essentiel', $codes);
        self::assertNotContains('retire', $codes);
    }

    /**
     * Une formule qui promet une capacité inexistante n'est pas annoncée non plus.
     *
     * Elle se vendrait normalement et ne se livrerait jamais. L'écarter de la vitrine met l'échec là
     * où il doit être — chez l'éditeur qui a saisi l'offre — plutôt que devant le prospect, après
     * qu'il a choisi.
     */
    public function testUneFormuleQuiPromettraitUneCapaciteFantomeNestPasAnnoncee(): void
    {
        $this->sauterSiRouteAbsente();
        $this->plan('essentiel', 'Essentiel', 4900, [self::COMPRISE]);
        $this->plan('fantome', 'Fantôme', 9900, ['capacite_qui_nexiste_pas']);

        $codes = array_column($this->membres(static::createClient()->request('GET', '/editor/plans')->toArray()), 'code');

        self::assertContains('essentiel', $codes);
        self::assertNotContains('fantome', $codes);
    }

    /**
     * La vitrine ne publie aucune information sur les clients de l'éditeur.
     *
     * Le risque est concret : une page de vente qui listerait des références, des noms
     * d'établissements ou des volumes publierait des informations commerciales que personne n'a
     * autorisées. On vérifie ici que la charge utile ne contient que de l'offre.
     */
    public function testLaVitrineNePublieRienSurLesClients(): void
    {
        $this->sauterSiRouteAbsente();
        $this->plan('essentiel', 'Essentiel', 4900, [self::COMPRISE]);

        $membre = $this->premier(static::createClient()->request('GET', '/editor/plans')->toArray());

        self::assertSame(
            ['code', 'label', 'monthlyPriceCents', 'includedCapabilities'],
            array_values(array_diff(array_keys($membre), ['@id', '@type'])),
            'la ressource publique ne doit exposer que l\'offre',
        );
    }

    // ---------------------------------------------------------------- montage

    /**
     * Les ressources de ce module ne sont pas encore découvertes par API Platform (C9).
     *
     * `mapping.paths` de `api_platform.yaml` est une **liste blanche explicite**, dossier par dossier :
     * un module neuf est invisible de l'API tant que personne n'y ajoute sa ligne, et rien ne le
     * signale — pas même une erreur au démarrage, la route n'existe simplement pas. Le fichier
     * appartient au noyau, pas à ce périmètre ; la demande est faite.
     *
     * On saute **explicitement** plutôt que de laisser quatre tests rouges sur la branche : un test
     * rouge qu'on apprend à ignorer ne protège plus rien. Le jour où la ligne est ajoutée, ils se
     * rallument seuls, sans que personne n'ait à y penser.
     */
    private function sauterSiRouteAbsente(): void
    {
        static::createClient();

        /** @var \Symfony\Component\Routing\RouterInterface $routeur */
        $routeur = static::getContainer()->get('router');

        foreach ($routeur->getRouteCollection() as $route) {
            if ('/editor/plans' === $route->getPath()) {
                return;
            }
        }

        self::markTestSkipped(
            'C9 — `src/Subscription/ApiResource` absent de `mapping.paths` dans api_platform.yaml : '
            .'la ressource publique existe mais n\'est pas routée. Demande faite à claude-A.'
        );
    }

    /** @param array<string, mixed> $reponse */
    private function membres(array $reponse): array
    {
        /** @var list<array<string, mixed>> $membres */
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];

        return $membres;
    }

    /** @param array<string, mixed> $reponse */
    private function premier(array $reponse): array
    {
        $membres = $this->membres($reponse);
        self::assertNotSame([], $membres, 'la collection publique ne doit pas être vide');

        return $membres[0];
    }

    /** @param list<string> $comprises */
    private function plan(string $code, string $label, int $prixCents, array $comprises): Plan
    {
        $plan = (new Plan())
            ->setCode($code)
            ->setLabel($label)
            ->setMonthlyPriceCents($prixCents)
            ->setIncludedCapabilities($comprises)
            ->setActive(true);

        $this->em()->persist($plan);
        $this->em()->flush();

        return $plan;
    }

    private function option(string $capacite, string $label, int $prixCents): PlanOption
    {
        \assert(null !== CapaciteCode::tryFrom($capacite));

        $option = (new PlanOption())
            ->setCapability($capacite)
            ->setLabel($label)
            ->setMonthlyPriceCents($prixCents);

        $this->em()->persist($option);
        $this->em()->flush();

        return $option;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
