<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\ImputationLotStock;
use App\Stock\Entity\Inventaire;
use App\Stock\Entity\LigneInventaire;
use App\Stock\Entity\LotStock;
use App\Stock\Entity\MouvementStock;
use App\Stock\Entity\ParametrageStock;
use App\Stock\Enum\OrigineLotStock;
use App\Stock\Enum\PerimetreInventaire;
use App\Stock\Enum\StatutInventaire;
use App\Stock\Enum\TypeMouvementStock;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Inventaire physique (US-STOCK-11, RG-STOCK-12, §2.3 du plan). Lancement : snapshot théorique figé.
 * Comptage : écart dérivé, significativité évaluée contre `ParametrageStock`. Régularisation : valorisée
 * au coût de la **dernière couche active** (écart positif : nouvelle couche à ce coût, ou au prix
 * d'achat courant à défaut de couche active ; écart négatif : consomme prioritairement cette couche,
 * repli FIFO/LIFO général si insuffisante, §2.3). Le mouvement créé porte le type
 * `regularisation_inventaire` (seul type de l'ensemble fermé RG-STOCK-07 pour cet événement) ; le sens
 * de l'écart reste disponible via `LigneInventaire.ecart` (non redondant avec `type`).
 */
final class InventaireRegularisationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MoteurValorisationFifoLifo $moteur,
        private readonly ResolveurMethodeValorisation $resolveur,
        private readonly DisponibiliteStockHandler $disponibilite,
    ) {
    }

    /** @param list<string>|null $filtre */
    public function lancer(Etablissement $etablissement, PerimetreInventaire $perimetre, ?array $filtre): Inventaire
    {
        $inventaire = new Inventaire();
        $inventaire->setEtablissement($etablissement)->setPerimetre($perimetre)->setFiltre($filtre);
        $this->em->persist($inventaire);

        $qb = $this->em->getRepository(ArticleStock::class)->createQueryBuilder('a')
            ->andWhere('a.etablissement = :etab')
            ->andWhere('a.actif = true')
            ->setParameter('etab', $etablissement->getId(), 'uuid');

        if ($perimetre === PerimetreInventaire::Selection && $filtre !== null && $filtre !== []) {
            $qb->andWhere('a.id IN (:ids)')->setParameter('ids', $filtre);
        }

        $articles = $qb->getQuery()->getResult();
        foreach ($articles as $article) {
            \assert($article instanceof ArticleStock);
            $theorique = (string) ($article->getProduit()?->getStock()?->disponibiliteEffective() ?? 0);

            $ligne = new LigneInventaire();
            $ligne->setArticleStock($article)->setQuantiteTheorique($theorique . '.000');
            $inventaire->addLigne($ligne);
            $this->em->persist($ligne);
        }

        return $inventaire;
    }

    public function saisirComptage(LigneInventaire $ligne, string $quantiteComptee): void
    {
        $ligne->setQuantiteComptee($quantiteComptee);
        $ecart = ArithmetiqueDecimale::soustraire($quantiteComptee, $ligne->getQuantiteTheorique(), 3);
        $ligne->setEcart($ecart);
        $ligne->setSignificatif($this->estSignificatif($ligne, $ecart));
    }

    public function regulariser(LigneInventaire $ligne, ?Utilisateur $validateur, bool $autoriseValidationEcart): MouvementStock
    {
        $ecart = $ligne->getEcart();
        if ($ecart === null) {
            throw new UnprocessableEntityHttpException('Aucun comptage saisi pour cette ligne.');
        }
        if ($ligne->getMouvementRegularisation() !== null) {
            throw new ConflictHttpException('Cette ligne a déjà été régularisée.');
        }
        $ecartMilli = ArithmetiqueDecimale::versEntier($ecart, 3);
        if ($ecartMilli === 0) {
            throw new UnprocessableEntityHttpException('Écart nul : rien à régulariser.');
        }

        if ($ligne->isSignificatif()) {
            if (!$autoriseValidationEcart) {
                throw new AccessDeniedHttpException('Écart significatif : validation Responsable requise (stock.valider_ecart).');
            }
            $ligne->setValideParUtilisateur($validateur)->setDateValidation(new \DateTimeImmutable());
        }

        $article = $ligne->getArticleStock();
        \assert($article !== null);
        $quantiteAbs = ArithmetiqueDecimale::versDecimal(abs($ecartMilli), 3);

        $mouvement = $ecartMilli > 0
            ? $this->regulariserPositif($article, $ligne, $quantiteAbs)
            : $this->regulariserNegatif($article, $ligne, $quantiteAbs);

        $ligne->setMouvementRegularisation($mouvement);

        return $mouvement;
    }

    public function cloturer(Inventaire $inventaire): void
    {
        if ($inventaire->getStatut() === StatutInventaire::Cloture) {
            throw new ConflictHttpException('Cet inventaire est déjà clôturé.');
        }
        $inventaire->setStatut(StatutInventaire::Cloture)->setDateCloture(new \DateTimeImmutable());
    }

    private function regulariserPositif(ArticleStock $article, LigneInventaire $ligne, string $quantiteAbs): MouvementStock
    {
        $cout = $this->moteur->coutDerniereCoucheActive($article) ?? $article->getPrixAchatHT();

        $lot = new LotStock();
        $lot->setArticleStock($article)
            ->setEtablissement($article->getEtablissement())
            ->setDateEntree(new \DateTimeImmutable())
            ->setQuantiteInitiale($quantiteAbs)
            ->setQuantiteRestante($quantiteAbs)
            ->setCoutUnitaireHT($cout)
            ->setOrigine(OrigineLotStock::RegularisationInventaire)
            ->setReferenceOrigineType('Inventaire')
            ->setReferenceOrigineId($ligne->getInventaire()?->getId());
        $this->em->persist($lot);

        $mouvement = new MouvementStock();
        $mouvement->setArticleStock($article)
            ->setEtablissement($article->getEtablissement())
            ->setType(TypeMouvementStock::RegularisationInventaire)
            ->setQuantite($quantiteAbs)
            ->setCoutUnitaireCalcule($cout)
            ->setCoutTotalCalcule(ArithmetiqueDecimale::multiplierVersMontant($quantiteAbs, $cout))
            ->setMotif('Régularisation inventaire (écart +' . $quantiteAbs . ')')
            ->setReferenceType('Inventaire')
            ->setReferenceId($ligne->getInventaire()?->getId());
        $this->em->persist($mouvement);

        $this->disponibilite->incrementer($article, $quantiteAbs);

        return $mouvement;
    }

    private function regulariserNegatif(ArticleStock $article, LigneInventaire $ligne, string $quantiteAbs): MouvementStock
    {
        $methode = $this->resolveur->pour($article);
        $derniereCouche = $this->moteur->dernierLotActif($article);

        $resultat = $derniereCouche !== null
            ? $this->moteur->consommerLotPrioritaire($derniereCouche, $quantiteAbs, $methode)
            : $this->moteur->consommerSelonMethode($article, $quantiteAbs, $methode);

        $mouvement = new MouvementStock();
        $mouvement->setArticleStock($article)
            ->setEtablissement($article->getEtablissement())
            ->setType(TypeMouvementStock::RegularisationInventaire)
            ->setQuantite($quantiteAbs)
            ->setCoutUnitaireCalcule($resultat->coutUnitaireMoyen())
            ->setCoutTotalCalcule($resultat->coutTotal)
            ->setMotif('Régularisation inventaire (écart -' . $quantiteAbs . ')')
            ->setReferenceType('Inventaire')
            ->setReferenceId($ligne->getInventaire()?->getId());
        $this->em->persist($mouvement);

        foreach ($resultat->imputations as $imputation) {
            $ligneImputation = new ImputationLotStock();
            $ligneImputation->setMouvementStock($mouvement)
                ->setLotStock($imputation->lot)
                ->setQuantiteImputee($imputation->quantite)
                ->setCoutUnitaire($imputation->coutUnitaire);
            $this->em->persist($ligneImputation);
        }

        $etablissement = $article->getEtablissement();
        $negatifAutorise = false;
        if ($etablissement !== null) {
            $parametrage = $this->em->getRepository(ParametrageStock::class)->findOneBy(['etablissement' => $etablissement->getId()]);
            $negatifAutorise = $parametrage instanceof ParametrageStock && $parametrage->isAutoriserStockNegatif();
        }
        $this->disponibilite->decrementer($article, $quantiteAbs, $negatifAutorise);

        return $mouvement;
    }

    private function estSignificatif(LigneInventaire $ligne, string $ecart): bool
    {
        $article = $ligne->getArticleStock();
        $etablissement = $article?->getEtablissement();
        if ($etablissement === null) {
            return false;
        }
        $parametrage = $this->em->getRepository(ParametrageStock::class)->findOneBy(['etablissement' => $etablissement->getId()]);
        if (!$parametrage instanceof ParametrageStock) {
            return false;
        }

        $ecartAbsMilli = abs(ArithmetiqueDecimale::versEntier($ecart, 3));
        if ($ecartAbsMilli === 0) {
            return false;
        }

        $seuilPourcentage = $parametrage->getSeuilEcartSignificatifPourcentage();
        if ($seuilPourcentage !== null) {
            $theoriqueMilli = ArithmetiqueDecimale::versEntier($ligne->getQuantiteTheorique(), 3);
            if ($theoriqueMilli > 0) {
                // ratio = ecart/théorique ; ×10000 pour obtenir le pourcentage à l'échelle 2 (ex. 800 = 8,00 %).
                $pourcentageEcart = intdiv($ecartAbsMilli * 10000, $theoriqueMilli);
                if ($pourcentageEcart >= ArithmetiqueDecimale::versEntier($seuilPourcentage, 2)) {
                    return true;
                }
            }
        }

        $seuilMontant = $parametrage->getSeuilEcartSignificatifMontant();
        if ($seuilMontant !== null) {
            $cout = $this->moteur->coutDerniereCoucheActive($article) ?? $article->getPrixAchatHT();
            $montantEcart = ArithmetiqueDecimale::multiplierVersMontant(ArithmetiqueDecimale::versDecimal($ecartAbsMilli, 3), $cout);
            if (ArithmetiqueDecimale::comparer($montantEcart, $seuilMontant, 2) >= 0) {
                return true;
            }
        }

        return false;
    }
}
