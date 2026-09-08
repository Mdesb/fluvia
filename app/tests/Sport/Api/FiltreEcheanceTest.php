<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Sport\Entity\EcheanceSepa;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `?abonnement=` ÉTAIT ACCEPTÉ ET IGNORÉ EN SILENCE.
 *
 * API Platform ne refuse pas un paramètre de requête inconnu : il le laisse passer et rend la
 * collection ENTIÈRE. Une fiche d'abonnement qui demandait « les échéances de celui-ci » recevait
 * donc celles de tout le monde — sans erreur, sans avertissement, avec un code 200.
 *
 * Signalé par `allaccess-c0` le 03/09 en construisant la fiche d'abonnement. Elle a dû trier côté
 * écran sur l'IRI : ça marche tant que la page rend tout, et ça cesse de marcher dès que la
 * pagination coupe. Son écran affichait un bandeau pour le dire ; ce filtre le rend inutile.
 *
 * ⚠ **C'est la famille des écritures acceptées qui n'enregistrent rien, vue côté lecture.** Le
 * garde-fou « filtres déclarés » surveille l'inverse — un filtre déclaré sur une propriété absente
 * du mapping. Celui-ci manquait tout court, et rien ne le signalait.
 */
final class FiltreEcheanceTest extends SportApiTestCase
{
    /**
     * **Le test qui compte, et son témoin dans le même corps.**
     *
     * Un filtre cassé qui rendrait une liste VIDE satisferait « aucune échéance étrangère ». C'est
     * pour ça que la collection non filtrée est lue d'abord : elle établit qu'il y a bien de quoi
     * filtrer, et que le filtre retire quelque chose.
     */
    public function testLeFiltreParAbonnementNeRendQueLesSiennes(): void
    {
        [$client, $entete] = $this->adminSurA();
        $abonnement = $this->abonnementDemo();
        $iri = '/api/abonnement_fitnesses/' . $abonnement->getId();

        // ⚠ LE VOISIN SE FABRIQUE, IL NE SE TROUVE PAS. Les fixtures ne portent d'echeances que
        // pour UN abonnement : sans un second, filtrer rendrait la meme liste et le test serait
        // vert sans rien mesurer. C'est mon garde-fou de non-vacuite qui l'a dit, pas moi.
        $this->echeanceChezLeVoisin($abonnement);

        // Témoin 1 — la collection entière porte des échéances d'AU MOINS DEUX abonnements.
        // Sans cette précondition, filtrer ne prouverait rien : tout appartiendrait déjà au même.
        $client->request('GET', '/api/echeance_sepas?itemsPerPage=200', $entete);
        self::assertResponseIsSuccessful();
        $toutes = $this->lignes($client);

        // ⚠ `abonnement` EST UN OBJET IMBRIQUE, PAS UNE IRI. L'API rend
        // `{"@id": "/api/abonnement_fitnesses/…", "@type": …}` : `(string)` dessus donne « Array »
        // pour TOUTES les lignes, et le compte d'uniques vaut 1 quoi qu'il arrive. Mon premier jet
        // faisait exactement ca, et le garde-fou de non-vacuite a signale « tout appartient au meme
        // abonnement » — un diagnostic faux produit par un instrument faux.
        $abonnements = array_unique(array_map([self::class, 'iriAbonnement'], $toutes));
        self::assertGreaterThanOrEqual(
            2,
            \count($abonnements),
            'Toutes les échéances appartiennent au même abonnement : ce test ne peut rien mesurer.',
        );

        // Le filtre.
        $client->request('GET', '/api/echeance_sepas?itemsPerPage=200&abonnement=' . $iri, $entete);
        self::assertResponseIsSuccessful();
        $filtrees = $this->lignes($client);

        // Témoin 2 — il en reste. Une liste vide satisferait l'assertion suivante sans rien prouver.
        self::assertNotEmpty($filtrees, 'Le filtre ne rend rien : il ne filtre pas, il efface.');
        self::assertLessThan(\count($toutes), \count($filtrees), 'Le filtre n a rien retiré : il est ignoré.');

        foreach ($filtrees as $ligne) {
            self::assertStringEndsWith(
                (string) $abonnement->getId(),
                self::iriAbonnement($ligne),
                'Une échéance d un AUTRE abonnement est rendue : c est exactement le défaut signalé.',
            );
        }
    }

    /**
     * Le filtre par statut, posé dans le même mouvement — l'écran en a besoin pour séparer ce qui
     * est dû de ce qui est passé.
     */
    public function testLeFiltreParStatutFonctionneAussi(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/echeance_sepas?itemsPerPage=200&statut=a_venir', $entete);
        self::assertResponseIsSuccessful();

        $lignes = $this->lignes($client);
        self::assertNotEmpty($lignes, 'Aucune échéance à venir : ce test ne mesure rien.');

        foreach ($lignes as $ligne) {
            self::assertSame('a_venir', $ligne['statut'] ?? null);
        }
    }

    /**
     * Un second abonnement, avec une echeance a lui.
     *
     * Il reprend l'adherent, le payeur, la formule et le mandat de la demonstration : ce test porte
     * sur le FILTRE, et fabriquer cinq entites de plus n'y ajouterait rien.
     */
    /**
     * `?order[dateProgrammee]=` ÉTAIT ACCEPTÉ ET IGNORÉ — LE MÊME PIÈGE QUE CE FICHIER DOCUMENTE.
     *
     * `FicheAbonnement.jsx:95` envoyait ce paramètre depuis le 03/09. Le serveur rendait 200 et la
     * collection dans l'ordre des UUID. Mesure du 07/09 contre la préproduction :
     * `2026-04, 2026-05, 2026-06, 2026-07, 2025-10, 2026-01, 2025-09, 2026-08, ...`
     *
     * ⚠ **Le témoin qui tranche venait du serveur, pas d'une relecture.** Le gabarit `search` de la
     * réponse déclarait `{?abonnement,abonnement[],statut,statut[]}` : `order` n'y figurait pas.
     * Une ressource dit elle-même ce qu'elle sait faire.
     *
     * ⚠ **Et l'écran n'était pas seulement mal rangé.** `FicheAbonnement` lisait la « prochaine
     * échéance » par un `find`, qui rend le PREMIER élément du tableau — donc, sur une liste non
     * triée, une échéance future quelconque. La tuile répondait « quand suis-je prélevé ? » par une
     * date arbitraire.
     *
     * ⚠ **POURQUOI CE TEST NE SE CONTENTE PAS D'ASSERTER « C'EST TRIÉ ».** L'ordre des UUID est
     * aléatoire : sur cinq lignes, « croissant » serait vert une fois sur cent vingt sans le
     * correctif. Un filet qui n'attrape que la plupart du temps n'en est pas un. On demande donc
     * `asc` **puis** `desc` : sans le filtre les deux réponses sont identiques — toujours, et pas
     * seulement souvent. C'est cette identité que le test interdit.
     */
    public function testLOrdreEstChronologiqueEtLeSensEstRespecte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->echeancesDansLeDesordre();

        $client->request('GET', '/api/echeance_sepas?itemsPerPage=200&order%5BdateProgrammee%5D=asc', $entete);
        self::assertResponseIsSuccessful();
        $asc = $this->datesProgrammees($client);

        $client->request('GET', '/api/echeance_sepas?itemsPerPage=200&order%5BdateProgrammee%5D=desc', $entete);
        self::assertResponseIsSuccessful();
        $desc = $this->datesProgrammees($client);

        // Témoin de non-vacuité : sans plusieurs dates DISTINCTES, « trié » et « inversé » sont la
        // même chose et les assertions suivantes seraient vraies sans rien mesurer.
        self::assertGreaterThan(
            2,
            \count(array_unique($asc)),
            'Il faut au moins trois dates distinctes pour que le sens du tri veuille dire quelque chose.',
        );

        $attendu = $asc;
        sort($attendu);
        self::assertSame($attendu, $asc, 'La collection demandée en `asc` n\'est pas chronologique.');

        // ⚠ L'ASSERTION QUI NE PEUT PAS PASSER PAR CHANCE : sans `OrderFilter`, `asc` et `desc`
        // rendent la MÊME liste, parce que les deux paramètres sont ignorés en silence.
        self::assertSame(
            array_reverse($asc),
            $desc,
            'Le sens du tri est ignoré : `asc` et `desc` rendent le même ordre.',
        );
    }

    /**
     * L'ordre par défaut, pour les appelants qui ne demandent rien.
     *
     * ⚠ `Sport.jsx` ne passe aucun paramètre. Déclarer `OrderFilter` sans poser `order:` sur la
     * ressource aurait donc corrigé la fiche et laissé l'écran principal dans l'ordre des UUID —
     * une moitié de correctif, et la moitié la plus visible restée cassée.
     */
    public function testSansParametreLEcheancierArriveDejaChronologique(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->echeancesDansLeDesordre();

        $client->request('GET', '/api/echeance_sepas?itemsPerPage=200', $entete);
        self::assertResponseIsSuccessful();
        $dates = $this->datesProgrammees($client);

        self::assertGreaterThan(2, \count(array_unique($dates)), 'Témoin : il faut de quoi trier.');

        $attendu = $dates;
        sort($attendu);
        self::assertSame($attendu, $dates, 'L\'échéancier n\'arrive pas trié par défaut.');
    }

    /**
     * Quatre échéances écrites dans un ordre chronologique VOLONTAIREMENT faux.
     *
     * L'ordre d'insertion n'est pas l'ordre rendu (c'est celui des UUID qui l'était), mais écrire
     * en désordre évite qu'un test soit vert parce que la base a rendu par hasard ce qu'on voulait.
     */
    private function echeancesDansLeDesordre(): void
    {
        $em = $this->em();
        $abonnement = $this->abonnementDemo();

        foreach (['2027-03-05', '2026-10-05', '2027-01-05', '2026-12-05'] as $jour) {
            $em->persist((new EcheanceSepa())
                ->setAbonnement($abonnement)
                ->setDateProgrammee(new \DateTimeImmutable($jour))
                ->setMontantCentimes(3990)
                ->setStatut(\App\Sport\Enum\StatutEcheanceSepa::AVenir));
        }

        $em->flush();
    }

    /** @return list<string> */
    private function datesProgrammees(object $client): array
    {
        return array_values(array_map(
            static fn (array $l): string => substr((string) ($l['dateProgrammee'] ?? ''), 0, 10),
            $this->lignes($client),
        ));
    }

    private function echeanceChezLeVoisin(\App\Sport\Entity\AbonnementFitness $demo): void
    {
        $em = $this->em();

        $voisin = (new \App\Sport\Entity\AbonnementFitness())
            ->setAdherent($demo->getAdherent())
            ->setPayeur($demo->getPayeur())
            ->setFormule($demo->getFormule())
            ->setMandatSepa($demo->getMandatSepa())
            ->setEtablissement($demo->getEtablissement())
            ->setMontantCentimes(2990)
            ->setDateSouscription(new \DateTimeImmutable('2026-02-01'))
            ->setDateDebutEngagement(new \DateTimeImmutable('2026-02-01'))
            ->setDateFinEngagement(new \DateTimeImmutable('2027-02-01'));
        $em->persist($voisin);

        $em->persist((new EcheanceSepa())
            ->setAbonnement($voisin)
            ->setDateProgrammee(new \DateTimeImmutable('2026-11-01'))
            ->setMontantCentimes(2990)
            ->setStatut(\App\Sport\Enum\StatutEcheanceSepa::AVenir));

        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /**
     * L'IRI de l'abonnement d'une ligne, quelle que soit la forme rendue.
     *
     * API Platform imbrique l'objet quand la relation est dans le groupe de lecture, et rend une
     * IRI nue quand elle ne l'est pas. Lire les deux evite qu'un changement de groupe rende ce test
     * vert pour la mauvaise raison.
     *
     * @param array<string, mixed> $ligne
     */
    private static function iriAbonnement(array $ligne): string
    {
        $brut = $ligne['abonnement'] ?? null;

        if (\is_array($brut)) {
            return (string) ($brut['@id'] ?? '');
        }

        return \is_string($brut) ? $brut : '';
    }

    /** @return list<array<string, mixed>> */
    private function lignes(object $client): array
    {
        $corps = $client->getResponse()->toArray();

        /** @var list<array<string, mixed>> $lignes */
        $lignes = $corps['member'] ?? $corps['hydra:member'] ?? [];

        return $lignes;
    }
}
