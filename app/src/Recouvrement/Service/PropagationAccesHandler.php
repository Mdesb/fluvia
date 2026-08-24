<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Recouvrement\Event\AccesRedevableChangeEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Seul point d'écriture du moteur de recouvrement sur `DroitAcces.statutProjection` (extrait de
 * `App\Sport\Service\PropagationAccesFitnessHandler`, généralisé). **Aucun fichier `App\Acces\*` n'est
 * modifié** : le moteur de recouvrement devient un producteur légitime de cette transition, exactement
 * comme M2/Sport. Le hors-ligne/synchro est hérité intégralement du mécanisme générique L3 — aucun
 * développement supplémentaire ici. Résout le `DroitAcces` via `RedevableRegistry` (port fourni par la
 * verticale) puis dispatche `AccesRedevableChangeEvent` pour que la verticale tienne à jour sa propre
 * projection métier (motif d'inactivité, etc.).
 */
final class PropagationAccesHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RedevableRegistry $redevables,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /** Restaure l'accès (dossier régularisé). */
    public function activer(string $typeRedevable, string $referenceRedevable): void
    {
        $this->appliquer($typeRedevable, $referenceRedevable, true);
    }

    /** Coupe l'accès (impayé non régularisé, selon la politique). */
    public function desactiver(string $typeRedevable, string $referenceRedevable): void
    {
        $this->appliquer($typeRedevable, $referenceRedevable, false);
    }

    private function appliquer(string $typeRedevable, string $referenceRedevable, bool $actif): void
    {
        $droit = $this->redevables->droitAcces($typeRedevable, $referenceRedevable);
        $etablissementId = null;
        if ($droit instanceof DroitAcces) {
            $droit->setStatutProjection($actif ? StatutProjectionDroit::Valide : StatutProjectionDroit::Devalide);
            $this->em->flush();
            // C12 (RG-PLAT-03) — l'établissement du droit résolu rend l'événement pontable (tenant D6).
            $etablissement = $droit->getEtablissement();
            $etablissementId = $etablissement !== null ? (string) $etablissement->getId() : null;
        }

        $this->dispatcher->dispatch(new AccesRedevableChangeEvent($typeRedevable, $referenceRedevable, $actif, $etablissementId));
    }
}
