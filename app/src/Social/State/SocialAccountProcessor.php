<?php

declare(strict_types=1);

namespace App\Social\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Service\ContexteEtablissement;
use App\Social\Crypto\SocialTokenCipher;
use App\Social\Entity\SocialAccount;
use App\Social\Enum\SocialAccountStatus;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Connexion et mise à jour d'un compte social (D14, SOC-1).
 *
 * Trois responsabilités, et aucune n'est déléguable au client :
 *
 * 1. `establishment` est toujours dérivé côté serveur (`ContexteEtablissement::etablissementActif()`,
 *    invariant noyau commun #1), jamais du corps de la requête — la propriété n'appartient à aucun
 *    groupe d'écriture. En PATCH, le périmètre est revérifié explicitement même si l'entité a déjà été
 *    filtrée en lecture par `SocialScopeExtension` : défense en profondeur, parce que les seize IDOR
 *    trouvés dans ce dépôt avaient tous la même forme — une entité résolue, jamais confrontée au
 *    périmètre.
 * 2. Les jetons en clair sont chiffrés avant persistance et le champ transitoire est vidé
 *    immédiatement après. Un jeton ne survit jamais en clair au-delà de cette méthode.
 * 3. Un compte remis en service par une reconnexion repasse en `Connected` : recevoir un nouveau jeton
 *    et rester affiché « expiré » ferait mentir l'interface sur l'état réel du coffre.
 *
 * Échec fermé en 404 et non en 403 (un 403 est un oracle d'énumération : il distinguerait « existe,
 * pas à toi » de « n'existe pas »).
 *
 * @implements ProcessorInterface<SocialAccount, SocialAccount>
 */
final class SocialAccountProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $establishmentContext,
        private readonly SocialTokenCipher $cipher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SocialAccount
    {
        \assert($data instanceof SocialAccount);

        $establishment = $this->establishmentContext->etablissementActif();
        if ($establishment === null) {
            throw new UnprocessableEntityHttpException('social.error.no_active_establishment');
        }

        if ($operation instanceof Post) {
            $data->setEstablishment($establishment);
        } else {
            $current = $data->getEstablishment();
            if ($current === null || (string) $current->getId() !== (string) $establishment->getId()) {
                throw new NotFoundHttpException();
            }
        }

        // Un hôte par défaut plutôt qu'un champ obligatoire : Bluesky en a un qui vaut pour presque
        // tout le monde, Mastodon n'en a aucun qui vaudrait quoi que ce soit (le jeton ne vaut que
        // pour l'instance qui l'a émis, et il n'existe pas d'instance « par défaut »).
        if ($data->getHost() === null || trim($data->getHost()) === '') {
            $data->setHost($data->getNetwork()->defaultHost());
        }
        if ($data->getNetwork()->requiresHost() && ($data->getHost() === null || trim($data->getHost()) === '')) {
            throw new UnprocessableEntityHttpException('social.error.host_required_for_network');
        }

        $accessToken = $data->getAccessTokenPlain();
        $hasNewAccessToken = trim($accessToken) !== '';
        if ($hasNewAccessToken) {
            $data->setAccessTokenEncrypted($this->cipher->encrypt($accessToken));
        }
        $data->setAccessTokenPlain('');

        $refreshToken = $data->getRefreshTokenPlain();
        if (trim($refreshToken) !== '') {
            $data->setRefreshTokenEncrypted($this->cipher->encrypt($refreshToken));
        }
        $data->setRefreshTokenPlain('');

        if ($hasNewAccessToken && $data->getStatus() === SocialAccountStatus::TokenExpired) {
            $data->setStatus(SocialAccountStatus::Connected);
        }

        // Révoquer, c'est cesser de détenir. C'est le seul cas où les jetons sont effacés : sur une
        // simple expiration on les garde, parce que c'est le jeton de rafraîchissement qui permettra
        // de se rétablir sans redemander à l'utilisateur de tout reconnecter.
        if ($data->getStatus() === SocialAccountStatus::Revoked) {
            $data->setAccessTokenEncrypted(null);
            $data->setRefreshTokenEncrypted(null);
            $data->setTokenExpiresAt(null);
        }

        if ($operation instanceof Post && !$data->hasAccessToken()) {
            throw new UnprocessableEntityHttpException('social.error.access_token_required');
        }

        $data->touchUpdatedAt();
        $this->em->persist($data);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Le même compte distant reconnecté deux fois sur le même établissement : la contrainte
            // unique tranche en base plutôt que d'empiler deux lignes dont on ne saurait plus laquelle
            // publie. Le geste attendu côté client est un PATCH sur la ressource existante.
            throw new ConflictHttpException('social.error.account_already_connected', $e);
        }

        return $data;
    }
}
