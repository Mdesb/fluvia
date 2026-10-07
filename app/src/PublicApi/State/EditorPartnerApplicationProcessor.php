<?php

declare(strict_types=1);

namespace App\PublicApi\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\PublicApi\ApiResource\EditorPartnerApplication;
use App\PublicApi\Entity\ApiCredential;
use App\PublicApi\Entity\PartnerApplication;
use App\PublicApi\Service\PartnerAccessManager;
use App\Securite\Entity\Utilisateur;
use App\Subscription\Security\EditorOnly;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Les quatre gestes de l'éditeur : créer une application, la désactiver, émettre une clé, révoquer une clé.
 *
 * @cloisonnement-verifie : ni `PartnerApplication` ni `ApiCredential` n'appartiennent à un établissement
 * client — une application sert plusieurs clients, c'est tout son objet (voir `PartnerApplication`).
 * `EditorOnly::assertEditor()`, appelé avant toute lecture, restreint l'appelant au tenant éditeur (404,
 * pas 403). Il n'y a donc pas d'établissement auquel comparer les entités résolues ici.
 */
final class EditorPartnerApplicationProcessor implements ProcessorInterface
{
    public const CREATE = 'editor_partner_application_create';
    public const DEACTIVATE = 'editor_partner_application_deactivate';
    public const ISSUE = 'editor_partner_credential_issue';
    public const REVOKE = 'editor_partner_credential_revoke';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly PartnerAccessManager $manager,
        private readonly EditorPartnerApplicationProvider $provider,
        private readonly LecteurCorps $body,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EditorPartnerApplication
    {
        $this->editorOnly->assertEditor(EditorPartnerApplicationProvider::PERMISSION);

        $body = $this->body->corps();

        switch ($operation->getName()) {
            case self::CREATE:
                return $this->provider->view($this->manager->createApplication(
                    \is_string($body['name'] ?? null) ? $body['name'] : '',
                    \is_string($body['contactEmail'] ?? null) ? $body['contactEmail'] : '',
                ));

            case self::DEACTIVATE:
                $application = $this->find(PartnerApplication::class, $uriVariables['id'] ?? null);
                $this->manager->deactivateApplication($application);

                return $this->provider->view($application);

            case self::ISSUE:
                $application = $this->find(PartnerApplication::class, $uriVariables['id'] ?? null);
                $issued = $this->manager->issueCredential($application, $this->expiresAt($body['expiresAt'] ?? null));

                $view = $this->provider->view($application);
                $view->issuedSecret = $issued['secret'];
                $view->issuedCredentialId = (string) $issued['credential']->getId();

                return $view;

            case self::REVOKE:
                $credential = $this->find(ApiCredential::class, $uriVariables['id'] ?? null);
                $user = $this->security->getUser();
                $this->manager->revokeCredential($credential, $user instanceof Utilisateur ? $user : null);
                $application = $credential->getApplication();
                if (!$application instanceof PartnerApplication) {
                    throw new NotFoundHttpException();
                }

                return $this->provider->view($application);
        }

        throw new NotFoundHttpException();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, mixed $reference): object
    {
        if (!\is_string($reference) || !Uuid::isValid($reference)) {
            throw new NotFoundHttpException();
        }

        $entity = $this->em->getRepository($class)->find(Uuid::fromString($reference));
        if (!$entity instanceof $class) {
            throw new NotFoundHttpException();
        }

        return $entity;
    }

    private function expiresAt(mixed $raw): ?\DateTimeImmutable
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        $date = \is_string($raw) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $raw) : false;
        if (false === $date) {
            $date = \is_string($raw) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw) : false;
        }
        if (false === $date) {
            throw new UnprocessableEntityHttpException('Date d’expiration illisible : attendu AAAA-MM-JJ ou une date ISO 8601.');
        }

        return $date;
    }
}
