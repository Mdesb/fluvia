<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Client;
use App\Crm\Enum\CanalMouvementPmv;
use App\Crm\Service\PmvRechargeHandler;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /clients/{id}/pmv/recharger (US-L5-04/06, CA-7/CA-11). Corps :
 * { "montant": "10.00", "canal"?: "caisse"|"en_ligne"|"autre" }.
 *
 * @implements ProcessorInterface<Client, JsonResponse>
 */
final class PmvRechargerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PmvRechargeHandler $handler,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Client);

        $corps = $this->lecteur->corps();
        $montant = isset($corps['montant']) ? number_format((float) $corps['montant'], 2, '.', '') : null;
        if ($montant === null) {
            throw new UnprocessableEntityHttpException('Le champ « montant » est requis.');
        }
        $canal = CanalMouvementPmv::tryFrom(\is_string($corps['canal'] ?? null) ? $corps['canal'] : 'caisse') ?? CanalMouvementPmv::Caisse;

        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }
        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        $mouvement = $this->handler->recharger($data, $montant, $canal, $etablissement, $utilisateur);
        $this->em->flush();

        return new JsonResponse([
            'client' => (string) $data->getId(),
            'mouvement' => (string) $mouvement->getId(),
            'montant' => $mouvement->getMontant(),
            'soldeApres' => $mouvement->getSoldeApres(),
            'dateEcheance' => $mouvement->getPmv()?->getDateEcheance()?->format('Y-m-d'),
            'statutPmv' => $mouvement->getPmv()?->getStatut()->value,
            'motif' => $mouvement->getMotif(),
        ], JsonResponse::HTTP_CREATED);
    }
}
