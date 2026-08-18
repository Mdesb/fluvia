<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SessionClient;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Enum\TypeSessionClient;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Security\VitrineAccessibleGuard;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /boutique/paniers (US-L8-03, RG-M3-03) : ouvre un panier invité, crée une `SessionClient` et
 * renvoie le jeton de panier en clair (`X-Panier-Token`, une seule fois, §4 du plan). Corps :
 * { "vitrine": iri|uuid }.
 *
 * @implements ProcessorInterface<mixed, PanierEnLigne>
 */
final class OuvrirPanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly VitrineAccessibleGuard $vitrineGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        $corps = $this->lecteur->corps();
        $vitrine = $this->resoudreVitrine($corps['vitrine'] ?? null);

        $jetonClair = PanierProprietaireGuard::genererJeton();
        $session = new SessionClient();
        $session->setToken(PanierProprietaireGuard::hacher($jetonClair))
            ->setType(TypeSessionClient::Invite)
            ->setContactEmail(\is_string($corps['email'] ?? null) ? $corps['email'] : null)
            ->setEtablissement($vitrine->getEtablissement());
        $this->em->persist($session);

        $panier = new PanierEnLigne();
        $panier->setVitrine($vitrine)
            ->setSessionClient($session)
            ->setEtablissement($vitrine->getEtablissement())
            ->setDateExpiration(new \DateTimeImmutable('+' . $vitrine->getDelaiExpirationPanierMinutes() . ' minutes'))
            ->setContactConnu($session->getContactEmail())
            ->setJetonSession($jetonClair);
        $this->em->persist($panier);
        $this->em->flush();

        return $panier;
    }

    private function resoudreVitrine(mixed $reference): Vitrine
    {
        $id = PanierProprietaireGuard::estUuid($reference);
        $vitrine = $id !== null ? $this->em->getRepository(Vitrine::class)->find($id) : null;
        if (!$vitrine instanceof Vitrine) {
            throw new UnprocessableEntityHttpException('« vitrine » est requise et doit référencer une vitrine existante.');
        }
        // Revue de sécurité — faille majeure : établissement inactif ou canal `en_ligne` coupé -> aucune
        // ouverture de panier possible (même règle que `VitrinesPubliquesProvider`/`CatalogueVitrineProvider`).
        $this->vitrineGuard->verifier($vitrine);

        return $vitrine;
    }
}
