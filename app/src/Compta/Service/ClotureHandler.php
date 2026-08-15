<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Enum\StatutPeriode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Clôture de période (RG-CLOTURE-10, CA-14) : fige définitivement les écritures, produit un état
 * récapitulatif (produits, TVA, encaissements, PCA), verrouille le référentiel comptable du profil
 * à la première clôture (RG-COMPTA-01).
 */
final class ClotureHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClotureGuard $guard,
    ) {
    }

    public function cloturer(PeriodeComptable $periode): PeriodeComptable
    {
        $bloquants = $this->guard->pointsBloquants($periode);
        if ($bloquants !== []) {
            throw new ConflictHttpException('Clôture refusée : ' . implode(' | ', $bloquants));
        }

        /** @var list<EcritureComptable> $ecritures */
        $ecritures = $this->em->getRepository(EcritureComptable::class)->findBy(['periode' => $periode->getId()]);

        $produits = 0;
        $tva = 0;
        $encaissements = 0;
        foreach ($ecritures as $ecriture) {
            $encaissements += $ecriture->totalDebitCentimes();
            foreach ($ecriture->getLignes() as $ligne) {
                if ($ligne->getCreditCentimes() <= 0) {
                    continue;
                }
                if (str_starts_with($ligne->getCompte()?->getNumero() ?? '', '4457')) {
                    $tva += $ligne->getCreditCentimes();
                } elseif (!str_starts_with($ligne->getCompte()?->getNumero() ?? '', '51') && !str_starts_with($ligne->getCompte()?->getNumero() ?? '', '41')) {
                    $produits += $ligne->getCreditCentimes();
                }
            }
        }

        $periode->setEtatCloture([
            'produitsCentimes' => $produits,
            'tvaCentimes' => $tva,
            'encaissementsCentimes' => $encaissements,
            'nbEcritures' => \count($ecritures),
            'clotureLe' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);
        $periode->setStatut(StatutPeriode::Cloturee);

        $profil = $periode->getProfilExploitant();
        if ($profil !== null && !$profil->isVerrouille()) {
            $profil->setVerrouille(true);
        }

        $this->em->flush();

        return $periode;
    }
}
