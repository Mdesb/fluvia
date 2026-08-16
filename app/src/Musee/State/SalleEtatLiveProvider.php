<?php

declare(strict_types=1);

namespace App\Musee\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\Entity\JaugeFmi;
use App\Musee\ApiResource\SalleEtatLive;
use App\Musee\Entity\PolitiqueDelestage;
use App\Musee\Entity\Salle;
use App\Musee\Entity\SousQuotaSalle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /musee/salles/{id}/etat (US-MUSEE-02, CA-2) : compose `JaugeFmi` (L3, lecture seule) et le
 * `SousQuotaSalle`/`PolitiqueDelestage` de la salle — même patron que `PossEtatLiveProvider` (Piscine).
 *
 * @implements ProviderInterface<SalleEtatLive>
 */
final class SalleEtatLiveProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SalleEtatLive
    {
        $id = $uriVariables['id'] ?? null;
        $salle = \is_string($id) && Uuid::isValid($id) ? $this->em->getRepository(Salle::class)->find($id) : null;
        if (!$salle instanceof Salle) {
            throw new NotFoundHttpException('Salle introuvable.');
        }

        $espaceAcces = $salle->getEspaceAcces();
        $jauge = $espaceAcces !== null ? $this->em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espaceAcces]) : null;
        $sousQuota = $this->em->getRepository(SousQuotaSalle::class)->findOneBy(['salle' => $salle]);
        $politique = $sousQuota !== null ? $this->em->getRepository(PolitiqueDelestage::class)->findOneBy(['sousQuotaSalle' => $sousQuota]) : null;

        $vue = new SalleEtatLive();
        $vue->id = (string) $salle->getId();
        $vue->presents = $jauge instanceof JaugeFmi ? $jauge->getValeurCourante() : 0;
        $vue->seuil = $sousQuota?->getSeuil() ?? 0;
        $vue->modeSeuil = $sousQuota?->getModeSeuil()?->value;
        $vue->seuilAtteint = $vue->seuil > 0 && $vue->presents >= $vue->seuil;
        $vue->preAlerteAtteinte = $this->preAlerteAtteinte($sousQuota, $vue->presents, $vue->seuil);
        $vue->politiqueDelestageMode = $politique?->getMode()->value;
        $vue->messageAgent = $politique?->getMessageAgent();

        return $vue;
    }

    private function preAlerteAtteinte(?SousQuotaSalle $sousQuota, int $presents, int $seuil): bool
    {
        $pct = $sousQuota?->getPreAlertePct();
        if ($pct === null || $seuil <= 0) {
            return false;
        }

        return $presents >= (int) ceil($seuil * $pct / 100);
    }
}
