<?php

declare(strict_types=1);

namespace App\Caisse\Service;

use App\Audit\Service\JournalAudit;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\LockedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Exige le code agent de caisse d'une personne (#346) : à l'ouverture d'une session (l'opérateur) et à
 * la réouverture d'une caisse sécurisée (le régisseur). Refuse en 422 (absent, faux, jamais défini) ou
 * en 423 (bloqué), et trace chaque refus au journal d'audit.
 *
 * ⚠ UN ÉCHEC SE PERSISTE AVANT LE REFUS. L'exception interrompt la requête : sans `flush()` ici, le
 * compteur d'échecs ne survivrait pas à la réponse, et le blocage ne se déclencherait jamais. Les
 * appelants l'invoquent donc AVANT toute autre écriture, pour ne rien emporter d'autre dans ce flush.
 */
final class AgentPinChecker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAudit $journal,
    ) {
    }

    /**
     * @param string $action        le geste qui exige le code, écrit au journal (ex. « cash_session.open »)
     * @param string $missingMessage le refus quand aucun code n'est saisi
     */
    public function assertValid(Utilisateur $agent, mixed $pin, string $action, ?Uuid $etablissement, string $missingMessage): void
    {
        $saisi = \is_string($pin) ? trim($pin) : '';
        if ($saisi === '') {
            throw new UnprocessableEntityHttpException($missingMessage);
        }

        $issue = AgentPinPolicy::check($agent, $saisi);
        if ($issue === AgentPinOutcome::Accepted) {
            return;
        }

        $entree = $this->journal->enregistrer('cash_pin.refused', Utilisateur::class, (string) $agent->getId(), $etablissement);
        $entree->setValeurApres([
            'action' => $action,
            'issue' => $issue->value,
            'echecsConsecutifs' => $agent->getCashPinFailures(),
        ]);
        $this->em->flush();

        $nom = $agent->getNom() !== '' ? $agent->getNom() : $agent->getEmail();
        throw match ($issue) {
            AgentPinOutcome::Undefined => new UnprocessableEntityHttpException(sprintf(
                'Aucun code agent n\'est défini pour %s : il se définit à l\'ouverture de caisse, avec le mot de passe du compte.',
                $nom,
            )),
            AgentPinOutcome::Locked => new LockedHttpException(sprintf(
                'Code agent de %s bloqué après %d erreurs : redéfinissez-le avec le mot de passe du compte, ou demandez à un administrateur de l\'effacer.',
                $nom,
                AgentPinPolicy::MAX_FAILURES,
            )),
            default => new UnprocessableEntityHttpException(sprintf(
                'Code agent incorrect. Encore %d essai(s) avant blocage.',
                AgentPinPolicy::remainingAttempts($agent),
            )),
        };
    }
}
