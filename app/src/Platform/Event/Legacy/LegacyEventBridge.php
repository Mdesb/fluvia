<?php

declare(strict_types=1);

namespace App\Platform\Event\Legacy;

use App\Crm\Event\PassageMajoriteEvent;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Event\IncidentImpayeDetecteEvent;
use App\Recouvrement\Event\IncidentImpayeReouvertureForceeEvent;
use App\Recouvrement\Event\IncidentImpayeResoluEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Pont transitoire : republie sur le bus, sous leur nom de contrat, les événements PHP maison qui
 * existaient avant le noyau (PLAT-3).
 *
 * **Pourquoi un pont plutôt qu'une réécriture des émetteurs.** `Recouvrement`, `Crm` et `Sport` ne sont
 * possédés par personne dans OWNERS.md, et `Sport\EventListener\SynchroniserImpayeFitnessListener`
 * écoute réellement quatre de ces classes. Réécrire les émetteurs imposerait de réécrire cet abonné en
 * même temps — quatre modules touchés pour une normalisation. Le pont obtient le même résultat en
 * n'ajoutant qu'un fichier, ici, dans le module du noyau : l'existant continue exactement comme avant,
 * et les nouveaux modules peuvent s'abonner par **chaîne de contrat** (`payment.failed`) sans jamais
 * importer le code de `Recouvrement`, ce qu'exige D2.
 *
 * **C'est explicitement temporaire.** L'état visé reste que chaque module publie lui-même son
 * `DomainEvent` ; ce jour-là, ce fichier se supprime d'un bloc et rien d'autre ne bouge. Un pont qu'on
 * oublie devient une couche de traduction permanente que plus personne n'ose retirer — d'où cette
 * phrase, et la tâche de suivi au tableau.
 *
 * **Best-effort, par obligation.** Le bus est synchrone et propage les exceptions à l'émetteur
 * (RG-PLAT-05). Ce pont s'exécute donc dans la transaction d'une action métier réelle : un impayé
 * détecté, une majorité franchie. Il n'a pas le droit de la casser. Toute erreur est donc capturée et
 * journalisée — c'est exactement l'invariant « un abonné best-effort enveloppe ses propres erreurs »,
 * appliqué au premier abonné que la plateforme ait eu.
 */
final class LegacyEventBridge implements EventSubscriberInterface
{
    public function __construct(
        private readonly EventBus $bus,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * `AccesRedevableChangeEvent` est volontairement absent : il ne transporte qu'un type et une
     * référence de redevable, sans établissement. On ne peut donc pas en dériver le tenant depuis le
     * sujet (D6), et l'enveloppe refuse un tenant absent (RG-PLAT-03). Le porter exigerait de modifier
     * l'événement chez `Recouvrement` — hors périmètre, suivi au tableau.
     *
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            IncidentImpayeDetecteEvent::class => 'surImpayeDetecte',
            IncidentImpayeResoluEvent::class => 'surImpayeResolu',
            IncidentImpayeReouvertureForceeEvent::class => 'surImpayeReouvert',
            PassageMajoriteEvent::class => 'surPassageMajorite',
        ];
    }

    public function surImpayeDetecte(IncidentImpayeDetecteEvent $evenement): void
    {
        $this->publier('payment.failed', $evenement->incident, [
            'amount_cents' => $evenement->montantCentimes,
            'cause' => $evenement->incident->getMotifBancaire(),
            'rejected_at' => $evenement->date->format(\DATE_ATOM),
        ]);
    }

    public function surImpayeResolu(IncidentImpayeResoluEvent $evenement): void
    {
        $this->publier('payment.succeeded', $evenement->incident, [
            'amount_cents' => $evenement->montantCentimes,
            'origin' => $evenement->origine,
            'settled_at' => $evenement->date->format(\DATE_ATOM),
        ]);
    }

    public function surImpayeReouvert(IncidentImpayeReouvertureForceeEvent $evenement): void
    {
        $this->publier('payment.incident_reopened', $evenement->incident, [
            'amount_cents' => $evenement->incident->getMontantCentimes(),
        ]);
    }

    public function surPassageMajorite(PassageMajoriteEvent $evenement): void
    {
        $client = $evenement->client;
        $etablissement = $client->getEtablissementCreation();

        if ($etablissement === null) {
            $this->journaliser('customer.came_of_age', 'client sans établissement de création');

            return;
        }

        $this->publierEnveloppe(
            'customer.came_of_age',
            new EventTenant($etablissement->getId()),
            new EventSubject('Customer', (string) $client->getId()),
            // Les canaux à renouveler sont des codes, pas des données personnelles : on transporte
            // leur nombre et leur liste, jamais le contenu du dossier client (RG-PLAT-04).
            ['channels_to_renew' => array_values($evenement->canauxARenouveler)],
        );
    }

    /**
     * @param array<string, scalar|array|null> $payload
     */
    private function publier(string $nom, IncidentImpaye $incident, array $payload): void
    {
        $etablissement = $incident->getEtablissement();

        if ($etablissement === null) {
            $this->journaliser($nom, 'incident sans établissement');

            return;
        }

        $this->publierEnveloppe(
            $nom,
            new EventTenant($etablissement->getId()),
            new EventSubject('PaymentIncident', (string) $incident->getId()),
            $payload,
        );
    }

    /**
     * @param array<string, scalar|array|null> $payload
     */
    private function publierEnveloppe(string $nom, EventTenant $tenant, EventSubject $sujet, array $payload): void
    {
        try {
            $this->bus->publish(new DomainEvent($nom, $tenant, $sujet, $payload));
        } catch (\Throwable $erreur) {
            // Jamais de propagation : l'action métier qui a déclenché ce pont doit aboutir même si la
            // republication échoue. Une plateforme qui refuse d'encaisser parce que son bus tousse est
            // pire que le problème qu'elle prétend résoudre.
            $this->journaliser($nom, $erreur->getMessage());
        }
    }

    private function journaliser(string $nom, string $raison): void
    {
        $this->logger?->warning('Pont d\'événements : « {evenement} » non republié sur le bus ({raison}).', [
            'evenement' => $nom,
            'raison' => $raison,
        ]);
    }
}
