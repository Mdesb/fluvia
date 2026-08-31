<?php

declare(strict_types=1);

namespace App\Boutique\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Anti-bruteforce sur `POST /boutique/paniers/{id}/identifier` (mode `compte`).
 *
 * **Pourquoi ce garde existe.** Ce point d'entrée valide un mot de passe **hors firewall** :
 * `security.yaml` ne couvre pas cette route applicative publique, donc le `login_throttling` natif de
 * Symfony ne s'y applique pas. Sans borne, l'endpoint est un oracle de mots de passe.
 *
 * **Ce qui change dans ce lot.** Le garde d'origine retombait volontairement sur un compteur
 * applicatif — son auteur l'a écrit noir sur blanc — parce que `symfony/rate-limiter` n'était alors
 * pas installé. Il l'est désormais. Le repli est remplacé par le composant, au même seuil : cinq
 * échecs par quart d'heure, pour un couple adresse + e-mail.
 *
 * **Ce que le repli avait de faux, et ce n'était pas cosmétique.** Il approximait la fenêtre par un
 * TTL, c'est-à-dire une fenêtre **fixe** : le compteur expirait quinze minutes après le premier échec,
 * et repartait de zéro. Un attaquant patient obtenait donc dix tentatives à cheval sur la bascule —
 * cinq juste avant l'expiration, cinq juste après — soit **le double du seuil affiché**. La fenêtre
 * glissante du composant ne le permet pas : elle regarde toujours les quinze dernières minutes, pas
 * les quinze qui ont suivi le premier échec.
 *
 * **La fabrique est construite ici plutôt que déclarée dans `framework.yaml`**, comme
 * `App\Subscription\Security\CartRateLimiter` : ce fichier appartient au noyau, pas à ce périmètre. La
 * configuration vit donc à côté de la règle qu'elle sert, et l'on lit la limite dans le même fichier
 * que sa raison d'être. Si le noyau en déclare un un jour, ce service se réduira à l'injecter.
 *
 * **La clé reste `sha256(ip | e-mail)`**, et c'est délibéré : borner sur la seule adresse punirait
 * tout un réseau d'entreprise derrière une IP unique, borner sur le seul e-mail donnerait à un tiers
 * le moyen de verrouiller le compte de quelqu'un d'autre en échouant cinq fois à sa place.
 */
final class TentativeIdentificationLimiter
{
    /** Échecs tolérés par couple adresse + e-mail, sur la fenêtre. */
    private const MAX_TENTATIVES = 5;

    private const FENETRE = '15 minutes';

    /** Repli d'en-tête `Retry-After` quand le composant ne date pas la reprise. */
    private const FENETRE_SECONDES = 900;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Consulte le quota **sans le consommer** : une tentative qui réussira ne doit rien coûter.
     *
     * @throws TooManyRequestsHttpException si le quota d'échecs est déjà atteint
     */
    public function verifierAvantTentative(string $email): void
    {
        // `consume(0)` interroge la fenêtre sans y ajouter de jeton — c'est ce qui distingue
        // « compter les échecs » de « compter les tentatives ». Un client légitime qui se trompe une
        // fois puis réussit ne doit pas voir son quota entamé par la réussite.
        $limite = $this->limiteur($email)->consume(0);

        // **On regarde les jetons restants, pas `isAccepted()`, et c'est un piège qui coûte cher.**
        // `isAccepted()` répond à la question « puis-je consommer ce que je viens de demander ? » — or
        // on n'a rien demandé. Zéro jeton est toujours disponible, même quand il n'en reste aucun :
        // la borne n'aurait donc jamais refusé personne, et le garde aurait eu l'air de fonctionner.
        if ($limite->getRemainingTokens() < 1) {
            $reprise = $limite->getRetryAfter()->getTimestamp() - time();

            throw new TooManyRequestsHttpException(
                max(1, $reprise > 0 ? $reprise : self::FENETRE_SECONDES),
                'Trop de tentatives d\'identification échouées, réessayez plus tard.',
            );
        }
    }

    public function enregistrerEchec(string $email): void
    {
        $this->limiteur($email)->consume(1);
    }

    public function reinitialiser(string $email): void
    {
        // Une identification réussie efface l'ardoise : sinon un client qui hésite en début de journée
        // se retrouverait bloqué le soir pour des échecs déjà pardonnés.
        $this->limiteur($email)->reset();
    }

    private function limiteur(string $email): LimiterInterface
    {
        $factory = new RateLimiterFactory(
            [
                'id' => 'boutique_identification',
                'policy' => 'sliding_window',
                'limit' => self::MAX_TENTATIVES,
                'interval' => self::FENETRE,
            ],
            new CacheStorage($this->cache),
        );

        return $factory->create($this->cle($email));
    }

    private function cle(string $email): string
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'inconnu';

        return hash('sha256', $ip . '|' . mb_strtolower($email));
    }
}
