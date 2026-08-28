<?php

declare(strict_types=1);

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Marketing\Entity\LoyaltyEntry;
use App\Marketing\Enum\LoyaltyMovement;
use App\Marketing\Service\LoyaltyLedger;
use App\Marketing\Service\MarketingContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /marketing/fidelite/mouvements` — dépenser des points, ou en accorder.
 *
 * ── LE SOLDE NE PEUT PAS DEVENIR NÉGATIF ────────────────────────────────────────────────────────
 *
 * Le refus est un **422 qui dit le solde réel**, pas un message générique. L'agent a le client
 * devant lui : « il ne reste que 40 points » lui permet de répondre, « requête invalide » le laisse
 * sans rien à dire.
 *
 * Un solde négatif serait pire qu'une erreur : il transformerait un programme de fidélité en
 * découvert, avec un client persuadé d'avoir échangé quelque chose qu'il n'avait pas.
 *
 * ── L'ÉTABLISSEMENT ET L'AUTEUR SONT POSÉS ICI, JAMAIS REÇUS ────────────────────────────────────
 *
 * D41 : l'établissement vient du contexte serveur, l'auteur de la session. Les accepter du corps de
 * la requête permettrait d'imputer un geste à quelqu'un d'autre, ou de créditer chez le voisin.
 *
 * @implements ProcessorInterface<LoyaltyEntry, LoyaltyEntry>
 */
final readonly class LoyaltyMovementProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketingContext $contexte,
        private LoyaltyLedger $registre,
    ) {
    }

    /**
     * @param LoyaltyEntry         $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LoyaltyEntry
    {
        [$utilisateur, $etablissement] = $this->contexte->exigerAutorite('fidelite', 'gerer');

        $client = $data->getCustomerRef();
        if ($client === null) {
            throw new NotFoundHttpException('Client introuvable.');
        }

        $this->contexte->exigerClientDansLePerimetre($client->toRfc4122(), $utilisateur);

        // Une dépense est toujours négative, quel que soit le signe reçu. Sans cette normalisation,
        // « dépenser 50 points » avec un signe oublié en CRÉDITERAIT cinquante — l'erreur la plus
        // facile à commettre et la plus difficile à voir.
        $points = $data->getMovement() === LoyaltyMovement::Depense
            ? -abs($data->getPoints())
            : $data->getPoints();

        $solde = (int) $this->registre->etatDe($client, $etablissement)['solde'];
        if ($solde + $points < 0) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Solde insuffisant : %d point(s) disponible(s), %d demandé(s).',
                $solde,
                abs($points),
            ));
        }

        $data->setPoints($points)
            ->setEstablishment($etablissement)
            ->setAuthorRef($utilisateur->getId());

        $this->entityManager->persist($data);
        $this->entityManager->flush();

        return $data;
    }
}
