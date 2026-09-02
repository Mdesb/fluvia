<?php

declare(strict_types=1);

namespace App\Facturation\Einvoicing;

use App\Facturation\Entity\Facture;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * OÙ LE SÉRIALISEUR VA CHERCHER LES TROIS MENTIONS OBLIGATOIRES DU PROFIL FRANÇAIS.
 *
 * BR-FR-05 impose trois notes (BG-1), chacune portant son code sujet :
 *
 *     PMT   frais de recouvrement
 *     PMD   pénalités de retard
 *     AAB   escompte, ou son absence
 *
 * ── ⚠ POURQUOI UNE CLASSE À PART, ET NON UNE LECTURE DANS LE SÉRIALISEUR ───────────────────────
 *
 * `CiiSerializer` construit du XML à partir d'une facture, sans base de données : ses tests
 * assemblent une `Facture` en mémoire et vérifient des chemins XPath. Lui donner un
 * `EntityManager` obligerait chacun de ces tests à monter un conteneur pour prouver une
 * concaténation.
 *
 * Le fournisseur porte l'accès aux données ; le sérialiseur reste une fonction du document.
 *
 * ── ⚠ ET IL NE FABRIQUE AUCUN TEXTE ────────────────────────────────────────────────────────────
 *
 * `tauxPenaliteRetard` et `indemniteForfaitaireRecouvrement` sont en base : composer
 * « Pénalités de retard : trois fois le taux légal » à partir d'eux serait deux lignes.
 *
 * Ce serait mettre des mots dans la bouche de l'exploitant sur un document **opposable**. Une clause
 * de pénalités engage : elle se relit, elle se négocie, et elle ne s'écrit pas pareil dans une régie
 * municipale et dans une salle de sport privée. Le produit fournit le véhicule ; la formulation
 * reste celle de qui facture.
 *
 * Une mention non rédigée est donc ABSENTE, et le rapport de validation la réclame — ce qui est
 * exactement ce qu'on veut lire.
 */
final class InvoiceMentionsProvider implements InvoiceMentions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, string|null> le code sujet → le texte, tel qu'il a été écrit
     */
    public function pour(Facture $facture): array
    {
        $etablissement = $facture->getEtablissement();
        if ($etablissement === null) {
            return [];
        }

        $parametre = $this->em->getRepository(ParametreFacturationEtablissement::class)
            ->findOneBy(['profilExploitant' => $facture->getProfilExploitant()]);

        if (!$parametre instanceof ParametreFacturationEtablissement) {
            // ⚠ AUCUN PARAMÉTRAGE N'EST UN ÉTAT NORMAL, PAS UNE ERREUR. Un établissement qui n'a
            // jamais ouvert l'écran de réglages n'en a pas. Les mentions manquent, le validateur le
            // dit, et c'est la même conclusion que si elles étaient vides.
            return [];
        }

        return [
            'PMT' => $parametre->getMentionRecouvrement(),
            'PMD' => $parametre->getMentionPenalitesRetard(),
            'AAB' => $parametre->getMentionEscompte(),
        ];
    }
}
