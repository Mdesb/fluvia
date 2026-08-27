<?php

declare(strict_types=1);

namespace App\Marketing\Service;

use App\Marketing\Entity\Campaign;
use App\Marketing\Entity\CampaignRecipient;
use App\Marketing\Enum\RecipientOutcome;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'ATTRIBUTION — la raison d'être du module.
 *
 * Sans elle, cet outil est un envoyeur de courriels de plus. Avec elle, il répond à la seule
 * question qui décide du budget de l'an prochain : **la campagne a-t-elle ramené quelqu'un ?**
 *
 * ── POURQUOI « COMBIEN SONT REVENUS » N'EST PAS LA RÉPONSE ──────────────────────────────────────
 *
 * Sur une audience de clients dormants, une part revient toujours — d'elle-même, parce que la saison
 * change ou parce qu'il pleut. Une campagne qui ne servirait à rien afficherait quand même « 240
 * personnes revenues ». Ce chiffre-là ne mesure pas la campagne, il mesure le mois de mars.
 *
 * Ce qu'on mesure ici est donc un **écart** entre deux groupes tirés de la même audience, dont un
 * seul a reçu le message. Ce que le message a produit est la différence, jamais le brut.
 *
 * ── POURQUOI ON COMPARE DES TAUX, JAMAIS DES TOTAUX ─────────────────────────────────────────────
 *
 * Le témoin est dix fois plus petit que le groupe contacté. « 12 000 € contre 1 100 € » ferait
 * croire à un triomphe alors que les deux groupes se comportent à l'identique. On ramène donc tout à
 * la personne : taux de retour, panier moyen.
 *
 * ── POURQUOI UN ÉCART S'ACCOMPAGNE TOUJOURS DE SA MARGE ─────────────────────────────────────────
 *
 * Sur 30 témoins, deux visites de plus ou de moins déplacent le taux de sept points. Rendre « +7 % »
 * sans dire « ± 12 » invite à conclure d'un bruit. La marge est calculée et rendue avec l'écart,
 * toujours ensemble — c'est elle qui distingue un résultat d'une impression.
 *
 * ── CE QUI EST FIGÉ, ET CE QUI SE RECALCULE ─────────────────────────────────────────────────────
 *
 * La liste des destinataires est **figée** à l'envoi : c'est la déclaration de rattachement exigée
 * par RG-CMP-12, et elle ne bouge plus. La mesure, elle, se **recalcule à chaque lecture** : le
 * client qui achète demain doit apparaître demain. Rien n'est stocké ici.
 *
 * > **Un état qui se calcule ne se stocke pas.**
 */
final readonly class AttributionMeasure
{
    /**
     * Seules les ventes VALIDÉES comptent comme un retour.
     *
     * Une vente en cours est un panier abandonné ; une vente annulée n'a pas eu lieu ; un avoir émis
     * est un retour d'argent. Compter l'une des trois pour un succès rendrait la campagne rentable
     * sur des ventes qui n'ont rapporté à personne.
     */
    private const VENTE_RETENUE = 'validee';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array<string, mixed> */
    public function mesurer(Campaign $campaign): array
    {
        $envoyeeLe = $campaign->getSentAt();
        if ($envoyeeLe === null) {
            // Rien à attribuer : personne n'a rien reçu. Le dire est plus utile que de rendre des
            // zéros, qu'on lirait comme « la campagne n'a rien produit ».
            return [
                'campagne' => $campaign->getLabel(),
                'mesurable' => false,
                'raison' => 'La campagne n’est pas encore partie.',
            ];
        }

        $jours = $campaign->getAttributionWindowDays();
        $fin = $envoyeeLe->modify('+' . $jours . ' days');
        $maintenant = new \DateTimeImmutable();
        $close = $maintenant >= $fin;

        $groupes = $this->repartir($campaign);
        $achats = $this->achatsSurLaFenetre(array_merge(...array_values($groupes)), $envoyeeLe, $fin);

        $contactes = $this->agreger($groupes['contactes'], $achats);
        $temoins = $this->agreger($groupes['temoins'], $achats);

        return [
            'campagne' => $campaign->getLabel(),
            'mesurable' => true,
            'fenetre' => [
                'jours' => $jours,
                'debut' => $envoyeeLe->format(\DATE_ATOM),
                'fin' => $fin->format(\DATE_ATOM),
                // Une mesure lue au 3e jour d'une fenêtre de 30 est provisoire. Ne pas le dire
                // laisserait conclure « la campagne n'a rien donné » à un dixième du chemin.
                'close' => $close,
                'joursRestants' => $close ? 0 : (int) $maintenant->diff($fin)->days,
            ],
            'contactes' => $contactes,
            'temoins' => $temoins,
            ...$this->comparer($contactes, $temoins),
        ];
    }

    /**
     * Les deux groupes comparables, et eux seuls.
     *
     * Les exclus ne forment pas un troisième groupe de comparaison : ils diffèrent **systématiquement**
     * de l'audience — ils n'ont pas consenti, ou pas de coordonnée. Les comparer aux contactés
     * mesurerait cette différence-là, pas la campagne.
     *
     * @return array{contactes: list<string>, temoins: list<string>}
     */
    private function repartir(Campaign $campaign): array
    {
        /** @var list<CampaignRecipient> $destinataires */
        $destinataires = $this->entityManager->getRepository(CampaignRecipient::class)
            ->findBy(['campaign' => $campaign]);

        $groupes = ['contactes' => [], 'temoins' => []];
        foreach ($destinataires as $destinataire) {
            $cle = match ($destinataire->getOutcome()) {
                RecipientOutcome::Envoye, RecipientOutcome::Journalise => 'contactes',
                RecipientOutcome::Temoin => 'temoins',
                RecipientOutcome::Exclu => null,
            };

            if ($cle !== null) {
                $groupes[$cle][] = (string) $destinataire->getCustomerRef();
            }
        }

        return $groupes;
    }

    /**
     * Les ventes de ces personnes, dans la fenêtre, et nulle part ailleurs.
     *
     * La borne basse est **stricte** : une vente conclue la seconde d'avant n'a pas été provoquée par
     * un message pas encore parti.
     *
     * Aucun filtre d'établissement : le client qui revient dépense là où il veut dans le groupe, et
     * la campagne l'a ramené quand même. Filtrer sur l'établissement émetteur ferait disparaître ces
     * visites-là — on mesurerait la caisse plutôt que la personne. Le cloisonnement est déjà tenu en
     * amont : ces identifiants viennent d'une liste résolue dans le périmètre du lecteur.
     *
     * @param list<string> $references
     *
     * @return array<string, array{visites: int, ca: string}>
     */
    private function achatsSurLaFenetre(array $references, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        if ($references === []) {
            return [];
        }

        // ⚠ D58 — `IN (:liste)` sur une colonne `uuid` ne trouve RIEN et ne lève pas : aucun type
        // scalaire ne s'applique à une liste. On passe donc les identifiants en binaire, en SQL.
        $binaires = array_map(
            static fn (string $reference): string => \Symfony\Component\Uid\Uuid::fromString($reference)->toBinary(),
            $references,
        );

        $lignes = $this->entityManager->getConnection()->executeQuery(
            'SELECT client, COUNT(*) AS visites, COALESCE(SUM(total), 0) AS ca '
            . 'FROM vente_vente '
            . 'WHERE client IN (?) AND statut = ? AND date > ? AND date <= ? '
            . 'GROUP BY client',
            [$binaires, self::VENTE_RETENUE, $debut->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')],
            [ArrayParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        )->fetchAllAssociative();

        $achats = [];
        foreach ($lignes as $ligne) {
            $reference = (string) \Symfony\Component\Uid\Uuid::fromBinary($ligne['client']);
            $achats[$reference] = [
                'visites' => (int) $ligne['visites'],
                'ca' => (string) $ligne['ca'],
            ];
        }

        return $achats;
    }

    /**
     * @param list<string>                                       $references
     * @param array<string, array{visites: int, ca: string}>     $achats
     *
     * @return array{effectif: int, revenus: int, visites: int, ca: string, tauxRetour: float, panierMoyen: string}
     */
    private function agreger(array $references, array $achats): array
    {
        $revenus = 0;
        $visites = 0;
        $centimes = 0;

        foreach ($references as $reference) {
            if (!isset($achats[$reference])) {
                continue;
            }

            ++$revenus;
            $visites += $achats[$reference]['visites'];
            $centimes += self::centimes($achats[$reference]['ca']);
        }

        $effectif = \count($references);

        return [
            'effectif' => $effectif,
            // « Revenus » compte des PERSONNES, « visites » compte des passages. Le taux de retour se
            // calcule sur les personnes : quelqu'un qui vient trois fois n'est pas revenu trois fois.
            'revenus' => $revenus,
            'visites' => $visites,
            'ca' => self::decimal($centimes),
            'tauxRetour' => $effectif === 0 ? 0.0 : round($revenus / $effectif * 100, 2),
            'panierMoyen' => $revenus === 0 ? '0.00' : self::decimal((int) round($centimes / $revenus)),
        ];
    }

    /**
     * L'ÉCART, ET CE QU'IL VAUT.
     *
     * Sans témoin, on refuse de conclure. Rendre le taux brut en le nommant « effet de la campagne »
     * serait le mensonge le plus facile de tout le module : le chiffre est flatteur, il est faux, et
     * rien dans l'écran ne le contredirait.
     *
     * @param array{effectif: int, revenus: int, ca: string, tauxRetour: float, panierMoyen: string} $contactes
     * @param array{effectif: int, revenus: int, ca: string, tauxRetour: float, panierMoyen: string} $temoins
     *
     * @return array<string, mixed>
     */
    private function comparer(array $contactes, array $temoins): array
    {
        if ($temoins['effectif'] === 0 || $contactes['effectif'] === 0) {
            return [
                'comparable' => false,
                'raison' => $temoins['effectif'] === 0
                    ? 'Sans groupe témoin, on ne peut pas distinguer ceux qui sont revenus grâce à la '
                        . 'campagne de ceux qui seraient revenus de toute façon.'
                    : 'Personne n’a été contacté : il n’y a rien à comparer.',
            ];
        }

        $ecart = round($contactes['tauxRetour'] - $temoins['tauxRetour'], 2);

        // Marge à 95 % sur la différence de deux proportions. Approximation normale : elle vaut ce
        // qu'elle vaut sur de petits effectifs — c'est précisément pour cela qu'on la publie.
        $p1 = $contactes['revenus'] / $contactes['effectif'];
        $p2 = $temoins['revenus'] / $temoins['effectif'];
        $marge = round(1.96 * sqrt(
            $p1 * (1 - $p1) / $contactes['effectif'] + $p2 * (1 - $p2) / $temoins['effectif']
        ) * 100, 2);

        // Ce que la campagne a produit EN PLUS : l'écart appliqué à l'effectif contacté. Négatif, il
        // reste rendu tel quel — un module qui ne sait afficher que des gains ne mesure rien.
        $visitesGagnees = (int) round($ecart / 100 * $contactes['effectif']);

        return [
            'comparable' => true,
            'ecartPoints' => $ecart,
            'margeErreur' => $marge,
            // Le seul verdict que les chiffres autorisent. En dessous de sa marge, un écart ne se
            // distingue pas de zéro : « on ne sait pas encore » est une réponse, « +3 % » n'en est
            // pas une quand la marge vaut 9.
            'concluant' => abs($ecart) > $marge,
            'visitesGagnees' => $visitesGagnees,
            'caGagne' => self::decimal($visitesGagnees * self::centimes($contactes['panierMoyen'])),
        ];
    }

    /**
     * Arithmetique en centimes.
     *
     * `bcmath` n'est pas installe sur l'image PHP du projet, et D2 interdit d'emprunter le
     * `MontantUtil` du CRM : le module Campagnes ne doit pas dependre du module Clients. Deux
     * lignes recopiees valent mieux qu'un lien entre modules qu'on ne saurait plus defaire.
     */
    private static function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    private static function decimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }
}
