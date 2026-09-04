<?php

declare(strict_types=1);

namespace App\Subscription\Service;

use App\Subscription\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Frappe le jeton de confirmation d'un essai et rend le lien en clair.
 *
 * **Pourquoi ce service existe, alors que trois lignes suffisaient là où elles étaient.**
 *
 * Ces trois lignes vivaient dans {@see \App\Subscription\EventListener\SendTrialConfirmationEmail}.
 * L'outil de recette du tunnel (E-8 non levé, aucun courriel ne part) a besoin exactement du même
 * geste. Le réécrire dans la commande aurait donné deux frappes de jeton : deux longueurs, deux
 * hachages, deux formes de lien, libres de diverger sans que rien ne le signale. L'outil aurait
 * alors prouvé **son propre chemin** plutôt que celui du prospect — c'est-à-dire rien.
 *
 * Un seul endroit frappe le jeton. La commande et le courriel appellent le même.
 *
 * ---
 *
 * **CE QUE LA BASE GARDE, ET CE QU'ELLE NE GARDE PAS.** Seul le `sha256` est écrit. La valeur en
 * clair n'existe que le temps de composer le message : c'est délibéré, et c'est la raison pour
 * laquelle un lien de confirmation ne se retrouve nulle part après coup — ni en base, ni dans le
 * journal, qui ne porte ni le contenu ni les variables d'une notification.
 *
 * **LA VALIDITÉ NE PART PAS D'ICI.** {@see SubscriptionFunnel::confirmEmailAndStartTrial} borne le
 * lien à {@see SubscriptionFunnel::CONFIRMATION_HOURS} après la **création de l'abonnement**, pas
 * après la frappe du jeton. Frapper un jeton neuf sur une demande ancienne rend donc un lien déjà
 * expiré : l'appelant doit le dire plutôt que de laisser croire à un outil cassé.
 */
final class TrialConfirmationLink
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        /**
         * Le lien pointe sur la VITRINE, pas sur le back-office : le prospect n'a encore aucun
         * compte, et `FRONT_BASE_URL` lui afficherait une page de connexion sur un parcours où il
         * n'a jamais choisi de mot de passe.
         */
        #[Autowire(env: 'VITRINE_BASE_URL')] private readonly string $vitrineBaseUrl,
    ) {
    }

    /**
     * Frappe un jeton neuf, n'en garde que l'empreinte, et rend le lien que le prospect doit suivre.
     *
     * Frapper une seconde fois invalide le lien précédent : c'est voulu, un seul lien vit à la fois.
     */
    public function issue(Subscription $subscription): string
    {
        $clearToken = bin2hex(random_bytes(32));

        $subscription->setEmailConfirmationTokenHash(hash('sha256', $clearToken));
        $this->em->flush();

        return sprintf(
            '%s/confirmation.html?jeton=%s',
            rtrim($this->vitrineBaseUrl, '/'),
            $clearToken,
        );
    }

    /**
     * L'instant après lequel le lien d'une demande ne vaut plus rien.
     *
     * Exposé ici parce que deux appelants en ont besoin pour se comporter honnêtement, et qu'il se
     * calcule sur la création de l'abonnement — une règle qu'on ne devine pas depuis le lien.
     */
    public function expiresAt(Subscription $subscription): \DateTimeImmutable
    {
        return $subscription->getCreatedAt()->modify(
            sprintf('+%d hours', SubscriptionFunnel::CONFIRMATION_HOURS)
        );
    }
}
