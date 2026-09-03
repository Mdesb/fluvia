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
use App\Boutique\Service\PanierTarificationHandler;
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
        private readonly PanierTarificationHandler $tarification,
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

        // ⚠ LE PANIER REPART AVEC SES PRIX. Sans cette ligne, la réponse d'une mutation ne
        // porte ni `total` ni `prixUnitaire` — seul `PanierAvecTotalProvider` (le GET) enrichit —
        // et le frontal, qui garde cette réponse en état, affichait un panier sans aucun montant
        // jusqu'au prochain rechargement. Mesuré à l'écran : « Total 8,00 € » disparaissait au
        // premier clic sur « − », remplacé par « le montant total sera calculé à l'étape de
        // paiement », qui se lit comme une politique et non comme un raté.
        // `calculer()` est pur : les trois champs sont transitoires, sans `#[ORM\Column]`.
        $this->tarification->calculer($panier);

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
