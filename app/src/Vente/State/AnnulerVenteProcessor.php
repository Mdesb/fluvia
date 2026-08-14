<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Avoir;
use App\Vente\Service\ContrePassationHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Annule une vente validée (POST /ventes/{id}/annuler, CA-13). Droit vente.annuler requis (403 sinon).
 * Génère un Avoir par contre-passation (aucune ligne supprimée) ; une annulation après impression
 * invalide le support côté Accès. Corps : { "motif": "…" }.
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
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof \App\Vente\Entity\Vente);
        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);

        $motif = \is_string($this->lecteur->corps()['motif'] ?? null) ? (string) $this->lecteur->corps()['motif'] : '';
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
}
