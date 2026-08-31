<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * GET /compta/ecritures/verifier-chaine?journal=... (CA-13) : recalcule la chaîne NF525 d'un journal
 * et détecte les ruptures.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class VerifierChaineEcritureProcessor implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScellementEcritureHandler $scellement,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        // ⚠ UNE ABSENCE DE PERIMETRE N'EST PAS UNE CHAINE INTACTE.
        //
        // Ces deux retours rendaient `['intacte' => true, 'nbOperations' => 0]` — donc appeler ce
        // point SANS AUCUN PARAMETRE repondait « tout va bien ». C'est la reponse qu'un controleur
        // lirait, et elle serait fausse : on n'a rien verifie du tout.
        $journalId = $this->requestStack->getCurrentRequest()?->query->get('journal');
        if (!\is_string($journalId) || !Uuid::isValid($journalId)) {
            throw new UnprocessableEntityHttpException(
                'La chaine n\'a PAS ete verifiee : precisez le journal a controler (`?journal=<uuid>`).'
            );
        }

        $journal = $this->em->getRepository(Journal::class)->find(Uuid::fromString($journalId));
        if ($journal === null) {
            throw new UnprocessableEntityHttpException(
                'La chaine n\'a PAS ete verifiee : ce journal n\'existe pas.'
            );
        }

        // ⚠ SEULES LES ECRITURES SCELLEES SONT DANS LA CHAINE — une ecriture non scellee porte
        // `numeroSequence = 0` et decalerait toute la sequence, produisant des anomalies sur une
        // chaine saine.
        $ecritures = $this->em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.journal) = :journal')
            ->andWhere('e.numeroSequence > 0')
            ->setParameter('journal', $journal->getId(), 'uuid')
            ->orderBy('e.numeroSequence', 'ASC')
            ->getQuery()
            ->getResult();
        $rapport = $this->scellement->verifieChaine($ecritures);

        return new JsonResponse($rapport);
    }
}
