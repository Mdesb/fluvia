<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\EspaceAcces;
use App\Organisation\Entity\Etablissement;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Entity\Employe;
use App\Personnel\Enum\ModeHoraireBadge;
use App\Personnel\Service\EmissionBadgeStaffHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Émet un badge staff (POST /personnel/employes/{id}/badges, RG-PERSO-06). Corps :
 * { "etablissement": IRI, "modeHoraire": "shifts_uniquement"|"permanent", "margeAvantApres": int?,
 *   "espacesAutorises": [IRI, ...] }.
 *
 * @implements ProcessorInterface<mixed, BadgeStaff>
 */
final class EmissionBadgeStaffProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly EmissionBadgeStaffHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BadgeStaff
    {
        $agent = $this->security->getUser();
        if (!$agent instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Agent authentifié requis.');
        }

        $idEmploye = $this->uuid($uriVariables['id'] ?? null);
        if ($idEmploye === null) {
            throw new UnprocessableEntityHttpException('Employé introuvable.');
        }
        $employe = $this->em->getRepository(Employe::class)->find($idEmploye);
        if (!$employe instanceof Employe) {
            throw new UnprocessableEntityHttpException('Employé introuvable.');
        }

        $corps = $this->lecteur->corps();

        $etablissement = $this->resoudre(Etablissement::class, $corps['etablissement'] ?? null, 'etablissement');
        \assert($etablissement instanceof Etablissement);

        $modeHoraire = ModeHoraireBadge::tryFrom((string) ($corps['modeHoraire'] ?? ''));
        if ($modeHoraire === null) {
            throw new UnprocessableEntityHttpException('Champ « modeHoraire » invalide ou manquant (shifts_uniquement|permanent).');
        }

        $margeAvantApres = isset($corps['margeAvantApres']) ? (int) $corps['margeAvantApres'] : null;

        $espacesAutorisesRefs = \is_array($corps['espacesAutorises'] ?? null) ? $corps['espacesAutorises'] : [];
        $espacesAutorises = [];
        foreach ($espacesAutorisesRefs as $ref) {
            $espace = $this->resoudre(EspaceAcces::class, $ref, 'espacesAutorises');
            \assert($espace instanceof EspaceAcces);
            $espacesAutorises[] = $espace;
        }

        return $this->handler->emettre($employe, $etablissement, $modeHoraire, $espacesAutorises, $margeAvantApres, $agent);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function resoudre(string $classe, mixed $reference, string $champ): object
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » obligatoire (UUID ou IRI).', $champ));
        }
        $entite = $this->em->getRepository($classe)->find($uuid);
        if ($entite === null) {
            throw new UnprocessableEntityHttpException(sprintf('%s introuvable.', $champ));
        }

        return $entite;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if ($reference instanceof Uuid) {
            return $reference;
        }
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
