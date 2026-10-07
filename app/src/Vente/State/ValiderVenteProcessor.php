<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
use App\Vente\Port\SaleSubscriptionInterface;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\ValiderVenteService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Valide et scelle une vente (POST /ventes/{id}/valider, CA-8/11/12/15). Refuse si reste dû > 0 (sauf
 * différé), décrémente le stock atomiquement, scelle l'opération NF525, émet/appaire les supports et
 * applique le seuil d'impression — le tout dans une seule transaction. Corps optionnel :
 *   { "supports": [{"ligne": uuid, "type": "qr", "identifiant": "…"}] }
 *
 * ⚠ ABONNEMENT CRÉÉ APRÈS LE COMMIT (spec-caisse-abonnement CP-1 G-1, plan CP-2 É1). Une ligne
 *   portant un produit à facette Formule crée son abonnement (`Membership`) via
 *   `App\Vente\Port\SaleSubscriptionInterface`, appelé ICI — après `service->valider()` et son
 *   `flush()`, donc après le commit réel du scellement NF525 — jamais dans la transaction scellée
 *   (D45, patron D7-bis / boutique en ligne `SouscriptionAbonnementEnLigneHandler` l.171→193). C'est
 *   la correction du montage transactionnel faux de l'ancienne branche `feature/caisse-abonnement`,
 *   qui créait l'abonnement DANS la transaction scellée (un refus y faisait rollback le scel). Un
 *   refus du port laisse ici la vente SCELLÉE et VALIDE (pas de rollback) : l'exception remonte
 *   telle quelle, la reprise se fait hors de cette transaction (G-5).
 *
 * ⚠ SAUF LE PAYEUR MANQUANT, REFUSÉ AVANT LE SCELLEMENT (décision de Maxime du 07/10, qui revoit
 *   G-5) : `assertSubscribable()` répond 422 et la vente reste ouverte, rien n'est scellé.
 *
 * @implements ProcessorInterface<Vente, Vente>
 */
final class ValiderVenteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ValiderVenteService $service,
        private readonly SaleSubscriptionInterface $abonnements,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Vente
    {
        \assert($data instanceof Vente);

        $overrides = [];
        foreach ($this->lecteur->corps()['supports'] ?? [] as $support) {
            if (\is_array($support) && isset($support['ligne'])) {
                $overrides[(string) $support['ligne']] = [
                    'type' => isset($support['type']) ? (string) $support['type'] : null,
                    'identifiant' => isset($support['identifiant']) ? (string) $support['identifiant'] : null,
                ];
            }
        }

        $this->abonnements->assertSubscribable($data);
        $this->service->valider($data, $overrides);
        $this->em->flush();

        // Après le commit réel (voir docblock de classe) : jamais dans la transaction de scellement.
        $this->abonnements->createSubscriptionsFromSale($data);

        return $data;
    }
}
