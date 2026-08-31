<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Reservation\Dto\ResourceOccupancy;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\IndisponibiliteRessource;
use App\Reservation\Entity\Ressource;
use App\Reservation\Service\JaugeCreneauGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `GET /reservation/ressources/{id}/occupation?du=…&au=…` — ce qu'un calendrier a besoin de savoir,
 * en une requête.
 *
 * **Ce que ce fournisseur ne fait pas : recalculer.** L'occupation vient de `JaugeCreneauGuard`, le
 * service qui **décide si une réservation est acceptée**. Deux implémentations de la jauge seraient la
 * pire divergence possible du dépôt : l'écran annoncerait de la place là où le serveur refuse, ou
 * l'inverse.
 *
 * Il appelle la variante **en lot** : un mois de créneaux sur une ressource, c'est quelques centaines
 * de lignes, donc quelques centaines de requêtes si on interroge créneau par créneau. Le défaut ne se
 * voit pas en test — on y compte trois créneaux — il se voit sur la vue qu'on ouvre tous les matins.
 *
 * **Et il ne rend aucune donnée personnelle.** Un balayage mensuel diffuserait les noms de tous les
 * réservants du mois à quiconque ouvre le calendrier ; un besoin de « réservé par » se sert par une
 * requête ciblée, au clic, et c'est une décision qui revient à Maxime.
 *
 * @implements ProviderInterface<ResourceOccupancy>
 */
final class OccupancyProvider implements ProviderInterface
{
    /** Une plage plus longue n'est pas un calendrier, c'est un export — et il n'est pas paginé. */
    private const JOURS_MAX = 366;

    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private readonly ProviderInterface $item,
        private readonly EntityManagerInterface $em,
        private readonly JaugeCreneauGuard $jauge,
        private readonly RequestStack $requetes,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ResourceOccupancy
    {
        // La ressource passe par le fournisseur Doctrine standard : elle hérite ainsi du cloisonnement
        // porté par l'extension de périmètre, au lieu d'un `find()` qui l'aurait court-circuité.
        $ressource = $this->item->provide($operation, $uriVariables, $context);
        if (!$ressource instanceof Ressource) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }

        $requete = $this->requetes->getCurrentRequest();
        $du = $this->jour($requete?->query->get('du'), 'du');
        $au = $this->jour($requete?->query->get('au'), 'au');

        if ($au < $du) {
            throw new UnprocessableEntityHttpException('La date de fin précède la date de début.');
        }
        if ($du->diff($au)->days > self::JOURS_MAX) {
            throw new UnprocessableEntityHttpException(sprintf('Plage trop longue : %d jours au maximum.', self::JOURS_MAX));
        }

        // `au` est inclusif côté appelant : « du 1er au 30 » comprend le 30 entier. Le borner à
        // minuit ferait disparaître une journée complète de créneaux, et personne ne le remarquerait
        // sur un mois de trente jours qui en afficherait vingt-neuf.
        $fin = $au->modify('+1 day');

        /** @var list<Creneau> $creneaux */
        $creneaux = $this->em->getRepository(Creneau::class)->createQueryBuilder('c')
            ->andWhere('c.ressource = :ressource')
            ->andWhere('c.debut >= :du')
            ->andWhere('c.debut < :fin')
            // D58 — l'identifiant et son type, jamais l'entité.
            ->setParameter('ressource', $ressource->getId(), 'uuid')
            ->setParameter('du', $du)
            ->setParameter('fin', $fin)
            ->orderBy('c.debut', 'ASC')
            ->getQuery()
            ->getResult();

        $occupees = $this->jauge->placesOccupeesPour($creneaux);

        $lignes = [];
        foreach ($creneaux as $creneau) {
            $prises = $occupees[(string) $creneau->getId()] ?? 0;
            $lignes[] = [
                'creneau' => (string) $creneau->getId(),
                'debut' => $creneau->getDebut()->format(\DATE_ATOM),
                'fin' => $creneau->getFin()->format(\DATE_ATOM),
                'activite' => $creneau->getActivite()?->getLibelle(),
                'capacite' => $creneau->getCapacite(),
                'occupees' => $prises,
                // Rendu par le serveur plutôt que laissé à `capacite - occupees` : aujourd'hui c'est
                // une soustraction, demain une règle de sur-réservation ou de quota par public la
                // rendra fausse — **en continuant de donner un nombre plausible** (`claude-H`).
                'restantes' => max(0, $creneau->getCapacite() - $prises),
                'statut' => $creneau->getStatut()->value,
                // Troisième état, et non une nuance des deux autres : « puis-je réserver ce créneau »
                // a trois réponses. Le poser sur l'échelle libre↔complet le ferait lire comme l'un des
                // deux — et on réserverait dessus, ou on renoncerait à un créneau disponible.
                'enAttenteArbitrage' => $creneau->isEnAttenteArbitrage(),
            ];
        }

        /** @var list<IndisponibiliteRessource> $indisponibilites */
        $indisponibilites = $this->em->getRepository(IndisponibiliteRessource::class)->createQueryBuilder('i')
            ->andWhere('i.ressource = :ressource')
            // Chevauchement, pas inclusion : une fermeture annuelle commencée en août et finie en
            // octobre doit apparaître sur le calendrier de septembre, qu'elle ne contient pourtant ni
            // par son début ni par sa fin.
            ->andWhere('i.debut < :fin')
            ->andWhere('i.fin > :du')
            ->setParameter('ressource', $ressource->getId(), 'uuid')
            ->setParameter('du', $du)
            ->setParameter('fin', $fin)
            ->orderBy('i.debut', 'ASC')
            ->getQuery()
            ->getResult();

        $fermetures = [];
        foreach ($indisponibilites as $indisponibilite) {
            $fermetures[] = [
                'debut' => $indisponibilite->getDebut()->format(\DATE_ATOM),
                'fin' => $indisponibilite->getFin()->format(\DATE_ATOM),
                'motif' => $indisponibilite->getMotif(),
            ];
        }

        return new ResourceOccupancy(
            ressource: (string) $ressource->getId(),
            libelle: $ressource->getLibelle(),
            du: $du->format('Y-m-d'),
            au: $au->format('Y-m-d'),
            creneaux: $lignes,
            fermetures: $fermetures,
        );
    }

    private function jour(mixed $brut, string $champ): \DateTimeImmutable
    {
        if (!\is_string($brut) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $brut) !== 1) {
            // Refuser plutôt qu'interpréter : une date illisible retombée sur « aujourd'hui »
            // afficherait un mois qui n'est pas celui qu'on regarde, sans rien signaler.
            throw new UnprocessableEntityHttpException(sprintf('Paramètre « %s » attendu au format AAAA-MM-JJ.', $champ));
        }

        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $brut);
        if ($jour === false || $jour->format('Y-m-d') !== $brut) {
            throw new UnprocessableEntityHttpException(sprintf('Date invalide pour « %s » : « %s ».', $champ, $brut));
        }

        return $jour;
    }
}
