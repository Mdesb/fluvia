<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Audit\Service\JournalAudit;
use App\Caisse\Service\AgentPinPolicy;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `POST /utilisateurs/{id}/cash-pin {motDePasse, pin}` (#346) — le titulaire, et lui seul (`object ==
 * user`), définit ou change son code agent de caisse. Le mot de passe du compte est exigé : une session
 * restée ouverte sur un poste partagé ne doit pas suffire à poser le code de quelqu'un d'autre. Redéfinir
 * le code débloque un code bloqué (compteur à zéro).
 *
 * @implements ProcessorInterface<Utilisateur, Utilisateur>
 */
final class CashPinProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly JournalAudit $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Utilisateur
    {
        \assert($data instanceof Utilisateur);
        $corps = $this->lecteur->corps();

        $motDePasse = \is_string($corps['motDePasse'] ?? null) ? $corps['motDePasse'] : '';
        if ($motDePasse === '' || !$this->hasher->isPasswordValid($data, $motDePasse)) {
            throw new UnprocessableEntityHttpException('Mot de passe du compte incorrect.');
        }

        $pin = \is_string($corps['pin'] ?? null) ? trim($corps['pin']) : '';
        if (!AgentPinPolicy::isWellFormed($pin)) {
            throw new UnprocessableEntityHttpException('Le code agent doit compter 4 à 8 chiffres.');
        }

        $redefini = $data->isCashPinDefined();
        $data->setCashPinHash(AgentPinPolicy::hash($pin));
        $data->setCashPinFailures(0);

        $entree = $this->journal->enregistrer('cash_pin.set', Utilisateur::class, (string) $data->getId(), null);
        $entree->setValeurApres(['redefini' => $redefini]);

        $this->em->flush();

        return $data;
    }
}
