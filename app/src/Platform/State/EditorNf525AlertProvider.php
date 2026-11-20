<?php

declare(strict_types=1);

namespace App\Platform\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Platform\ApiResource\EditorNf525Alert;
use App\Platform\Entity\Nf525Alert;
use App\Platform\Nf525\SealAlertStatus;
use App\Subscription\Security\EditorOnly;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture éditeur des alertes NF525 : toutes, de tous les établissements. Le cloisonnement est ici le
 * contrôle de tenant (`EditorOnly`), pas un périmètre d'établissement.
 *
 * @cloisonnement-verifie: l'alerte n'appartient à aucun établissement client : `EditorOnly::assertEditor('editor.nf525_alerts')`, première ligne, ne laisse passer que le tenant éditeur et le droit ; l'éditeur voit toutes les alertes, c'est le sens de la ressource.
 */
final class EditorNf525AlertProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|EditorNf525Alert
    {
        $this->editorOnly->assertEditor('editor.nf525_alerts');

        $qb = $this->em->getRepository(Nf525Alert::class)->createQueryBuilder('a');

        if (!$operation instanceof CollectionOperationInterface) {
            $alert = $this->find((string) ($uriVariables['id'] ?? ''));

            return self::view($alert);
        }

        $status = $context['filters']['status'] ?? null;
        if (\is_string($status) && '' !== $status) {
            $wanted = SealAlertStatus::tryFrom($status);
            if (null === $wanted) {
                return []; // valeur inconnue : rien ne correspond, on ne rend pas « tout »
            }
            $qb->andWhere('a.status = :status')->setParameter('status', $wanted);
        }

        $qb->addSelect('CASE WHEN a.status = :open THEN 0 ELSE 1 END AS HIDDEN openRank')
            ->setParameter('open', SealAlertStatus::Open)
            ->addOrderBy('openRank', 'ASC')
            ->addOrderBy('a.lastEventAt', 'DESC');

        return array_map(self::view(...), $qb->getQuery()->getResult());
    }

    public function find(string $id): Nf525Alert
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }
        $alert = $this->em->getRepository(Nf525Alert::class)->find(Uuid::fromString($id));
        if (!$alert instanceof Nf525Alert) {
            throw new NotFoundHttpException();
        }

        return $alert;
    }

    public static function view(Nf525Alert $alert): EditorNf525Alert
    {
        $view = new EditorNf525Alert();
        $view->id = $alert->getId()->toRfc4122();
        $view->reference = $alert->getReference();
        $view->chainType = $alert->getChainType()->value;
        $view->chainLabel = $alert->getChainLabel();
        $view->cause = $alert->getCause()->value;
        $view->causeLabel = Nf525AlertViewProvider::CAUSE_LABELS[$alert->getCause()->value] ?? 'Chaîne fiscale bloquée';
        $view->status = $alert->getStatus()->value;
        $view->origin = $alert->getOrigin()->value;
        $view->firstEventAt = $alert->getFirstEventAt()->format(\DATE_ATOM);
        $view->lastEventAt = $alert->getLastEventAt()->format(\DATE_ATOM);
        $view->refusalCount = $alert->getRefusalCount();
        $view->detectionCount = $alert->getDetectionCount();
        $view->resolvedAt = $alert->getResolvedAt()?->format(\DATE_ATOM);
        $view->technicalDetail = $alert->getTechnicalDetail();
        $names = [];
        foreach ($alert->getEstablishments() as $establishment) {
            $names[] = $establishment->getNom();
        }
        sort($names);
        $view->establishmentNames = $names;
        $view->note = $alert->getNote();
        $view->noteAt = $alert->getNoteAt()?->format(\DATE_ATOM);
        $view->noteBy = $alert->getNoteBy();

        return $view;
    }
}
