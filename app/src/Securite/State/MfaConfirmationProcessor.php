<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Crypto\ChiffreurSecret;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\GenerateurTotp;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /utilisateurs/{id}/mfa/confirmer {code}` (§2.3 plan-backoffice.md) : vérifie le code TOTP
 * courant contre le secret généré par `/mfa/activer` ; si valide, `mfaActif = true` ; sinon 422
 * (secret non activé, à refaire).
 *
 * @implements ProcessorInterface<Utilisateur, Utilisateur>
 */
final class MfaConfirmationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GenerateurTotp $totp,
        private readonly ChiffreurSecret $chiffreur,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Utilisateur
    {
        \assert($data instanceof Utilisateur);

        $secretChiffre = $data->getMfaSecret();
        if ($secretChiffre === null) {
            throw new UnprocessableEntityHttpException('Activez le MFA avant de le confirmer.');
        }

        $code = (string) ($this->lecteur->corps()['code'] ?? '');
        $secretClair = $this->chiffreur->dechiffrer($secretChiffre);

        if (!$this->totp->verifier($secretClair, $code)) {
            throw new UnprocessableEntityHttpException('Code invalide.');
        }

        $data->setMfaActif(true);
        $this->em->flush();

        return $data;
    }
}
