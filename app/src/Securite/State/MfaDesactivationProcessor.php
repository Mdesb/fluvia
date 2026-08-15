<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Crypto\ChiffreurSecret;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\GenerateurCodesRecuperation;
use App\Securite\Service\GenerateurTotp;
use App\Securite\Service\RoleAPrivileges;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /utilisateurs/{id}/mfa/desactiver {code}` (§2.1/§2.3 plan-backoffice.md) : self, code TOTP
 * ou de récupération requis. Corollaire du garde MFA sur affectation (CA-4) : refusé (422) si
 * l'utilisateur détient encore une `Affectation` active vers un rôle à privilèges (RG-M8-06) — ne
 * recrée pas la faille en sens inverse.
 *
 * @implements ProcessorInterface<Utilisateur, Utilisateur>
 */
final class MfaDesactivationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GenerateurTotp $totp,
        private readonly ChiffreurSecret $chiffreur,
        private readonly GenerateurCodesRecuperation $codes,
        private readonly RoleAPrivileges $roleAPrivileges,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Utilisateur
    {
        \assert($data instanceof Utilisateur);

        $affectations = $this->em->getRepository(Affectation::class)->findBy(['utilisateur' => $data]);
        foreach ($affectations as $affectation) {
            $role = $affectation->getRole();
            if ($role !== null && $this->roleAPrivileges->estAPrivileges($role)) {
                throw new UnprocessableEntityHttpException(
                    'Impossible de désactiver le MFA : vous détenez un rôle à privilèges qui l\'exige.'
                );
            }
        }

        $code = (string) ($this->lecteur->corps()['code'] ?? '');
        if (!$this->codeValide($data, $code)) {
            throw new UnprocessableEntityHttpException('Code invalide.');
        }

        $data->setMfaActif(false);
        $data->setMfaSecret(null);
        $data->setMfaCodesRecuperation(null);
        $this->em->flush();

        return $data;
    }

    private function codeValide(Utilisateur $utilisateur, string $code): bool
    {
        $secretChiffre = $utilisateur->getMfaSecret();
        if ($secretChiffre !== null && $this->totp->verifier($this->chiffreur->dechiffrer($secretChiffre), $code)) {
            return true;
        }

        $hashes = $utilisateur->getMfaCodesRecuperation() ?? [];
        $restants = $this->codes->consommer($hashes, $code);
        if ($restants !== null) {
            $utilisateur->setMfaCodesRecuperation($restants);

            return true;
        }

        return false;
    }
}
