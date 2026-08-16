<?php

declare(strict_types=1);

namespace App\Reservation\Port\Adapter;

use App\Offre\Entity\ServiceInclus;
use App\Reservation\Port\FormuleBeneficiaireInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Stub par défaut (Risque n°5 du plan) : aucun rattachement bénéficiaire↔formule connu par défaut.
 * `definir()`/`definirConsommations()` permettent aux tests d'injecter un scénario « quota disponible »
 * sans dépendre d'un câblage M1/M4 réel (même patron que `App\Sepa\Adapter\RetourSepaStubAdapter`).
 */
final class FormuleBeneficiaireStub implements FormuleBeneficiaireInterface
{
    /** @var array<string, ServiceInclus> */
    private array $services = [];

    /** @var array<string, list<\DateTimeImmutable>> */
    private array $consommations = [];

    public function serviceInclus(Uuid $beneficiaireId, Uuid $activiteId): ?ServiceInclus
    {
        return $this->services[$this->cle($beneficiaireId, $activiteId)] ?? null;
    }

    /** @return list<\DateTimeImmutable> */
    public function consommations(Uuid $beneficiaireId, Uuid $serviceInclusId): array
    {
        return $this->consommations[(string) $beneficiaireId . '|' . (string) $serviceInclusId] ?? [];
    }

    /** Réservé aux tests : déclare qu'un bénéficiaire dispose d'un ServiceInclus pour une activité. */
    public function definir(Uuid $beneficiaireId, Uuid $activiteId, ServiceInclus $service): void
    {
        $this->services[$this->cle($beneficiaireId, $activiteId)] = $service;
    }

    /** @param list<\DateTimeImmutable> $consommations */
    public function definirConsommations(Uuid $beneficiaireId, Uuid $serviceInclusId, array $consommations): void
    {
        $this->consommations[(string) $beneficiaireId . '|' . (string) $serviceInclusId] = $consommations;
    }

    private function cle(Uuid $beneficiaireId, Uuid $activiteId): string
    {
        return (string) $beneficiaireId . '|' . (string) $activiteId;
    }
}
