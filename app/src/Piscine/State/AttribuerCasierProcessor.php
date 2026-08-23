<?php

declare(strict_types=1);

namespace App\Piscine\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Piscine\Entity\BraceletEtanche;
use App\Piscine\Entity\Casier;
use App\Piscine\Entity\CautionCasier;
use App\Piscine\Service\AttribuerCasierHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /piscine/casiers/{id}/attribuer (US-L6-09, CA-9). Corps :
 *   { "bracelet": iri|uuid, "moyenEncaissement"?: string, "montant"?: string }
 * Renvoie un JSON manuel (le type de sortie — CautionCasier — diffère du type de la ressource hôte —
 * Casier —, même pattern que `AnnulerVenteProcessor`/`BloquerSupportProcessor`).
 *
 * @implements ProcessorInterface<Casier, JsonResponse>
 */
final class AttribuerCasierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly AttribuerCasierHandler $handler,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Casier);

        $corps = $this->lecteur->corps();
        $braceletId = $this->uuid($corps['bracelet'] ?? null);
        $bracelet = $braceletId !== null ? $this->em->getRepository(BraceletEtanche::class)->find($braceletId) : null;
        // D8 — « bracelet » est un identifiant fourni par le client, resolu par un `find()` direct : il
        // echappe donc a toute extension de perimetre. Le casier hote, lui, est bien cloisonne
        // (`read: true`), ce qui rendait le defaut invisible : on croyait l'operation protegee parce
        // que sa ressource principale l'etait.
        //
        // Ce n'est pas « juste un casier » : le handler cree une `CautionCasier` avec un moyen
        // d'encaissement et un montant, eux aussi pris dans le corps. Sans ce controle, on rattachait
        // le bracelet d'un etablissement au casier d'un autre, **et on posait de l'argent dessus**.
        //
        // Le bracelet porte son etablissement via son support d'acces. On le confronte a celui du
        // casier plutot qu'a l'etablissement actif : c'est plus strict, et c'est la regle metier reelle
        // — un bracelet d'un autre site n'a rien a faire dans ce casier-ci, meme pour un agent
        // legitimement positionne sur les deux.
        //
        // Echec ferme en 404 : un 403 confirmerait l'existence du bracelet ailleurs. Un bracelet sans
        // support ou sans etablissement echoue aussi — fermeture par defaut.
        if ($bracelet instanceof BraceletEtanche) {
            $etablissementBracelet = $bracelet->getSupport()?->getEtablissement()?->getId();
            if ((string) $etablissementBracelet !== (string) $data->getEtablissement()?->getId()) {
                throw new NotFoundHttpException('Bracelet introuvable.');
            }
        }

        if (!$bracelet instanceof BraceletEtanche) {
            throw new UnprocessableEntityHttpException('Bracelet introuvable.');
        }

        $moyen = isset($corps['moyenEncaissement']) ? (string) $corps['moyenEncaissement'] : null;
        $montant = isset($corps['montant']) ? (string) $corps['montant'] : null;

        $caution = $this->handler->attribuer($data, $bracelet, $moyen, $montant);

        return $this->reponse($caution);
    }

    private function reponse(CautionCasier $caution): JsonResponse
    {
        return new JsonResponse([
            'id' => (string) $caution->getId(),
            'casier' => (string) $caution->getCasier()?->getId(),
            'montant' => $caution->getMontant(),
            'statut' => $caution->getStatut()->value,
            'moyenEncaissement' => $caution->getMoyenEncaissement(),
            'dateEncaissement' => $caution->getDateEncaissement()?->format(DATE_ATOM),
        ], JsonResponse::HTTP_CREATED);
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
