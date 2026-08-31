<?php

declare(strict_types=1);

namespace App\Marketing\Service;

use App\Marketing\Entity\LoyaltyEntry;
use App\Marketing\Entity\LoyaltyRule;
use App\Marketing\Entity\LoyaltyTier;
use App\Organisation\Entity\Etablissement;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LE SOLDE DE POINTS — calculé de bout en bout, jamais stocké.
 *
 * Les points gagnés se relisent depuis les ventes validées, vente par vente, avec le barème en
 * vigueur **au jour de chaque vente**. C'est plus long qu'un compteur, et c'est le seul moyen
 * d'obtenir un solde qui reste juste :
 *
 *   - une vente annulée ou remboursée retire ses points d'elle-même ;
 *   - un changement de barème ne réécrit pas le passé ;
 *   - il n'y a pas d'événement à rejouer, donc pas d'événement à manquer.
 *
 * > **Un chiffre qu'on peut compter ne se recopie pas.**
 *
 * ── POURQUOI LES POINTS SONT PAR ÉTABLISSEMENT ──────────────────────────────────────────────────
 *
 * Un point gagné à la piscine ne s'échange pas à la patinoire. C'est la caisse qui finance la
 * récompense qui décide de l'accorder — un programme commun se paramètre, il ne se suppose pas.
 * (L'attribution des campagnes compte au contraire les ventes de tout le groupe : elle mesure si la
 * personne est revenue, pas qui paye la remise. Deux questions, deux périmètres.)
 *
 * ── CE QUE CETTE VERSION NE FAIT PAS, ET POURQUOI C'EST DÉLIBÉRÉ ────────────────────────────────
 *
 * **Les points n'expirent pas.** Une expiration silencieuse est la façon la plus sûre de perdre la
 * confiance qu'un programme de fidélité était censé construire : le client découvre au comptoir que
 * son solde a fondu. Tant que rien ne PRÉVIENT le client — courriel à J-30, mention sur le ticket —
 * l'expiration ne doit pas exister. Mieux vaut pas d'expiration qu'une expiration muette.
 */
final readonly class LoyaltyLedger
{
    /** Un point ne s'acquiert que sur une vente encaissée. */
    private const VENTE_RETENUE = 'validee';

    /** Le palier se lit sur douze mois glissants : il dit « bon client MAINTENANT ». */
    private const FENETRE_PALIER = '-12 months';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array<string, mixed> */
    public function etatDe(Uuid $client, Etablissement $etablissement): array
    {
        $gains = $this->gains($client, $etablissement);
        $mouvements = $this->mouvements($client, $etablissement);

        $depenses = 0;
        $ajustements = 0;
        foreach ($mouvements as $mouvement) {
            if ($mouvement->getPoints() < 0) {
                $depenses += -$mouvement->getPoints();
                continue;
            }

            $ajustements += $mouvement->getPoints();
        }

        $solde = $gains['total'] - $depenses + $ajustements;

        return [
            'client' => (string) $client,
            'solde' => $solde,
            'gagnes' => $gains['total'],
            'depenses' => $depenses,
            'ajustements' => $ajustements,
            // Le palier ne baisse pas quand on dépense : sinon utiliser sa fidélité ferait perdre
            // son statut, et le client apprendrait à ne jamais s'en servir.
            'cumuleDouzeMois' => $gains['douzeMois'],
            ...$this->paliers($gains['douzeMois'], $etablissement),
            'baremeCourant' => $this->baremeCourant($etablissement),
            'historique' => array_map(
                static fn (LoyaltyEntry $e): array => [
                    'le' => $e->getCreatedAt()->format(\DATE_ATOM),
                    'points' => $e->getPoints(),
                    'mouvement' => $e->getMovement()->label(),
                    'motif' => $e->getReason(),
                ],
                $mouvements,
            ),
            // Dit à l'écran, pas seulement au code : une promesse tacite se découvre au comptoir.
            'expirationDesPoints' => false,
        ];
    }

    /**
     * Les points gagnés, vente par vente, au barème du jour de la vente.
     *
     * @return array{total: int, douzeMois: int}
     */
    private function gains(Uuid $client, Etablissement $etablissement): array
    {
        $baremes = $this->entityManager->getRepository(LoyaltyRule::class)
            ->findBy(['establishment' => $etablissement], ['validFrom' => 'ASC']);

        if ($baremes === []) {
            // Aucun barème : aucun point. Prendre « un point par euro » par défaut inventerait une
            // promesse que l'exploitant n'a pas faite.
            return ['total' => 0, 'douzeMois' => 0];
        }

        $lignes = $this->entityManager->getConnection()->executeQuery(
            'SELECT date, total FROM vente_vente '
            . 'WHERE client = ? AND etablissement_id = ? AND statut = ? '
            . 'ORDER BY date',
            [
                $client->toBinary(),
                $etablissement->getId()?->toBinary(),
                self::VENTE_RETENUE,
            ],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        )->fetchAllAssociative();

        $depuis = new \DateTimeImmutable(self::FENETRE_PALIER);
        $total = 0;
        $douzeMois = 0;

        foreach ($lignes as $ligne) {
            $date = new \DateTimeImmutable((string) $ligne['date']);
            $bareme = $this->baremeAu($baremes, $date);
            if ($bareme === null) {
                // Vente antérieure au premier barème : elle n'a rien promis, elle ne rapporte rien.
                continue;
            }

            // Arrondi à l'euro INFÉRIEUR : accorder un point non gagné est une dette qu'on
            // découvre au moment de l'échanger.
            $points = (int) floor((float) $ligne['total']) * $bareme->getPointsPerEuro();
            $total += $points;

            if ($date >= $depuis) {
                $douzeMois += $points;
            }
        }

        return ['total' => $total, 'douzeMois' => $douzeMois];
    }

    /** @param list<LoyaltyRule> $baremes triés par date d'effet croissante */
    private function baremeAu(array $baremes, \DateTimeImmutable $quand): ?LoyaltyRule
    {
        $courant = null;
        foreach ($baremes as $bareme) {
            if ($bareme->getValidFrom() > $quand) {
                break;
            }

            $courant = $bareme;
        }

        return $courant;
    }

    /** @return list<LoyaltyEntry> */
    private function mouvements(Uuid $client, Etablissement $etablissement): array
    {
        /** @var list<LoyaltyEntry> $mouvements */
        $mouvements = $this->entityManager->getRepository(LoyaltyEntry::class)->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.establishment) = :etab')
            ->andWhere('e.customerRef = :client')
            // ⚠ D58 — sans le type explicite, la comparaison ne compte RIEN et ne lève pas. Un
            // solde faux se lit exactement comme un solde juste. Le piège vaut pour la référence
            // libre ET pour l'association : y passer l'ENTITÉ lie son identifiant sans son type.
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('client', $client, 'uuid')
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $mouvements;
    }

    /** @return array<string, mixed> */
    private function paliers(int $cumule, Etablissement $etablissement): array
    {
        /** @var list<LoyaltyTier> $paliers */
        $paliers = $this->entityManager->getRepository(LoyaltyTier::class)
            ->findBy(['establishment' => $etablissement], ['threshold' => 'ASC']);

        $atteint = null;
        $suivant = null;
        foreach ($paliers as $palier) {
            if ($palier->getThreshold() <= $cumule) {
                $atteint = $palier;
                continue;
            }

            $suivant ??= $palier;
        }

        return [
            'palier' => $atteint === null ? null : [
                'libelle' => $atteint->getLabel(),
                'seuil' => $atteint->getThreshold(),
            ],
            'palierSuivant' => $suivant === null ? null : [
                'libelle' => $suivant->getLabel(),
                'seuil' => $suivant->getThreshold(),
                // Le chiffre qui fait revenir : « il vous manque 40 points » agit, « vous avez
                // 260 points » n'agit pas.
                'pointsManquants' => $suivant->getThreshold() - $cumule,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function baremeCourant(Etablissement $etablissement): ?array
    {
        $baremes = $this->entityManager->getRepository(LoyaltyRule::class)
            ->findBy(['establishment' => $etablissement], ['validFrom' => 'ASC']);

        $bareme = $this->baremeAu($baremes, new \DateTimeImmutable());

        return $bareme === null ? null : [
            'pointsParEuro' => $bareme->getPointsPerEuro(),
            'depuis' => $bareme->getValidFrom()->format(\DATE_ATOM),
        ];
    }
}
