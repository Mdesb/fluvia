<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Crm\Entity\Client;
use App\Crm\Entity\CommercialActivity;
use App\Crm\Entity\Opportunity;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `GET /crm/relances` — ce qu'il reste à faire, **déduit et jamais tenu à la main**.
 *
 * **Une relance ne se coche pas : elle se remplace en agissant.** Le jour où l'on rappelle, on
 * enregistre un nouvel échange, et c'est lui qui porte — ou non — la relance suivante. Une relance est
 * donc en attente tant qu'**aucune activité plus récente n'existe sur la même cible**.
 *
 * > **Une tâche qu'il faut penser à cocher est une tâche qui reste ouverte pour toujours.**
 *
 * C'est aussi ce qui évite d'ouvrir une seconde liste de travail à côté du module `Project` : il n'y a
 * pas d'objet « tâche commerciale », seulement le prochain geste du dernier échange.
 *
 * **Le retard est calculé, pas stocké.** Une échéance comparée à aujourd'hui — en faire un état
 * obligerait à le maintenir toutes les nuits, et une relance serait « à l'heure » jusqu'au prochain
 * passage.
 *
 * @cloisonnement-verifie: la requête filtre sur l'établissement ACTIF résolu par
 * `ContexteEtablissement`, et `PermissionVoter` a déjà refusé l'accès si l'utilisateur n'y est pas
 * affecté — les droits sont l'union des permissions des affectations SUR cet établissement.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class FollowUpProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par défaut : sans établissement actif, aucune relance. Rendre « tout » serait
            // la seule erreur irrattrapable de ce fichier.
            return new JsonResponse(['relances' => [], 'total' => 0, 'enRetard' => 0]);
        }

        /** @var list<CommercialActivity> $activites */
        $activites = $this->em->getRepository(CommercialActivity::class)->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.establishment) = :etab')
            // Type `uuid` explicite : sur un identifiant à type personnalisé, une comparaison sans
            // type ne compte rien **et ne lève pas** (D58). Ici, zéro relance ressemblerait à un CRM
            // à jour — la plus rassurante des réponses fausses.
            ->setParameter('etab', $actif, 'uuid')
            ->orderBy('a.occurredAt', 'DESC')
            ->getQuery()
            ->getResult();

        // LA DERNIERE ACTIVITE PAR CIBLE FAIT FOI.
        //
        // Les activités arrivent de la plus récente à la plus ancienne : la première rencontrée pour
        // une cible donnée est donc la dernière en date, et les suivantes sont dépassées. Une relance
        // portée par une activité dépassée a déjà été honorée — quelqu'un a rappelé.
        $vues = [];
        $relances = [];
        $aujourdhui = new \DateTimeImmutable('today');
        $enRetard = 0;

        foreach ($activites as $activite) {
            foreach ($this->cibles($activite) as $cle) {
                if (isset($vues[$cle])) {
                    continue 2;
                }
            }
            foreach ($this->cibles($activite) as $cle) {
                $vues[$cle] = true;
            }

            $echeance = $activite->getNextActionAt();
            if ($echeance === null) {
                continue;
            }

            $retard = $echeance < $aujourdhui;
            if ($retard) {
                ++$enRetard;
            }

            $relances[] = [
                'id' => (string) $activite->getId(),
                'echeance' => $echeance->format('Y-m-d'),
                'enRetard' => $retard,
                'aFaire' => $activite->getNextAction(),
                'dernierEchange' => [
                    'type' => $activite->getType()->value,
                    'typeLibelle' => $activite->getType()->label(),
                    'le' => $activite->getOccurredAt()->format(\DATE_ATOM),
                    'resume' => $activite->getSummary(),
                ],
                'client' => $this->nom($activite->getCustomer()),
                'clientId' => $activite->getCustomer()?->getId() !== null
                    ? (string) $activite->getCustomer()->getId()
                    : null,
                'affaire' => $activite->getOpportunity()?->getTitle(),
                'affaireId' => $activite->getOpportunity()?->getId() !== null
                    ? (string) $activite->getOpportunity()->getId()
                    : null,
            ];
        }

        // Les plus en retard d'abord : c'est l'ordre dans lequel on rattrape.
        usort($relances, static fn (array $a, array $b): int => $a['echeance'] <=> $b['echeance']);

        return new JsonResponse([
            'relances' => $relances,
            'total' => \count($relances),
            'enRetard' => $enRetard,
        ]);
    }

    /**
     * Les cibles d'une activité — client, affaire, ou les deux.
     *
     * Une activité attachée aux deux « consomme » les deux : rappeler à propos d'une affaire, c'est
     * avoir rappelé le client. L'inverse produirait deux relances pour un seul appel.
     *
     * @return list<string>
     */
    private function cibles(CommercialActivity $activite): array
    {
        $cles = [];
        if ($activite->getCustomer() instanceof Client) {
            $cles[] = 'c:' . $activite->getCustomer()->getId();
        }
        if ($activite->getOpportunity() instanceof Opportunity) {
            $cles[] = 'o:' . $activite->getOpportunity()->getId();
        }

        return $cles;
    }

    private function nom(?Client $client): ?string
    {
        if ($client === null) {
            return null;
        }

        $nom = $client->getRaisonSociale();
        if ($nom !== null && trim($nom) !== '') {
            return $nom;
        }

        $nom = trim(($client->getPrenom() ?? '') . ' ' . ($client->getNom() ?? ''));

        return $nom !== '' ? $nom : $client->getEmail();
    }
}
