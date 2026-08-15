<?php

declare(strict_types=1);

namespace App\Compta\Adapter;

use App\Compta\Entity\MoyenPaiement as MoyenPaiementEntite;
use App\Vente\Port\MoyenPaiement as MoyenPaiementDto;
use App\Vente\Port\ReferentielReglementInterface;
use App\Vente\Port\ReferentielReglementStub;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Implémentation réelle du référentiel des moyens de paiement (§3 du plan, RG-M2-02) : lit
 * `App\Compta\Entity\MoyenPaiement` (M6, source) et l'expose au port M2 sans FK dure, sans
 * modification du code M2 (seul l'alias `services.yaml` change). Se replie sur le stub L2 (même jeu
 * par défaut, non-régression) tant que la table `compta_moyen_paiement` n'a pas été seedée
 * (migration `VersionM6_moyens_paiement` / fixtures L4) — utile aux contextes M2 qui ne chargent pas
 * les fixtures L4 (ex. suite de tests L2 existante, non modifiée).
 */
final class ReferentielReglementDoctrineAdapter implements ReferentielReglementInterface
{
    /** @var array<string, MoyenPaiementDto>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReferentielReglementStub $repli = new ReferentielReglementStub(),
    ) {
    }

    public function moyensDisponibles(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        /** @var list<MoyenPaiementEntite> $entites */
        $entites = $this->em->getRepository(MoyenPaiementEntite::class)->findBy(['actif' => true]);

        if ($entites === []) {
            return $this->cache = $this->repli->moyensDisponibles();
        }

        $indexe = [];
        foreach ($entites as $entite) {
            $indexe[$entite->getCode()] = new MoyenPaiementDto(
                code: $entite->getCode(),
                libelle: $entite->getLibelle(),
                autoriseRendu: $entite->isAutoriseRendu(),
                exigeReference: $entite->isExigeReference(),
                autoriseDiffere: $entite->isAutoriseDiffere(),
            );
        }

        return $this->cache = $indexe;
    }

    public function moyen(string $code): ?MoyenPaiementDto
    {
        return $this->moyensDisponibles()[$code] ?? null;
    }
}
