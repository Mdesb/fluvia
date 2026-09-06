<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\VenteImpayeeRegie;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * LA VENTE MARQUÉE « IMPAYÉE RÉGIE » A FINALEMENT ÉTÉ ENCAISSÉE.
 *
 * ── CE QUE CE GESTE RÉPARE ─────────────────────────────────────────────────────────────────────
 *
 * Marquer était un aller sans retour : ni `Delete`, ni annulation, ni règlement, et un second
 * marquage rend 409. Le coût n'était pas l'ergonomie — `GenerateurEReportingHandler` excluait de la
 * déclaration DGFiP toutes les ventes marquées, par un `findAll()` sans statut. Un chèque finalement
 * encaissé restait donc exclu POUR TOUJOURS : une recette réelle, recouvrée, que rien ne pouvait
 * faire redéclarer.
 *
 * ── ON RÈGLE, ON NE DÉMARQUE PAS ───────────────────────────────────────────────────────────────
 *
 * ⚠ LE MARQUAGE A EU LIEU ET RESTE VRAI. Le chèque est bien revenu impayé ce jour-là. L'effacer
 * réécrirait l'histoire d'une écriture auditée (`AuditWriteSubscriber` suit cette entité) ; le
 * régler la continue, et les deux gestes se relisent.
 *
 * ── POURQUOI CES RÈGLES VIVENT ICI ET NON DANS LE PROCESSEUR ───────────────────────────────────
 *
 * ⚠ CE SONT DES RÈGLES DE DOMAINE, PAS DES PRÉOCCUPATIONS HTTP — et je l'ai appris en butant :
 * `LecteurCorps` et `Security` sont `final`, donc immockables, et des gardes écrites dans le
 * processeur auraient été intestables. L'impossibilité de les tester n'était pas une contrainte
 * technique, c'était le symptôme d'un mauvais rangement.
 */
final class UnpaidSaleSettlement
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function settle(VenteImpayeeRegie $marquage, string $motif, ?Utilisateur $auteur): VenteImpayeeRegie
    {
        /*
         * ⚠ DEUX RÈGLEMENTS EFFACERAIENT LE PREMIER AUTEUR. La vente ne reviendrait pas deux fois
         * dans la déclaration — mais l'écran afficherait la seconde date et le second motif, et on
         * ne saurait plus qui a constaté l'encaissement. Le refus dit l'état plutôt que d'écraser.
         */
        if ($marquage->estReglee()) {
            throw new ConflictHttpException(sprintf(
                'Cette vente a déjà été réglée le %s (%s).',
                $marquage->getRegleLe()?->format('d/m/Y') ?? '—',
                $marquage->getMotifReglement() ?? '—',
            ));
        }

        $motif = trim($motif);
        if ($motif === '') {
            throw new UnprocessableEntityHttpException(
                '« motif » est requis : cette vente rentre dans la déclaration fiscale par un geste '
                . 'manuel, et le rapprochement se fera sur ce que vous écrivez ici.',
            );
        }

        $marquage->setRegleLe(new \DateTimeImmutable())
            ->setMotifReglement($motif)
            ->setRegleParUtilisateur($auteur);

        $this->em->flush();

        return $marquage;
    }
}
