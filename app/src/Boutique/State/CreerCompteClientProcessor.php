<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\CreationCompteHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /boutique/comptes (US-L8-04, RG-M3-10, CA-5). Corps :
 * { "vitrine": iri|uuid, "email", "motDePasse", "civilite"?, "nom", "prenom"?, "dateNaissance",
 *   "panier"?: iri|uuid } — si `panier` fourni, le panier en cours est rattaché **sans perte de
 * contenu** (§4.3 spec).
 *
 * @implements ProcessorInterface<mixed, CompteClient>
 */
final class CreerCompteClientProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly CreationCompteHandler $creationCompte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CompteClient
    {
        $corps = $this->lecteur->corps();

        $vitrineId = PanierProprietaireGuard::estUuid($corps['vitrine'] ?? null);
        $vitrine = $vitrineId !== null ? $this->em->getRepository(Vitrine::class)->find($vitrineId) : null;
        if (!$vitrine instanceof Vitrine) {
            throw new UnprocessableEntityHttpException('« vitrine » est requise et doit référencer une vitrine existante.');
        }

        $compte = $this->creationCompte->creer($corps, $vitrine);

        $panierId = PanierProprietaireGuard::estUuid($corps['panier'] ?? null);
        if ($panierId !== null) {
            $panier = $this->em->getRepository(PanierEnLigne::class)->find($panierId);
            if ($panier instanceof PanierEnLigne) {
                $panier->setCompteClient($compte);
                $this->em->flush();
            }
        }

        return $compte;
    }
}
