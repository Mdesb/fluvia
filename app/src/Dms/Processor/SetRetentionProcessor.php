<?php

declare(strict_types=1);

namespace App\Dms\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dms\Entity\Document;
use App\Dms\Entity\RetentionPolicy;
use App\Dms\Security\DmsScopeGuard;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /documents/{id}/retention` (corps JSON, RG-DMS-16) : attache/prolonge/lève une politique de
 * rétention. `retentionPolicyCode: null` + `retainUntilOverride: null` = lever la politique (cas
 * exceptionnel encadré). Corps lu via `App\Vente\Service\LecteurCorps` — utilitaire générique déjà
 * réutilisé par plusieurs modules (Finance, Devis...) pour le JSON manuel, aucune modification du
 * module `App\Vente` lui-même.
 *
 * @implements ProcessorInterface<Document, Document>
 */
final class SetRetentionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly DmsScopeGuard $scopeGuard,
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly EventBus $eventBus,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Document
    {
        \assert($data instanceof Document);
        $etablissement = $this->scopeGuard->verify($data->getEstablishment());
        \assert($etablissement instanceof Etablissement);

        $corps = $this->lecteur->corps();
        $code = $corps['retentionPolicyCode'] ?? null;
        $overrideRaw = $corps['retainUntilOverride'] ?? null;

        if ($code === null && $overrideRaw === null) {
            // Lever la politique — cas exceptionnel très encadré (RG-DMS-16), réservé à dms.manage_retention.
            $data->setRetentionPolicy(null);
            $data->setRetainUntil(null);
        } elseif (\is_string($overrideRaw) && $overrideRaw !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $overrideRaw);
            if ($date === false) {
                throw new UnprocessableEntityHttpException('dms.error.retain_until_invalid');
            }
            if (\is_string($code) && $code !== '') {
                $data->setRetentionPolicy($this->resolvePolicy($code));
            }
            $data->setRetainUntil($date);
        } elseif (\is_string($code) && $code !== '') {
            $politique = $this->resolvePolicy($code);
            $data->setRetentionPolicy($politique);
            $data->setRetainUntil(
                (new \DateTimeImmutable('today'))->modify(sprintf('+%d months', $politique->getDurationMonths())),
            );
        } else {
            throw new UnprocessableEntityHttpException('dms.error.retention_input_invalid');
        }

        $data->touch();
        $this->em->flush();

        $acteur = $this->security->getUser();
        $this->eventBus->publish(new DomainEvent(
            'document.retention_set',
            new EventTenant($etablissement->getId()),
            new EventSubject('Document', (string) $data->getId()),
            [
                'retentionPolicyCode' => $data->getRetentionPolicy()?->getCode(),
                'retainUntil' => $data->getRetainUntil()?->format('Y-m-d'),
            ],
            $acteur instanceof Utilisateur ? new EventActor($acteur->getId()) : null,
        ));

        return $data;
    }

    private function resolvePolicy(string $code): RetentionPolicy
    {
        $politique = $this->em->getRepository(RetentionPolicy::class)->findOneBy(['code' => $code]);
        if (!$politique instanceof RetentionPolicy) {
            throw new UnprocessableEntityHttpException('dms.error.retention_policy_not_found');
        }

        return $politique;
    }
}
