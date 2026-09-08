<?php

declare(strict_types=1);

namespace App\Facturation\Controller;

use App\Facturation\Einvoicing\FacturXAssembler;
use App\Facturation\Einvoicing\InvoiceHtmlRenderer;
use App\Facturation\Einvoicing\InvoiceNotEmittableException;
use App\Facturation\Entity\Facture;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /factures/{id}/facturx` — télécharge le Factur-X (PDF/A-3 + XML CII EN 16931 embarqué) d'une
 * facture. Contrôleur simple (pas de ressource API Platform), même patron que
 * `App\Sepa\Controller\TelechargerPain008Controller` : cloisonnement manuel (RG-SOCLE-05).
 *
 * ⚠ CE N'EST PAS `/rendu`. `/factures/{id}/rendu` renvoie le JSON du document légal pour l'AFFICHAGE
 * écran ; ici on renvoie le BINAIRE opposable : le PDF que le client emporte, avec le XML structuré
 * enfoui dedans. Le fichier est PRODUIT à la demande par LE MÊME chemin que la commande
 * `facturation:einvoicing:emettre --facturx` (`InvoiceHtmlRenderer` puis `FacturXAssembler`) — aucune
 * seconde implémentation qui pourrait diverger.
 *
 * ⚠ UNE FACTURE INCOMPLÈTE N'EST PAS TÉLÉCHARGEABLE, ET LE DIT. Si un terme obligatoire EN 16931
 * manque (aujourd'hui l'adresse de l'acheteur, notamment), l'assembleur lève
 * `InvoiceNotEmittableException` : on répond 422 en NOMMANT ce qui manque, plutôt que de livrer un PDF
 * que le produit ne sait pas encore rendre conforme. La conformité du fichier produit (PDF/A-3B +
 * EN 16931 + Factur-X) est prouvée hors-ligne par `infra/valider-facturx.sh`, pas affirmée ici.
 */
#[AsController]
final class TelechargerFacturXController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly InvoiceHtmlRenderer $renduHtml,
        private readonly FacturXAssembler $assembleur,
    ) {
    }

    #[Route('/factures/{id}/facturx', name: 'facture_facturx_telecharger', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Facture introuvable.');
        }

        $facture = $this->em->getRepository(Facture::class)->find($id);
        if (!$facture instanceof Facture) {
            throw new NotFoundHttpException('Facture introuvable.');
        }

        // Accès : facturation.lire (tout l'établissement) OU facturation.lire_soi (ses propres
        // factures) — même règle que l'opération `/rendu`.
        $estPorteurSoi = $this->security->isGranted('PERM', 'facturation.lire_soi')
            && $facture->estLieA($this->security->getUser());
        $estOperateur = $this->security->isGranted('PERM', 'facturation.lire');
        if (!$estOperateur && !$estPorteurSoi) {
            throw new AccessDeniedHttpException('Téléchargement Factur-X réservé à facturation.lire / facturation.lire_soi.');
        }

        // Cloisonnement RG-SOCLE-05 : un opérateur d'un autre établissement ne doit rien apprendre —
        // pas même que la facture existe. Comme `FactureRenduProvider` (et le durcissement e915c94e),
        // on répond 404, JAMAIS 403 : un 403 confirmerait l'existence d'une facture d'un voisin, avec
        // ses montants et les données personnelles du destinataire. Le porteur « soi » y échappe : il
        // lit SA facture, dont l'établissement n'a pas à coïncider avec un contexte d'exploitation.
        if (!$estPorteurSoi) {
            $actif = $this->contexte->idActif();
            if ($actif === null || $facture->getEtablissement() === null
                || !$actif->equals($facture->getEtablissement()->getId())) {
                throw new NotFoundHttpException('Facture introuvable.');
            }
        }

        try {
            $pdf = $this->assembleur->assemble($this->renduHtml->render($facture), $facture);
        } catch (InvoiceNotEmittableException $refus) {
            // Le fichier européen ne peut pas être produit : on NOMME ce qui manque (le message porte
            // les termes obligatoires absents), au lieu de livrer un document non conforme.
            throw new UnprocessableEntityHttpException($refus->getMessage());
        }

        $nom = $facture->getNumero() ?? (string) $facture->getId();
        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s.pdf"', $nom),
        );

        return $response;
    }
}
