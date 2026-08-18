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
use App\Vente\Entity\Avoir;
use App\Vente\Service\ContrePassationHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Annule une vente validée (POST /ventes/{id}/annuler, CA-13). Droit vente.annuler requis (403 sinon).
 * Génère un Avoir par contre-passation (aucune ligne supprimée) ; une annulation après impression
 * invalide le support côté Accès. Corps : { "motif": "…", "demandeEscalade"?: "<jeton>" (rejeu) }.
 *
 * Intégration Autorisations graduées (module App\Autorisation, non modifié ici hormis ce point
 * d'insertion) : `ServiceAutorisation::evaluer()` est appelé avant `ContrePassationHandler::annuler()`
 * — si la décision n'est pas AUTORISE, le handler n'est jamais invoqué. Aucune limite configurée
 * pour `vente.annuler` ⇒ décision AUTORISE immédiate, sans écriture : comportement strictement
 * inchangé pour un client qui ne configure rien (rétrocompatibilité M2).
 *
 * @implements ProcessorInterface<\App\Vente\Entity\Vente, JsonResponse>
 */
final class AnnulerVenteProcessor implements ProcessorInterface
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
        \assert($data instanceof \App\Vente\Entity\Vente);
        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);

        $corps = $this->lecteur->corps();
        $motif = \is_string($corps['motif'] ?? null) ? (string) $corps['motif'] : '';

        $decision = $this->serviceAutorisation->evaluer(new RequeteAutorisation(
            operationCode: 'vente.annuler',
            utilisateur: $auteur,
            montant: $data->getTotal(),
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
        $avoir = $this->handler->annuler($data, $motif, $auteur);
        $this->em->persist($avoir);
        $this->em->flush();

        return $this->reponse($avoir);
    }

    private function reponse(Avoir $avoir): JsonResponse
    {
        return new JsonResponse([
            'avoir' => (string) $avoir->getId(),
            'numero' => $avoir->getNumero(),
            'venteOrigine' => (string) $avoir->getVenteOrigine()?->getId(),
            'statutVente' => $avoir->getVenteOrigine()?->getStatut()->value,
            'montant' => $avoir->getMontant(),
            'nature' => $avoir->getNature(),
            'supportInvalide' => $avoir->isSupportInvalide(),
        ], JsonResponse::HTTP_CREATED);
    }

    /** @param array<string, mixed> $corps */
    private function lireJetonRejeu(array $corps): ?Uuid
    {
        $jeton = $corps['demandeEscalade'] ?? null;

        return \is_string($jeton) && Uuid::isValid($jeton) ? Uuid::fromString($jeton) : null;
    }
}
