<?php

declare(strict_types=1);

namespace App\Stay\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Client;
use App\Securite\Service\ContexteEtablissement;
use App\Stay\Entity\Stay;
use App\Stay\Security\StayScopeGuard;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Ouverture d'un séjour (ACT-3). Corps attendu :
 *   { "customer": uuid, "arrivalDate": "2026-08-24", "expectedDepartureDate": "2026-08-28"? }
 *
 * **L'établissement vient de la session serveur, jamais du corps** (D3). Accepter un
 * `establishment` dans la charge utile laisserait l'appelant choisir son propre périmètre — la faille
 * exacte corrigée le 19/08 sur cinq endpoints.
 *
 * **Le client est confronté au périmètre, et l'échec est fermé.** `App\Crm\Entity\Client` n'a pas
 * d'établissement propriétaire : il porte `etablissementCreation`, nullable. Faute de mieux, c'est lui
 * qui sert de rattachement, et un client sans établissement de création est **refusé** plutôt que
 * rattaché par défaut au périmètre courant — sans quoi n'importe quel identifiant de client valide
 * ouvrirait un séjour dans n'importe quel établissement. Cette limite du CRM est signalée à
 * l'intégrateur dans `RAPPORTS/claude-F.md` : elle dépasse mon périmètre.
 *
 * @implements ProcessorInterface<mixed, Stay>
 */
final class OpenStayProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly StayScopeGuard $garde,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Stay
    {
        $corps = $this->lecteur->corps();

        $etablissement = $this->garde->verify($this->contexte->etablissementActif());

        $idClient = $corps['customer'] ?? null;
        if (!is_string($idClient) || !Uuid::isValid($idClient)) {
            throw new UnprocessableEntityHttpException('stay.error.customer_required');
        }

        $client = $this->em->getRepository(Client::class)->find(Uuid::fromString($idClient));
        // Confrontation au périmètre de l'entité résolue depuis l'entrée client (D8, garde-fou C19) :
        // 404 uniforme, indiscernable de « ce client n'existe pas ».
        $this->garde->verify($client?->getEtablissementCreation(), 'stay.error.customer_not_found');

        $arrivee = $this->date($corps['arrivalDate'] ?? null, 'arrivalDate');
        $depart = isset($corps['expectedDepartureDate'])
            ? $this->date($corps['expectedDepartureDate'], 'expectedDepartureDate')
            : null;

        if (null !== $depart && $depart < $arrivee) {
            throw new UnprocessableEntityHttpException('stay.error.departure_before_arrival');
        }

        $sejour = new Stay(
            $etablissement,
            $client,
            $this->reference(),
            $arrivee,
            new \DateTimeImmutable('now'),
        );
        $sejour->setExpectedDepartureDate($depart);

        $this->em->persist($sejour);
        $this->em->flush();

        return $sejour;
    }

    /**
     * Référence lisible, unique par établissement grâce à la contrainte de schéma.
     *
     * Volontairement **non séquentielle** : un compteur par établissement demanderait soit une table
     * de séquence, soit un verrou, et rendrait les références devinables — or l'indiscernabilité 404
     * de `StayScopeGuard` perd de sa valeur si `SEJ-0002` se déduit de `SEJ-0001`. Si l'exploitation
     * réclame un numéro suivi, c'est un arbitrage, pas un détail d'implémentation.
     */
    private function reference(): string
    {
        return 'SEJ-' . strtoupper(substr(Uuid::v7()->toBase58(), 0, 10));
    }

    private function date(mixed $valeur, string $champ): \DateTimeImmutable
    {
        if (!is_string($valeur) || '' === $valeur) {
            throw new UnprocessableEntityHttpException(sprintf('stay.error.%s_required', $champ));
        }

        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException(sprintf('stay.error.%s_invalid', $champ));
        }
    }
}
