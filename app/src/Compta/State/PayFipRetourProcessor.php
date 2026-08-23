<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\BordereauPayFiP;
use App\Compta\Enum\StatutPayFiP;
use App\Compta\Service\TraiterRetourPayFipHandler;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /compta/payfip/retour (webhook, RG-PAYFIP-03, CA-6). Corps :
 *   { "venteOrigine": uuid, "referenceTransaction": string, "statut": "ok|echec|annule" }
 * Journalise pour contrôle et rejeu (retour manquant → rejouable, cf. `PayFipRejouerProcessor`).
 *
 * @implements ProcessorInterface<mixed, BordereauPayFiP>
 */
final class PayFipRetourProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly TraiterRetourPayFipHandler $handler,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BordereauPayFiP
    {
        $corps = $this->lecteur->corps();
        $reference = \is_string($corps['referenceTransaction'] ?? null) ? $corps['referenceTransaction'] : '';
        $statut = StatutPayFiP::tryFrom((string) ($corps['statut'] ?? 'en_attente')) ?? StatutPayFiP::EnAttente;

        $bordereau = $this->em->getRepository(BordereauPayFiP::class)->findOneBy(['referenceTransaction' => $reference]);
        if ($bordereau === null) {
            $venteId = \is_string($corps['venteOrigine'] ?? null) ? $corps['venteOrigine'] : null;
            if ($venteId === null || !Uuid::isValid($venteId)) {
                throw new UnprocessableEntityHttpException('Retour PayFiP : venteOrigine et referenceTransaction requis pour un premier retour.');
            }
            $this->assertVenteDansLePerimetre(Uuid::fromString($venteId));
            $bordereau = $this->handler->initier(Uuid::fromString($venteId), $reference);
        } else {
            $this->assertVenteDansLePerimetre($bordereau->getVenteOrigine());
        }

        return $this->handler->traiterRetour($bordereau, $statut);
    }

    /**
     * D8 — `referenceTransaction` et `venteOrigine` viennent du corps de la requete. `BordereauPayFiP`
     * ne porte pas d'etablissement : il ne connait que l'UUID de sa vente d'origine, c'est donc par
     * elle qu'on retrouve le perimetre.
     *
     * Le controle porte sur les **deux** branches : celle qui retrouve un bordereau existant et celle
     * qui en cree un. Ne garder que la premiere laisserait ouverte la creation d'un bordereau sur la
     * vente d'autrui — c'est-a-dire exactement l'operation la plus dommageable des deux.
     *
     * Echec ferme en 404 : un 403 confirmerait l'existence de la vente ailleurs.
     */
    private function assertVenteDansLePerimetre(Uuid $venteId): void
    {
        $vente = $this->em->getRepository(Vente::class)->find($venteId);
        $actif = $this->contexte->etablissementActif();

        if ($vente === null
            || (string) $vente->getEtablissement()?->getId() !== (string) $actif?->getId()) {
            throw new NotFoundHttpException('Vente introuvable.');
        }
    }
}
