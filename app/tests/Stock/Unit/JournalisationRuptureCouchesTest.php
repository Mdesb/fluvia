<?php

declare(strict_types=1);

namespace App\Tests\Stock\Unit;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Region;
use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\ImputationLotStock;
use App\Stock\Entity\LotStock;
use App\Stock\Entity\MouvementStock;
use App\Stock\Entity\TransfertStock;
use App\Stock\Enum\OrigineLotStock;
use App\Stock\Enum\TypeMouvementStock;
use App\Stock\Enum\Unite;
use App\Stock\Service\AjustementStockHandler;
use App\Stock\Service\DisponibiliteStockHandler;
use App\Stock\Service\MoteurValorisationFifoLifo;
use App\Stock\Service\ResolveurMethodeValorisation;
use App\Stock\Service\StockSettingsProvider;
use App\Stock\Service\TransfertStockHandler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * §2.1 du plan : une rupture de couches FIFO/LIFO (consommation demandée > quantité disponible sur
 * les lots actifs) reste **non bloquante** (imputation partielle) mais doit désormais être
 * journalisée (warning), au lieu de passer silencieusement inaperçue —
 * `ResultatConsommation::$quantiteNonCouverte` était calculé mais jamais lu avant ce correctif.
 */
final class JournalisationRuptureCouchesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ArticleStock $article;
    private Etablissement $etablissement;

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

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);
        $this->etablissement = $etab;

        $article = new ArticleStock();
        $article->setEtablissement($etab)
            ->setCodeEAN('5901234123457')
            ->setLibelle('Article rupture')
            ->setUnite(Unite::Piece)
            ->setPrixAchatHT('2.0000')
            ->setTauxTvaAchat('20.00');
        $em->persist($article);
        $this->article = $article;

        $lot = new LotStock();
        $lot->setArticleStock($article)->setEtablissement($etab)
            ->setDateEntree(new \DateTimeImmutable('2026-03-01'))
            ->setQuantiteInitiale('5.000')->setQuantiteRestante('5.000')
            ->setCoutUnitaireHT('2.0000')->setOrigine(OrigineLotStock::Reception);
        $em->persist($lot);

        $em->flush();
    }

    public function testAjustementNegatifAuDelaDesCouchesDisponiblesJournaliseUnWarning(): void
    {
        $container = static::getContainer();
        /** @var MoteurValorisationFifoLifo $moteur */
        $moteur = $container->get(MoteurValorisationFifoLifo::class);
        /** @var ResolveurMethodeValorisation $resolveur */
        $resolveur = $container->get(ResolveurMethodeValorisation::class);
        /** @var DisponibiliteStockHandler $disponibilite */
        $disponibilite = $container->get(DisponibiliteStockHandler::class);

        $logger = new LoggerEspion();
        /** @var StockSettingsProvider $reglages */
        $reglages = $container->get(StockSettingsProvider::class);
        $handler = new AjustementStockHandler($this->em, $moteur, $resolveur, $disponibilite, $logger, $reglages);

        // 8 unités demandées, seulement 5 disponibles sur l'unique lot actif : rupture de 3 unités.
        $mouvement = $handler->ajuster($this->article, TypeMouvementStock::AjustementNegatif, '8.000', 'Perte constatée', null, null);
        $this->em->flush();

        self::assertInstanceOf(MouvementStock::class, $mouvement);
        self::assertSame('8.000', $mouvement->getQuantite(), 'La quantité demandée reste tracée sur le mouvement, même partiellement couverte.');
        self::assertSame('10.00', $mouvement->getCoutTotalCalcule(), 'Coût = seules les 5 unités effectivement imputées (5 × 2,00 €).');

        $imputations = $this->em->getRepository(ImputationLotStock::class)->findBy(['mouvementStock' => $mouvement->getId()]);
        self::assertCount(1, $imputations);
        self::assertSame('5.000', $imputations[0]->getQuantiteImputee(), 'Seule la quantité disponible est imputée, le reste (3) est une rupture.');

        self::assertCount(1, $logger->warnings, 'La rupture de couches (3 unités non couvertes) doit être journalisée en warning (§2.1 du plan).');
        $contexte = $logger->warnings[0]['context'];
        self::assertSame((string) $this->article->getId(), $contexte['articleStock']);
        self::assertSame((string) $mouvement->getId(), $contexte['mouvementStock']);
        self::assertSame('3.000', $contexte['quantiteNonCouverte']);
    }

    public function testUnRetourFournisseurAuDelaDesCouchesDisponiblesEstRefuse(): void
    {
        // **Le pendant de la regle.** Une perte se constate ; un retour fournisseur ne s'invente pas —
        // on ne renvoie pas au grossiste une marchandise qu'on ne detient plus.
        //
        // Avant, le seul refus vivait dans le compteur M1 `off_stock`, qui n'existe que pour un article
        // rattache a un produit. Cet article-ci n'en a pas : `decrementer()` rendait donc la main en
        // silence et le refus etait inatteignable. La garde s'appuie desormais sur les lots, qui sont
        // la source de verite du module.
        $container = static::getContainer();
        /** @var MoteurValorisationFifoLifo $moteur */
        $moteur = $container->get(MoteurValorisationFifoLifo::class);
        /** @var ResolveurMethodeValorisation $resolveur */
        $resolveur = $container->get(ResolveurMethodeValorisation::class);
        /** @var DisponibiliteStockHandler $disponibilite */
        $disponibilite = $container->get(DisponibiliteStockHandler::class);
        /** @var StockSettingsProvider $reglages */
        $reglages = $container->get(StockSettingsProvider::class);

        $handler = new AjustementStockHandler($this->em, $moteur, $resolveur, $disponibilite, new LoggerEspion(), $reglages);

        $this->expectException(UnprocessableEntityHttpException::class);
        // 8 demandees, 5 en lot : le retour porte sur 3 unites qui n'existent pas.
        $handler->ajuster($this->article, TypeMouvementStock::RetourFournisseur, '8.000', 'Retour grossiste', null, null);
    }

    public function testUnePerteConstateeResteEnregistrableAuDelaDesCouches(): void
    {
        // Le meme depassement, mais constate et non pretendu : il doit passer. Refuser laisserait les
        // livres durablement faux et obligerait l'exploitant a mentir sur la quantite pour declarer
        // sa perte.
        $container = static::getContainer();
        /** @var MoteurValorisationFifoLifo $moteur */
        $moteur = $container->get(MoteurValorisationFifoLifo::class);
        /** @var ResolveurMethodeValorisation $resolveur */
        $resolveur = $container->get(ResolveurMethodeValorisation::class);
        /** @var DisponibiliteStockHandler $disponibilite */
        $disponibilite = $container->get(DisponibiliteStockHandler::class);
        /** @var StockSettingsProvider $reglages */
        $reglages = $container->get(StockSettingsProvider::class);

        $handler = new AjustementStockHandler($this->em, $moteur, $resolveur, $disponibilite, new LoggerEspion(), $reglages);

        $mouvement = $handler->ajuster($this->article, TypeMouvementStock::PerteCasse, '8.000', 'Casse en reserve', null, null);
        $this->em->flush();

        self::assertSame('8.000', $mouvement->getQuantite());
    }

    public function testExpeditionTransfertAuDelaDesCouchesDisponiblesJournaliseUnWarning(): void
    {
        $region = $this->em->getRepository(Region::class)->findOneBy([]);
        self::assertInstanceOf(Region::class, $region);

        $etabB = new Etablissement();
        $etabB->setNom('Établissement destination rupture')->setRegion($region)->setActif(true);
        $this->em->persist($etabB);

        $articleDestination = new ArticleStock();
        $articleDestination->setEtablissement($etabB)
            ->setCodeEAN('40170725')
            ->setLibelle('Article destination')
            ->setUnite(Unite::Piece)
            ->setPrixAchatHT('2.0000')
            ->setTauxTvaAchat('20.00');
        $this->em->persist($articleDestination);
        $this->em->flush();

        $transfert = new TransfertStock();
        $transfert->setArticleStockSource($this->article)
            ->setArticleStockDestination($articleDestination)
            ->setQuantite('8.000'); // seulement 5 disponibles côté source.
        $this->em->persist($transfert);
        $this->em->flush();

        $container = static::getContainer();
        /** @var MoteurValorisationFifoLifo $moteur */
        $moteur = $container->get(MoteurValorisationFifoLifo::class);
        /** @var ResolveurMethodeValorisation $resolveur */
        $resolveur = $container->get(ResolveurMethodeValorisation::class);
        /** @var DisponibiliteStockHandler $disponibilite */
        $disponibilite = $container->get(DisponibiliteStockHandler::class);

        $logger = new LoggerEspion();
        $handler = new TransfertStockHandler($this->em, $moteur, $resolveur, $disponibilite, $logger);

        $handler->expedier($transfert);
        $this->em->flush();

        self::assertCount(1, $logger->warnings, 'La rupture de couches côté source d\'un transfert doit être journalisée en warning (§2.1 du plan).');
        $contexte = $logger->warnings[0]['context'];
        self::assertSame((string) $this->article->getId(), $contexte['articleStock']);
        self::assertSame((string) $transfert->getId(), $contexte['transfertStock']);
        self::assertSame('3.000', $contexte['quantiteNonCouverte']);
    }
}

/** Espion minimal PSR-3 : enregistre les warnings pour assertion, sans dépendance à un TestHandler Monolog. */
final class LoggerEspion extends AbstractLogger implements LoggerInterface
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $warnings = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ((string) $level === 'warning') {
            $this->warnings[] = ['message' => (string) $message, 'context' => $context];
        }
    }
}
