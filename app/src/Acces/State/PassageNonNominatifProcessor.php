<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\Passage;
use App\Acces\Enum\SensPassage;
use App\Acces\Service\ComptageNonNominatifHandler;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Comptage non nominatif « +1 » (POST /acces/passages/non-nominatif, US-L3-04, CA-5). Corps :
 *   { "equipement": iri|uuid, "motif": string, "sens"?: "entree"|"sortie" }
 *
 * @implements ProcessorInterface<mixed, Passage>
 */
final class PassageNonNominatifProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly ComptageNonNominatifHandler $handler,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Passage
    {
        $corps = $this->lecteur->corps();

        $equipementId = $this->uuid($corps['equipement'] ?? null);
        if ($equipementId === null) {
            throw new UnprocessableEntityHttpException('Référence d\'équipement obligatoire.');
        }
        $equipement = $this->em->getRepository(Equipement::class)->find($equipementId);
        if (!$equipement instanceof Equipement) {
            throw new UnprocessableEntityHttpException('Équipement introuvable.');
        }

        // Cloisonnement (D3/D8) — l'opération est `input: false` : l'équipement est résolu depuis le
        // corps par un `find()` direct, hors des extensions. On recalcule l'autorité de l'agent (la
        // sécurité de route : acces.superviser OU acces.controler) contre l'établissement de
        // l'ÉQUIPEMENT VISÉ, pas l'en-tête X-Etablissement (D6). Sans quoi on comptabilise un passage
        // sur un équipement d'un autre établissement. Échec fermé en 404 (anti-oracle).
        $agent = $this->security->getUser();
        $codes = $agent instanceof Utilisateur
            ? $this->calculateur->codesEffectifs($agent, $equipement->getEtablissement()?->getId())
            : [];
        if (!$this->calculateur->autorise($codes, 'acces', 'superviser')
            && !$this->calculateur->autorise($codes, 'acces', 'controler')) {
            throw new NotFoundHttpException('Équipement introuvable.');
        }

        $motif = (string) ($corps['motif'] ?? '');
        $sens = isset($corps['sens']) ? SensPassage::tryFrom((string) $corps['sens']) : SensPassage::Entree;

        return $this->handler->compter($equipement, $sens ?? SensPassage::Entree, $motif);
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
