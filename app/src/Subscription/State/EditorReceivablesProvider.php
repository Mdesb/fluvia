<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Facturation\Entity\Facture;
use App\Facturation\Enum\StatutFacture;
use App\Organisation\Service\EditorTenantResolver;
use App\Subscription\ApiResource\EditorReceivable;
use App\Subscription\Security\EditorOnly;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qui reste dû à l'éditeur (ED-8).
 *
 * **Le plus en retard d'abord.** Une liste de créances triée par date d'émission met en tête ce qui
 * vient d'être facturé — c'est-à-dire ce dont personne ne se soucie encore. Ce qu'on cherche ici,
 * c'est la facture de mars que plus personne ne regarde.
 *
 * **Les factures de l'éditeur seulement.** Le filtre porte sur l'établissement émetteur : les
 * factures qu'un exploitant émet à ses propres clients ne regardent pas l'éditeur, et les faire
 * apparaître ici serait la même fuite que celle du CRM partagé.
 *
 * **Une facture soldée disparaît de la liste.** Elle n'est pas grisée, pas repliée : elle sort. Une
 * liste de créances où figurent les factures payées oblige à lire pour savoir quoi faire, et c'est
 * exactement l'effort qu'on veut supprimer.
 */
final class EditorReceivablesProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly EditorTenantResolver $editorTenant,
    ) {
    }

    /** @return list<EditorReceivable> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $this->editorOnly->assertEditor();

        $maintenant = new \DateTimeImmutable();
        $creances = [];

        foreach ($this->facturesDeLediteur() as $facture) {
            $creance = $this->creance($facture, $maintenant);

            // Soldée : elle sort de la liste. Voir le commentaire de classe.
            if ($creance->remainingCents <= 0) {
                continue;
            }

            $creances[] = $creance;
        }

        // Le plus en retard d'abord ; à retard égal, le plus gros montant.
        usort($creances, static function (EditorReceivable $a, EditorReceivable $b): int {
            return [$b->daysLate, $b->remainingCents] <=> [$a->daysLate, $a->remainingCents];
        });

        return $creances;
    }

    /**
     * Les factures émises par l'établissement éditeur, hors brouillons.
     *
     * Un brouillon n'a pas été envoyé : il ne peut pas être en retard de paiement, et le faire
     * apparaître comme une créance ferait relancer un client pour une facture qu'il n'a jamais reçue.
     *
     * @return list<Facture>
     */
    private function facturesDeLediteur(): array
    {
        /** @var list<Facture> $factures */
        $factures = $this->em->getRepository(Facture::class)->findBy([
            'etablissement' => $this->editorTenant->resolve(),
            'statut' => [
                StatutFacture::Emise,
                StatutFacture::EnAttentePaiement,
                StatutFacture::PartiellementReglee,
                StatutFacture::Echue,
            ],
        ]);

        return $factures;
    }

    private function creance(Facture $facture, \DateTimeImmutable $maintenant): EditorReceivable
    {
        $creance = new EditorReceivable();
        $creance->invoiceId = $facture->getId()->toRfc4122();
        $creance->invoiceNumber = $facture->getNumero();
        $creance->status = $facture->getStatut()->value;
        $creance->totalCents = $this->cents($facture->getTotalTTC());
        $creance->paidCents = $this->encaisse($facture);
        $creance->remainingCents = $creance->totalCents - $creance->paidCents;

        $destinataire = $facture->getDestinataire();
        $creance->customerName = $this->nom($destinataire?->getRaisonSociale(), $destinataire?->getPrenom(), $destinataire?->getNom());

        $echeance = $facture->getDateEcheance();
        if (null !== $echeance) {
            $creance->dueDate = $echeance->format('Y-m-d');
            // Le retard se compte en jours entiers révolus : une facture due aujourd'hui n'est pas
            // en retard, elle est due. Compter autrement ferait relancer le jour même.
            $creance->daysLate = $echeance < $maintenant ? (int) $echeance->diff($maintenant)->days : 0;
        }

        return $creance;
    }

    /** Somme des règlements enregistrés, en centimes. */
    private function encaisse(Facture $facture): int
    {
        $total = 0;

        foreach ($facture->getReglements() as $reglement) {
            $total += $this->cents($reglement->getMontant());
        }

        return $total;
    }

    /**
     * Les montants de `Facturation` sont des décimaux en chaîne ; les nôtres des centimes entiers.
     *
     * La conversion passe par une multiplication puis un arrondi, jamais par une troncature : sur
     * « 49.99 », un cast direct donnerait 4998 à cause de la représentation binaire du flottant, et
     * l'écart d'un centime se lirait comme un impayé partiel qui n'existe pas.
     */
    private function cents(string $montant): int
    {
        return (int) round(((float) $montant) * 100);
    }

    private function nom(?string $raisonSociale, ?string $prenom, ?string $nom): string
    {
        $raisonSociale = trim((string) $raisonSociale);
        if ('' !== $raisonSociale) {
            return $raisonSociale;
        }

        $complet = trim(($prenom ?? '').' '.($nom ?? ''));

        return '' !== $complet ? $complet : 'Sans nom';
    }
}
