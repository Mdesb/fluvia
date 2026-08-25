<?php

declare(strict_types=1);

namespace App\RevenueRecovery\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\RevenueRecovery\Entity\RecoverySequence;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /revenue-recovery/sequences` + `PATCH /revenue-recovery/sequences/{id}` (RG-RR-01,
 * plan-revenue-recovery.md §2, patron `App\Ocr\State\OcrProviderConfigProcessor`).
 *
 * `establishment` **toujours** dérivé côté serveur (`ContexteEtablissement::etablissementActif()`,
 * invariant noyau commun #1) — jamais du corps de la requête, la propriété n'appartient à aucun groupe
 * d'écriture. `POST` (création par corps brut, hors du filet de `RevenueRecoveryScopeExtension`, §0.6
 * pt.2 du plan) revérifie explicitement l'unicité `(establishment, triggerType)` avant persistance — 409
 * si une séquence existe déjà (RG-RR-01), jamais un doublon silencieux. `PATCH` (entité déjà résolue et
 * filtrée par le provider standard) revérifie tout de même l'établissement en défense en profondeur
 * (RG-RR-07, échec fermé 404, jamais 403).
 *
 * @implements ProcessorInterface<RecoverySequence, RecoverySequence>
 */
final class RecoverySequenceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexteEtablissement,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RecoverySequence
    {
        \assert($data instanceof RecoverySequence);

        $etablissement = $this->contexteEtablissement->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('revenue_recovery.error.no_active_establishment');
        }

        if ($operation instanceof Post) {
            $existante = $this->em->getRepository(RecoverySequence::class)->findOneBy([
                'establishment' => $etablissement,
                'triggerType' => $data->getTriggerType(),
            ]);
            if ($existante !== null) {
                // RG-RR-01 : une séquence par (établissement, déclencheur) — geste attendu côté client
                // est un PATCH sur la ressource existante, pas un doublon silencieux.
                throw new ConflictHttpException('revenue_recovery.error.sequence_already_exists');
            }
            $data->setEstablishment($etablissement);
        } else {
            // PATCH : périmètre serveur revérifié explicitement (échec fermé) avant toute écriture,
            // défense en profondeur même si l'entité a déjà été filtrée par `RevenueRecoveryScopeExtension`
            // côté lecture (RG-RR-07).
            $etablissementEntite = $data->getEstablishment();
            if ($etablissementEntite === null || (string) $etablissementEntite->getId() !== (string) $etablissement->getId()) {
                throw new NotFoundHttpException();
            }
        }

        $data->touchUpdatedAt();
        $this->em->persist($data);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Course POST/POST concurrente sur le même (établissement, déclencheur) : la contrainte
            // unique tranche en base — 409 attendu plutôt qu'une 500 non gérée.
            throw new ConflictHttpException('revenue_recovery.error.sequence_already_exists', $e);
        }

        return $data;
    }
}
