<?php

declare(strict_types=1);

namespace App\Tests\Sport\Unit;

use App\Offre\Entity\Formule;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\PeriodiciteAbonnementFitness;
use App\Sport\Service\GenerateurEcheancierHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * `Formule::$jourPrelevement` DÉCIDE ENFIN DE QUELQUE CHOSE.
 *
 * Le champ était mappé, exposé en `produit:write` et `formule:write` — donc réglable depuis l'écran
 * produit — et lu par personne : zéro appel à `getJourPrelevement()` hors de l'entité. Un exploitant
 * pouvait régler « prélèvement le 5 » et tout le monde restait prélevé à sa date anniversaire.
 *
 * ⚠ CES TÉMOINS PORTENT AUTANT SUR CE QUE LA RÈGLE ÉPARGNE QUE SUR CE QU'ELLE FAIT. Un cliquet qui
 * ne prouve que le cas nominal laisserait passer la vraie régression possible ici : appliquer la
 * règle là où elle n'a pas de sens (l'hebdomadaire), ou déplacer la première échéance — celle que
 * l'appelant dimensionne avec le prorata.
 */
final class JourPrelevementTest extends TestCase
{
    /** @return list<EcheanceSepa> */
    private function generer(?int $jourPrelevement, PeriodiciteAbonnementFitness $periodicite, string $debut, string $fin): array
    {
        $posees = [];

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $e) use (&$posees): void {
            if ($e instanceof EcheanceSepa) {
                $posees[] = $e;
            }
        });

        $formule = new Formule();
        $formule->setJourPrelevement($jourPrelevement);

        $abonnement = new AbonnementFitness();
        $abonnement->setFormule($formule)
            ->setPeriodicite($periodicite)
            ->setDateDebutEngagement(new \DateTimeImmutable($debut))
            ->setDateFinEngagement(new \DateTimeImmutable($fin));

        (new GenerateurEcheancierHandler($em))->generer($abonnement, 3990);

        return $posees;
    }

    /** @param list<EcheanceSepa> $echeances */
    private function jours(array $echeances): array
    {
        return array_map(
            static fn (EcheanceSepa $e): string => $e->getDateProgrammee()->format('Y-m-d'),
            $echeances,
        );
    }

    /** SANS JOUR DÉCLARÉ, RIEN NE CHANGE — le comportement d'avant, à l'identique. */
    public function testSansJourDeclareLesEcheancesRestentSurLaDateAnniversaire(): void
    {
        $dates = $this->jours($this->generer(null, PeriodiciteAbonnementFitness::Mensuel, '2026-01-17', '2026-05-17'));

        self::assertSame(['2026-01-17', '2026-02-17', '2026-03-17', '2026-04-17'], $dates);
    }

    /** AVEC UN JOUR DÉCLARÉ : la première ne bouge pas, les suivantes se posent dessus. */
    public function testLesEcheancesSuivantesSePosentSurLeJourDeclare(): void
    {
        $dates = $this->jours($this->generer(5, PeriodiciteAbonnementFitness::Mensuel, '2026-01-17', '2026-05-17'));

        // ⚠ LA PREMIÈRE RESTE AU 17. C'est l'échéance que l'appelant dimensionne avec le prorata
        //    d'une souscription en cours de période ; la déplacer changerait en silence ce que ce
        //    montant couvre.
        self::assertSame(['2026-01-17', '2026-02-05', '2026-03-05', '2026-04-05'], $dates);
    }

    /**
     * LE 31 N'EXISTE PAS TOUS LES MOIS, ET `setDate` NE LE DIT PAS.
     *
     * ⚠ SANS LA BORNE, `setDate(2026, 2, 31)` NE LÈVE RIEN : il rend le 3 mars. L'échéance de
     * février partirait en mars, une échéance silencieusement décalée d'un mois — le genre d'erreur
     * qu'on ne découvre qu'au relevé bancaire.
     */
    public function testLeJourEstBorneALaLongueurDuMois(): void
    {
        $dates = $this->jours($this->generer(31, PeriodiciteAbonnementFitness::Mensuel, '2026-01-15', '2026-05-15'));

        self::assertSame(['2026-01-15', '2026-02-28', '2026-03-31', '2026-04-30'], $dates);
    }

    /**
     * CE QUE LA RÈGLE ÉPARGNE (1) — l'hebdomadaire.
     *
     * Une périodicité hebdomadaire n'a pas de « jour du mois ». Y appliquer la règle produirait des
     * échéances aux intervalles arbitraires : le 5 de chaque mois traversé, et rien entre les deux.
     */
    public function testLHebdomadaireIgnoreLeJourDeclare(): void
    {
        $dates = $this->jours($this->generer(5, PeriodiciteAbonnementFitness::Hebdomadaire, '2026-01-05', '2026-02-02'));

        self::assertSame(['2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26'], $dates);
    }

    /**
     * CE QUE LA RÈGLE ÉPARGNE (2) — une valeur qui n'a pas de sens.
     *
     * Un jour hors de 1..31 ne se corrige pas en silence : mieux vaut le comportement connu qu'une
     * date fabriquée à partir d'une valeur absurde. `setDate(2026, 2, 99)` rendrait le 9 mai.
     */
    public function testUnJourHorsBornesEstIgnoreEtNonCorrige(): void
    {
        $dates = $this->jours($this->generer(99, PeriodiciteAbonnementFitness::Mensuel, '2026-01-17', '2026-04-17'));

        self::assertSame(['2026-01-17', '2026-02-17', '2026-03-17'], $dates);
    }

    /**
     * LES DATES RESTENT STRICTEMENT CROISSANTES, MÊME AU CAS LIMITE.
     *
     * Souscription le 31 janvier avec un jour 1 : la deuxième tombe le 1er février, un jour plus
     * tard. C'est court, et c'est correct — cette première échéance est un prorata d'un jour, que
     * l'appelant dimensionne. Ce qui ne doit jamais arriver, c'est une date qui recule.
     */
    public function testLesDatesNeReculentJamais(): void
    {
        $dates = $this->jours($this->generer(1, PeriodiciteAbonnementFitness::Mensuel, '2026-01-31', '2026-05-01'));

        $triees = $dates;
        sort($triees);
        self::assertSame($triees, $dates, 'les échéances doivent être strictement croissantes');
        self::assertSame(\count($dates), \count(array_unique($dates)), 'deux échéances ne peuvent pas tomber le même jour');
        self::assertSame('2026-01-31', $dates[0]);
    }
}
