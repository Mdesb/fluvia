<?php

declare(strict_types=1);

namespace App\Autorisation\Service;

use App\Audit\Service\JournalAudit;
use App\Autorisation\Entity\DemandeEscalade;
use App\Autorisation\Entity\LimiteAutorisation;
use App\Autorisation\Entity\OperationSensible;
use App\Autorisation\Enum\PerimetreAutorisation;
use App\Autorisation\Enum\ResultatDecision;
use App\Autorisation\Enum\StatutEscalade;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Service central de décision (§2 plan, RG-AUTZ-04) : avant exécution d'une opération sensible
 * câblée, répond AUTORISÉ / REFUSÉ / ESCALADE_REQUISE à partir (1) du droit binaire existant
 * (`is_granted`, défensif — déjà garanti par le `security:` de l'opération API Platform appelante),
 * (2) du périmètre de la cible (RG-AUTZ-05), (3) du montant vs plafond résolu par
 * `ResolveurLimiteAutorisation` (RG-AUTZ-03).
 *
 * Rétrocompatibilité stricte par construction (§0 n°3 plan, RG-AUTZ-09, CA-6) : si aucune
 * `LimiteAutorisation` n'est trouvée, retour **immédiat** AUTORISÉ, sans écriture ni flush — le
 * chemin d'exécution pour un client qui ne configure rien reste rigoureusement identique au code M2
 * actuel (une seule lecture `LimiteAutorisation` en plus, aucune écriture).
 *
 * Flush ciblé côté service (§0 n°4 plan) : ce service persiste et flush lui-même les effets de bord
 * **uniquement** pour les décisions bloquantes (REFUSE, ESCALADE_REQUISE) — dans ces deux cas, le
 * Processor appelant interrompt de toute façon l'exécution et n'atteindra jamais son propre
 * `flush()` final. Pour AUTORISE, aucun flush n'est déclenché ici : le `flush()` unique du Processor
 * (portant l'`Avoir`) reste strictement inchangé.
 */
final class ServiceAutorisation
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly ResolveurLimiteAutorisation $resolveur,
        private readonly GestionnaireEscalade $gestionnaire,
        private readonly JournalAudit $journal,
    ) {
    }

    public function evaluer(RequeteAutorisation $requete): Decision
    {
        if ($requete->jetonRejeu !== null) {
            return $this->evaluerRejeu($requete);
        }

        // 1. Droit binaire (défensif) : déjà garanti par le `security:` de l'opération appelante.
        if (!$this->security->isGranted('PERM', $requete->operationCode)) {
            return new Decision(ResultatDecision::Refuse, 'Droit non accordé pour cette opération.');
        }

        // 2. Résolution de l'opération sensible + de la limite applicable.
        $operation = $this->em->getRepository(OperationSensible::class)->findOneBy([
            'code' => $requete->operationCode,
            'active' => true,
        ]);
        if ($operation === null) {
            // Opération non référencée au catalogue (ou désactivée) : comportement binaire (RG-AUTZ-09).
            return new Decision(ResultatDecision::Autorise, 'Aucune opération sensible active configurée : comportement binaire.');
        }

        $limite = $this->resolveur->resoudre($operation, $requete->utilisateur, $this->contexte->idActif());
        if ($limite === null) {
            // Rétrocompatibilité stricte (§0 n°3 plan, RG-AUTZ-09) : aucune écriture, retour immédiat.
            return new Decision(ResultatDecision::Autorise, 'Aucune limite configurée : comportement binaire inchangé.', null);
        }

        // 3. Périmètre de la cible (RG-AUTZ-05).
        if (!$this->perimetreRespecte($limite, $requete)) {
            $decision = new Decision(ResultatDecision::Refuse, 'Hors périmètre autorisé pour cette opération (RG-AUTZ-05).', $limite);
            $this->journaliserRefus('autorisation.refus_perimetre', $requete, $limite);
            $this->em->flush();

            return $decision;
        }

        // 4. Montant vs plafond (comparaison inclusive, via ComparateurMontant — pas de bccomp(),
        // ext-bcmath absente de cette image PHP, §0 additif signalé au rapport).
        if ($limite->getPlafondMontant() === null || ComparateurMontant::comparer($requete->montant, $limite->getPlafondMontant()) <= 0) {
            return new Decision(ResultatDecision::Autorise, 'Montant sous le plafond autorisé (ou plafond illimité).', $limite);
        }

        if (!$limite->isEscaladeAuDela()) {
            $decision = new Decision(ResultatDecision::Refuse, 'Dépassement du plafond : aucune escalade possible pour cette configuration (RG-AUTZ-04).', $limite);
            $this->journaliserRefus('autorisation.refus_plafond', $requete, $limite);
            $this->em->flush();

            return $decision;
        }

        $demande = $this->gestionnaire->creer($operation, $requete, $limite);
        $this->em->flush();

        return new Decision(ResultatDecision::EscaladeRequise, "Dépassement du plafond : escalade requise auprès d'un superviseur (RG-AUTZ-06).", $limite, $demande);
    }

    /** Rejeu d'une demande approuvée (§2.4 plan, RG-AUTZ-06). */
    private function evaluerRejeu(RequeteAutorisation $requete): Decision
    {
        $demande = $this->em->getRepository(DemandeEscalade::class)->findOneBy(['jeton' => $requete->jetonRejeu]);
        if ($demande === null) {
            return new Decision(ResultatDecision::Refuse, "Jeton de demande d'escalade inconnu.");
        }
        if ($demande->getStatut() !== StatutEscalade::Approuvee) {
            return new Decision(ResultatDecision::Refuse, "Cette demande d'escalade n'a pas été approuvée (couvre rejetée/expirée/en attente).", null, $demande);
        }
        if ($demande->getDateRejeu() !== null) {
            // §0 n°6 : usage unique, refus 409 explicite (distinct des décisions REFUSE/403 habituelles).
            throw new ConflictHttpException("Cette demande d'escalade a déjà été utilisée pour rejouer l'opération.");
        }
        if ($demande->getOperation()?->getCode() !== $requete->operationCode
            || $demande->getCibleType() !== $requete->cibleType
            || $demande->getCibleId() !== (string) $requete->cibleId
            || ComparateurMontant::comparer($demande->getMontant(), $requete->montant) !== 0
            || $demande->getAuteur() !== $requete->utilisateur
        ) {
            return new Decision(ResultatDecision::Refuse, "La demande approuvée ne correspond pas à cette exécution (cible/montant/auteur différents).", null, $demande);
        }

        $demande->setDateRejeu(new \DateTimeImmutable());
        $this->journal->enregistrer('escalade.rejouee', 'DemandeEscalade', (string) $demande->getId(), $demande->getEtablissement()?->getId(), $requete->utilisateur->getEmail());
        // Pas de flush ici (§0 n°4) : décision AUTORISE, le Processor appelant porte la transaction.

        return new Decision(ResultatDecision::Autorise, "Escalade approuvée : rejeu autorisé sans recomparaison au plafond (RG-AUTZ-06).", null, $demande);
    }

    private function perimetreRespecte(LimiteAutorisation $limite, RequeteAutorisation $requete): bool
    {
        $utilisateurId = $requete->utilisateur->getId();

        return match ($limite->getPerimetre()) {
            PerimetreAutorisation::PropreSession =>
                ($requete->cibleSessionOperateurId !== null && $requete->cibleSessionOperateurId->equals($utilisateurId))
                || ($requete->cibleSessionRegisseurId !== null && $requete->cibleSessionRegisseurId->equals($utilisateurId)),
            PerimetreAutorisation::PropreEtablissement =>
                $this->contexte->idActif() !== null
                && $requete->cibleEtablissementId !== null
                && $requete->cibleEtablissementId->equals($this->contexte->idActif()),
            PerimetreAutorisation::Global => true,
        };
    }

    private function journaliserRefus(string $action, RequeteAutorisation $requete, LimiteAutorisation $limite): void
    {
        $entree = $this->journal->enregistrer(
            $action,
            $requete->cibleType,
            (string) $requete->cibleId,
            $requete->cibleEtablissementId,
            $requete->utilisateur->getEmail(),
        );
        $entree->setValeurApres([
            'operation' => $requete->operationCode,
            'montant' => $requete->montant,
            'plafond' => $limite->getPlafondMontant(),
            'perimetre' => $limite->getPerimetre()->value,
        ]);
    }
}
