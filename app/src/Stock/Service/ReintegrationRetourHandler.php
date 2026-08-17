<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Securite\Entity\Utilisateur;
use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\LotStock;
use App\Stock\Entity\MouvementStock;
use App\Stock\Enum\OrigineLotStock;
use App\Stock\Enum\TypeMouvementStock;
use App\Vente\Entity\Avoir;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `POST /stock/mouvements/reintegration-retour` (§0 décision n°5 du plan, CA-11) : réintégration du
 * stock après un avoir M2, **jamais automatique** — invoquée volontairement après confirmation
 * opérateur du retour physique en bon état. Référence l'`Avoir` (lecture seule, réutilisé tel quel).
 * Génère un mouvement `ajustement_positif`, motif « retour client », nouvelle couche de coût au prix
 * d'achat courant (comme toute entrée manuelle, cf. `AjustementStockHandler::entreePositive`).
 */
final class ReintegrationRetourHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DisponibiliteStockHandler $disponibilite,
    ) {
    }

    public function reintegrer(ArticleStock $article, string $quantite, Avoir $avoir, ?Utilisateur $auteur): MouvementStock
    {
        $lot = new LotStock();
        $lot->setArticleStock($article)
            ->setEtablissement($article->getEtablissement())
            ->setDateEntree(new \DateTimeImmutable())
            ->setQuantiteInitiale($quantite)
            ->setQuantiteRestante($quantite)
            ->setCoutUnitaireHT($article->getPrixAchatHT())
            ->setOrigine(OrigineLotStock::RegularisationInventaire);
        $this->em->persist($lot);

        $mouvement = new MouvementStock();
        $mouvement->setArticleStock($article)
            ->setEtablissement($article->getEtablissement())
            ->setType(TypeMouvementStock::AjustementPositif)
            ->setQuantite($quantite)
            ->setCoutUnitaireCalcule($article->getPrixAchatHT())
            ->setCoutTotalCalcule(ArithmetiqueDecimale::multiplierVersMontant($quantite, $article->getPrixAchatHT()))
            ->setMotif('Retour client (avoir ' . $avoir->getNumero() . '), retour physique bon état confirmé')
            ->setReferenceType('Avoir')
            ->setReferenceId($avoir->getId())
            ->setAuteur($auteur);
        $this->em->persist($mouvement);

        $this->disponibilite->incrementer($article, $quantite);

        return $mouvement;
    }
}
