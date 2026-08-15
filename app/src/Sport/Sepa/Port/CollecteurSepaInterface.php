<?php

declare(strict_types=1);

namespace App\Sport\Sepa\Port;

use App\Sport\Entity\EcheanceSepa;
use App\Sport\Entity\RemiseSepa;
use App\Sport\Entity\RepresentationSepa;
use App\Sport\Sepa\Dto\ResultatGenerationRemise;
use App\Sport\Sepa\Dto\RetourSepaDto;

/**
 * Port de collecte bancaire SEPA (§2.1 du plan). Aucun moteur d'exécution SEPA n'existe ailleurs dans
 * le dépôt (spec §8 point 3, gap confirmé) : ce port est neuf et légitime. L'adaptateur par défaut est
 * un stub — aucune remise bancaire réelle n'est effectuée par ce lot (Risque n°2).
 */
interface CollecteurSepaInterface
{
    /**
     * Génère le fichier de remise (pain.008 simulé) pour un lot d'échéances.
     *
     * @param list<EcheanceSepa> $echeances
     */
    public function genererRemise(RemiseSepa $remise, array $echeances): ResultatGenerationRemise;

    /** Transmet une remise déjà générée au partenaire bancaire (SFTP/EBICS/API — non spécifié). */
    public function transmettre(RemiseSepa $remise): void;

    /** @return list<RetourSepaDto> Relève les retours normalisés (rejets/représentations) depuis une date. */
    public function relerverRetours(\DateTimeImmutable $depuis): array;

    /** Soumet une représentation ponctuelle (hors remise groupée). */
    public function soumettreRepresentation(RepresentationSepa $representation): void;
}
