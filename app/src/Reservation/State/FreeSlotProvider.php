<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Ressource;
use App\Reservation\Service\FreeSlotFinder;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /reservation/creneaux-libres?activite=…&date=…[&ressource=…][&pas=…]`
 *
 * Les débuts possibles pour une prestation, un jour donné — avec un praticien nommé, ou **avec qui
 * est libre**.
 *
 * **Le second cas double la valeur de l'écran, et c'est celui qui remplit un agenda.** Un client qui
 * demande « samedi matin » se moque de savoir avec qui ; lui imposer de choisir d'abord un praticien
 * le fait renoncer quand le premier essayé est complet. On interroge donc toutes les ressources
 * capables, et on trie par heure — le praticien est une conséquence du créneau retenu, pas un
 * préalable.
 *
 * **Les paramètres passent en query, pas en segments d'URL.** Une variable d'URL doit être déclarée
 * dans `uriVariables` et se voit convertie par API Platform avant d'atteindre le fournisseur — c'est
 * ce qui a fait répondre 404 « Invalid uri variables » sur le point d'entrée légal, et 404 sur une
 * vitrine désignée par son nom. Une date et un pas n'ont rien à faire dans un chemin de ressource.
 *
 * @cloisonnement-verifie: le contrôle porte sur l'établissement de l'ACTIVITÉ résolue, pas sur
 * l'en-tête client. `ContexteEtablissement` lit `X-Etablissement`, qui est un sélecteur et non une
 * preuve d'appartenance (D6) : on refuse en 404 quand l'activité appartient à un autre établissement,
 * et non en 403 — distinguer « hors périmètre » de « inexistant » permettrait d'énumérer l'offre d'un
 * voisin, ce qui est déjà une fuite.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class FreeSlotProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FreeSlotFinder $finder,
        private readonly ContexteEtablissement $contexte,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            throw new UnprocessableEntityHttpException('Requête indisponible.');
        }

        $activity = $this->activity($request->query->get('activite'));
        $day = $this->day($request->query->get('date'));
        $step = $request->query->getInt('pas', FreeSlotFinder::DEFAULT_STEP_MINUTES);

        $resources = $this->resources($activity, $request->query->get('ressource'));

        $propositions = [];
        foreach ($resources as $resource) {
            foreach ($this->finder->findStarts($resource, $activity, $day, $step) as $creneau) {
                $propositions[] = [
                    'debut' => $creneau['debut']->format(\DATE_ATOM),
                    'fin' => $creneau['fin']->format(\DATE_ATOM),
                    'ressource' => (string) $resource->getId(),
                    'ressourceLibelle' => $resource->getLibelle(),
                ];
            }
        }

        // Tri par heure PUIS par ressource : le client lit une journée, pas une liste de praticiens.
        usort($propositions, static function (array $a, array $b): int {
            return [$a['debut'], $a['ressourceLibelle']] <=> [$b['debut'], $b['ressourceLibelle']];
        });

        return new JsonResponse([
            'activite' => (string) $activity->getId(),
            'libelle' => $activity->getLibelle(),
            'dureeMinutes' => $activity->getDureeMinutes(),
            'battementMinutes' => $activity->getBattementMinutes(),
            'date' => $day->format('Y-m-d'),
            'pasMinutes' => max(5, $step),
            // Le nombre de ressources interrogées : sans lui, « aucune proposition » ne distingue pas
            // « tout est pris » de « aucun praticien n'a la compétence exigée ».
            'ressourcesInterrogees' => \count($resources),
            'propositions' => $propositions,
        ]);
    }

    private function activity(mixed $reference): Activite
    {
        $id = \is_string($reference) && $reference !== '' ? basename($reference) : null;
        if ($id === null || !Uuid::isValid($id)) {
            throw new UnprocessableEntityHttpException('Le paramètre « activite » est obligatoire (identifiant).');
        }

        $activity = $this->em->getRepository(Activite::class)->find(Uuid::fromString($id));
        if (!$activity instanceof Activite) {
            throw new NotFoundHttpException('Activité introuvable.');
        }

        $actif = $this->contexte->idActif();
        if ($actif === null || $activity->getEtablissement()?->getId()?->equals($actif) !== true) {
            // 404 et non 403 : voir le bloc @cloisonnement-verifie ci-dessus.
            throw new NotFoundHttpException('Activité introuvable.');
        }

        return $activity;
    }

    private function day(mixed $valeur): \DateTimeImmutable
    {
        if (!\is_string($valeur) || $valeur === '') {
            throw new UnprocessableEntityHttpException('Le paramètre « date » est obligatoire (AAAA-MM-JJ).');
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);
        if ($day === false) {
            // On refuse plutôt que de replier sur aujourd'hui : une date mal formée qui rendrait le
            // jour courant afficherait un agenda plausible pour la mauvaise journée.
            throw new UnprocessableEntityHttpException('Date invalide : format attendu AAAA-MM-JJ.');
        }

        return $day;
    }

    /** @return list<Ressource> */
    private function resources(Activite $activity, mixed $reference): array
    {
        if (\is_string($reference) && $reference !== '') {
            $id = basename($reference);
            if (!Uuid::isValid($id)) {
                throw new UnprocessableEntityHttpException('Paramètre « ressource » invalide.');
            }
            $resource = $this->em->getRepository(Ressource::class)->find(Uuid::fromString($id));
            if (!$resource instanceof Ressource
                || $resource->getEtablissement()?->getId()?->equals($activity->getEtablissement()?->getId()) !== true
            ) {
                throw new NotFoundHttpException('Ressource introuvable.');
            }

            return [$resource];
        }

        return $this->finder->eligibleResources($activity);
    }
}
