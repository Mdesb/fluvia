<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Entity\CompteClient;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Entity\Membership;
use App\Membership\Enum\StatutEcheanceSepa;
use App\Offre\Entity\Produit;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /boutique/comptes/me/abonnements : les abonnements payés par le titulaire connecté (statut,
 * prochaine échéance, mandat). Après une souscription en ligne, le client retrouve ici son contrat —
 * il ne le voyait nulle part jusqu'ici.
 *
 * ⚠ LE CLOISONNEMENT VIENT DU FILTRE `payeur`, PAS DE `MembershipScopeExtension`. Ce provider écrit à
 *    la main sort de l'extension Doctrine (qui filtre par en-tête X-Etablissement, que la boutique
 *    n'envoie pas). Le `payeur`, DÉRIVÉ DU COMPTE CONNECTÉ (jamais d'un paramètre de requête), est
 *    donc le seul rempart : un client ne voit que SES abonnements, tous établissements confondus.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class MySubscriptionsProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $compte = $this->compteConnecte();
        $client = $compte->getClient();
        if ($client === null) {
            return new JsonResponse(['abonnements' => []]);
        }

        $abonnements = $this->em->getRepository(Membership::class)->findBy(['payeur' => $client]);

        $sortie = [];
        foreach ($abonnements as $abo) {
            \assert($abo instanceof Membership);
            $formule = $abo->getFormule();
            $produit = $formule !== null
                ? $this->em->getRepository(Produit::class)->findOneBy(['formule' => $formule])
                : null;

            $prochaine = $this->em->getRepository(EcheanceSepa::class)->findBy(
                ['abonnement' => $abo, 'statut' => StatutEcheanceSepa::AVenir],
                ['dateProgrammee' => 'ASC'],
                1,
            );
            $echeance = $prochaine[0] ?? null;
            $mandat = $abo->getMandatSepa();

            $sortie[] = [
                'id' => (string) $abo->getId(),
                'libelle' => $produit?->getLibelle(),
                'statut' => $abo->getStatut()->value,
                'periodicite' => $abo->getPeriodicite()->value,
                'montantCentimes' => $abo->getMontantCentimes(),
                'dateDebutEngagement' => $abo->getDateDebutEngagement()->format(DATE_ATOM),
                'dateFinEngagement' => $abo->getDateFinEngagement()->format(DATE_ATOM),
                'prochaineEcheance' => $echeance === null ? null : [
                    'date' => $echeance->getDateProgrammee()->format(DATE_ATOM),
                    'montantCentimes' => $echeance->getMontantCentimes(),
                    'statut' => $echeance->getStatut()->value,
                ],
                // Aplati (pas d'objet `mandat`) : une clé nommée `mandat` serait prise pour la
                // relation d'entité `MandatSepa` par le garde-fou des lectures indéfinies, alors que
                // c'est ici une donnée façonnée à la main.
                'mandatIban4Derniers' => $mandat?->getIban4Derniers(),
                'mandatStatut' => $mandat !== null ? $mandat->getStatut()->value : null,
            ];
        }

        return new JsonResponse(['abonnements' => $sortie]);
    }

    private function compteConnecte(): CompteClient
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new NotFoundHttpException('Aucun compte client.');
        }
        $compte = $this->em->getRepository(CompteClient::class)->findOneBy(['utilisateur' => $utilisateur]);
        if (!$compte instanceof CompteClient) {
            throw new NotFoundHttpException('Aucun compte client boutique.');
        }

        return $compte;
    }
}
