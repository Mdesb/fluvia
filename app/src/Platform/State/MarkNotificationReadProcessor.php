<?php

declare(strict_types=1);

namespace App\Platform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Platform\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /notifications/{id}/lue` — marquer une notification lue.
 *
 * L'objet est chargé par API Platform, donc déjà passé par
 * {@see \App\Platform\Doctrine\NotificationScopeExtension} : la notification d'un collègue rend 404,
 * pas 403. C'est voulu — répondre « interdit » confirmerait qu'elle existe.
 *
 * @implements ProcessorInterface<Notification, Notification>
 */
final class MarkNotificationReadProcessor implements ProcessorInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Notification
    {
        // ⚠ PAS D'`assert()`. En production, `zend.assertions` est à -1 : l'assertion disparaît, et un
        // `$data` nul passerait jusqu'à une erreur de typage — un 500 là où la réponse juste est 404.
        if (!$data instanceof Notification) {
            throw new NotFoundHttpException('Cette notification n’existe pas, ou ne vous est pas adressée.');
        }

        $data->marquerLue(new \DateTimeImmutable());
        $this->em->flush();

        return $data;
    }
}
