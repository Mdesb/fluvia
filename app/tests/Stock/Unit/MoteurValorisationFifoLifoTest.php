<?php

declare(strict_types=1);

namespace App\Tests\Stock\Unit;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\ImputationLotStock;
use App\Stock\Entity\LotStock;
use App\Stock\Entity\MouvementStock;
use App\Stock\Enum\MethodeValorisation;
use App\Stock\Enum\OrigineLotStock;
use App\Stock\Enum\TypeMouvementStock;
use App\Stock\Enum\Unite;
use App\Stock\Service\MoteurValorisationFifoLifo;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `MoteurValorisationFifoLifo` (§2 du plan) — reproduit exactement l'exemple chiffré de la spec §4.5 :
 * Lot A 100u@4,00€ (01/03), Lot B 50u@4,50€ (15/03), vente de 120u le 20/03.
 * CA-8 (FIFO) : coût sorti 490,00 €, Lot A épuisé, Lot B 30u restantes = 135,00 €.
 * CA-9 (LIFO) : coût sorti 505,00 €, Lot B épuisé, Lot A 30u restantes = 120,00 €.
 */
final class MoteurValorisationFifoLifoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MoteurValorisationFifoLifo $moteur;
    private ArticleStock $article;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $container->get(SocleFixtures::class)->load($em);

        /** @var MoteurValorisationFifoLifo $moteur */
        $moteur = $container->get(MoteurValorisationFifoLifo::class);
        $this->moteur = $moteur;

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);

        $article = new ArticleStock();
        $article->setEtablissement($etab)
            ->setCodeEAN('5901234123457')
            ->setLibelle('Mug musée')
            ->setUnite(Unite::Piece)
            ->setPrixAchatHT('4.0000')
            ->setTauxTvaAchat('20.00');
        $em->persist($article);
        $this->article = $article;

        $lotA = new LotStock();
        $lotA->setArticleStock($article)->setEtablissement($etab)
            ->setDateEntree(new \DateTimeImmutable('2026-03-01'))
            ->setQuantiteInitiale('100.000')->setQuantiteRestante('100.000')
            ->setCoutUnitaireHT('4.0000')->setOrigine(OrigineLotStock::Reception);
        $em->persist($lotA);

        $lotB = new LotStock();
        $lotB->setArticleStock($article)->setEtablissement($etab)
            ->setDateEntree(new \DateTimeImmutable('2026-03-15'))
            ->setQuantiteInitiale('50.000')->setQuantiteRestante('50.000')
            ->setCoutUnitaireHT('4.5000')->setOrigine(OrigineLotStock::Reception);
        $em->persist($lotB);

        $em->flush();
    }

    public function testCa8ConsommationFifo(): void
    {
        $resultat = $this->moteur->consommerSelonMethode($this->article, '120.000', MethodeValorisation::Fifo);

        self::assertSame('490.00', $resultat->coutTotal);
        self::assertCount(2, $resultat->imputations);
        self::assertSame('100.000', $resultat->imputations[0]->quantite);
        self::assertSame('4.0000', $resultat->imputations[0]->coutUnitaire);
        self::assertSame('20.000', $resultat->imputations[1]->quantite);
        self::assertSame('4.5000', $resultat->imputations[1]->coutUnitaire);
        self::assertNull($resultat->quantiteNonCouverte);

        $this->em->flush();

        $lots = $this->em->getRepository(LotStock::class)->findBy(['articleStock' => $this->article->getId()], ['dateEntree' => 'ASC']);
        self::assertSame('0.000', $lots[0]->getQuantiteRestante(), 'Lot A (le plus ancien) épuisé en FIFO.');
        self::assertSame('30.000', $lots[1]->getQuantiteRestante(), 'Lot B entamé : 30 unités restantes.');

        self::assertSame('135.00', $this->moteur->valoriserCourant($this->article), '30 × 4,50 € = 135,00 €.');
    }

    public function testCa9ConsommationLifo(): void
    {
        $resultat = $this->moteur->consommerSelonMethode($this->article, '120.000', MethodeValorisation::Lifo);

        self::assertSame('505.00', $resultat->coutTotal);
        self::assertCount(2, $resultat->imputations);
        self::assertSame('50.000', $resultat->imputations[0]->quantite);
        self::assertSame('4.5000', $resultat->imputations[0]->coutUnitaire);
        self::assertSame('70.000', $resultat->imputations[1]->quantite);
        self::assertSame('4.0000', $resultat->imputations[1]->coutUnitaire);

        $this->em->flush();

        $lots = $this->em->getRepository(LotStock::class)->findBy(['articleStock' => $this->article->getId()], ['dateEntree' => 'ASC']);
        self::assertSame('30.000', $lots[0]->getQuantiteRestante(), 'Lot A entamé : 30 unités restantes.');
        self::assertSame('0.000', $lots[1]->getQuantiteRestante(), 'Lot B (le plus récent) épuisé en LIFO.');

        self::assertSame('120.00', $this->moteur->valoriserCourant($this->article), '30 × 4,00 € = 120,00 €.');
    }

    /** RG-STOCK-12 : coût de la dernière couche active = coût du lot le plus récemment entré. */
    public function testCoutDerniereCoucheActive(): void
    {
        self::assertSame('4.5000', $this->moteur->coutDerniereCoucheActive($this->article));
    }

    /**
     * RG-STOCK-18/§2.4 : valorisation historique — reconstruit `quantiteRestante(T)` à partir des
     * imputations datées ≤ T, indépendamment de l'état courant des lots (déjà modifié après la vente).
     */
    public function testValorisationADateReconstruiteAvantEtApresLaSortie(): void
    {
        $resultat = $this->moteur->consommerSelonMethode($this->article, '120.000', MethodeValorisation::Fifo);

        $mouvement = new MouvementStock();
        $mouvement->setArticleStock($this->article)
            ->setEtablissement($this->article->getEtablissement())
            ->setType(TypeMouvementStock::SortieVente)
            ->setDate(new \DateTimeImmutable('2026-03-20'))
            ->setQuantite('120.000')
            ->setCoutTotalCalcule($resultat->coutTotal);
        $this->em->persist($mouvement);

        foreach ($resultat->imputations as $imputation) {
            $ligne = new ImputationLotStock();
            $ligne->setMouvementStock($mouvement)->setLotStock($imputation->lot)
                ->setQuantiteImputee($imputation->quantite)->setCoutUnitaire($imputation->coutUnitaire);
            $this->em->persist($ligne);
        }
        foreach ($resultat->lotsModifies() as $lot) {
            $this->em->persist($lot);
        }
        $this->em->flush();

        // Avant la sortie (19/03) : les deux lots sont intégralement en stock = 400,00 + 225,00 = 625,00 €.
        self::assertSame('625.00', $this->moteur->valoriserADate($this->article, new \DateTimeImmutable('2026-03-19')));

        // À/après la sortie (20/03) : identique à la valorisation courante post-FIFO = 135,00 €.
        self::assertSame('135.00', $this->moteur->valoriserADate($this->article, new \DateTimeImmutable('2026-03-20')));
    }
}
