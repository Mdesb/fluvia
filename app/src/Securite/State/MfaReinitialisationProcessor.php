<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Audit\Service\JournalAudit;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `POST /utilisateurs/{id}/mfa/reinitialiser` (admin, `securite.gerer`) — §2.3 plan-backoffice.md,
 * cas limite spec §7 (codes de récupération épuisés + appareil perdu) : remet `mfaActif = false`,
 * purge `mfaSecret`/`mfaCodesRecuperation`. Action sensible tracée avant/après (RG-M8-05).
 *
 * @implements ProcessorInterface<Utilisateur, Utilisateur>
 */
final class MfaReinitialisationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAudit $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Utilisateur
    {
        \assert($data instanceof Utilisateur);

        $avant = ['mfaActif' => $data->isMfaActif()];

        $data->setMfaActif(false);
        $data->setMfaSecret(null);
        $data->setMfaCodesRecuperation(null);

        $entree = $this->journal->enregistrer('mfa.reinitialise', Utilisateur::class, (string) $data->getId(), null);
        $entree->setValeurAvant($avant);
        $entree->setValeurApres(['mfaActif' => false]);

        $this->em->flush();

        return $data;
    }
}
