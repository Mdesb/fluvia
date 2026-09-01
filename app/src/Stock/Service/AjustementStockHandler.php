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
        private readonly StockSettingsProvider $reglages,
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
        // **La garde de disponibilite du module, et elle manquait pour toute une famille d'articles.**
        //
        // Jusqu'ici, le seul refus de sortie vivait dans `DisponibiliteStockHandler::decrementer()`,
        // qui n'agit que sur le compteur M1 `off_stock`. Or ce compteur n'existe que pour un article
        // **rattache a un produit** : pour les autres, la methode rendait la main en silence et le
        // refus etait donc **inatteignable**. On pouvait sortir indefiniment un article non catalogue,
        // sans jamais rencontrer d'erreur et sans que la disponibilite ne baisse, puisqu'elle n'etait
        // jamais ecrite. Le controle est desormais porte par la source de verite du module, les lots.
        //
        // **Mais il ne s'applique pas a toutes les sorties, et la ligne de partage compte :**
        //
        //   on refuse ce qui pretend FAIRE SORTIR de la marchandise ;
        //   on n'empeche jamais de CONSTATER qu'elle n'est plus la.
        //
        // Une vente ou un retour fournisseur presupposent qu'on detient le bien : les refuser quand
        // les lots ne couvrent pas est la seule reponse juste. Un ajustement negatif ou une perte
        // constatee, au contraire, enregistrent un fait deja survenu — les refuser laisserait les
        // livres durablement faux et obligerait l'exploitant a mentir sur la quantite pour pouvoir
        // declarer sa perte. C'est ce que verifie `JournalisationRuptureCouchesTest` avec son
        // scenario « Perte constatee », et il a raison.
        //
        // `SortieTransfert` tombe logiquement du cote des refus, mais elle vit dans
        // `TransfertStockHandler` et porte son propre test de non-blocage : je la signale a
        // l'integrateur plutot que de la trancher dans un lot qui ne la vise pas.
        //
        // Placee **avant** le moindre `persist()` et avant l'ecriture DBAL du compteur M1 : refuser
        // apres avoir ecrit obligerait a compter sur un rollback pour rester coherent.
        // `SortieVente` **ne transite jamais par ce handler** — verifie : `ajuster()` ne l'accepte pas,
        // et une vente est deja refusee en amont par `App\Vente\Service\DecrementStockHandler`, dont
        // l'UPDATE conditionnel rend 422 sur rupture. L'inclure ici aurait fait croire a une protection
        // qui n'existe pas a cet endroit. Reste le retour fournisseur, qui presuppose bien la detention.
        $presupposeLaDetention = TypeMouvementStock::RetourFournisseur === $type;

        if ($presupposeLaDetention && $resultat->quantiteNonCouverte !== null && !$this->negatifAutorise($article)) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Stock insuffisant pour ce mouvement (RG-STOCK-16) : les lots ne couvrent pas %s unite(s).',
                $resultat->quantiteNonCouverte,
            ));
        }

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

        // §2.1 du plan : rupture de couches FIFO/LIFO — imputation partielle. Depuis la garde
        // posee en tete de cette methode, ce cas ne survit que lorsque le stock negatif est
        // **autorise** : la journalisation garde donc tout son sens, mais elle ne couvre plus une
        // sortie a decouvert subie, seulement une sortie a decouvert assumee.
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
        // Le stock negatif est une PERMISSION : sans decision explicite, elle n'est pas accordee.
        // La regle est declaree dans `StockSettings`, plus improvisee ici (D52).
        return $this->reglages->forEstablishment($article->getEtablissement())->allowsNegativeStock();
    }
}
