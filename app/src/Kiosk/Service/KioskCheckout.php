<?php

declare(strict_types=1);

namespace App\Kiosk\Service;

use App\Kiosk\Entity\Kiosk;
use App\Kiosk\Entity\KioskOrder;
use App\Kiosk\Enum\KioskDelivery;
use App\Kiosk\Port\BadgeEncoderInterface;
use App\Kiosk\Port\BadgeEncodingResult;
use App\Offre\Enum\Canal;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\PaymentAttemptStatus as Status;
use App\Vente\Enum\StatutTPE;
use App\Vente\Enum\StatutVente;
use App\Vente\Enum\TypeSupport;
use App\Vente\Service\AjoutLigneHandler;
use App\Vente\Service\DocumentTicket;
use App\Vente\Service\GenerateurNumero;
use App\Vente\Service\PanierCalculateur;
use App\Vente\Service\PaymentAttemptConflict;
use App\Vente\Service\PaymentAttemptStore;
use App\Vente\Service\SettlementCoordinator;
use App\Vente\Service\ValiderVenteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Kiosk\Exception\KioskOutOfServiceException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * LE PARCOURS D'UNE BORNE, côté serveur : ouvrir la commande, payer au terminal, délivrer.
 *
 * Il ne réécrit RIEN de la caisse : la vente est une vente sans session sur le point de vente de la
 * borne (comme la vente directe, D44-bis — donc jamais d'espèces), ses lignes passent par
 * `AjoutLigneHandler` sur le canal « borne », le paiement par `SettlementCoordinator` (tentative écrite
 * avant l'effet, terminal du point de vente, issue asynchrone), la validation par `ValiderVenteService`
 * (scellement NF525, émission ou recharge des supports). Ce service n'ajoute que les règles d'un
 * appareil SANS CAISSIER :
 *
 * 1. Une borne ne touche qu'aux commandes qu'ELLE a ouvertes : toute commande se retrouve par sa vente
 *    ET par la borne qui la présente. Celle d'une autre borne, d'un autre établissement ou d'un
 *    guichet rend 404, comme une commande inconnue (D3).
 * 2. RIEN N'EST DÉLIVRÉ SANS PAIEMENT ACCEPTÉ. `complete()` refuse tant que le reste dû n'est pas nul :
 *    aucun support émis, aucune recharge créditée, aucune écriture NF525. Un refus de carte laisse la
 *    vente ouverte et vide de titre ; l'usager réessaie ou abandonne.
 * 3. La carte à recharger est vérifiée À L'OUVERTURE, avant le débit : on ne fait pas payer une
 *    recharge qui échouerait à la validation.
 * 4. Le terminal doit être PILOTÉ (`KioskCatalogue::terminalReady`) : le terminal externe attend une
 *    déclaration de caissier qu'aucune borne ne fera.
 */
final class KioskCheckout
{
    public const PAYMENT_METHOD = 'cb';
    public const MAX_LINES = 6;
    public const MAX_QUANTITY = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KioskCatalogue $catalogue,
        private readonly AjoutLigneHandler $lines,
        private readonly GenerateurNumero $numbers,
        private readonly SettlementCoordinator $settlement,
        private readonly PaymentAttemptStore $attempts,
        private readonly ValiderVenteService $validation,
        private readonly DocumentTicket $document,
        private readonly BadgeEncoderInterface $badges,
        private readonly PanierCalculateur $calculator,
    ) {
    }

    /** @return array<string, mixed> ce que l'écran de la borne doit savoir pour s'afficher */
    public function config(Kiosk $kiosk): array
    {
        $pdv = $kiosk->getPointOfSale();

        return [
            'kiosk' => ['id' => (string) $kiosk->getId(), 'label' => $kiosk->getLabel()],
            'establishment' => $kiosk->getEtablissement()?->getNom(),
            'idleSeconds' => $kiosk->getIdleSeconds(),
            'rechargeEnabled' => $kiosk->isRechargeEnabled(),
            'delivery' => $kiosk->getDelivery()->value,
            'terminalReady' => $this->catalogue->terminalReady($kiosk),
            'ticketPerUnit' => $pdv?->isUnBilletParUnite() ?? false,
            'offers' => $this->catalogue->offers($kiosk),
        ];
    }

    /**
     * Ouvre la commande : une vente sans session sur le point de vente de la borne, et ses lignes.
     *
     * @param array<string, mixed> $body { items: list<{offer, quantity}>, cardCode? }
     *
     * @return array<string, mixed>
     */
    public function open(Kiosk $kiosk, array $body): array
    {
        $this->assertInService($kiosk);
        $etablissement = $kiosk->getEtablissement() ?? throw new KioskOutOfServiceException('Borne sans établissement.');
        $pdv = $kiosk->getPointOfSale() ?? throw new KioskOutOfServiceException('Borne sans point de vente.');
        if ((string) $pdv->getEtablissement()?->getId() !== (string) $etablissement->getId()) {
            throw new KioskOutOfServiceException('Le point de vente de cette borne n\'est pas sur son site : borne hors service.');
        }

        $items = \is_array($body['items'] ?? null) ? array_values($body['items']) : [];
        if ($items === [] || \count($items) > self::MAX_LINES) {
            throw new UnprocessableEntityHttpException(sprintf('Choisissez de 1 à %d articles.', self::MAX_LINES));
        }
        $offers = [];
        foreach ($this->catalogue->offers($kiosk) as $offer) {
            $offers[$offer['offer']] = $offer;
        }
        $wanted = [];
        foreach ($items as $item) {
            $key = \is_array($item) && \is_string($item['offer'] ?? null) ? $item['offer'] : '';
            $quantity = \is_array($item) && \is_int($item['quantity'] ?? null) ? $item['quantity'] : 0;
            if (!isset($offers[$key])) {
                throw new UnprocessableEntityHttpException('Cet article n\'est plus proposé à la borne : revenez à l\'accueil.');
            }
            if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
                throw new UnprocessableEntityHttpException(sprintf('La quantité va de 1 à %d.', self::MAX_QUANTITY));
            }
            $wanted[] = ['offer' => $offers[$key], 'quantity' => $quantity];
        }

        $cardCode = \is_string($body['cardCode'] ?? null) ? trim($body['cardCode']) : '';
        if ($cardCode !== '') {
            $this->assertRechargeable($kiosk, $cardCode, $wanted);
        }

        return $this->em->wrapInTransaction(function () use ($kiosk, $pdv, $etablissement, $wanted, $cardCode): array {
            $sale = (new Vente())
                ->setPointDeVente($pdv)
                ->setEtablissement($etablissement)
                ->setNumero($this->numbers->numeroVenteDirecte($pdv))
                ->setOrigineHorsLigne(false);
            $this->em->persist($sale);
            $this->em->flush();

            $order = new KioskOrder($kiosk, $sale);
            foreach ($wanted as $want) {
                $line = $this->lines->ajouter($sale, [
                    'produit' => $want['offer']['product'],
                    'typeTarif' => $want['offer']['tariff'],
                    'quantite' => $want['quantity'],
                ], false, Canal::Borne);
                if ($cardCode !== '') {
                    $order->setRecharge($cardCode, $line->getId());
                }
            }
            $this->em->persist($order);
            $this->em->flush();

            return $this->orderView($order);
        });
    }

    /**
     * Demande le paiement au terminal de la borne. `key` (UUID tiré par l'écran à chaque essai) rend la
     * demande rejouable : un réseau qui coupe pendant l'attente ne débite pas deux fois.
     *
     * @param array<string, mixed> $body { key }
     *
     * @return array{0: int, 1: array<string, mixed>} le code HTTP et la réponse
     */
    public function pay(Kiosk $kiosk, string $saleId, array $body): array
    {
        $this->assertInService($kiosk);
        $order = $this->order($kiosk, $saleId);
        $sale = $order->getSale();
        if ($order->getCompletedAt() !== null || $order->getAbandonedAt() !== null) {
            throw new ConflictHttpException('Cette commande est close.');
        }
        $key = \is_string($body['key'] ?? null) && Uuid::isValid($body['key']) ? $body['key'] : null;
        if ($key === null) {
            throw new UnprocessableEntityHttpException('Clé de paiement manquante.');
        }

        try {
            $result = $this->settlement->settle($sale, ['moyen' => self::PAYMENT_METHOD, 'cleIdempotence' => $key]);
        } catch (PaymentAttemptConflict $conflict) {
            $payload = json_decode((string) $conflict->toResponse($sale)->getContent(), true);

            return [409, ['status' => 'busy', 'code' => $payload['code'] ?? null, 'message' => $payload['message'] ?? null, 'attempt' => $payload['tentative'] ?? null]];
        }

        if (isset($result['tentative'])) {
            return [202, ['status' => 'waiting', 'attempt' => PaymentAttemptStore::summary($result['tentative'])]];
        }
        if ($result['paiement'] instanceof Paiement) {
            return [200, ['status' => 'accepted', 'remaining' => $sale->getResteAPayer()]];
        }

        return [200, ['status' => $result['statutTPE'] === StatutTPE::Annule ? 'cancelled' : 'refused', 'terminalStatus' => $result['statutTPE']?->value]];
    }

    /**
     * Ce que devient la tentative pendant que l'usager tape son code (scrutée par l'écran).
     *
     * @return array<string, mixed>
     */
    public function payment(Kiosk $kiosk, string $saleId, string $attemptId): array
    {
        $order = $this->order($kiosk, $saleId);
        $attempt = $this->settlement->attemptOnSale($order->getSale(), $attemptId)
            ?? throw new NotFoundHttpException('Paiement introuvable.');
        $attempt = $this->settlement->judged($attempt);

        return ['status' => self::statusOf($attempt['status']), 'attempt' => PaymentAttemptStore::summary($attempt)];
    }

    /** @return array<string, mixed> */
    public function cancelPayment(Kiosk $kiosk, string $saleId, string $attemptId): array
    {
        $order = $this->order($kiosk, $saleId);
        try {
            $result = $this->settlement->cancel($order->getSale(), ['tentative' => $attemptId]);
        } catch (PaymentAttemptConflict $conflict) {
            // L'issue est arrivée avant l'annulation : elle tient (une acceptation gagne, le client a été débité).
            return $this->payment($kiosk, $saleId, $attemptId) + ['cancelRefused' => $conflict->getMessage()];
        }

        return ['status' => self::statusOf($result['attempt']['status']), 'attempt' => PaymentAttemptStore::summary($result['attempt'])];
    }

    /**
     * Valide la vente payée et rend ce que la borne délivre : le ticket (QR des supports), le ticket
     * carte du terminal, et l'issue du badge. Rejouée, elle rend le même document, marqué duplicata.
     *
     * @return array<string, mixed>
     */
    public function complete(Kiosk $kiosk, string $saleId): array
    {
        $order = $this->order($kiosk, $saleId);
        $sale = $order->getSale();
        if ($order->getAbandonedAt() !== null) {
            throw new ConflictHttpException('Cette commande a été abandonnée : rien n\'est délivré.');
        }
        if ($order->getCompletedAt() !== null) {
            return $this->receipt($order, true);
        }

        $this->em->refresh($sale);
        $this->calculator->recalculerVente($sale);
        if ($sale->getStatut() === StatutVente::EnCours) {
            if ($this->calculator->centimes($sale->getResteAPayer()) > 0) {
                throw new ConflictHttpException('Le paiement n\'est pas accepté : rien n\'est délivré. Réessayez le paiement ou abandonnez.');
            }
            $overrides = [];
            if ($order->getRechargeLineId() !== null && $order->getRechargeCardCode() !== null) {
                $overrides[(string) $order->getRechargeLineId()] = ['identifiant' => $order->getRechargeCardCode()];
            }
            $this->validation->valider($sale, $overrides);
        } elseif (!$sale->estScellee()) {
            throw new ConflictHttpException('Cette commande a été annulée à l\'accueil : rien n\'est délivré.');
        }

        $supports = $this->em->getRepository(BilletSupport::class)->findBy(['vente' => $sale]);
        if ($kiosk->getDelivery() === KioskDelivery::Badge && $supports !== []) {
            $outcome = null;
            foreach ($supports as $support) {
                $result = $this->badges->encode($kiosk, $support);
                if (!$result->isIssued()) {
                    $outcome = $result;
                    break;
                }
                $outcome ??= $result;
            }
            if ($outcome instanceof BadgeEncodingResult) {
                $order->setBadgeOutcome($outcome->status, $outcome->message ?? $outcome->badgeReference);
            }
        }

        // La borne imprime : la vente est marquée imprimée, et toute réédition au guichet dira DUPLICATA.
        $sale->setImprime(true);
        $order->markCompleted(new \DateTimeImmutable());
        $this->em->flush();

        return $this->receipt($order, false);
    }

    /** @return array<string, mixed> */
    public function abandon(Kiosk $kiosk, string $saleId): array
    {
        $order = $this->order($kiosk, $saleId);
        $sale = $order->getSale();
        if ($order->getCompletedAt() !== null) {
            throw new ConflictHttpException('Cette commande est délivrée.');
        }
        if ($order->getAbandonedAt() !== null) {
            return ['status' => 'abandoned'];
        }
        if ($this->settlement->holdingAttempt($sale) !== null) {
            throw new ConflictHttpException('Un paiement est en cours sur le terminal : attendez sa réponse.');
        }
        $this->em->refresh($sale);
        if ($sale->estScellee() || !$sale->getPaiements()->isEmpty()) {
            throw new ConflictHttpException('Cette commande a déjà été réglée : elle ne s\'abandonne pas.');
        }
        foreach ($sale->getLignes()->toArray() as $line) {
            $sale->removeLigne($line);
            $this->em->remove($line);
        }
        $this->calculator->recalculerVente($sale);
        $order->markAbandoned(new \DateTimeImmutable());
        $this->em->flush();

        return ['status' => 'abandoned'];
    }

    /**
     * La commande de CETTE borne pour cette vente — sinon 404, quelle que soit la raison (D3).
     */
    private function order(Kiosk $kiosk, string $saleId): KioskOrder
    {
        $sale = Uuid::isValid($saleId) ? $this->em->find(Vente::class, Uuid::fromString($saleId)) : null;
        if (!$sale instanceof Vente || (string) $sale->getEtablissement()?->getId() !== (string) $kiosk->getEtablissement()?->getId()) {
            throw new NotFoundHttpException('Commande introuvable.');
        }
        $order = $this->em->getRepository(KioskOrder::class)->findOneBy(['sale' => $sale, 'kiosk' => $kiosk]);

        return $order instanceof KioskOrder ? $order : throw new NotFoundHttpException('Commande introuvable.');
    }

    private function assertInService(Kiosk $kiosk): void
    {
        if (!$kiosk->isActive()) {
            throw new KioskOutOfServiceException('Cette borne est désactivée.');
        }
        if (!$this->catalogue->terminalReady($kiosk)) {
            throw new KioskOutOfServiceException('Le terminal de paiement de cette borne n\'est pas piloté : la borne est hors service, adressez-vous à l\'accueil.');
        }
    }

    /**
     * @param list<array{offer: array<string, mixed>, quantity: int}> $wanted
     */
    private function assertRechargeable(Kiosk $kiosk, string $cardCode, array $wanted): void
    {
        if (!$kiosk->isRechargeEnabled()) {
            throw new UnprocessableEntityHttpException('Cette borne ne recharge pas les cartes.');
        }
        if (\count($wanted) !== 1 || $wanted[0]['quantity'] !== 1 || ($wanted[0]['offer']['card'] ?? false) !== true) {
            throw new UnprocessableEntityHttpException('Une recharge porte sur une seule formule de carte, en un exemplaire.');
        }
        if (mb_strlen($cardCode) > 128) {
            throw new NotFoundHttpException('Carte inconnue : vérifiez le numéro, ou adressez-vous à l\'accueil.');
        }
        $support = $this->em->getRepository(BilletSupport::class)->findOneBy(['identifiantSupport' => $cardCode]);
        // Une carte d'un autre établissement est « inconnue » comme une carte qui n'existe pas (RG-CQ1-06).
        if (!$support instanceof BilletSupport
            || $support->getType() !== TypeSupport::Carte
            || (string) $support->getVente()?->getEtablissement()?->getId() !== (string) $kiosk->getEtablissement()?->getId()) {
            throw new NotFoundHttpException('Carte inconnue : vérifiez le numéro, ou adressez-vous à l\'accueil.');
        }
    }

    /** @return array<string, mixed> */
    private function orderView(KioskOrder $order): array
    {
        $sale = $order->getSale();
        $lines = [];
        foreach ($sale->getLignes() as $line) {
            $lines[] = [
                'label' => $line->getLibelleProduit(),
                'tariff' => $line->getLibelleTypeTarif(),
                'quantity' => $line->getQuantite(),
                'amount' => $line->getMontantLigne(),
            ];
        }

        return [
            'order' => (string) $sale->getId(),
            'number' => $sale->getNumero(),
            'lines' => $lines,
            'total' => $sale->getTotal(),
            'remaining' => $sale->getResteAPayer(),
            'recharge' => $order->getRechargeCardCode() !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function receipt(KioskOrder $order, bool $duplicate): array
    {
        $sale = $order->getSale();
        $supports = [];
        foreach ($this->em->getRepository(BilletSupport::class)->findBy(['vente' => $sale]) as $support) {
            $supports[] = [
                'code' => $support->getIdentifiantSupport(),
                'ligne' => $support->getLigne() !== null ? (string) $support->getLigne()->getId() : null,
                'type' => $support->getType()->value,
            ];
        }
        $cardReceipt = null;
        foreach ($sale->getPaiements() as $payment) {
            $key = $payment->getCleIdempotence();
            $attempt = $key !== null ? $this->attempts->byKey($key) : null;
            if ($attempt !== null && $attempt['customerReceipt'] !== null) {
                $cardReceipt = $attempt['customerReceipt'];
            }
        }
        $badgeIssued = $order->getBadgeStatus() === BadgeEncodingResult::ISSUED;

        return [
            'order' => (string) $sale->getId(),
            'ticket' => $this->document->pour($sale, $duplicate) + [
                'supports' => $supports,
                'codeSupport' => $supports[0]['code'] ?? null,
                'billetParUnite' => $sale->getPointDeVente()?->isUnBilletParUnite() ?? false,
                'ticketClientTerminal' => $cardReceipt,
            ],
            'recharge' => $order->getRechargeCardCode() !== null,
            'badge' => $order->getBadgeStatus() !== null ? ['status' => $order->getBadgeStatus(), 'message' => $badgeIssued ? null : $order->getBadgeMessage()] : null,
            'printTicket' => !$badgeIssued,
        ];
    }

    private static function statusOf(Status $status): string
    {
        return match ($status) {
            Status::Accepted, Status::DeclaredAccepted => 'accepted',
            Status::Refused => 'refused',
            Status::Cancelled, Status::DeclaredNotProcessed => 'cancelled',
            Status::Failed => 'failed',
            Status::Unresolved => 'unresolved',
            Status::Pending, Status::Sent => 'waiting',
        };
    }
}
