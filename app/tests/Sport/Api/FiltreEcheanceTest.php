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
