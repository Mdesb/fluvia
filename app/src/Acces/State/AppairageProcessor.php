<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\TypeSupport;
use App\Acces\Port\ProjectionDroitInterface;
use App\Acces\Service\AppairageHandler;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Appairage Support ↔ DroitAcces (POST /acces/appairages, US-L3-02, CA-2). Corps :
 *   { "identifiantSupport": string, "typeSupport": "QR"|"RFID"|"wallet",
 *     "droit"?: iri|uuid, "billetSupportRef"?: uuid, "mode": "caisse"|"autonome" }
 * `droit` référence une projection `DroitAcces` déjà existante ; `billetSupportRef` déclenche la
 * projection à la volée depuis un `BilletSupport` M2 (§1.3, point ouvert n°9) si `droit` est absent —
 * cas de l'appairage en borne autonome/ré-appairage où L3 prolonge l'appairage émis par M2.
 *
 * @implements ProcessorInterface<mixed, Appairage>
 */
final class AppairageProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly AppairageHandler $handler,
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $security,
        private readonly ProjectionDroitInterface $projection,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Appairage
    {
        $corps = $this->lecteur->corps();

        $identifiant = (string) ($corps['identifiantSupport'] ?? '');
        if ($identifiant === '') {
            throw new UnprocessableEntityHttpException('Identifiant de support obligatoire.');
        }
        $type = TypeSupport::tryFrom((string) ($corps['typeSupport'] ?? ''));
        if ($type === null) {
            throw new UnprocessableEntityHttpException('Type de support invalide (QR|RFID|wallet).');
        }

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $droitId = $this->uuid($corps['droit'] ?? null);
        $droit = $droitId !== null ? $this->em->getRepository(DroitAcces::class)->find($droitId) : null;

        if (!$droit instanceof DroitAcces) {
            $billetSupportRef = $this->uuid($corps['billetSupportRef'] ?? null);
            if ($billetSupportRef !== null) {
                $droit = $this->projection->projeter($billetSupportRef, $etablissement);
            }
        }

        if (!$droit instanceof DroitAcces) {
            throw new UnprocessableEntityHttpException('Droit introuvable (fournir « droit » ou « billetSupportRef »).');
        }

        $mode = ModeAppairage::tryFrom((string) ($corps['mode'] ?? 'caisse')) ?? ModeAppairage::Caisse;

        $agent = $this->security->getUser();

        return $this->handler->appairer(
            $identifiant,
            $type,
            $droit,
            $mode,
            $etablissement,
            $agent instanceof Utilisateur ? $agent : null,
        );
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
