<?php

declare(strict_types=1);

namespace App\Sepa\Controller;

use App\Sepa\Entity\RemiseSepa;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /sepa/remises/{id}/pain008` (plan §6) : télécharge le contenu XML pain.008.001.02 déjà généré
 * et conservé sur la remise (`RemiseSepa::$contenuXml`). Contrôleur simple (pas de ressource API
 * Platform) — même patron que `App\Securite\Controller\ExportAuditController` pour un contenu de type
 * fichier. Cloisonnement manuel : l'établissement actif (en-tête `X-Etablissement`) doit correspondre
 * à celui de la remise (RG-SOCLE-05).
 */
#[AsController]
final class TelechargerPain008Controller
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    #[Route('/sepa/remises/{id}/pain008', name: 'sepa_remise_pain008', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        if (!$this->security->isGranted('PERM', 'sepa.lire') && !$this->security->isGranted('PERM', 'compta.lire')) {
            throw new AccessDeniedHttpException("Téléchargement du pain.008 réservé à sepa.lire/compta.lire.");
        }

        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Remise SEPA introuvable.');
        }

        $remise = $this->em->getRepository(RemiseSepa::class)->find($id);
        if (!$remise instanceof RemiseSepa) {
            throw new NotFoundHttpException('Remise SEPA introuvable.');
        }

        $etablissementActif = $this->contexte->idActif();
        if ($etablissementActif === null || $remise->getEtablissement() === null
            || !$etablissementActif->equals($remise->getEtablissement()->getId())) {
            throw new AccessDeniedHttpException('Remise SEPA hors du périmètre de l\'établissement actif (RG-SOCLE-05).');
        }

        $response = new Response((string) $remise->getContenuXml());
        $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s.xml"', $remise->getMessageId() ?? (string) $remise->getId()),
        );

        return $response;
    }
}
