<?php

declare(strict_types=1);

namespace App\Sepa\Service;

use App\Sepa\Entity\CardFallbackDebt;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\PreNotificationReason;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Exception\CardFallbackRefusedException;
use App\Sepa\Exception\PreNotificationRefusedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La bascule carte → prélèvement après un refus de carte (PAY-2, D43).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ **RIEN N'APPELLE ENCORE CE SERVICE, ET C'EST ÉCRIT ICI POUR QUE PERSONNE NE CROIE LE CONTRAIRE.**
 *
 * Le déclencheur est le refus de carte, qui vit dans `App\Vente` et **n'existe pas encore** : c'est
 * `PAY-3`, chez `claude-G`. Tant qu'il n'a pas atterri, cette bascule ne se produit jamais.
 *
 * `CardDebitFallbackNonBrancheTest` épingle cette absence et **échouera le jour où quelqu'un appellera
 * ce service** — son message dira alors quoi supprimer. C'est délibéré : j'ai passé la journée à
 * trouver des mécanismes qui existent sans que rien ne les appelle, et le seul moyen de ne pas en
 * livrer un quinzième est que l'absence parle d'elle-même.
 *
 * **Le contrat attendu de `App\Vente`** — écrit ici plutôt que dans un message, pour qu'il survive à
 * la conversation : un événement `sale.card_payment_rejected` portant `paymentId`, `saleId`,
 * `amountCents`, `establishmentId`, `customerId`, `occurredAt` (l'instant du refus, pas celui du
 * traitement — D37). Un événement et non un appel direct : `App\Sepa` ne doit pas dépendre de
 * `App\Vente` (D2/D8).
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * **Ce que la bascule fait, et ce qu'elle ne fait surtout pas.** Elle ne prélève pas. Elle enregistre
 * une dette dont la date d'exigibilité est la première date **après que le préavis aura couru**, et
 * elle envoie ce préavis. Prélever tout de suite serait un prélèvement dont le client n'a pas été
 * prévenu, sur un moyen qu'il ne s'attendait pas à voir utilisé ce mois-ci — la situation exacte où
 * l'absence de préavis se conteste, et où elle se conteste avec raison.
 *
 * **Le contrôle du mandat n'est pas une précondition technique : c'est le filtre métier du lot.**
 * Trouvé en répondant à `claude-G`, qui me demandait quoi faire d'un refus de carte sur une vente
 * anonyme. Au comptoir, la bascule est une mauvaise réponse **même si un mandat existe** : une carte
 * refusée devant un client qui est là se règle en trente secondes, il paie autrement. Lui annoncer un
 * prélèvement dans quatorze jours lui imposerait un débit qu'il n'a pas choisi, pour une transaction
 * qu'il pouvait solder sur place.
 *
 * La bascule n'a de sens que quand **le client n'est pas devant nous** — renouvellement, paiement à
 * distance. Et ce qui sépare les deux n'est pas la présence d'un client identifié, mais celle d'un
 * **mandat actif**, qui n'existe que dans une relation suivie. Le refus ci-dessous sélectionne donc
 * exactement la population pour qui la bascule est utile : c'est un heureux hasard qu'il faut écrire,
 * parce que quelqu'un finira par vouloir « assouplir » ce contrôle pour élargir la couverture.
 *
 * **Elle refuse s'il n'y a pas de mandat actif, et ce refus n'a pas d'échappatoire.** Une bascule sans
 * mandat serait un prélèvement sans autorisation. Le mandat n'est pas une commodité qu'on cherche
 * après coup : c'est ce qui rend la bascule possible, et c'est pourquoi les deux moyens de paiement
 * sont collectés ensemble à la souscription plutôt qu'au moment du refus — au moment du refus, le
 * client n'est plus devant nous.
 *
 * **Aucun numéro de carte n'est lu, stocké, ni même reçu.** Ce service ne connaît qu'un montant, une
 * référence et un mandat. C'était la contrainte non négociable du lot, et la meilleure façon de la
 * tenir est de ne jamais faire passer la donnée par ici.
 */
final class CardDebitFallback
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DebitPreNotifier $preNotifier,
    ) {
    }

    /**
     * Bascule une somme refusée par carte vers le prélèvement, et prévient le client.
     *
     * Idempotente sur le couple (mandat, référence) : rejouer le même refus met à jour la dette au lieu
     * d'en empiler une seconde. Un événement redélivré ne doit pas faire payer deux fois.
     *
     * @param string $originReference l'identifiant du paiement refusé, tel que la caisse le connaît ;
     *                                la même chaîne servira au préavis et à l'échéance présentée à la
     *                                collecte
     *
     * @throws CardFallbackRefusedException   si le mandat manque ou n'est pas actif
     * @throws PreNotificationRefusedException si le préavis n'a pas pu partir — et alors **aucune dette
     *                                         n'est enregistrée** : une dette prélevable sans préavis
     *                                         parti serait pire que pas de bascule du tout
     */
    public function basculer(
        ?MandatSepa $mandate,
        string $originReference,
        int $amountCents,
        ?Uuid $invoiceId,
        \DateTimeImmutable $at,
    ): CardFallbackDebt {
        if (!$mandate instanceof MandatSepa) {
            throw new CardFallbackRefusedException(
                'Aucun mandat SEPA : la bascule est impossible. Prélever sans mandat serait prélever '
                .'sans autorisation. Le mandat se signe à la souscription, pas au moment du refus — au '
                .'moment du refus, le client n\'est plus devant nous.'
            );
        }

        if (StatutMandatSepa::Actif !== $mandate->getStatut()) {
            throw new CardFallbackRefusedException(sprintf(
                'Mandat « %s » : statut « %s ». Un mandat révoqué ou suspendu n\'autorise plus rien.',
                $mandate->getRum(),
                $mandate->getStatut()->value,
            ));
        }

        if ($amountCents <= 0) {
            throw new CardFallbackRefusedException('Une bascule porte sur une somme à percevoir.');
        }

        $exigibleLe = $this->preNotifier->earliestDebitDate($mandate, $at);

        // Le préavis d'abord : s'il ne part pas, rien n'est enregistré. L'ordre n'est pas indifférent —
        // l'inverse laisserait une dette prélevable derrière un préavis qui n'est jamais parti.
        $this->preNotifier->announce(
            $mandate,
            $originReference,
            $amountCents,
            $exigibleLe,
            PreNotificationReason::CardFallback,
            $at,
        );

        $dette = $this->existante($mandate, $originReference) ?? new CardFallbackDebt();
        $dette->setMandate($mandate)
            ->setOriginReference($originReference)
            ->setAmountCents($amountCents)
            ->setDueDate($exigibleLe)
            ->setInvoiceId($invoiceId)
            ->setCreatedAt($at);

        $this->em->persist($dette);
        $this->em->flush();

        return $dette;
    }

    private function existante(MandatSepa $mandate, string $originReference): ?CardFallbackDebt
    {
        return $this->em->getRepository(CardFallbackDebt::class)->findOneBy([
            'mandate' => $mandate,
            'originReference' => $originReference,
        ]);
    }
}
