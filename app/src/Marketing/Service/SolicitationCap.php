<?php

declare(strict_types=1);

namespace App\Marketing\Service;

use App\Crm\Entity\Client;
use App\Marketing\Entity\CampaignRecipient;
use App\Marketing\Enum\RecipientOutcome;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE PLAFOND DE SOLLICITATION — ce qui empêche de brûler un canal.
 *
 * Un client qui reçoit cinq campagnes en une semaine se désabonne, et **on perd le canal pour
 * toujours**. Une campagne qui touche moins de monde coûte une occasion ; un désabonnement coûte
 * toutes les suivantes.
 *
 * > **Mieux vaut une campagne qui touche moins de monde qu'un canal grillé.**
 *
 * ── CE QUI EST COMPTÉ, ET CE QUI NE L'EST PAS ───────────────────────────────────────────────────
 *
 * Seuls les **contacts réels** comptent. Un exclu n'a rien reçu : le compter le pénaliserait deux
 * fois — d'abord écarté, puis réputé sollicité. Un membre du groupe témoin non plus, par
 * construction : il n'a rien reçu, c'est tout son intérêt.
 *
 * Le compte porte sur **le canal**, pas sur la personne en général. Trois courriels et un SMS ne
 * font pas quatre sollicitations du même tuyau, et les seuils de tolérance ne sont pas les mêmes —
 * un SMS s'impose, un courriel attend.
 *
 * ── LE SEUIL N'EST PAS ENCORE RÉGLABLE, ET C'EST DIT ────────────────────────────────────────────
 *
 * La spec le veut réglable par établissement. Il ne l'est pas : ce serait une table de paramètres
 * pour une valeur que personne n'a encore eu l'occasion de contester, puisque aucun envoi réel
 * n'existe. Quatre par mois est un point de départ défendable, écrit à un seul endroit.
 *
 * Ce qui compte, c'est que le plafond EXISTE dès le premier envoi. Un plafond ajouté après coup
 * arrive toujours après le premier client perdu.
 */
final readonly class SolicitationCap
{
    /** Quatre messages par mois et par canal : un par semaine, pas davantage. */
    public const PAR_MOIS = 4;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function estAtteint(Client $client, string $canal): bool
    {
        $depuis = new \DateTimeImmutable('-30 days');

        $deja = (int) $this->entityManager->getRepository(CampaignRecipient::class)
            ->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.customerRef = :client')
            ->andWhere('r.channel = :canal')
            ->andWhere('r.notifiedAt >= :depuis')
            // Seuls les contacts réels : un exclu n'a rien reçu, un témoin non plus.
            ->andWhere('r.outcome IN (:contacts)')
            // Type `uuid` explicite sur une référence libre : sans lui, la comparaison ne compte
            // rien **et ne lève pas** (D58). Ici, zéro sollicitation antérieure voudrait dire
            // « plafond jamais atteint » — le plafond serait inerte, silencieusement.
            ->setParameter('client', $client->getId(), 'uuid')
            ->setParameter('canal', $canal)
            ->setParameter('depuis', $depuis)
            ->setParameter('contacts', [RecipientOutcome::Envoye->value, RecipientOutcome::Journalise->value])
            ->getQuery()
            ->getSingleScalarResult();

        return $deja >= self::PAR_MOIS;
    }
}
