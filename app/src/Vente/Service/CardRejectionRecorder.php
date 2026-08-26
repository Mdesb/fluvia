<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Vente\Entity\CardRejection;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutTPE;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Consigne un refus de carte, **puis** l'annonce (PAY-3).
 *
 * **L'ordre est la règle, pas un détail d'implémentation.** On écrit, on vide, on publie. L'inverse
 * laisserait un abonné référencer une trace qui n'existe pas encore — et le bus de ce dépôt est
 * **synchrone** (`SymfonyEventBus::publish()` dispatche immédiatement), donc l'abonné s'exécuterait
 * réellement avant l'écriture. Ce n'est pas une course théorique, c'est l'ordre des lignes.
 *
 * **Pourquoi la trace existe alors que le contrat n'en demandait pas.** `claude-D` n'attendait qu'un
 * message sur le bus. Mais elle a elle-même établi que sa bascule carte → prélèvement **n'a aucun
 * client aujourd'hui** : aucun débit récurrent sur carte n'existe dans le produit. Son abonné est donc
 * le seul consommateur, et il n'agira sur aucun refus. **Publier sans écrire ne laisserait aucune
 * trace de la totalité des refus** — pas en cas de panne, mais en fonctionnement normal, dès le
 * premier jour. C'est D59 connu à l'avance : un mécanisme qui s'exécute et ne fait rien.
 *
 * **Un seul endroit, et c'est voulu.** Le refus de carte est produit dans `PaiementHandler`, au milieu
 * de l'encaissement. Y écrire la persistance et la publication aurait dispersé deux responsabilités
 * dans une méthode qui en a déjà quatre, et surtout : l'ordre « écrire puis publier » serait devenu
 * une convention à respecter plutôt qu'une propriété du code.
 */
final class CardRejectionRecorder
{
    public const EVENEMENT = 'sale.card_payment_rejected';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventBus $bus,
        private readonly PanierCalculateur $calculateur,
    ) {
    }

    public function consigner(Vente $vente, string $moyenCode, string $montant, StatutTPE $statut, ?string $refTpe): CardRejection
    {
        $refus = new CardRejection();
        $refus->setVente($vente)
            ->setPointDeVente($vente->getPointDeVente())
            ->setMoyenCode($moyenCode)
            ->setMontantCentimes($this->calculateur->centimes($montant))
            ->setStatutTpe($statut)
            ->setRefTpe($refTpe)
            ->setClient($vente->getClient())
            ->setEtablissement($vente->getEtablissement());

        $this->em->persist($refus);
        // Vidé ici, et pas laissé au `flush()` de l'appelant : l'événement part juste après et il
        // référence cette ligne. Un abonné synchrone qui irait la relire ne doit pas trouver le vide.
        $this->em->flush();

        $etablissement = $vente->getEtablissement();
        if ($etablissement !== null) {
            $this->bus->publish(new DomainEvent(
                self::EVENEMENT,
                new EventTenant($etablissement->getId()),
                new EventSubject('CardRejection', (string) $refus->getId()),
                [
                    // **`rejectionId` et non `paymentId`** — `claude-D` avait demandé le second, et il
                    // n'existe pas : un refus TPE ne crée aucun `Paiement`, c'est toute la règle
                    // CA-10. Nommer `paymentId` l'identifiant d'autre chose aurait donné à son abonné
                    // une clé d'idempotence dont le nom ment sur ce qu'elle désigne. Elle rend le même
                    // service : le même refus porte toujours le même identifiant, donc un événement
                    // redélivré met à jour la dette au lieu d'en créer une seconde.
                    'rejectionId' => (string) $refus->getId(),
                    'saleId' => (string) $vente->getId(),
                    // Entier, jamais décimal : `DebitPreNotifier::covers()` compare le montant annoncé
                    // et le montant prélevé. Un arrondi en route ferait reconnaître « une annonce qui
                    // ressemble à la bonne sans en être une » — tout vert, rien de couvert.
                    'amountCents' => $refus->getMontantCentimes(),
                    'establishmentId' => (string) $etablissement->getId(),
                    // Nul sur une vente anonyme : cas normal au guichet (US-L2-05). Un événement qu'on
                    // n'émet pas est une information qui n'existe plus ; un champ nul est une
                    // information qu'on choisit d'ignorer (arbitrage `claude-D`).
                    'customerId' => $vente->getClient() !== null ? (string) $vente->getClient() : null,
                ],
                // Acteur `null` : le refus est constaté par le terminal, il n'est décidé par personne.
                null,
                // L'instant du refus, pas celui du traitement (D37). Ici les deux coïncident — le
                // refus n'est jamais différé — et l'écrire explicitement empêche que ça change sans
                // qu'on s'en aperçoive.
                $refus->getDateHeure(),
            ));
        }

        return $refus;
    }
}
