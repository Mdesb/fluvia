<?php

declare(strict_types=1);

namespace App\Reporting\Security;

use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Résout le périmètre Reporting effectif d'un utilisateur PAR DÉRIVATION (§0/§2.2 plan-reporting.md)
 * — le socle M8 actuel (`Affectation`) ne porte un rôle qu'au niveau Établissement (écart RG-M8-02
 * déjà signalé par `spec-backoffice.md` §7). Une Région/un Groupe est donc considéré « couvert » en
 * Reporting quand la TOTALITÉ de ses établissements descendants est couverte par les affectations
 * `reporting.*` de l'utilisateur — lecture pure de données M8 existantes, sans y ajouter d'affectation
 * de niveau supérieur (hors périmètre M7, appartient à M8, RG-M7-01 « jamais redéfini »).
 */
final class PerimetreReportingResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<Uuid> */
    public function etablissementsAutorises(Utilisateur $utilisateur, string $action = 'lire'): array
    {
        /** @var list<Affectation> $affectations */
        $affectations = $this->em->getRepository(Affectation::class)->findBy(['utilisateur' => $utilisateur]);

        $ids = [];
        foreach ($affectations as $affectation) {
            $role = $affectation->getRole();
            $etablissement = $affectation->getEtablissement();
            if ($role === null || $etablissement === null) {
                continue;
            }
            foreach ($role->getPermissions() as $permission) {
                if ($permission->getModule() !== 'reporting') {
                    continue;
                }
                if ($permission->getAction() === $action || $permission->getAction() === '*') {
                    $ids[$etablissement->getId()->toRfc4122()] = $etablissement->getId();
                    break;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * Régions dont TOUS les établissements sont dans `$etablissementsAutorises`.
     *
     * @param list<Uuid> $etablissementsAutorises
     *
     * @return list<Uuid>
     */
    public function regionsCouvertes(array $etablissementsAutorises): array
    {
        $etabIds = array_map(static fn (Uuid $u): string => $u->toRfc4122(), $etablissementsAutorises);

        /** @var list<Region> $regions */
        $regions = $this->em->getRepository(Region::class)->findAll();

        $couvertes = [];
        foreach ($regions as $region) {
            $etablissementsRegion = $region->getEtablissements();
            if ($etablissementsRegion->isEmpty()) {
                continue;
            }
            $toutesCouvertes = true;
            foreach ($etablissementsRegion as $etablissement) {
                \assert($etablissement instanceof Etablissement);
                if (!\in_array($etablissement->getId()->toRfc4122(), $etabIds, true)) {
                    $toutesCouvertes = false;
                    break;
                }
            }
            if ($toutesCouvertes) {
                $couvertes[] = $region->getId();
            }
        }

        return $couvertes;
    }

    /**
     * Groupes dont TOUTES les régions sont couvertes (cf. `regionsCouvertes`).
     *
     * @param list<Uuid> $etablissementsAutorises
     *
     * @return list<Uuid>
     */
    public function groupesCouverts(array $etablissementsAutorises): array
    {
        $regionsCouvertes = $this->regionsCouvertes($etablissementsAutorises);
        $regionIds = array_map(static fn (Uuid $u): string => $u->toRfc4122(), $regionsCouvertes);

        /** @var list<Groupe> $groupes */
        $groupes = $this->em->getRepository(Groupe::class)->findAll();

        $couverts = [];
        foreach ($groupes as $groupe) {
            $regionsGroupe = $groupe->getRegions();
            if ($regionsGroupe->isEmpty()) {
                continue;
            }
            $toutesCouvertes = true;
            foreach ($regionsGroupe as $region) {
                \assert($region instanceof Region);
                if (!\in_array($region->getId()->toRfc4122(), $regionIds, true)) {
                    $toutesCouvertes = false;
                    break;
                }
            }
            if ($toutesCouvertes) {
                $couverts[] = $groupe->getId();
            }
        }

        return $couverts;
    }

    public function perimetreEffectif(Utilisateur $utilisateur, string $action = 'lire'): PerimetreReporting
    {
        $etablissements = $this->etablissementsAutorises($utilisateur, $action);

        return new PerimetreReporting(
            $etablissements,
            $this->regionsCouvertes($etablissements),
            $this->groupesCouverts($etablissements),
        );
    }
}
