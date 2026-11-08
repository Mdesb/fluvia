<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Audit\Service\JournalAudit;
use App\Crm\Enum\PrivacyRequestOrigin;
use App\Crm\Security\CustomerGroupGuard;
use App\Crm\Service\EffacementRgpdHandler;
use App\Crm\Service\PurgeCandidateFinder;
use App\Crm\Service\PurgeRequestReader;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /rgpd/purge (#344) : anonymise les fiches COCHÉES dans l'aperçu.
 *
 * Corps : `{ inactiveMonths, createdBefore?, clientIds: [uuid…], confirm: true }`.
 *
 * ── CE QUE LE SERVEUR NE CROIT PAS ─────────────────────────────────────────────────────────────
 *
 * La liste `clientIds` vient du navigateur. Chaque identifiant est re-vérifié contre les MÊMES
 * critères que l'aperçu, dans la collectivité de l'établissement actif : une fiche d'une autre
 * collectivité, une fiche redevenue active depuis l'aperçu, une fiche qui a pris un abonnement entre
 * deux clics — toutes sont ignorées, et comptées comme telles dans la réponse. La sélection ne peut
 * que RESTREINDRE l'épuration, jamais l'étendre.
 *
 * `confirm: true` est exigé : un appel qui oublie ce drapeau n'efface rien. Le geste est définitif.
 *
 * Plafond : `MAX_SELECTION` fiches par appel — au-delà, l'écran enchaîne les lots. Chaque fiche
 * anonymisée laisse une demande réalisée d'origine `purge` signée de l'administrateur, et une entrée
 * au journal d'audit.
 *
 * @cloisonnement-verifie : aucune résolution par `find()` — chaque identifiant passe par
 * `PurgeCandidateFinder::candidates()`, dont la requête pose le groupe de `CustomerGroupGuard`.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class ApplyPersonalDataPurgeProcessor implements ProcessorInterface
{
    public const MAX_SELECTION = 500;

    public function __construct(
        private readonly CustomerGroupGuard $guard,
        private readonly PurgeCandidateFinder $finder,
        private readonly PurgeRequestReader $reader,
        private readonly EffacementRgpdHandler $handler,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly JournalAudit $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $groupe = $this->guard->activeGroup();
        $corps = $this->reader->body();
        [$seuil, $createdBefore] = $this->reader->criteria($corps);

        if (($corps['confirm'] ?? null) !== true) {
            throw new UnprocessableEntityHttpException("confirm : l'épuration est définitive, elle doit être confirmée explicitement.");
        }
        $ids = $corps['clientIds'] ?? null;
        if (!\is_array($ids) || $ids === []) {
            throw new UnprocessableEntityHttpException('clientIds : cochez au moins une fiche dans l\'aperçu.');
        }
        if (\count($ids) > self::MAX_SELECTION) {
            throw new UnprocessableEntityHttpException(sprintf('clientIds : %d fiches au plus par lot.', self::MAX_SELECTION));
        }

        $administrateur = $this->security->getUser();
        \assert($administrateur instanceof Utilisateur);

        $retenus = $this->finder->candidates($groupe, $seuil, $createdBefore, self::MAX_SELECTION, array_values($ids));
        foreach ($retenus as $client) {
            $this->handler->anonymiseWithoutRequest($client, PrivacyRequestOrigin::Purge, $administrateur);
            $this->journal->enregistrer('crm.rgpd.epuration', 'Client', (string) $client->getId());
        }
        $this->em->flush();

        return new JsonResponse([
            'requested' => \count($ids),
            'anonymised' => \count($retenus),
            'ignored' => \count($ids) - \count($retenus),
        ]);
    }
}
