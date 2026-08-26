<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Subscription\ApiResource\EditorSupportAccess;
use App\Subscription\Security\EditorOnly;
use App\Subscription\Service\SupportAccessGuard;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /editor/support-accesses` — ouvrir un accès d'assistance sur l'établissement d'un client.
 *
 * **La durée est bornée ici, pas seulement dans l'entité.** `expiresAt` est obligatoire en base, ce
 * qui garantit qu'un accès a toujours un terme ; cette borne-ci garantit que le terme est *court*. Un
 * accès de six mois respecte la contrainte de schéma et vide la règle de son sens.
 *
 * **L'agent peut s'accorder l'accès à lui-même, et c'est un choix.** Exiger un second intervenant
 * serait meilleur en principe et pire en pratique : à deux heures du matin, personne n'est là pour
 * valider, et ce qui se passerait alors est ce que ce lot cherche à empêcher — une affectation
 * permanente posée à la main. La garantie ne vient donc pas de la séparation des rôles mais de la
 * **trace** : qui, chez qui, pourquoi, jusqu'à quand, et le journal d'audit qui enregistre l'ouverture
 * comme l'usage.
 *
 * **Le motif n'est pas décoratif.** `SupportAccessGuard::grant()` refuse un motif vide ; on le
 * revalide ici pour rendre un 422 lisible plutôt qu'une exception de domaine, mais la règle vit dans
 * le garde — un contrôle recopié finit par diverger de celui qui fait foi.
 */
final class GrantSupportAccessProcessor implements ProcessorInterface
{
    /**
     * La fenêtre la plus longue qu'on accepte d'ouvrir d'un coup.
     *
     * Huit heures : la durée d'une intervention, pas celle d'un accès de confort. Au-delà, on rouvre —
     * et rouvrir laisse une seconde trace, ce qui est exactement l'information qu'on veut avoir.
     */
    public const MAX_HOURS = 8;

    public const DEFAULT_HOURS = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly SupportAccessGuard $guard,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EditorSupportAccess
    {
        $this->editorOnly->assertEditor();

        $corps = $this->lecteur->corps();
        $maintenant = new \DateTimeImmutable();

        $beneficiaire = $this->beneficiaire($corps['granteeId'] ?? null);
        $etablissement = $this->etablissement($corps['establishmentId'] ?? null);
        $motif = \is_string($corps['reason'] ?? null) ? trim($corps['reason']) : '';
        $heures = $this->heures($corps['hours'] ?? null);

        if ('' === $motif) {
            throw new UnprocessableEntityHttpException(
                'Indiquez pourquoi vous ouvrez cet accès. Ce motif sera lu par le client s\'il le demande.'
            );
        }

        $access = $this->guard->grant(
            $beneficiaire,
            $etablissement,
            $motif,
            $maintenant,
            $maintenant->modify(sprintf('+%d hours', $heures)),
            $this->auteur(),
        );

        return EditorSupportAccessProvider::fiche($access, $maintenant);
    }

    private function beneficiaire(mixed $reference): Utilisateur
    {
        // À défaut de bénéficiaire désigné, c'est l'agent qui demande. Voir le commentaire de classe :
        // l'auto-attribution est assumée, la garantie est la trace et non la séparation des rôles.
        if (!\is_string($reference) || !Uuid::isValid($reference)) {
            $courant = $this->security->getUser();
            if (!$courant instanceof Utilisateur) {
                throw new UnprocessableEntityHttpException('Un accès d\'assistance est nominatif.');
            }

            return $courant;
        }

        $utilisateur = $this->em->getRepository(Utilisateur::class)->find(Uuid::fromString($reference));
        if (!$utilisateur instanceof Utilisateur) {
            throw new NotFoundHttpException();
        }

        return $utilisateur;
    }

    private function etablissement(mixed $reference): Etablissement
    {
        if (!\is_string($reference) || !Uuid::isValid($reference)) {
            throw new UnprocessableEntityHttpException('Indiquez l\'établissement du client à assister.');
        }

        $etablissement = $this->em->getRepository(Etablissement::class)->find(Uuid::fromString($reference));
        if (!$etablissement instanceof Etablissement) {
            throw new NotFoundHttpException();
        }

        return $etablissement;
    }

    private function heures(mixed $brut): int
    {
        $heures = \is_numeric($brut) ? (int) $brut : self::DEFAULT_HOURS;

        if ($heures < 1 || $heures > self::MAX_HOURS) {
            throw new UnprocessableEntityHttpException(sprintf(
                'La durée d\'un accès d\'assistance va de 1 à %d heures. Au-delà, rouvrez un accès : '
                .'la seconde ouverture laisse une seconde trace, et c\'est précisément l\'information '
                .'qu\'on veut avoir.',
                self::MAX_HOURS,
            ));
        }

        return $heures;
    }

    private function auteur(): ?string
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur->getEmail() : null;
    }
}
