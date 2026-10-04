<?php

declare(strict_types=1);

namespace App\PublicApi\Service;

use App\Audit\Service\JournalAudit;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Service\EditorTenantResolver;
use App\PublicApi\Entity\ApiCredential;
use App\PublicApi\Entity\ApiGrant;
use App\PublicApi\Entity\PartnerApplication;
use App\PublicApi\Enum\ApiScope;
use App\PublicApi\Enum\CredentialStatus;
use App\PublicApi\Enum\GrantStatus;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Les gestes qui rendent une clé réelle (spec API partenaire v1, §3.1) — et la trace de chacun.
 *
 * ⚠ **CE SERVICE NE VÉRIFIE PAS QUI APPELLE.** Les gestes de l'éditeur passent par
 * `EditorOnly::assertEditor()`, ceux de l'exploitant par la permission `api.gerer` ET l'établissement
 * actif validé de la session. Ici, on fait le geste et on l'écrit au journal ; l'établissement reçu
 * est déjà celui que l'appelant a le droit de toucher.
 *
 * ⚠ **CHAQUE GESTE ÉCRIT SON ENTRÉE D'AUDIT DANS LA MÊME TRANSACTION.** Une clé émise sans trace est
 * exactement ce que ce lot répare : jusqu'au 04/10, tout se faisait en base, et rien ne disait qui avait
 * ouvert quoi. Les gestes de l'éditeur sont rattachés à l'éditeur ; ceux de l'exploitant à SON
 * établissement, qui doit pouvoir relire qui a ouvert ses données à un tiers.
 */
final class PartnerAccessManager
{
    public const ACTION_APPLICATION_CREATED = 'public_api.application.created';
    public const ACTION_APPLICATION_DEACTIVATED = 'public_api.application.deactivated';
    public const ACTION_CREDENTIAL_ISSUED = 'public_api.credential.issued';
    public const ACTION_CREDENTIAL_REVOKED = 'public_api.credential.revoked';
    public const ACTION_GRANT_GRANTED = 'public_api.grant.granted';
    public const ACTION_GRANT_WITHDRAWN = 'public_api.grant.withdrawn';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAudit $audit,
        private readonly EditorTenantResolver $editorTenant,
        private readonly ApiCredentialFactory $factory,
    ) {
    }

    public function createApplication(string $name, string $contactEmail): PartnerApplication
    {
        $name = trim($name);
        $contactEmail = trim($contactEmail);

        if ('' === $name || mb_strlen($name) > 120) {
            throw new UnprocessableEntityHttpException('Le nom de l’application est obligatoire (120 caractères au plus).');
        }
        if (false === filter_var($contactEmail, \FILTER_VALIDATE_EMAIL) || mb_strlen($contactEmail) > 180) {
            throw new UnprocessableEntityHttpException('Indiquez une adresse e-mail de contact valide : c’est à elle qu’on écrit quand une clé fuit.');
        }

        $application = (new PartnerApplication())->setName($name)->setContactEmail($contactEmail);
        $this->em->persist($application);
        $this->audit->enregistrer(self::ACTION_APPLICATION_CREATED, 'PartnerApplication', (string) $application->getId(), $this->editorTenant->resolveId());
        $this->em->flush();

        return $application;
    }

    /**
     * Désactiver coupe toutes ses clés d'un coup (l'authentificateur refuse une application inactive),
     * sans révoquer : la trace de chaque clé reste lisible. Désactiver deux fois ne lève pas.
     */
    public function deactivateApplication(PartnerApplication $application): void
    {
        if (!$application->isActive()) {
            return;
        }

        $application->setActive(false);
        $this->audit->enregistrer(self::ACTION_APPLICATION_DEACTIVATED, 'PartnerApplication', (string) $application->getId(), $this->editorTenant->resolveId());
        $this->em->flush();
    }

    /**
     * @return array{credential: ApiCredential, secret: string} le secret n'existe que dans ce retour
     */
    public function issueCredential(PartnerApplication $application, ?\DateTimeImmutable $expiresAt): array
    {
        if (!$application->isActive()) {
            throw new UnprocessableEntityHttpException('Cette application est désactivée : une clé émise pour elle serait refusée à chaque appel.');
        }
        if (null !== $expiresAt && $expiresAt <= new \DateTimeImmutable()) {
            throw new UnprocessableEntityHttpException('La date d’expiration doit être dans le futur.');
        }

        // La fabrique flushe elle-même : la transaction englobante garde la clé et sa trace ensemble.
        return $this->em->wrapInTransaction(function () use ($application, $expiresAt): array {
            $issued = $this->factory->issue($application, $expiresAt);
            $this->audit->enregistrer(self::ACTION_CREDENTIAL_ISSUED, 'ApiCredential', (string) $issued['credential']->getId(), $this->editorTenant->resolveId())
                ->setValeurApres(['prefix' => $issued['credential']->getPrefix(), 'application' => (string) $application->getId()]);
            $this->em->flush();

            return $issued;
        });
    }

    /** Révoquer deux fois ne lève pas : la première date l'emporte, c'est elle qui dit quand la porte s'est fermée. */
    public function revokeCredential(ApiCredential $credential, ?Utilisateur $by): void
    {
        if (CredentialStatus::Revoked === $credential->getStatus()) {
            return;
        }

        $credential->setStatus(CredentialStatus::Revoked)
            ->setRevokedAt(new \DateTimeImmutable())
            ->setRevokedBy($by);
        $this->audit->enregistrer(self::ACTION_CREDENTIAL_REVOKED, 'ApiCredential', (string) $credential->getId(), $this->editorTenant->resolveId())
            ->setValeurApres(['prefix' => $credential->getPrefix()]);
        $this->em->flush();
    }

    /**
     * Accorder des portées sur un établissement.
     *
     * ⚠ **MODIFIER LES PORTÉES = RETIRER L'ACCORD EN COURS (tracé `withdrawn`), PUIS EN CRÉER UN NEUF.** Le retrait est
     * absorbant ({@see GrantStatus}) : on ne réécrit jamais les portées d'un consentement, on en date un
     * nouveau. L'historique dit ainsi, ligne par ligne, ce qui a été ouvert et quand.
     *
     * @param list<ApiScope> $scopes
     */
    public function grant(PartnerApplication $application, Etablissement $establishment, array $scopes, ?Utilisateur $by): ApiGrant
    {
        if ([] === $scopes) {
            throw new UnprocessableEntityHttpException('Cochez au moins une portée. Pour couper l’accès, retirez l’accord.');
        }
        foreach ($scopes as $scope) {
            if (!$scope->isGrantable()) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'La portée « %s » ne s’accorde pas encore : aucune ressource ne la sert, et un accord donné aujourd’hui ouvrirait la donnée plus tard sans nouvel accord.',
                    $scope->value,
                ));
            }
        }
        if (!$application->isActive()) {
            throw new UnprocessableEntityHttpException('Cette application est désactivée par l’éditeur.');
        }

        // L'accord remplacé est RETIRÉ, et le journal le dit avec ses anciennes portées : sans cette
        // trace, une réduction de portées ne se lirait que par différence entre deux accords.
        $this->auditWithdrawn($application, $establishment, $this->closeActiveGrants($application, $establishment));

        $grant = (new ApiGrant())
            ->setApplication($application)
            ->setEtablissement($establishment)
            ->setScopes(array_values(array_unique($scopes, \SORT_REGULAR)))
            ->setGrantedBy($by);
        $this->em->persist($grant);
        $this->audit->enregistrer(self::ACTION_GRANT_GRANTED, 'ApiGrant', (string) $grant->getId(), $establishment->getId())
            ->setValeurApres(['application' => (string) $application->getId(), 'scopes' => $grant->getScopes()]);
        $this->em->flush();

        return $grant;
    }

    /** Retirer l'accord d'un établissement. Sans accord en cours, rien n'est écrit — ni geste, ni trace. */
    public function withdraw(PartnerApplication $application, Etablissement $establishment): void
    {
        $this->auditWithdrawn($application, $establishment, $this->closeActiveGrants($application, $establishment));
        $this->em->flush();
    }

    /** @param list<ApiGrant> $grants */
    private function auditWithdrawn(PartnerApplication $application, Etablissement $establishment, array $grants): void
    {
        foreach ($grants as $grant) {
            $this->audit->enregistrer(self::ACTION_GRANT_WITHDRAWN, 'ApiGrant', (string) $grant->getId(), $establishment->getId())
                ->setValeurApres(['application' => (string) $application->getId(), 'scopes' => $grant->getScopes()]);
        }
    }

    /** @return list<ApiGrant> les accords qui viennent d'être fermés */
    private function closeActiveGrants(PartnerApplication $application, Etablissement $establishment): array
    {
        /** @var list<ApiGrant> $active */
        $active = $this->em->getRepository(ApiGrant::class)->findBy([
            'application' => $application,
            'etablissement' => $establishment,
            'status' => GrantStatus::Active,
        ]);

        foreach ($active as $grant) {
            $grant->setStatus(GrantStatus::Revoked)->setRevokedAt(new \DateTimeImmutable());
        }

        return $active;
    }
}
