<?php

declare(strict_types=1);

namespace App\Compta\State;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Securite\Service\ContexteEtablissement;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Platform\Nf525\Alert\SealAlertRecorder;
use App\Platform\Nf525\SealChainRef;
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
        private readonly ContexteEtablissement $contexte,
        // #370 — l'alerte NF525 ; null : aucune alerte (constructions manuelles).
        private readonly ?SealAlertRecorder $alerts = null,
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

        // ⚠ AUDIT DU 06/09, CONSTAT 5 : le journal venait de l'URL par `find()`, sans être confronté au
        //   périmètre — on vérifiait (et lisait) la chaîne comptable d'un autre exploitant. Il doit
        //   appartenir au profil qui couvre l'établissement actif ; sinon 404, pas 422, pour ne pas
        //   confirmer qu'il existe.
        $etablissementActif = $this->contexte->etablissementActif();
        if ($etablissementActif === null || $journal->getProfilExploitant()?->couvre($etablissementActif) !== true) {
            throw new NotFoundHttpException('Ressource introuvable.');
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
        // Vérification vivante (D-I), après le contrôle de périmètre ; la chaîne vient du journal résolu, jamais de la requête.
        $profil = $journal->getProfilExploitant();
        if ($profil !== null) {
            $this->alerts?->recordVerification(
                SealChainRef::forAccounting($profil, $journal),
                $rapport['intacte'],
                $rapport['intacte'] ? null : implode(' ; ', array_map(static fn (array $a): string => sprintf('n°%d : %s', $a['sequence'], $a['probleme']), \array_slice($rapport['anomalies'], 0, 5))),
            );
        }

        return new JsonResponse($rapport);
    }
}
