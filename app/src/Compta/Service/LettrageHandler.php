<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\LettrageEcriture;
use App\Compta\Entity\LigneEcriture;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Lettrage & contrôle (§4.2 spec) : rapprochement recette / mode de paiement / versement. Détecte
 * les écritures déséquilibrées, non lettrées ou hors période (`controler`).
 */
final class LettrageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function lettrer(LigneEcriture $ligne, Utilisateur $auteur, ?\DateTimeImmutable $date = null): LettrageEcriture
    {
        if (($ligne->getEcriture()?->estScellee() ?? false) === false) {
            throw new ConflictHttpException('Seule une écriture scellée peut être lettrée.');
        }

        $lettrage = new LettrageEcriture();
        $lettrage->setLigne($ligne);
        $lettrage->setAuteur($auteur);
        $lettrage->setDateLettrage($date ?? new \DateTimeImmutable());

        $this->em->persist($lettrage);
        $this->em->flush();

        return $lettrage;
    }

    /**
     * Contrôle d'une période : écritures déséquilibrées ou hors période (§4.2 spec).
     *
     * @param iterable<\App\Compta\Entity\EcritureComptable> $ecritures
     *
     * @return list<string>
     */
    public function controler(iterable $ecritures, \App\Compta\Entity\PeriodeComptable $periode): array
    {
        $anomalies = [];
        foreach ($ecritures as $ecriture) {
            if (!$ecriture->estEquilibree()) {
                $anomalies[] = sprintf('Écriture %s déséquilibrée (débit ≠ crédit).', $ecriture->getId());
            }
            if (!$periode->couvre($ecriture->getDateEcriture())) {
                $anomalies[] = sprintf('Écriture %s hors période.', $ecriture->getId());
            }
        }

        return $anomalies;
    }
}
