<?php

declare(strict_types=1);

namespace App\Autorisation\Service;

use App\Audit\Service\JournalAudit;
use App\Autorisation\Entity\DemandeEscalade;
use App\Autorisation\Entity\LimiteAutorisation;
use App\Autorisation\Entity\OperationSensible;
use App\Autorisation\Enum\StatutEscalade;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Flux d'escalade superviseur (§3 plan) : création (par `ServiceAutorisation` uniquement, jamais par
 * l'API publique), approbation/rejet par un titulaire de `autorisation.approuver`, garde
 * séparation des tâches (RG-AUTZ-13). Aucun `flush()` ici (convention constante du dépôt, ex.
 * `App\Caution\Service\GestionCaution`) : les Processors/`ServiceAutorisation` appelants portent la
 * transaction.
 */
final class GestionnaireEscalade
{
    private const DELAI_EXPIRATION_MINUTES_DEFAUT = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAudit $journal,
        private readonly int $delaiExpirationMinutes = self::DELAI_EXPIRATION_MINUTES_DEFAUT,
    ) {
    }

    public function creer(OperationSensible $operation, RequeteAutorisation $requete, LimiteAutorisation $limite): DemandeEscalade
    {
        $demande = new DemandeEscalade();
        $demande->setOperation($operation)
            ->setCibleType($requete->cibleType)
            ->setCibleId((string) $requete->cibleId)
            ->setMontant($requete->montant)
            ->setAuteur($requete->utilisateur)
            ->setEtablissement($limite->getEtablissement())
            ->setDateExpiration((new \DateTimeImmutable())->modify(sprintf('+%d minutes', $this->delaiExpirationMinutes)));
        $this->em->persist($demande);

        $entree = $this->journal->enregistrer(
            'escalade.creee',
            'DemandeEscalade',
            (string) $demande->getId(),
            $demande->getEtablissement()?->getId(),
            $requete->utilisateur->getEmail(),
        );
        $entree->setValeurApres([
            'operation' => $operation->getCode(),
            'montant' => $requete->montant,
            'auteur' => $requete->utilisateur->getEmail(),
            'plafond' => $limite->getPlafondMontant(),
        ]);

        return $demande;
    }

    public function approuver(DemandeEscalade $demande, Utilisateur $superviseur): void
    {
        $this->garderTraitable($demande, $superviseur);

        $demande->setStatut(StatutEscalade::Approuvee)
            ->setSuperviseur($superviseur)
            ->setDateTraitement(new \DateTimeImmutable());

        $entree = $this->journal->enregistrer(
            'escalade.approuvee',
            'DemandeEscalade',
            (string) $demande->getId(),
            $demande->getEtablissement()?->getId(),
            $superviseur->getEmail(),
        );
        $entree->setValeurApres([
            'statut' => 'approuvee',
            'superviseur' => $superviseur->getEmail(),
            'montant' => $demande->getMontant(),
        ]);
    }

    public function rejeter(DemandeEscalade $demande, Utilisateur $superviseur, ?string $motif): void
    {
        $this->garderTraitable($demande, $superviseur);

        if ($motif === null || trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Motif requis pour rejeter une demande d\'escalade.');
        }

        $demande->setStatut(StatutEscalade::Rejetee)
            ->setSuperviseur($superviseur)
            ->setDateTraitement(new \DateTimeImmutable())
            ->setMotifRejet($motif);

        $entree = $this->journal->enregistrer(
            'escalade.rejetee',
            'DemandeEscalade',
            (string) $demande->getId(),
            $demande->getEtablissement()?->getId(),
            $superviseur->getEmail(),
        );
        $entree->setValeurApres([
            'statut' => 'rejetee',
            'superviseur' => $superviseur->getEmail(),
            'motif' => $motif,
        ]);
    }

    private function garderTraitable(DemandeEscalade $demande, Utilisateur $superviseur): void
    {
        if (!$demande->estEnAttenteMaintenant(new \DateTimeImmutable())) {
            throw new ConflictHttpException("Cette demande d'escalade n'est plus en attente (déjà traitée ou expirée).");
        }
        if ($demande->getAuteur() === $superviseur) {
            throw new AccessDeniedHttpException("Vous ne pouvez pas traiter votre propre demande d'escalade (RG-AUTZ-13).");
        }
    }
}
