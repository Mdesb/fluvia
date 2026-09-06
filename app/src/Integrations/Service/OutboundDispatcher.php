<?php

declare(strict_types=1);

namespace App\Integrations\Service;

use App\Integrations\Crypto\WebhookUrlCipher;
use App\Integrations\Entity\OutboundEndpoint;
use App\Integrations\Enum\EndpointKind;
use App\Platform\Event\DomainEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoie un événement de domaine vers une destination.
 *
 * ── ⚠ UN ENVOI NE FAIT JAMAIS ÉCHOUER CE QUI L'A DÉCLENCHÉ ─────────────────────────────────────
 *
 * Même règle que `NotifyOnDomainEvent`, et pour la même raison : si prévenir Slack d'un paiement
 * échoué faisait échouer l'enregistrement du paiement échoué, le remède serait pire que le mal.
 * Toute exception est attrapée, consignée sur la destination, et avalée.
 *
 * ── ⚠ CE QUI N'EST PAS FAIT DANS CETTE VERSION, ET QUI SE SAIT ─────────────────────────────────
 *
 * **Aucun réessai.** L'envoi est synchrone, avec un délai court. Un canal indisponible dix secondes
 * perd le message, et `dernierEchec` le dit. C'est acceptable pour de l'alerte — une alerte qui
 * arrive vingt minutes plus tard ne sert souvent plus à rien — mais ce serait inacceptable pour un
 * flux qui doit être complet. Le jour où quelqu'un branche là-dessus une comptabilité, il faut une
 * file, pas ce service.
 *
 * **Et l'envoi synchrone ralentit ce qui l'a déclenché** du temps de la requête HTTP. Le délai est
 * donc volontairement bas : mieux vaut un message perdu qu'une caisse qui attend.
 */
final class OutboundDispatcher
{
    /** Trois secondes : au-delà, on préfère perdre le message que retenir l'appelant. */
    private const DELAI_SECONDES = 3;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly WebhookUrlCipher $coffre,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function envoyer(OutboundEndpoint $destination, DomainEvent $evenement): bool
    {
        $url = $this->coffre->dechiffrer($destination->getUrlChiffree());
        if ($url === null) {
            // ⚠ ON LE DIT SUR LA DESTINATION, PAS SEULEMENT DANS UN JOURNAL. Une adresse illisible
            // est un canal muet, et un canal muet est indiscernable d'un canal calme : sans cette
            // trace, on ne s'en aperçoit que le jour où l'on comptait sur l'alerte.
            $this->consigner($destination, 'Adresse illisible : la clé de chiffrement a-t-elle changé ?');

            return false;
        }

        try {
            $reponse = $this->http->request('POST', $url, [
                'json' => $this->corps($destination->getKind(), $evenement),
                'timeout' => self::DELAI_SECONDES,
            ]);

            $code = $reponse->getStatusCode();
            if ($code >= 200 && $code < 300) {
                $destination->setDernierEnvoiLe(new \DateTimeImmutable());
                $destination->setDernierEchec(null);
                $this->em->flush();

                return true;
            }

            // ⚠ ON NE RECOPIE PAS LE CORPS DE LA RÉPONSE : il peut contenir l'URL appelée, donc la
            // clé. Le code HTTP suffit à diagnostiquer, et 404 sur un webhook Slack veut dire une
            // chose précise — l'adresse a été révoquée.
            $this->consigner($destination, sprintf('Refusé par le service (HTTP %d).', $code));

            return false;
        } catch (\Throwable $e) {
            $this->consigner($destination, 'Injoignable : ' . $e->getMessage());
            $this->logger->warning('integrations.envoi.echec', [
                'destination' => (string) $destination->getId(),
                'evenement' => (string) $evenement->name,
            ]);

            return false;
        }
    }

    private function consigner(OutboundEndpoint $destination, string $raison): void
    {
        $destination->setDernierEchec($raison);
        try {
            $this->em->flush();
        } catch (\Throwable) {
            // Même une trace d'échec ne doit pas faire échouer l'appelant.
        }
    }

    /**
     * ⚠ LES TROIS SERVICES N'ATTENDENT PAS LE MÊME CORPS, et envoyer le mauvais ne produit pas une
     * erreur : il produit un message VIDE dans le canal. Slack et Discord lisent `text` ; Teams
     * veut une carte adaptative, et ignore silencieusement tout le reste.
     *
     * @return array<string, mixed>
     */
    private function corps(EndpointKind $kind, DomainEvent $evenement): array
    {
        $texte = $this->texte($evenement);

        return match ($kind) {
            EndpointKind::Slack, EndpointKind::Discord => ['text' => $texte],
            EndpointKind::Teams => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content' => [
                        'type' => 'AdaptiveCard',
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'version' => '1.4',
                        'body' => [['type' => 'TextBlock', 'text' => $texte, 'wrap' => true]],
                    ],
                ]],
            ],
            // ⚠ L'ÉVÉNEMENT TEL QUEL, SANS HABILLAGE. Un automate maison veut la donnée, pas une
            // phrase : lui envoyer du texte l'obligerait à le désosser pour retrouver ce que
            // `toArray()` lui donne déjà.
            EndpointKind::Generique => $evenement->toArray(),
        };
    }

    /**
     * ⚠ `EventSubject` N'EST PAS CONVERTIBLE EN CHAÎNE — il porte deux champs, `type` et `id`. Le
     * convertir aurait levé à l'exécution, pas au lint. Les lire nommément donne d'ailleurs un
     * meilleur message : « reservation #a7610f2f » plutôt qu'une adresse d'objet.
     *
     * `EventName`, lui, est bien `Stringable` — vérifié, pas supposé.
     */
    private function texte(DomainEvent $evenement): string
    {
        $quand = $evenement->occurredAt->format('d/m/Y H:i');

        return sprintf(
            '%s — %s #%s (%s)',
            (string) $evenement->name,
            $evenement->subject->type,
            $evenement->subject->id,
            $quand,
        );
    }
}
