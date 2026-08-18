<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Facturation\Entity\Facture;
use App\Facturation\Service\ResolveurComptesFacturation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /factures/{id}/rendu (RG-FACT-02, CA-8) : structure de rendu normalisée, prête pour un gabarit
 * (PDF/HTML) — numéro, émetteur, destinataire, lignes, TVA ventilée, totaux, conditions de règlement,
 * mention « acquittée ». La génération d'un binaire PDF est repoussée (§7 point 10 du plan) : ce
 * provider livre la **structure de données**, pas le moteur PDF.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class FactureRenduProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurComptesFacturation $comptes,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = $uriVariables['id'] ?? null;
        $uuid = match (true) {
            $id instanceof Uuid => $id,
            \is_string($id) && Uuid::isValid($id) => Uuid::fromString($id),
            default => null,
        };
        $facture = $uuid === null ? null : $this->em->getRepository(Facture::class)->find($uuid);
        if ($facture === null) {
            throw new NotFoundHttpException('Facture introuvable.');
        }

        $utilisateur = $this->security->getUser();
        $peutTout = $this->security->isGranted('PERM', 'facturation.lire');
        $peutSoi = $this->security->isGranted('PERM', 'facturation.lire_soi') && $facture->estLieA($utilisateur);
        if (!$peutTout && !$peutSoi) {
            throw new AccessDeniedHttpException();
        }
        \assert($utilisateur instanceof Utilisateur || $utilisateur === null);

        $profil = $facture->getProfilExploitant();
        $emetteur = $profil !== null ? $this->comptes->parametre($profil)?->getMentionsLegalesEmetteur() : null;

        $destinataire = $facture->getDestinataire();

        $lignes = [];
        foreach ($facture->getLignes() as $ligne) {
            $lignes[] = [
                'designation' => $ligne->getDesignation(),
                'quantite' => $ligne->getQuantite(),
                'prixUnitaireHT' => $ligne->getPrixUnitaireHT(),
                'tauxTva' => $ligne->getTauxTvaValeur(),
                'montantHT' => $ligne->getMontantHT(),
                'montantTva' => $ligne->getMontantTva(),
                'montantTTC' => $ligne->getMontantTTC(),
            ];
        }

        return new JsonResponse([
            'id' => (string) $facture->getId(),
            'numero' => $facture->getNumero(),
            'nature' => $facture->getNature()->value,
            'dateEmission' => $facture->getDateEmission()?->format(\DATE_ATOM),
            'dateEcheance' => $facture->getDateEcheance()?->format('Y-m-d'),
            'emetteur' => $emetteur ?? [],
            'destinataire' => $destinataire === null ? null : [
                'type' => $destinataire->getType()->value,
                'denomination' => $destinataire->denomination(),
                'siret' => $destinataire->getSiret(),
                'tvaIntracommunautaire' => $destinataire->getTvaIntracommunautaire(),
                'adresse' => $destinataire->getAdresse(),
            ],
            'lignes' => $lignes,
            'ventilationTva' => $facture->getVentilationTva() ?? [],
            'totalHT' => $facture->getTotalHT(),
            'totalTVA' => $facture->getTotalTVA(),
            'totalTTC' => $facture->getTotalTTC(),
            'conditionsReglement' => $facture->getConditionsReglement(),
            'mentionAcquittee' => $facture->isMentionAcquittee(),
            'acquitteeLe' => $facture->getAcquitteeLe()?->format(\DATE_ATOM),
            'acquitteeMoyen' => $facture->getAcquitteeMoyen(),
            'acquitteeReference' => $facture->getAcquitteeReference(),
        ]);
    }
}
