<?php

declare(strict_types=1);

namespace App\Boutique\State;

use App\Boutique\Entity\SuiviCommandeEnLigne;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Enum\StatutPanier;
use App\Boutique\Paiement\SelecteurPaiementEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\ConfirmerCommandeHandler;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /boutique/paniers/{id}/payer — étape 3→4 (US-L8-07, RG-M3-11, CA-9/CA-10). Vérifie
 * consentement (CA-6), bénéficiaires (CA-7), autorisations parentales (CA-8), blocage abonnement
 * invité (CA-13), revérifie la disponibilité (§0 décision n°8) puis initie le paiement commuté
 * (`SelecteurPaiementEnLigne`).
 *
 * @implements ProcessorInterface<PanierEnLigne, JsonResponse>
 */
final class PayerPanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierProprietaireGuard $guard,
        private readonly ConfirmerCommandeHandler $confirmerCommande,
        private readonly SelecteurPaiementEnLigne $selecteur,
        private readonly PanierCalculateur $calculateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        if ($data->getStatut() !== StatutPanier::Ouvert) {
            throw new ConflictHttpException('Panier expiré ou déjà transformé en commande.');
        }
        if ($data->getLignes()->isEmpty()) {
            throw new UnprocessableEntityHttpException('Le panier est vide.');
        }
        if ($data->getConsentementRgpdHorodatage() === null) {
            // CA-6 : bouton de paiement bloqué tant que le consentement RGPD n'est pas coché.
            throw new UnprocessableEntityHttpException('RG-M3-07 : consentement RGPD requis avant paiement.');
        }

        foreach ($data->getLignes() as $ligne) {
            \assert($ligne instanceof LignePanierEnLigne);
            if (!$ligne->aBeneficiaire()) {
                // CA-7 : chaque article doit porter un bénéficiaire affecté.
                throw new UnprocessableEntityHttpException('RG-M4-02 : chaque article doit avoir un bénéficiaire affecté avant paiement.');
            }
            if ($ligne->isAutorisationParentaleRequise() && $ligne->getAutorisationParentaleHorodatage() === null) {
                // CA-8 : autorisation parentale requise pour un bénéficiaire mineur.
                throw new UnprocessableEntityHttpException('RG-M3-13 : autorisation parentale requise pour un bénéficiaire mineur.');
            }
            if (($ligne->getProduit()?->getFormule()?->isSepaActif() ?? false) && $data->getCompteClient() === null) {
                // CA-13 : achat d'un produit sepa/abonnement en pur parcours invité bloqué.
                throw new AccessDeniedHttpException('RG-M3-12 : la création d\'un compte est requise pour acheter un abonnement.');
            }
        }

        $this->confirmerCommande->reverifierDisponibilite($data);
        $vente = $this->confirmerCommande->creerOuRecupererVente($data);

        $profil = $this->resoudreProfil($data);
        $adaptateur = $this->selecteur->pour($profil);
        $montantCentimes = $this->calculateur->centimes($vente->getTotal());
        $initiation = $adaptateur->initierPaiement($vente->getId(), $montantCentimes, '');

        // Ce qu'on vient de confier au prestataire, mémorisé : le retour se confrontera à ces deux
        // valeurs, pas à ce que l'acheteur renverra (audit 06/09, constat 1).
        $suivi = $this->em->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $data]);
        \assert($suivi instanceof SuiviCommandeEnLigne);
        $suivi->recordPaymentInitiation($initiation->referenceTransaction, $montantCentimes);
        $this->em->flush();

        $reponse = [
            'vente' => (string) $vente->getId(),
            'panier' => (string) $data->getId(),
            'moyen' => $profil->getType() === TypeExploitant::RegieDirecte ? 'payfip' : 'cb_psp',
            'referenceTransaction' => $initiation->referenceTransaction,
            'urlRedirection' => $initiation->urlRedirection,
            'montantCentimes' => $montantCentimes,
        ];
        if ($initiation->simulationReceipts !== null) {
            // Rendu par un BOUCHON seulement : la page « prestataire » du frontal public en fait ses
            // boutons. Un vrai prestataire ne rend rien ici — c'est lui qui décide de l'issue.
            $reponse['simulation'] = $initiation->simulationReceipts;
        }

        return new JsonResponse($reponse);
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

        throw new UnprocessableEntityHttpException('Aucun profil exploitant (M6) configuré pour cet établissement (RG-M6-01).');
    }
}
