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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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

        // ⚠ UNE RECHARGE « SOI » N'A AUCUN PAIEMENT DERRIÈRE ELLE. Le montant envoyé était crédité
        //    tel quel : un porteur de `crm.pmv_recharger_soi` se créditait 1 000 € et les dépensait à
        //    toutes les caisses du groupe. Elle exige un paiement en ligne réel, et il n'y en a pas
        //    (bouchons, D112) : seul l'agent habilité au guichet (`crm.pmv_recharger`) recharge.
        if (!$this->security->isGranted('PERM', 'crm.pmv_recharger')) {
            throw new AccessDeniedHttpException('La recharge du porte-monnaie par le client exige un paiement en ligne, qui n’est pas encore disponible : la recharge se fait au guichet.');
        }

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
