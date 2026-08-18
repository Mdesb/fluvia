<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Compta\Entity\FactureB2G;
use App\Compta\Port\ChorusProInterface;
use App\Facturation\Entity\Facture;
use App\Facturation\Enum\CanalFacture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Dépôt Chorus Pro (B2G, RG-FACT-07, `plan-facturation.md`, CA-9). Réutilise à l'identique le port
 * `App\Compta\Port\ChorusProInterface`/`ChorusProStubAdapter` et l'entité `App\Compta\Entity\FactureB2G`
 * (M6, non modifiés). Un échec de dépôt (`statutEnvoi=rejete`) peut être **rejoué sans générer un
 * nouveau numéro** de facture — seul le dépôt est rejoué (§7 cas limite de la spec).
 */
final class DepotChorusProHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChorusProInterface $chorusPro,
    ) {
    }

    public function deposer(Facture $facture, ?string $numeroEngagement, ?string $serviceExecutant): FactureB2G
    {
        if ($facture->estBrouillon()) {
            throw new ConflictHttpException('Dépôt Chorus Pro impossible : facture non émise.');
        }
        $destinataire = $facture->getDestinataire();
        if ($destinataire === null || !$destinataire->isEstOrganismePublic()) {
            throw new UnprocessableEntityHttpException('Dépôt Chorus Pro réservé aux destinataires personne morale de droit public (RG-FACT-07).');
        }

        $factureB2G = $facture->getFactureB2G();
        if (!$factureB2G instanceof FactureB2G) {
            $factureB2G = new FactureB2G();
            $factureB2G->setClientRef($destinataire->getClientRef() ?? Uuid::v4());
        }
        if ($numeroEngagement !== null) {
            $factureB2G->setNumeroEngagement($numeroEngagement);
        }
        if ($serviceExecutant !== null) {
            $factureB2G->setServiceExecutant($serviceExecutant);
        }

        $statut = $this->chorusPro->deposer($factureB2G);
        $factureB2G->setStatutEnvoi($statut);

        $this->em->persist($factureB2G);
        $facture->setFactureB2G($factureB2G);
        $facture->setCanal(CanalFacture::ChorusPro);
        $this->em->flush();

        return $factureB2G;
    }
}
