<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Service\ClotureHandler;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `POST /compta/periodes/{id}/simuler-cloture` — ce que la clôture enregistrerait, sans rien enregistrer.
 *
 * **Pourquoi cette route existe.** La clôture est définitive : aucun code de ce dépôt ne repasse une
 * période à `Ouverte`, et elle fige un arrêté chiffré — produits, TVA, encaissements, nombre
 * d'écritures. Or ces montants n'étaient calculés qu'**après** la clôture. L'exploitant validait donc
 * à l'aveugle le seul geste irréversible du module, et la meilleure fenêtre de confirmation possible
 * se réduisait à « faites-moi confiance ».
 *
 * **Les mêmes montants, par le même code.** `ClotureHandler::arrete()` sert la simulation et la
 * clôture. Un aperçu calculé à part finirait par annoncer autre chose que ce que la clôture
 * enregistre, et la divergence se découvrirait sur un arrêté — trop tard, et sur le document qui fait
 * foi.
 *
 * **Rend aussi les points bloquants.** Montrer les montants sans dire que la clôture refusera encore
 * ferait promettre un geste qui échouera : l'exploitant lirait un arrêté crédible, cliquerait, et
 * recevrait un refus qu'il aurait pu voir venir.
 *
 * **Pourquoi un POST pour une lecture, et c'est délibéré.** `read: true` est ce qui fait résoudre la
 * période par le provider Doctrine, donc **à travers l'extension de périmètre**. Un provider qui
 * ferait son propre `find()` court-circuiterait le cloisonnement, et rejouer la règle de tenant à la
 * main est précisément la famille de défauts que ce dépôt a rencontrée seize fois. Entre une méthode
 * HTTP discutable et un contrôle d'accès dupliqué, le choix n'est pas serré. Rien n'est écrit : le
 * nom de la route dit « simuler », et cette classe ne persiste ni ne modifie quoi que ce soit.
 */
final class SimulateClosureProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ClotureHandler $cloture,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof PeriodeComptable);

        return new JsonResponse([
            'periode' => (string) $data->getId(),
            'statut' => $data->getStatut()->value,
            'arrete' => $this->cloture->arrete($data),
            'pointsBloquants' => $this->cloture->pointsBloquants($data),
            // Dit au client ce qu'il doit annoncer, plutôt que de le lui faire deviner d'un statut.
            'definitive' => true,
        ]);
    }
}
