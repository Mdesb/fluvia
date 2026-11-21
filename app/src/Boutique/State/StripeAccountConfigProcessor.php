<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\StripeAccountConfig;
use App\Compta\Enum\StripeKeyMode;
use App\Compta\Enum\TypeExploitant;
use App\Compta\Service\StripeKeyEncryptor;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Écriture (Post/Patch) du compte Stripe d'un exploitant (lot D3-B de #365).
 *
 * - **Périmètre** : le profil doit avoir l'établissement ACTIF pour **principal** (pas seulement « couvre »,
 *   arbitrage CP-2) — la clé engage les encaissements de tous les établissements de l'exploitant. Sinon
 *   404, avant toute écriture ; jamais de confiance en l'IRI du corps.
 * - **Régie directe** : refusée (422), elle encaisse par PayFiP et n'a pas de clé. Ce processeur vit côté Boutique
 *   (et non Compta) parce que `ArchitectureRegimeTest` interdit à Compta de lire `ProfilExploitant::type` hors du
 *   résolveur de régime ; le paiement en ligne est le domaine de la Boutique, qui lit déjà ce discriminant.
 * - **Chiffrement** : les valeurs en clair (champs transitoires) sont chiffrées par `StripeKeyEncryptor`,
 *   puis vidées. Sans clé maîtresse, la saisie est refusée en **503** (le serveur n'est pas configuré :
 *   la requête est valide, un 422 accuserait le client), rien n'est stocké.
 * - **Aucune valeur** ne sort d'ici : ni dans un message d'exception, ni dans un journal (ce service ne
 *   journalise rien).
 *
 * @implements ProcessorInterface<StripeAccountConfig, StripeAccountConfig>
 */
final class StripeAccountConfigProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $context,
        private readonly StripeKeyEncryptor $encryptor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StripeAccountConfig
    {
        \assert($data instanceof StripeAccountConfig);

        $active = $this->context->etablissementActif();
        $profile = $data->getBusinessProfile();
        $principal = $profile?->getEtablissementPrincipal();
        if ($active === null || $profile === null || $principal === null || !$principal->getId()->equals($active->getId())) {
            throw new NotFoundHttpException('Profil exploitant introuvable.');
        }

        if ($profile->getType() === TypeExploitant::RegieDirecte) {
            throw new UnprocessableEntityHttpException('Une régie directe encaisse par PayFiP : elle n\'a pas de clé Stripe à saisir.');
        }

        $isCreation = $operation instanceof Post;
        $plainKey = (string) $data->getSecretKeyPlain();
        $plainSecret = (string) $data->getWebhookSecretPlain();

        if ($isCreation) {
            if ($this->em->getRepository(StripeAccountConfig::class)->findOneBy(['businessProfile' => $profile->getId()]) !== null) {
                throw new ConflictHttpException('Une clé Stripe est déjà enregistrée pour cet exploitant : modifiez-la ou retirez-la.');
            }
            if ($plainKey === '') {
                throw new UnprocessableEntityHttpException('La clé secrète Stripe est obligatoire : un secret de webhook ne s\'enregistre pas seul.');
            }
        }

        if ($plainKey === '' && $plainSecret === '') {
            $data->eraseTransientSecrets();

            return $data;
        }

        if (!$this->encryptor->isAvailable()) {
            $data->eraseTransientSecrets();

            throw new ServiceUnavailableHttpException(null, 'Le chiffrement des clés Stripe n\'est pas configuré sur ce serveur : la clé ne peut pas être enregistrée. Contactez l\'administrateur de la plateforme.');
        }

        $now = new \DateTimeImmutable();
        if ($plainKey !== '') {
            $mode = StripeKeyMode::fromKey($plainKey) ?? throw new UnprocessableEntityHttpException('Format de clé Stripe non reconnu.');
            $data->setSecretKeyEncrypted($this->encryptor->encrypt($plainKey))->setKeyMode($mode)->setKeyUpdatedAt($now);
        }
        if ($plainSecret !== '') {
            $data->setWebhookSecretEncrypted($this->encryptor->encrypt($plainSecret))->setWebhookSecretUpdatedAt($now);
        }
        $data->eraseTransientSecrets();

        $data->touchUpdatedAt();
        $this->em->persist($data);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Course POST/POST : la contrainte unique (1 par profil) tranche en base.
            throw new ConflictHttpException('Une clé Stripe est déjà enregistrée pour cet exploitant : modifiez-la ou retirez-la.', $e);
        }

        return $data;
    }
}
