<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Vente\ApiResource\Synchronisation;
use App\Vente\Entity\Vente;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fournit l'indicateur d'état de synchronisation (US-L2-12) : en ligne / dégradé / synchro en cours.
 * Côté serveur l'état nominal est « en_ligne » ; le compteur des ventes d'origine hors-ligne aide au
 * suivi de la remontée. La bascule réseau et le stockage local relèvent du poste (hors périmètre API).
 *
 * @implements ProviderInterface<Synchronisation>
 */
final class EtatSynchroProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Synchronisation
    {
        $etat = new Synchronisation();
        $etat->etat = 'en_ligne';
        // ── CE COMPTEUR N'ETAIT BORNE PAR RIEN ───────────────────────────────────────────────
        //
        // Il comptait les ventes hors ligne de TOUS les etablissements. Un exploitant qui lit
        // « 12 en attente » sans en avoir fait une seule ne peut rien en conclure -- et celui qui
        // en a vraiment douze n'apprend rien non plus.
        //
        // Un fournisseur sur mesure ne passe par aucune extension Doctrine : c'est la meme famille
        // que `PassageExportProvider` et `EtatSynchroAccesProvider`, corriges le meme jour.
        $actif = $this->contexte->idActif();

        $etat->ventesHorsLigne = $actif === null ? 0 : (int) $this->em->getRepository(Vente::class)
            ->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.origineHorsLigne = true')
            ->andWhere('IDENTITY(v.etablissement) = :synchro_etablissement')
            ->setParameter('synchro_etablissement', $actif, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return $etat;
    }
}
