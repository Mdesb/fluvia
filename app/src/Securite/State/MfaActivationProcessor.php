<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Crypto\ChiffreurSecret;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\GenerateurCodesRecuperation;
use App\Securite\Service\GenerateurTotp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `POST /utilisateurs/{id}/mfa/activer` (§2.3 plan-backoffice.md, CA-5) : génère un secret TOTP +
 * 8 codes de récupération, chiffre/hache et persiste (mais `mfaActif` reste `false` tant que
 * `/mfa/confirmer` n'a pas validé un premier code — évite un verrouillage si mal-scanné) ;
 * retourne UNE SEULE FOIS le secret en clair + URI `otpauth://` + les codes en clair.
 *
 * @implements ProcessorInterface<Utilisateur, JsonResponse>
 */
final class MfaActivationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GenerateurTotp $totp,
        private readonly GenerateurCodesRecuperation $codes,
        private readonly ChiffreurSecret $chiffreur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Utilisateur);

        $secretClair = $this->totp->genererSecret();
        $codesClair = $this->codes->genererCodesClair();

        $data->setMfaSecret($this->chiffreur->chiffrer($secretClair));
        $data->setMfaCodesRecuperation($this->codes->hacher($codesClair));
        $this->em->flush();

        return new JsonResponse([
            'secret' => $secretClair,
            'uriProvisionnement' => $this->totp->uriProvisionnement($secretClair, $data->getEmail()),
            'codesRecuperation' => $codesClair,
        ]);
    }
}
