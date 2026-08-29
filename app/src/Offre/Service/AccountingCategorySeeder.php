<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Categorie;
use App\Offre\Enum\AxeCategorie;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LES CATÉGORIES COMPTABLES USUELLES, POSÉES COMME NOMENCLATURE.
 *
 * ── LE DÉFAUT QUE ÇA FERME, ET IL ÉTAIT SILENCIEUX ──────────────────────────────────────────────
 *
 * Une ligne libre de facture porte une catégorie comptable, et cette catégorie décide du compte de
 * produit par `MappingComptable`. Mesuré sur la préproduction le 29/08 : **une seule catégorie d'axe
 * comptable existait, et une seule correspondance**. Le résolveur se repliait donc en silence sur le
 * compte par défaut, quelle que soit la catégorie choisie.
 *
 * ⚠ CE N'EST PAS UNE ERREUR QUI SE VOIT. L'écran accepte la saisie, la facture s'émet, l'écriture
 * part — sur le mauvais compte. Un exploitant qui catégorise consciencieusement pendant des mois
 * découvrirait à la clôture que sa comptabilité ne distingue rien. Signalé par allaccess-b8 à partir
 * du champ livré par allaccess-34.
 *
 * ── POURQUOI LES CATÉGORIES SE SEEDENT ET PAS LES CORRESPONDANCES ───────────────────────────────
 *
 * La question a été posée en ces termes : « un mapping par défaut est-il de la nomenclature comme le
 * plan de comptes, ou un choix d'exploitant ? » Le modèle tranche, et il tranche différemment pour
 * les deux :
 *
 * - **La catégorie est de la nomenclature.** « Billetterie », « Restauration », « Location » sont les
 *   mêmes chez tout le monde ; elles sont posées en portée **socle** (D51), donc partagées, et
 *   chacun peut toujours en ajouter localement.
 * - **La correspondance est un choix d'exploitant.** `MappingComptable::$categorie` vise une
 *   catégorie, et son compte dépend du plan de comptes du profil et des habitudes de son
 *   comptable. Elle a déjà son API ; il lui manque un écran.
 *
 * Sans les catégories, cet écran serait vide — c'est pourquoi celles-ci passent d'abord.
 *
 * ── IDEMPOTENT, ET LA CLÉ EST L'AXE PLUS LE LIBELLÉ ─────────────────────────────────────────────
 *
 * `manquants()` est la seule source consultée : le constat d'une commande de reprise et la pose
 * réelle lisent la même chose, donc le constat ne peut pas annoncer autre chose que ce que la pose
 * ferait. C'est le patron déjà retenu pour le plan de comptes.
 */
final class AccountingCategorySeeder
{
    /**
     * Les catégories usuelles d'un exploitant de loisirs.
     *
     * ⚠ ON N'EN MET PAS TRENTE. Une liste longue se parcourt mal dans un menu déroulant, et une
     * catégorie qu'on ne sait pas choisir est choisie au hasard — ce qui coûte plus cher qu'une
     * catégorie manquante, parce qu'une écriture mal rangée ne se signale pas. Celles-ci couvrent ce
     * qu'une billetterie de loisirs facture réellement ; le reste s'ajoute localement.
     *
     * @var list<string>
     */
    private const USUELLES = [
        'Billetterie',
        'Abonnements',
        'Locations',
        'Restauration',
        'Boutique',
        'Prestations et animations',
        'Frais de dossier',
        'Divers',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Les libellés qui n'existent pas encore en portée socle sur l'axe comptable.
     *
     * @return list<string>
     */
    public function manquants(): array
    {
        $existants = [];

        foreach ($this->em->getRepository(Categorie::class)->findBy(['axe' => AxeCategorie::Comptable]) as $categorie) {
            // ⚠ On ne compte que le socle. Une catégorie LOCALE portant le même libellé n'est pas la
            // même chose : elle n'appartient qu'à un établissement, et la considérer comme présente
            // priverait tous les autres de la nomenclature.
            if ($categorie->estDuSocle()) {
                $existants[] = mb_strtolower($categorie->getLibelle());
            }
        }

        $manquants = [];
        foreach (self::USUELLES as $libelle) {
            if (!\in_array(mb_strtolower($libelle), $existants, true)) {
                $manquants[] = $libelle;
            }
        }

        return $manquants;
    }

    /**
     * Pose ce qui manque. Ne touche jamais à ce qui existe.
     *
     * @return list<string> les libellés réellement créés
     */
    public function poser(): array
    {
        $poses = [];

        foreach ($this->manquants() as $libelle) {
            $categorie = (new Categorie())
                ->setAxe(AxeCategorie::Comptable)
                ->setLibelle($libelle);

            $this->em->persist($categorie);
            $poses[] = $libelle;
        }

        return $poses;
    }
}
