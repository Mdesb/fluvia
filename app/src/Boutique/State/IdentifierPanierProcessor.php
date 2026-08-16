<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Enum\TypeSessionClient;
use App\Boutique\Identite\FournisseurIdentiteInterface;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * POST /boutique/paniers/{id}/identifier — étape 1 du tunnel (US-L8-04, RG-M3-06, CA-4). Trois
 * voies : compte existant (e-mail + mot de passe), invité (achat simple sans compte), FranceConnect
 * (stub, identifie sans imposer de compte). Corps :
 * { "mode": "compte"|"invite"|"franceconnect", "email"?, "motDePasse"?, "franceConnectCode"? }.
 *
 * @implements ProcessorInterface<PanierEnLigne, PanierEnLigne>
 */
final class IdentifierPanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PanierProprietaireGuard $guard,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly FournisseurIdentiteInterface $fournisseurIdentite,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        $corps = $this->lecteur->corps();
        $mode = \is_string($corps['mode'] ?? null) ? $corps['mode'] : 'invite';

        match ($mode) {
            'compte' => $this->identifierParCompte($data, $corps),
            'franceconnect' => $this->identifierParFranceConnect($data, $corps),
            default => $this->identifierInvite($data, $corps),
        };

        $this->em->flush();

        return $data;
    }

    /** @param array<string, mixed> $corps */
    private function identifierParCompte(PanierEnLigne $panier, array $corps): void
    {
        $email = \is_string($corps['email'] ?? null) ? $corps['email'] : '';
        $motDePasse = \is_string($corps['motDePasse'] ?? null) ? $corps['motDePasse'] : '';

        $utilisateur = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        if (!$utilisateur instanceof Utilisateur || !$this->hasher->isPasswordValid($utilisateur, $motDePasse)) {
            throw new UnauthorizedHttpException('', 'Identifiants invalides.');
        }
        $compte = $this->em->getRepository(CompteClient::class)->findOneBy(['utilisateur' => $utilisateur]);
        if (!$compte instanceof CompteClient) {
            throw new UnprocessableEntityHttpException('Ce compte ne porte pas d\'espace boutique.');
        }

        $panier->setCompteClient($compte)->setContactConnu($email);
    }

    /** @param array<string, mixed> $corps */
    private function identifierInvite(PanierEnLigne $panier, array $corps): void
    {
        $email = \is_string($corps['email'] ?? null) ? $corps['email'] : null;
        if ($email !== null) {
            $panier->setContactConnu($email);
            $session = $panier->getSessionClient();
            $session?->setContactEmail($email);
        }
    }

    /** @param array<string, mixed> $corps */
    private function identifierParFranceConnect(PanierEnLigne $panier, array $corps): void
    {
        $code = \is_string($corps['franceConnectCode'] ?? null) ? $corps['franceConnectCode'] : '';
        $identite = $this->fournisseurIdentite->authentifier($code, '');

        $compte = $this->em->getRepository(CompteClient::class)->findOneBy(['franceConnectId' => $identite->sub]);
        if ($compte instanceof CompteClient) {
            $panier->setCompteClient($compte)->setContactConnu($identite->email);

            return;
        }

        // RG-M3-06 : identifié sans compte imposé — session FranceConnect en cours, aucun CompteClient
        // créé tant que l'achat ne requiert pas explicitement un compte (RG-M3-12/17, §4.9).
        $panier->setContactConnu($identite->email);
        $session = $panier->getSessionClient();
        if ($session !== null) {
            $session->setType(TypeSessionClient::FranceconnectEnCours)->setContactEmail($identite->email);
        }
    }
}
