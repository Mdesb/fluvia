<?php

declare(strict_types=1);

namespace App\Vente\Controller;

use App\Securite\Service\ContexteEtablissement;
use App\Vente\Entity\Vente;
use App\Vente\Service\DocumentTicket;
use App\Vente\Service\TicketPdfRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/ventes/{id}/ticket.pdf` — le ticket au format d'un rouleau, imprimable partout.
 *
 * Contrôleur simple plutôt que ressource API Platform, même patron que
 * `App\Sepa\Controller\TelechargerPain008Controller` : la réponse est un fichier, pas une ressource.
 *
 * ── ⚠ POURQUOI LE CHEMIN COMMENCE PAR `/api` ──────────────────────────────────────────────────
 *
 * **Ce n'est pas cosmétique.** `infra/nginx/billetterie-preprod.conf` ne route vers le serveur que
 * les préfixes qu'il énumère — `v1|api|auth|me|reporting|media|dms|sepa|audit|calendar|health|…`.
 * Une route posée sur `/ventes/…` tomberait dans le repli SPA : nginx rendrait la coquille HTML du
 * front, avec un 200, et le téléchargement donnerait une page web déguisée en PDF.
 *
 * Et **les tests ne peuvent pas l'attraper** : le harnais parle au noyau Symfony et ne traverse
 * jamais nginx. C'est le piège décrit dans la conf elle-même, qui a déjà coûté cinq routes le 28/08
 * et toute la surface `/v1` le 06/09. `sepa` fonctionne pour la même raison : il est dans la liste.
 *
 * ── ⚠ CE QUE CETTE ROUTE N'EST PAS ────────────────────────────────────────────────────────────
 *
 * **Ce n'est pas la réédition officielle.** NF525 veut qu'une réimpression porte la mention
 * DUPLICATA *et un numéro d'édition*, et que son horodatage soit celui de la réédition. Le modèle ne
 * sait pas encore compter les rééditions (`Vente::$imprime` est un booléen), donc ce GET se contente
 * de **rendre** ce que le POST a déjà décidé : il ne marque rien, ne compte rien, et deux appels
 * donnent le même document — ce qu'on attend d'un GET.
 *
 * Le jour où le compteur existera, cette route devra passer par lui. C'est écrit ici pour que le
 * raccordement ne s'oublie pas : une route qui produit du papier sans laisser de trace est
 * exactement ce que la norme interdit, et rien dans le code ne le rappellera tout seul.
 *
 * ── LE CLOISONNEMENT FERME EN 404, PAS EN 403 ─────────────────────────────────────────────────
 *
 * Décision de flotte : un 403 sur une vente d'un autre établissement confirme qu'elle existe. Le
 * contrôleur SEPA cité plus haut rend encore 403 — il est antérieur à la décision, et ce n'est pas à
 * ce lot de le corriger.
 */
#[AsController]
final class TicketPdfController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly DocumentTicket $document,
        private readonly TicketPdfRenderer $rendu,
    ) {
    }

    #[Route('/api/ventes/{id}/ticket.pdf', name: 'vente_ticket_pdf', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        if (!$this->security->isGranted('PERM', 'vente.lire')) {
            throw new AccessDeniedHttpException('Lecture du ticket réservée à vente.lire.');
        }

        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Vente introuvable.');
        }

        $vente = $this->em->getRepository(Vente::class)->find($id);
        if (!$vente instanceof Vente) {
            throw new NotFoundHttpException('Vente introuvable.');
        }

        $actif = $this->contexte->idActif();
        if ($actif === null || $vente->getEtablissement() === null
            || !$actif->equals($vente->getEtablissement()->getId())) {
            // 404 et non 403 : un refus qui distingue « pas le droit » de « n'existe pas » révèle
            // l'existence de la vente d'un autre établissement (RG-SOCLE-05).
            throw new NotFoundHttpException('Vente introuvable.');
        }

        // `isImprime()` est LU, jamais écrit : ce GET ne décide pas qu'un ticket est sorti. Si la
        // vente a déjà été imprimée, le papier qu'on rend ici en est bien un second — la mention est
        // donc juste, et elle ne dépend pas du nombre d'appels à cette route.
        $pdf = $this->rendu->render($this->document->pour($vente, $vente->isImprime()));

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="ticket-%s.pdf"', $vente->getNumero() ?? $vente->getId()),
        );

        return $response;
    }
}
