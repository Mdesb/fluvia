<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\LigneReceptionAchat;
use App\Stock\Entity\LotStock;
use App\Stock\Entity\MouvementStock;
use App\Stock\Entity\ParametrageStock;
use App\Stock\Entity\ReceptionAchat;
use App\Stock\Enum\OrigineLotStock;
use App\Stock\Enum\TypeMouvementStock;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /stock/mouvements/ajustement` (§4 du plan, CA-6/CA-7) : ajustement positif/négatif,
 * perte/casse, retour fournisseur. Motif obligatoire (RG-STOCK-07). Les sorties consomment les
 * couches actives via `MoteurValorisationFifoLifo` selon la méthode active de l'article (ou le lot
 * d'origine en priorité pour un retour fournisseur, RG-STOCK-06).
 *
 * Note d'implémentation : un ajustement positif « manuel » (hors réception/inventaire) crée une
 * nouvelle couche de coût au prix d'achat courant de l'article ; `OrigineLotStock` ne portant pas de
 * valeur dédiée pour ce cas (fermé par la spec §1), il réutilise `RegularisationInventaire` (choix
 * pragmatique documenté, cf. rapport final).
 */
final class AjustementStockHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MoteurValorisationFifoLifo $moteur,
        private readonly ResolveurMethodeValorisation $resolveur,
        private readonly DisponibiliteStockHandler $disponibilite,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function ajuster(
        ArticleStock $article,
        TypeMouvementStock $type,
        string $quantite,
        string $motif,
        ?ReceptionAchat $receptionOrigine,
        ?Utilisateur $auteur,
    ): MouvementStock {
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Motif requis pour ce mouvement (RG-STOCK-07).');
        }
        if (!ArithmetiqueDecimale::estPositif($quantite, 3)) {
            throw new UnprocessableEntityHttpException('La quantité doit être strictement positive.');
        }

        return match ($type) {
            TypeMouvementStock::AjustementPositif => $this->entreePositive($article, $quantite, $motif, $auteur),
            TypeMouvementStock::AjustementNegatif, TypeMouvementStock::PerteCasse => $this->sortieGenerique($article, $type, $quantite, $motif, $auteur),
            TypeMouvementStock::RetourFournisseur => $this->retourFournisseur($article, $quantite, $motif, $receptionOrigine, $auteur),
            default => throw new UnprocessableEntityHttpException('Type de mouvement non supporté par cet endpoint d\'ajustement.'),
        };
    }

    private function entreePositive(ArticleStock $article, string $quantite, string $motif, ?Utilisateur $auteur): MouvementStock
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
            ->setMotif($motif)
            ->setAuteur($auteur);
        $this->em->persist($mouvement);

        $this->disponibilite->incrementer($article, $quantite);

        return $mouvement;
    }

    private function sortieGenerique(ArticleStock $article, TypeMouvementStock $type, string $quantite, string $motif, ?Utilisateur $auteur): MouvementStock
    {
        $methode = $this->resolveur->pour($article);
        $resultat = $this->moteur->consommerSelonMethode($article, $quantite, $methode);

        $mouvement = $this->creerMouvementSortie($article, $type, $quantite, $motif, $auteur, $resultat);
        $this->disponibilite->decrementer($article, $quantite, $this->negatifAutorise($article));

        return $mouvement;
    }

    private function retourFournisseur(ArticleStock $article, string $quantite, string $motif, ?ReceptionAchat $receptionOrigine, ?Utilisateur $auteur): MouvementStock
    {
        $methode = $this->resolveur->pour($article);
        $lotOrigine = $this->resoudreLotOrigine($article, $receptionOrigine);

        $resultat = $lotOrigine !== null
            ? $this->moteur->consommerLotPrioritaire($lotOrigine, $quantite, $methode)
            : $this->moteur->consommerSelonMethode($article, $quantite, $methode);

        $mouvement = $this->creerMouvementSortie($article, TypeMouvementStock::RetourFournisseur, $quantite, $motif, $auteur, $resultat);
        $this->disponibilite->decrementer($article, $quantite, $this->negatifAutorise($article));

        return $mouvement;
    }

    private function creerMouvementSortie(ArticleStock $article, TypeMouvementStock $type, string $quantite, string $motif, ?Utilisateur $auteur, ResultatConsommation $resultat): MouvementStock
    {
        $mouvement = new MouvementStock();
        $mouvement->setArticleStock($article)
            ->setEtablissement($article->getEtablissement())
            ->setType($type)
            ->setQuantite($quantite)
            ->setCoutUnitaireCalcule($resultat->coutUnitaireMoyen())
            ->setCoutTotalCalcule($resultat->coutTotal)
            ->setMotif($motif)
            ->setAuteur($auteur);
        $this->em->persist($mouvement);

        foreach ($resultat->imputations as $imputation) {
            $ligneImputation = new \App\Stock\Entity\ImputationLotStock();
            $ligneImputation->setMouvementStock($mouvement)
                ->setLotStock($imputation->lot)
                ->setQuantiteImputee($imputation->quantite)
                ->setCoutUnitaire($imputation->coutUnitaire);
            $this->em->persist($ligneImputation);
        }

        // §2.1 du plan : rupture de couches FIFO/LIFO — imputation partielle, non bloquante, mais
        // journalisée (ne doit pas passer silencieusement inaperçue).
        if ($resultat->quantiteNonCouverte !== null) {
            $this->logger->warning('stock.consommation.rupture_couches', [
                'articleStock' => (string) $article->getId(),
                'mouvementStock' => (string) $mouvement->getId(),
                'typeMouvement' => $type->value,
                'quantiteNonCouverte' => $resultat->quantiteNonCouverte,
            ]);
        }

        return $mouvement;
    }

    private function resoudreLotOrigine(ArticleStock $article, ?ReceptionAchat $receptionOrigine): ?LotStock
    {
        if ($receptionOrigine === null) {
            return null;
        }
        $ligne = $this->em->getRepository(LigneReceptionAchat::class)->findOneBy([
            'reception' => $receptionOrigine->getId(),
            'articleStock' => $article->getId(),
        ]);

        return $ligne instanceof LigneReceptionAchat ? $ligne->getLotCree() : null;
    }

    private function negatifAutorise(ArticleStock $article): bool
    {
        $etablissement = $article->getEtablissement();
        if ($etablissement === null) {
            return false;
        }
        $parametrage = $this->em->getRepository(ParametrageStock::class)->findOneBy(['etablissement' => $etablissement->getId()]);

        return $parametrage instanceof ParametrageStock && $parametrage->isAutoriserStockNegatif();
    }
}
