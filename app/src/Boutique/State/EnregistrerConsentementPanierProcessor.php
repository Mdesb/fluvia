<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\ConfirmerCommandeHandler;
use App\Crm\Entity\Consentement;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\EtatConsentement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;

/**
 * POST /boutique/paniers/{id}/consentement — étape 3 du tunnel (US-L8-06, RG-M3-07/13, CA-6/CA-8).
 * Le consentement RGPD (horodaté, `App\Crm\Entity\Consentement`, canal `boutique` via `source`,
 * réutilisé sans redéfinition) est requis avant paiement ; résout/crée le `Client` du payeur (§0
 * décision n°4 du plan). Corps : { "rgpd": bool, "autorisationsParentales"?: {ligneId: bool} }.
 *
 * @implements ProcessorInterface<PanierEnLigne, PanierEnLigne>
 */
final class EnregistrerConsentementPanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PanierProprietaireGuard $guard,
        private readonly ConfirmerCommandeHandler $confirmerCommande,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        $corps = $this->lecteur->corps();
        $rgpd = ($corps['rgpd'] ?? false) === true;
        /** @var array<string, mixed> $autorisations */
        $autorisations = \is_array($corps['autorisationsParentales'] ?? null) ? $corps['autorisationsParentales'] : [];

        if ($rgpd) {
            $client = $this->confirmerCommande->resoudreClient($data);
            $consentement = new Consentement(CanalConsentement::Email, EtatConsentement::Accorde);
            $consentement->setClient($client)->setSource('boutique');
            $this->em->persist($consentement);
            $data->setConsentementRgpdHorodatage(new \DateTimeImmutable());
        }

        foreach ($data->getLignes() as $ligne) {
            \assert($ligne instanceof LignePanierEnLigne);
            if (!$ligne->isAutorisationParentaleRequise()) {
                continue;
            }
            $id = (string) $ligne->getId();
            if (($autorisations[$id] ?? false) === true) {
                $ligne->setAutorisationParentaleHorodatage(new \DateTimeImmutable());
            }
        }

        $this->em->flush();

        return $data;
    }
}
