<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Securite\Entity\Utilisateur;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Enum\StatutEcheanceSepa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * UNE OFFRE SUR UN PRÉLÈVEMENT À VENIR — parrainage, geste commercial, mois offert.
 *
 * ── CE QU'ON POUVAIT FAIRE, ET CE QU'ON NE POUVAIT PAS ─────────────────────────────────────────
 *
 * `EcheanceSepa` n'exposait que `simuler-rejet` et `annuler` : on pouvait ANNULER un prélèvement,
 * jamais le RÉDUIRE. Et le montant d'une échéance n'avait qu'un seul écrivain, le générateur
 * d'échéancier, à la création. Un geste de 10 € obligeait donc à annuler tout le prélèvement du
 * mois — ou à ne rien faire.
 *
 * ── ⚠ RÉDUIRE UN MONTANT REPOUSSE LE PRÉLÈVEMENT, ET C'EST LE POINT LE MOINS ÉVIDENT ───────────
 *
 * `DebitPreNotifier::reasonNotCovered()` refuse un prélèvement dont le montant diffère de celui
 * annoncé : « préavis émis pour X €, prélèvement de Y € ». Le préavis sera donc réémis, et
 * `announce()` remet `sentAt` à l'instant courant — délibérément : « un montant qui change doit
 * rendre au client la totalité du délai ».
 *
 * Conséquence concrète : un geste commercial consenti trois jours avant l'échéance la décale de
 * deux semaines. C'est **correct** — on ne prélève pas un montant qu'on n'a pas annoncé — et
 * personne ne le devinerait.
 *
 * ⚠ LA DATE EST CALCULÉE PAR LE PROCESSEUR, PAS ICI. Elle sert à l'écrire à l'écran, et un appelant
 * programmatique — la récompense d'un parrainage, demain — n'en a que faire. La sortir d'ici laisse
 * ce handler avec sa seule dépendance utile, et le rend testable sans base : `DebitPreNotifier` est
 * `final`, donc immockable, et l'aurait rendu intestable.
 *
 * ── LE MOT « REMISE » EST INTERDIT ICI ─────────────────────────────────────────────────────────
 *
 * `EcheanceSepa` porte déjà `private ?RemiseSepa $remise` : la remise BANCAIRE, le lot envoyé à la
 * banque. Employer le même mot pour un rabais mettrait deux objets sans un champ en commun sous un
 * seul nom, dans le même fichier — le piège « terminal » que l'audit du 06/09 vient de nommer.
 */
final class ScheduledDebitReductionHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param int $reductionCentimes ce qu'on retire, strictement positif
     */
    public function reduce(
        EcheanceSepa $echeance,
        int $reductionCentimes,
        string $motif,
        ?Utilisateur $auteur,
    ): EcheanceSepa {
        $motif = trim($motif);
        if ($motif === '') {
            throw new UnprocessableEntityHttpException(
                '« motif » est requis : la seule question posée six mois plus tard, devant un relevé '
                . 'qui ne correspond pas au contrat, sera « pourquoi ».',
            );
        }

        /*
         * ⚠ `Gelee` EST NOMMÉE À PART, PARCE QUE CE N'EST PAS UN REFUS DÉFINITIF. Une échéance gelée
         * l'est par une pause d'abonnement, et elle REVIENDRA à la reprise. Dire « impossible » sans
         * le préciser enverrait chercher un défaut là où il n'y a qu'à attendre.
         */
        if ($echeance->getStatut() !== StatutEcheanceSepa::AVenir) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Seule une échéance à venir peut être réduite ; celle-ci est « %s ».%s',
                $echeance->getStatut()->value,
                $echeance->getStatut() === StatutEcheanceSepa::Gelee
                    ? ' Elle est suspendue par une pause et reviendra à la reprise : le geste sera possible alors.'
                    : '',
            ));
        }

        /*
         * ⚠ UNE SEULE RÉDUCTION PAR ÉCHÉANCE, ET LE REFUS DIT L'ÉTAT. La trace tient en trois champs
         * plats (motif, date, auteur) comme celle de l'annulation juste à côté ; en empiler deux y
         * effacerait la première. Un second geste sur le même mois se décide en connaissance de ce
         * qui a déjà été consenti — d'où le montant dans le message.
         */
        if ($echeance->getMontantInitialCentimes() !== null) {
            throw new ConflictHttpException(sprintf(
                'Cette échéance a déjà été réduite le %s (%s) : de %s € à %s €. Une seule réduction par échéance.',
                $echeance->getReductionAt()?->format('d/m/Y') ?? '—',
                $echeance->getReductionMotif() ?? '—',
                number_format($echeance->getMontantInitialCentimes() / 100, 2, ',', ' '),
                number_format($echeance->getMontantCentimes() / 100, 2, ',', ' '),
            ));
        }

        if ($reductionCentimes <= 0) {
            throw new UnprocessableEntityHttpException('Le montant de la réduction doit être strictement positif.');
        }

        /*
         * ⚠ RÉDUIRE À ZÉRO EST REFUSÉ, ET CE N'EST PAS UN SCRUPULE ARITHMÉTIQUE. `annuler` exprime
         * déjà « on ne prélève rien ce mois-ci », avec son propre statut et son propre motif. Une
         * échéance à 0,00 € encore « à venir » dirait la même chose d'une seconde façon : elle
         * partirait dans la remise bancaire, serait annoncée au client, et l'échéancier afficherait
         * un prélèvement là où il n'y en a aucun. Deux écritures pour un seul fait, c'est la
         * duplication qu'on retire ailleurs — on ne l'introduit pas ici.
         */
        $avant = $echeance->getMontantCentimes();
        if ($reductionCentimes >= $avant) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Réduction de %s € sur une échéance de %s € : il ne resterait rien à prélever. '
                . 'Pour ne rien prélever ce mois-ci, annulez l\'échéance — l\'échéancier lit '
                . '« annulée » et « 0,00 € » différemment.',
                number_format($reductionCentimes / 100, 2, ',', ' '),
                number_format($avant / 100, 2, ',', ' '),
            ));
        }

        $echeance->setMontantInitialCentimes($avant)
            ->setMontantCentimes($avant - $reductionCentimes)
            ->setReductionMotif($motif)
            ->setReductionAt(new \DateTimeImmutable())
            ->setReductionPar($auteur);

        $this->em->flush();

        return $echeance;
    }
}
