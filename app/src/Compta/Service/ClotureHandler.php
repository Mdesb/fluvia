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

        $arrete = $this->arrete($periode);
        $arrete['clotureLe'] = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $periode->setEtatCloture($arrete);
        $periode->setStatut(StatutPeriode::Cloturee);

        $profil = $periode->getProfilExploitant();
        if ($profil !== null && !$profil->isVerrouille()) {
            $profil->setVerrouille(true);
        }

        $this->em->flush();

        return $periode;
    }

    /**
     * L'arrêté chiffré de la période — les mêmes montants que la clôture, calculés sans rien figer.
     *
     * **Extrait de `cloturer()` pour qu'on puisse le montrer AVANT le clic.** La clôture est
     * définitive : aucun code de ce dépôt ne repasse une période à `Ouverte`. Or `etatCloture` n'était
     * rempli qu'une fois la clôture faite — l'exploitant signait donc à l'aveugle le seul geste
     * irréversible du module, et la meilleure fenêtre de confirmation possible se réduisait à
     * « faites-moi confiance ».
     *
     * **Une seule source pour les deux usages, et c'est tout l'intérêt.** Un aperçu calculé à part
     * finirait par annoncer autre chose que ce que la clôture enregistre, et la divergence se
     * découvrirait sur un arrêté — c'est-à-dire trop tard, et sur le document qui fait foi.
     *
     * Ne contient pas `clotureLe` : tant que rien n'est clôturé, il n'y a pas de date de clôture, et
     * en inventer une ferait passer un aperçu pour un arrêté.
     *
     * @return array{produitsCentimes: int, tvaCentimes: int, encaissementsCentimes: int, nbEcritures: int}
     */
    public function arrete(PeriodeComptable $periode): array
    {
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

        return [
            'produitsCentimes' => $produits,
            'tvaCentimes' => $tva,
            'encaissementsCentimes' => $encaissements,
            'nbEcritures' => \count($ecritures),
        ];
    }

    /** Ce qui empêche encore de clôturer, dans les mots que l'exploitant lira sur le refus. */
    public function pointsBloquants(PeriodeComptable $periode): array
    {
        return $this->guard->pointsBloquants($periode);
    }
}
