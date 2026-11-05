<?php

declare(strict_types=1);

namespace App\Boutique\State;

use App\Boutique\Entity\SuiviCommandeEnLigne;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Enum\StatutPanier;
use App\Boutique\Paiement\OnlinePaymentContext;
use App\Boutique\Paiement\OnlinePaymentSettlement;
use App\Boutique\Paiement\SelecteurPaiementEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\ConfirmerCommandeHandler;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\Vente\Service\PanierCalculateur;
use App\Vente\Enum\StatutVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /boutique/paniers/{id}/payer — étape 3→4 (US-L8-07, RG-M3-11, CA-9/CA-10). Vérifie
 * mention d'information (CA-6, #101), bénéficiaires (CA-7), autorisations parentales (CA-8), blocage abonnement
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
        private readonly OnlinePaymentSettlement $settlement,
        #[Autowire(env: 'default::BOUTIQUE_BASE_URL')] private readonly ?string $boutiqueBaseUrl = null,
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
        if ($data->getPrivacyNoticeShownAt() === null) {
            // CA-6, relu par #101 : on ne paie pas avant que la mention d'information ait été affichée
            // et horodatée (appel `consentement`). Ce n'est plus une case à cocher : c'est la preuve
            // que l'acheteur a été informé, que l'on doit pouvoir produire.
            throw new UnprocessableEntityHttpException('RG-M3-07 : la mention d\'information doit être enregistrée avant paiement.');
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

        // #339 — UNE INITIATION PRÉCÉDENTE A PEUT-ÊTRE ÉTÉ PAYÉE. L'acheteur revenu sur « payer » (autre
        // onglet, bouton précédent) écraserait la référence d'une opération que le prestataire a peut-être
        // encaissée : la notification ne retrouverait plus la commande. On demande donc d'abord l'issue de
        // la précédente, par la voie ordinaire ; payée, la commande est réglée et on n'en ouvre pas d'autre.
        $this->reglerUneInitiationPrecedentePayee($data);

        $this->confirmerCommande->reverifierDisponibilite($data);

        $vente = $this->confirmerCommande->creerOuRecupererVente($data);

        $profil = $this->resoudreProfil($data);
        $adaptateur = $this->selecteur->pour($profil);
        $montantCentimes = $this->calculateur->centimes($vente->getTotal());
        $initiation = $adaptateur->initierPaiement(
            $vente->getId(),
            $montantCentimes,
            $this->urlRetour($data),
            new OnlinePaymentContext($vente->getNumero(), $this->adresseAcheteur($data)),
        );

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

    private function reglerUneInitiationPrecedentePayee(PanierEnLigne $panier): void
    {
        $suivi = $this->em->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $panier]);
        $reference = $suivi?->getPaymentReference();
        if (!$suivi instanceof SuiviCommandeEnLigne || $reference === null || $suivi->getVente()?->getStatut() !== StatutVente::EnCours) {
            return;
        }

        try {
            $issue = $this->settlement->settle($suivi, $reference, []);
        } catch (HttpExceptionInterface) {
            // Rien n'atteste la précédente (bouchon sans reçu, opération inconnue) : on en ouvre une neuve.
            return;
        }
        if ($issue->vente !== null) {
            throw new ConflictHttpException('Cette commande vient d\'être réglée : vos billets sont disponibles.');
        }
    }

    /**
     * L'adresse où le prestataire renvoie l'acheteur : la boutique de SA vitrine, page de retour. Pas de
     * paramètre ici — PayFiP y ajoute `?idop=…` (guide §3.5.2.4), Stripe `?session_id=…`. Vide si
     * BOUTIQUE_BASE_URL n'est pas renseignée : les bouchons n'en ont pas besoin, un vrai prestataire
     * refusera clairement.
     */
    private function urlRetour(PanierEnLigne $panier): string
    {
        $base = rtrim(trim((string) $this->boutiqueBaseUrl), '/');
        $vitrine = $panier->getVitrine();
        if ($base === '' || $vitrine === null) {
            return '';
        }
        $cle = $vitrine->getSlug() ?: (string) $vitrine->getId();

        return $base . '/b/' . rawurlencode($cle) . '/retour-paiement';
    }

    private function adresseAcheteur(PanierEnLigne $panier): string
    {
        return (string) ($this->confirmerCommande->resoudreClient($panier)->getEmail() ?: $panier->getContactConnu());
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
