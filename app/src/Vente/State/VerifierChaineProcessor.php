<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\PointDeVente;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Vente\Nf525\ScellementHandler;
use App\Vente\Nf525\SignataireOperation;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Vérifie l'intégrité de la chaîne NF525 d'un point de vente (POST /nf525/verifier-chaine, CA-15).
 * Recalcule chaque maillon ; une rupture (trou de séquence, empreinte incohérente, signature
 * invalide) remonte une alerte de contrôle. Corps : { "pointDeVente": iri|uuid }.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class VerifierChaineProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ScellementHandler $scellement,
        private readonly SignataireOperation $signataire,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $reference = $this->lecteur->corps()['pointDeVente'] ?? null;
        $segment = \is_string($reference) && str_contains($reference, '/') ? basename($reference) : $reference;
        if (!\is_string($segment) || !Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException('Référence de point de vente obligatoire.');
        }
        $pdv = $this->em->getRepository(PointDeVente::class)->find(Uuid::fromString($segment));
        if ($pdv === null) {
            throw new UnprocessableEntityHttpException('Point de vente introuvable.');
        }

        // Cloisonnement (D3/D8) — opération `input: false` : le pointDeVente est lu au corps de la
        // requête et résolu par un `find()`, hors des extensions Doctrine. On recalcule l'autorité
        // (`caisse.lire`, permission de l'opération) contre l'établissement du PDV, jamais contre
        // l'en-tête X-Etablissement : sans quoi un agent `caisse.lire` sur A pouvait sonder l'intégrité
        // de la chaîne NF525 (signal de conformité fiscale) d'un point de vente d'un autre établissement.
        // Échec fermé en 404 (anti-oracle).
        $this->assertPdvDansLePerimetre($pdv);

        $rapport = $this->signataire->verifieChaine($this->scellement->chaine($pdv));

        return new JsonResponse(
            ['pointDeVente' => (string) $pdv->getId()] + $rapport->toArray(),
            $rapport->intacte ? JsonResponse::HTTP_OK : JsonResponse::HTTP_CONFLICT,
        );
    }

    private function assertPdvDansLePerimetre(PointDeVente $pdv): void
    {
        $utilisateur = $this->security->getUser();
        $etablissement = $pdv->getEtablissement();

        $codes = $utilisateur instanceof Utilisateur && $etablissement !== null
            ? $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId())
            : [];

        if (!$this->calculateur->autorise($codes, 'caisse', 'lire')) {
            throw new NotFoundHttpException('Point de vente introuvable.');
        }
    }
}
