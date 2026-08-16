<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Paiement\SelecteurPaiementEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\ConfirmerCommandeHandler;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\Vente\Enum\StatutTPE;
use App\Vente\Enum\StatutVente;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /boutique/paniers/{id}/retour-paiement — retour PayFiP/PSP (US-L8-07/08, RG-M3-04/11, CA-9 à
 * CA-12). Un échec/timeout n'enregistre **aucun** paiement : la vente reste `en_cours`, le panier
 * reste valide jusqu'à expiration (CA-10, retentative possible). Un succès déclenche
 * `ConfirmerCommandeHandler::confirmerApresPaiementReussi()` (billets immédiats, e-mail, Reservation
 * confirmée par ligne timed-entry). Corps : { "referenceTransaction", "statut", "montantCentimes" }.
 *
 * @implements ProcessorInterface<PanierEnLigne, JsonResponse>
 */
final class RetourPaiementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierProprietaireGuard $guard,
        private readonly LecteurCorps $lecteur,
        private readonly ConfirmerCommandeHandler $confirmerCommande,
        private readonly SelecteurPaiementEnLigne $selecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        $suivi = $this->em->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $data]);
        $vente = $suivi?->getVente();
        if ($vente === null || $vente->getStatut() !== StatutVente::EnCours) {
            throw new ConflictHttpException('Aucun paiement en cours pour ce panier (RG-M3-11).');
        }

        $profil = $this->resoudreProfil($data);
        $adaptateur = $this->selecteur->pour($profil);
        $resultat = $adaptateur->traiterRetour($this->lecteur->corps());

        if ($resultat->statut !== StatutTPE::Accepte) {
            // CA-10 : aucun paiement enregistré, la vente reste en_cours, le panier reste valide.
            return new JsonResponse(['statut' => 'echec', 'peutReessayer' => true, 'panier' => (string) $data->getId()]);
        }

        $moyen = $profil->getType() === TypeExploitant::RegieDirecte ? 'payfip' : 'cb_psp';
        $conflit = $this->confirmerCommande->confirmerApresPaiementReussi($data, $vente, $moyen, $resultat->referenceTransaction);

        return new JsonResponse([
            'statut' => $conflit ? 'conflit_inventaire' : 'confirme',
            'vente' => (string) $vente->getId(),
            'panier' => (string) $data->getId(),
        ]);
    }

    private function resoudreProfil(PanierEnLigne $panier): ProfilExploitant
    {
        $etablissement = $panier->getEtablissement();
        $profils = $this->em->getRepository(ProfilExploitant::class)->findAll();
        foreach ($profils as $profil) {
            \assert($profil instanceof ProfilExploitant);
            if ($etablissement !== null && $profil->couvre($etablissement)) {
                return $profil;
            }
        }

        throw new UnprocessableEntityHttpException('Aucun profil exploitant (M6) configuré pour cet établissement.');
    }
}
