<?php

declare(strict_types=1);

namespace App\Calendar\Controller;

use App\Calendar\Entity\IcsSubscription;
use App\Calendar\Service\CalendarAggregator;
use App\Calendar\Service\IcsWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * LE FLUX ICS — la seule porte anonyme de l'agenda, et elle est étroite.
 *
 * ── POURQUOI ELLE EST ANONYME ───────────────────────────────────────────────────────────────────
 *
 * Google Agenda, Apple Calendar et Outlook interrogent une URL toutes les heures, sans en-tête,
 * sans jeton porteur, sans contexte. Exiger une authentification reviendrait à ne pas livrer la
 * fonctionnalité. L'ADRESSE EST DONC LE SECRET, et tout le reste en découle.
 *
 * ── CE QUI BORNE CETTE PORTE ────────────────────────────────────────────────────────────────────
 *
 *   - Le jeton est tiré de `random_bytes(24)` : 192 bits, indevinables, jamais dérivés d'un
 *     identifiant.
 *   - Il est NOMINATIF et borné à UN établissement : le flux rend exactement ce que son porteur
 *     verrait à l'écran, y compris le filtre de propriété — jamais l'agenda personnel d'un tiers.
 *   - Il est RÉVOCABLE : régénérer casse l'ancienne URL sur-le-champ.
 *   - Il est dans le CHEMIN et non dans la chaîne de requête, qui finit dans les journaux d'accès
 *     de tous les intermédiaires.
 *   - Un jeton inconnu rend **404 et non 403** : un 403 confirmerait qu'une URL voisine existe.
 *
 * ⚠ La réponse ne porte AUCUN en-tête de cache. Un agenda republié doit être relu ; un flux mis en
 * cache par un intermédiaire est un flux qui montre l'ancien planning sans dire qu'il est ancien.
 */
#[AsController]
final class IcsFeedController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarAggregator $agregateur,
        private readonly IcsWriter $redacteur,
    ) {
    }

    #[Route('/calendar/ics/{token}.ics', name: 'calendar_ics_feed', methods: ['GET'], requirements: ['token' => '[0-9a-f]{48}'])]
    public function __invoke(string $token): Response
    {
        $abonnement = $this->em->getRepository(IcsSubscription::class)->findOneBy(['token' => $token]);
        $utilisateur = $abonnement?->getUser();
        $etablissement = $abonnement?->getEstablishment();
        if ($abonnement === null || $utilisateur === null || $etablissement === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        // FENÊTRE GLISSANTE plutôt que tout l'historique : un agenda abonné n'a pas besoin des
        // créneaux de l'an dernier, et les lui envoyer à chaque relève horaire coûte à tout le
        // monde. Un mois en arrière suffit à garder le contexte récent visible.
        $fuseau = new \DateTimeZone($etablissement->getFuseauHoraire());
        $du = (new \DateTimeImmutable('now', $fuseau))->modify('-1 month')->setTime(0, 0);
        $au = $du->modify('+4 months');

        $evenements = array_merge(
            // ⚠ « mine » ET NON « moi ». `CalendarAggregator` lit `$portee === 'mine'` pour
            // décider s'il borne sur le propriétaire. Avec « moi », il rendait les événements DU
            // SITE — donc deux fois les mêmes, et jamais ceux de l'utilisateur. Le vocabulaire des
            // ONGLETS de l'écran (« moi ») n'est pas celui du FIL (« mine ») ; le front traduit,
            // ce contrôleur ne le faisait pas.
            $this->agregateur->evenements($etablissement, $utilisateur, $du, $au, 'mine'),
            $this->agregateur->evenements($etablissement, $utilisateur, $du, $au, 'site'),
        );

        $corps = $this->redacteur->rediger($evenements, $etablissement->getNom());

        $reponse = new Response($corps, Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="agenda.ics"',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
        $reponse->setPrivate();
        $reponse->headers->addCacheControlDirective('no-store');

        return $reponse;
    }
}
