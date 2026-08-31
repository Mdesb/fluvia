<?php

declare(strict_types=1);

namespace App\Sport\Security;

use App\Acces\Entity\EspaceAcces;
use App\Audit\Service\JournalAudit;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Borne le bouton SOS, SANS JAMAIS PERDRE LE PREMIER APPEL.
 *
 * ── CE QUI EST OUVERT, ET POURQUOI ÇA LE RESTE ─────────────────────────────────────────────────
 *
 * `POST /sport/espaces/{id}/sos` est en `PUBLIC_ACCESS`, et c'est un choix documenté depuis
 * l'origine — risque n°7 du plan : un boîtier posé sur un mur, sans personnel, 24 h sur 24, ne peut
 * pas se connecter. **On n'y touche pas.** Le jeton d'appareil reste la vraie réponse ; Maxime la
 * remet à plus tard en connaissance de cause (31/08).
 *
 * La borne réelle aujourd'hui est l'identifiant de l'espace, qui n'est pas devinable et qu'aucun
 * point d'entrée public n'expose. Mais **il est embarqué dans le boîtier** : qui le lit une fois
 * peut déclencher depuis n'importe où, indéfiniment.
 *
 * ── ⚠ LE DANGER N'EST PAS LA FAUSSE ALERTE, C'EST QU'ELLE NOIE UNE VRAIE ──────────────────────
 *
 * Un système d'alerte dont le personnel a appris à se méfier ne protège plus personne.
 *
 * ── LA CLÉ PORTE L'ESPACE **ET** L'ADRESSE, ET C'EST CE QUI NE PERD PAS LE PREMIER APPEL ──────
 *
 * Sur l'espace seul, une adresse malveillante ferait taire le bouton pour tout le monde — y compris
 * pour la personne debout devant lui. Sur l'adresse seule, un boîtier partagé par plusieurs salles
 * se freinerait lui-même.
 *
 * Le couple donne la propriété qu'on cherche : **une personne en détresse appuie une fois, sur un
 * bouton dont le couple (espace, adresse) n'a rien consommé — son appel passe toujours.** Ce qu'on
 * jette est le vingtième appel en trois minutes depuis la même adresse sur le même espace, jamais
 * le premier.
 *
 * ⚠ **UNE PREMIÈRE VERSION FAISAIT MIEUX SUR LE PAPIER ET RIEN DU TOUT EN VRAI.** Elle interrogeait
 * la base — « aucun SOS sur cet espace depuis un quart d'heure ? alors on laisse passer quoi qu'il
 * arrive » — pour couvrir le cas d'une adresse partagée. La requête rendait **zéro alors qu'un
 * événement venait d'être écrit**, cause non élucidée, et cette dérogation avalait donc TOUT le
 * limiteur : cinq appels sur cinq passaient. Un frein dont la porte de secours reste ouverte n'est
 * pas un frein, et rien ne le disait — le compteur, lui, fonctionnait parfaitement.
 *
 * La fenêtre glissante de cinq minutes couvre le même besoin sans rien demander à personne : après
 * un flot, le budget se reconstitue tout seul, et une urgence réelle survenue plus tard passe.
 *
 * ── ⚠ UN REFUS DOIT SE VOIR, SINON ON A CHANGÉ DE PROBLÈME SANS LE SAVOIR ─────────────────────
 *
 * Sans trace, on aurait remplacé « on est noyé sous les fausses alertes » par « on ne sait pas
 * qu'on l'est » — ce qui est pire, parce que le second ne se remarque pas. Chaque appel écarté
 * s'inscrit au journal d'audit de l'établissement, sous `sport.sos_ecarte`, avec l'espace concerné.
 */
final class SosRateLimiter
{
    /**
     * Trois déclenchements par fenêtre, pour un même espace et une même adresse.
     *
     * Assez pour une personne qui appuie plusieurs fois par panique ou parce qu'elle doute que ça
     * ait marché — c'est le comportement normal devant un bouton d'urgence, et le punir serait
     * absurde. Trop peu pour qu'une boucle produise du bruit.
     */
    private const LIMIT = 3;

    /**
     * Cinq minutes, et la fenêtre GLISSE.
     *
     * Un compteur remis à zéro à heure fixe autorise deux fois la limite à cheval sur la bascule.
     * Et la brièveté de la fenêtre est ce qui remplace la dérogation retirée : après un flot, le
     * budget se reconstitue sans que personne ait à décider quoi que ce soit.
     */
    private const INTERVAL = '5 minutes';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $pool,
        private readonly EntityManagerInterface $em,
        private readonly JournalAudit $journal,
    ) {
    }

    /**
     * @throws TooManyRequestsHttpException si ce couple (espace, adresse) a déjà crié trois fois
     */
    public function assertNotExceeded(EspaceAcces $espace, ?string $clientIp): void
    {
        // Adresse inconnue : on borne quand même, sur une clé commune. Laisser passer parce qu'on
        // ne sait pas d'où vient l'appel donnerait la marche à suivre pour contourner la borne.
        $limiteur = $this->factory()->create(
            sprintf('%s|%s', $espace->getId(), $clientIp ?? 'inconnue'),
        );

        $limite = $limiteur->consume();
        if ($limite->isAccepted()) {
            return;
        }

        $this->tracerLeRefus($espace);

        throw new TooManyRequestsHttpException(
            max(1, $limite->getRetryAfter()->getTimestamp() - time()),
            'Cette alerte a déjà été déclenchée plusieurs fois à l\'instant. Elle est prise en '
            .'compte : le personnel est prévenu. Si la situation change, rappelez dans quelques '
            .'minutes.',
        );
    }

    /**
     * ⚠ LA TRACE EST LA MOITIÉ QUI COMPTE. Un frein muet transforme « trop d'alertes » en « on ne
     * sait pas ce qu'on n'a pas reçu », et la seconde forme ne se remarque jamais.
     */
    private function tracerLeRefus(EspaceAcces $espace): void
    {
        $this->journal->enregistrer(
            'sport.sos_ecarte',
            EspaceAcces::class,
            (string) $espace->getId(),
            $espace->getEtablissement()?->getId(),
        );
        $this->em->flush();
    }

    private function factory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            [
                'id' => 'sport_sos',
                'policy' => 'sliding_window',
                'limit' => self::LIMIT,
                'interval' => self::INTERVAL,
            ],
            new CacheStorage($this->pool),
        );
    }
}
