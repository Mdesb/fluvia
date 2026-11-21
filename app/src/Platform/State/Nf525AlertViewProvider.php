<?php

declare(strict_types=1);

namespace App\Platform\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Platform\ApiResource\Nf525AlertView;
use App\Platform\Entity\Nf525Alert;
use App\Platform\Nf525\SealAlertStatus;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /nf525-alerts` et `GET /nf525-alerts/{id}` : les alertes de l'établissement actif.
 *
 * **Cloisonnement** : `EXISTS` sur la liaison `platform_nf525_alert_establishment`. Pas d'établissement
 * actif : liste vide (échec fermé, comme `NotificationScopeExtension`). `{id}` d'un autre établissement :
 * 404, jamais 403 (un 403 confirmerait l'existence). L'en-tête est déjà confronté au périmètre de
 * l'appelant par `EstablishmentHeaderListener`.
 *
 * **Tri** : ouvertes d'abord, puis les plus récentes d'abord.
 *
 * @implements ProviderInterface<Nf525AlertView>
 */
final class Nf525AlertViewProvider implements ProviderInterface
{
    public const CAUSE_LABELS = [
        'invalid_signature' => 'Signature de scellement invalide',
        'unknown_key' => 'Clé de scellement inconnue',
        'key_regression' => 'Retour en arrière de la clé de scellement',
        'unreadable_order' => 'Ordre de scellement illisible',
        'chain_broken' => 'Chaîne rompue détectée à la vérification',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<Nf525AlertView>|Nf525AlertView
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|Nf525AlertView
    {
        $collection = $operation instanceof CollectionOperationInterface;
        $establishment = $this->contexte->etablissementActif();

        if ($establishment === null) {
            if ($collection) {
                return [];
            }
            throw new NotFoundHttpException();
        }

        $qb = $this->em->getRepository(Nf525Alert::class)->createQueryBuilder('a')
            ->andWhere(
                'EXISTS (SELECT 1 FROM '.Nf525Alert::class.' scoped JOIN scoped.establishments e '
                .'WHERE scoped = a AND e = :establishment)',
            )
            ->setParameter('establishment', $establishment->getId(), 'uuid');

        if (!$collection) {
            $id = (string) ($uriVariables['id'] ?? '');
            if (!Uuid::isValid($id)) {
                throw new NotFoundHttpException();
            }
            $alert = $qb->andWhere('a.id = :id')->setParameter('id', Uuid::fromString($id), 'uuid')
                ->getQuery()->getOneOrNullResult();
            if (!$alert instanceof Nf525Alert) {
                throw new NotFoundHttpException();
            }

            return self::view($alert);
        }

        $status = $context['filters']['status'] ?? null;
        if (\is_string($status) && $status !== '') {
            $wanted = SealAlertStatus::tryFrom($status);
            if ($wanted === null) {
                return []; // valeur inconnue : rien ne correspond, on ne rend pas « tout »
            }
            $qb->andWhere('a.status = :status')->setParameter('status', $wanted);
        }

        $this->order($qb);

        /** @var list<Nf525Alert> $alerts */
        $alerts = $qb->getQuery()->getResult();

        return array_map(self::view(...), $alerts);
    }

    private function order(QueryBuilder $qb): void
    {
        $qb->addSelect('CASE WHEN a.status = :open THEN 0 ELSE 1 END AS HIDDEN openRank')
            ->setParameter('open', SealAlertStatus::Open)
            ->addOrderBy('openRank', 'ASC')
            ->addOrderBy('a.lastEventAt', 'DESC');
    }

    private static function view(Nf525Alert $alert): Nf525AlertView
    {
        $view = new Nf525AlertView();
        $view->id = $alert->getId()->toRfc4122();
        $view->reference = $alert->getReference();
        $view->chainType = $alert->getChainType()->value;
        $view->chainLabel = $alert->getChainLabel();
        $view->cause = $alert->getCause()->value;
        $view->causeLabel = self::CAUSE_LABELS[$alert->getCause()->value] ?? 'Chaîne fiscale bloquée';
        $view->status = $alert->getStatus()->value;
        $view->firstEventAt = $alert->getFirstEventAt()->format(\DATE_ATOM);
        $view->lastEventAt = $alert->getLastEventAt()->format(\DATE_ATOM);
        $view->refusalCount = $alert->getRefusalCount();
        $view->resolvedAt = $alert->getResolvedAt()?->format(\DATE_ATOM);

        return $view;
    }
}
