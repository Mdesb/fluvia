<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Ticket (POST /ventes/{id}/ticket, CA-11). Impression automatique au-dessus du seuil du point de
 * vente ; en dessous, impression à la demande + renvoi e-mail/SMS proposé au client rattaché. Le
 * duplicata et le renvoi sont tracés dans la réponse. Corps :
 *   { "mode": "imprimer|renvoyer|duplicata", "canal"?: "email|sms" }
 *
 * @implements ProcessorInterface<Vente, JsonResponse>
 */
final class TicketProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PanierCalculateur $calc,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Vente);

        $corps = $this->lecteur->corps();
        $mode = \is_string($corps['mode'] ?? null) ? $corps['mode'] : 'imprimer';

        $seuil = $this->calc->centimes($data->getSession()?->getPointDeVente()?->getSeuilImpression() ?? '0.00');
        $auDessusSeuil = $this->calc->centimes($data->getTotal()) >= $seuil;

        $duplicata = false;
        if ($mode === 'imprimer' || $mode === 'duplicata') {
            $duplicata = $data->isImprime();
            $data->setImprime(true);
            $this->em->flush();
        }

        $renvoiPropose = !$auDessusSeuil && $data->getClient() !== null;
        $renvoye = $mode === 'renvoyer' && $data->getClient() !== null;

        return new JsonResponse([
            'vente' => (string) $data->getId(),
            'numero' => $data->getNumero(),
            'mode' => $mode,
            'imprime' => $data->isImprime(),
            'impressionAutomatique' => $auDessusSeuil,
            'duplicata' => $duplicata,
            'renvoiPropose' => $renvoiPropose,
            'renvoye' => $renvoye,
            'canal' => $renvoye ? ($corps['canal'] ?? 'email') : null,
        ], JsonResponse::HTTP_OK);
    }
}
