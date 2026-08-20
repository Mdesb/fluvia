<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\LettrageEcriture;
use App\Compta\Entity\LigneEcriture;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

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
     * Lettrage groupé (US-L4-14, RG-M6-14, extension additive — `lettrer()` ci-dessus reste
     * strictement inchangé, non-régression testée explicitement). Rapproche plusieurs lignes entre
     * elles (facture fournisseur + son règlement ; écriture bancaire + ligne de relevé importé) en un
     * seul geste : toutes les `LettrageEcriture` créées partagent le **même** `reconciliationCode`.
     *
     * @param list<LigneEcriture> $lignes
     *
     * @return list<LettrageEcriture>
     *
     * @throws UnprocessableEntityHttpException si moins de 2 lignes ou débit total ≠ crédit total (CA-6)
     * @throws ConflictHttpException            si une ligne n'est pas scellée ou est déjà lettrée
     */
    public function lettrerGroupe(array $lignes, Utilisateur $auteur, ?\DateTimeImmutable $date = null): array
    {
        if (\count($lignes) < 2) {
            throw new UnprocessableEntityHttpException('Un lettrage groupé exige au moins 2 lignes (§4.4 spec).');
        }

        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($lignes as $ligne) {
            if (($ligne->getEcriture()?->estScellee() ?? false) === false) {
                throw new ConflictHttpException('Seule une écriture scellée peut être lettrée.');
            }
            $dejaLettree = $this->em->getRepository(LettrageEcriture::class)->findOneBy(['ligne' => $ligne->getId()]);
            if ($dejaLettree !== null) {
                throw new ConflictHttpException(sprintf('La ligne %s est déjà lettrée.', $ligne->getId()));
            }
            $totalDebit += $ligne->getDebitCentimes();
            $totalCredit += $ligne->getCreditCentimes();
        }

        if ($totalDebit !== $totalCredit) {
            throw new UnprocessableEntityHttpException('Lettrage groupé rejeté : la somme des débits doit égaler la somme des crédits (CA-6).');
        }

        $reconciliationCode = Uuid::v4()->toRfc4122();
        $dateEffective = $date ?? new \DateTimeImmutable();

        $lettrages = [];
        foreach ($lignes as $ligne) {
            $lettrage = new LettrageEcriture();
            $lettrage->setLigne($ligne);
            $lettrage->setAuteur($auteur);
            $lettrage->setDateLettrage($dateEffective);
            $lettrage->setReconciliationCode($reconciliationCode);
            $this->em->persist($lettrage);
            $lettrages[] = $lettrage;
        }
        $this->em->flush();

        return $lettrages;
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
