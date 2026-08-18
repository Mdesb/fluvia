<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Autorisation\Enum\ResultatDecision;
use App\Autorisation\Exception\EscaladeRequiseException;
use App\Autorisation\Service\RequeteAutorisation;
use App\Autorisation\Service\ServiceAutorisation;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Vente;
use App\Vente\Service\ContrePassationHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Rembourse une vente validée (POST /ventes/{id}/rembourser, CA-13). Droit vente.rembourser requis
 * (403 sinon). Aucun remboursement automatique : passe par cette demande explicite, tracée par
 * contre-passation (Avoir). Corps : { "motif": "…", "montant"?: "…" (partiel, défaut = total),
 * "demandeEscalade"?: "<jeton>" (rejeu) }.
 *
 * Intégration Autorisations graduées (module App\Autorisation, non modifié ici hormis ce point
 * d'insertion) : `ServiceAutorisation::evaluer()` est appelé avant `ContrePassationHandler::
 * rembourser()`, avec le montant **effectivement évalué** (partiel ou total) — reproduction minimale
 * (une ligne, `number_format`) de la résolution déjà faite dans `ContrePassationHandler::rembourser()`,
 * sans modifier ce handler. Aucune limite configurée pour `vente.rembourser` ⇒ décision AUTORISE
 * immédiate, sans écriture : comportement strictement inchangé (rétrocompatibilité M2, CA-6).
 *
 * @implements ProcessorInterface<Vente, JsonResponse>
 */
final class RembourserVenteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContrePassationHandler $handler,
        private readonly Security $security,
        private readonly ServiceAutorisation $serviceAutorisation,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Vente);
        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);

        $corps = $this->lecteur->corps();
        $motif = \is_string($corps['motif'] ?? null) ? (string) $corps['motif'] : '';
        $montant = isset($corps['montant']) ? (string) $corps['montant'] : null;

        $montantEvalue = $montant !== null ? number_format((float) $montant, 2, '.', '') : $data->getTotal();

        $decision = $this->serviceAutorisation->evaluer(new RequeteAutorisation(
            operationCode: 'vente.rembourser',
            utilisateur: $auteur,
            montant: $montantEvalue,
            cibleType: 'Vente',
            cibleId: $data->getId(),
            cibleEtablissementId: $data->getEtablissement()?->getId(),
            cibleSessionOperateurId: $data->getSession()?->getOperateur()?->getId(),
            cibleSessionRegisseurId: $data->getSession()?->getRegisseur()?->getId(),
            jetonRejeu: $this->lireJetonRejeu($corps),
        ));

        match ($decision->resultat) {
            ResultatDecision::Refuse => throw new AccessDeniedException($decision->motif),
            ResultatDecision::EscaladeRequise => throw new EscaladeRequiseException($decision),
            ResultatDecision::Autorise => null,
        };

        // --- code existant, strictement inchangé à partir d'ici ---
        $avoir = $this->handler->rembourser($data, $montant, $motif, $auteur);
        $this->em->persist($avoir);
        $this->em->flush();

        return new JsonResponse([
            'avoir' => (string) $avoir->getId(),
            'numero' => $avoir->getNumero(),
            'venteOrigine' => (string) $data->getId(),
            'statutVente' => $data->getStatut()->value,
            'montant' => $avoir->getMontant(),
            'nature' => $avoir->getNature(),
        ], JsonResponse::HTTP_CREATED);
    }

    /** @param array<string, mixed> $corps */
    private function lireJetonRejeu(array $corps): ?Uuid
    {
        $jeton = $corps['demandeEscalade'] ?? null;

        return \is_string($jeton) && Uuid::isValid($jeton) ? Uuid::fromString($jeton) : null;
    }
}
