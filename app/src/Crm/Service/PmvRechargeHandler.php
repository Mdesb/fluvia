<?php

declare(strict_types=1);

namespace App\Crm\Service;

use App\Crm\Entity\Client;
use App\Crm\Entity\MouvementPmv;
use App\Crm\Entity\ParametrePmvEtablissement;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Enum\CanalMouvementPmv;
use App\Crm\Enum\StatutPmv;
use App\Crm\Enum\TypeMouvementPmv;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Recharge du PMV (US-L5-04/06, RG-M4-03/04, CA-7/CA-11). Crée le PMV à la demande (1ʳᵉ recharge,
 * §1.2 plan-crm.md). Un PMV expiré est réactivé (nouvelle échéance) si
 * `ParametrePmvEtablissement.rechargeExpireeAutorisee`, sinon la recharge est **bloquée** avec motif
 * affiché — dans les deux cas le comportement retenu est journalisé sur le mouvement (CA-11).
 */
final class PmvRechargeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function recharger(Client $client, string $montant, CanalMouvementPmv $canal, Etablissement $etablissement, Utilisateur $utilisateur): MouvementPmv
    {
        if (MontantUtil::comparer($montant, '0.00') <= 0) {
            throw new UnprocessableEntityHttpException('Le montant de recharge doit être strictement positif.');
        }

        $pmv = $this->em->getRepository(PorteMonnaieVirtuel::class)->findOneBy(['client' => $client]);
        // Un PMV fraîchement créé (1ʳᵉ recharge, §1.2 plan-crm.md) démarre `statut=expire` par défaut
        // (entité) : ce n'est PAS un « PMV expiré » au sens RG-M4-04, seulement « pas encore actif ».
        // Seul un PMV **préexistant** trouvé en statut expiré déclenche la règle de blocage CA-11.
        $pmvPreexistant = $pmv !== null;
        if ($pmv === null) {
            $pmv = new PorteMonnaieVirtuel();
            $pmv->setClient($client);
            $this->em->persist($pmv);
        }

        $parametre = $this->em->getRepository(ParametrePmvEtablissement::class)->findOneBy(['etablissement' => $etablissement]);
        $regle = $parametre ?? (new ParametrePmvEtablissement())->setEtablissement($etablissement);

        $motif = null;
        if ($pmvPreexistant && $pmv->getStatut() === StatutPmv::Expire) {
            if (!$regle->isRechargeExpireeAutorisee()) {
                $motif = 'Recharge refusée : PMV expiré, recharge non autorisée sur cet établissement (RG-M4-04).';
                $mouvement = new MouvementPmv(TypeMouvementPmv::Ajustement);
                $mouvement->setPmv($pmv);
                $mouvement->setMontant('0.00');
                $mouvement->setSoldeApres($pmv->getSolde());
                $mouvement->setCanal($canal);
                $mouvement->setEtablissement($etablissement);
                $mouvement->setUtilisateur($utilisateur);
                $mouvement->setMotif($motif);
                $this->em->persist($mouvement);
                // Flush immédiat : le comportement (blocage) reste journalisé même si l'exception
                // interrompt le traitement (CA-11 : « dans les deux cas … journalisé »).
                $this->em->flush();

                throw new UnprocessableEntityHttpException($motif);
            }
            $motif = 'PMV expiré réactivé par recharge (RG-M4-04, nouvelle échéance calculée).';
        }

        $pmv->setSolde(MontantUtil::addition($pmv->getSolde(), $montant));
        $pmv->setDateEcheance($regle->calculerEcheance());
        $pmv->setStatut(StatutPmv::Actif);

        $mouvement = new MouvementPmv(TypeMouvementPmv::Recharge);
        $mouvement->setPmv($pmv);
        $mouvement->setMontant($montant);
        $mouvement->setSoldeApres($pmv->getSolde());
        $mouvement->setCanal($canal);
        $mouvement->setEtablissement($etablissement);
        $mouvement->setUtilisateur($utilisateur);
        $mouvement->setMotif($motif);
        $this->em->persist($mouvement);

        return $mouvement;
    }
}
