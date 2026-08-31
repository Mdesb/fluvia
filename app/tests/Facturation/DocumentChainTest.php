<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Compta\Entity\TauxTva;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\CommercialDocument;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\DocumentLine;
use App\Facturation\Enum\DocumentNature;
use App\Facturation\Enum\DocumentStatus;
use App\Facturation\Enum\TypeDestinataire;
use App\Facturation\Exception\ForbiddenDocumentTransitionException;
use App\Facturation\Service\DocumentChain;
use App\Facturation\Service\EmettreFactureDirecteHandler;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * FAC-1 — la chaîne devis → bon de commande → bon de livraison → facture.
 *
 * **Ce que ces tests protègent, c'est la non-ressaisie.** Un club qui vend sans caisse propose, fait
 * confirmer, livre, puis facture. Si chaque étape se ressaisit, les quatre documents finissent par
 * différer — et le client qui reçoit une facture ne correspondant pas à son devis appelle, avec
 * raison. La reprise doit être exacte, et la filiation écrite plutôt que déduite.
 */
final class DocumentChainTest extends FacturationApiTestCase
{
    /** Le chemin nominal : le devis accepté produit la commande, lignes comprises. */
    public function testUnDevisAccepteProduitLaCommandeAvecSesLignes(): void
    {
        $quote = $this->quote([['Cours collectif', 10, '25.00']]);
        $quote->transitionVers(DocumentStatus::Issued, $this->maintenant())
            ->transitionVers(DocumentStatus::Accepted, $this->maintenant());
        $this->em()->flush();

        $commande = $this->chaine()->deriver($quote, $this->auteur(), $this->maintenant());

        self::assertSame(DocumentNature::SalesOrder, $commande->getNature());
        self::assertCount(1, $commande->getLignes());
        self::assertSame('Cours collectif', $commande->getLignes()->first()->getDesignation());
        self::assertSame(10, $commande->getLignes()->first()->getQuantite());
        self::assertSame('250.00', $commande->getTotalHT());

        // La filiation est écrite, pas déduite.
        self::assertSame($quote->getId()->toRfc4122(), $commande->getDocumentOrigine()?->getId()->toRfc4122());
        self::assertSame($quote->getLignes()->first()->getId()->toRfc4122(), $commande->getLignes()->first()->getLigneOrigine()?->toRfc4122());

        // Et le devis est converted : il a produit sa suite.
        self::assertSame(DocumentStatus::Converted, $quote->getStatut());
    }

    /**
     * **Un devis ne produit pas deux commandes.**
     *
     * Deux clics fabriqueraient deux commandes pour un devis, ce qui se découvre à la livraison —
     * quand le client reçoit tout en double.
     */
    public function testUnDevisNeProduitPasDeuxCommandes(): void
    {
        $quote = $this->quote([['Cours collectif', 10, '25.00']]);
        $quote->transitionVers(DocumentStatus::Issued, $this->maintenant())
            ->transitionVers(DocumentStatus::Accepted, $this->maintenant());
        $this->em()->flush();

        $this->chaine()->deriver($quote, $this->auteur(), $this->maintenant());

        $this->expectException(ForbiddenDocumentTransitionException::class);

        $this->chaine()->deriver($quote, $this->auteur(), $this->maintenant());
    }

    /** Un devis non accepté ne produit rien : on ne commande pas ce que le client n'a pas validé. */
    public function testUnDevisNonAccepteNeProduitRien(): void
    {
        $quote = $this->quote([['Cours collectif', 10, '25.00']]);
        $quote->transitionVers(DocumentStatus::Issued, $this->maintenant());
        $this->em()->flush();

        $this->expectException(ForbiddenDocumentTransitionException::class);

        $this->chaine()->deriver($quote, $this->auteur(), $this->maintenant());
    }

    /**
     * **Le cas qui justifie le bon de livraison : on facture ce qui est arrivé.**
     *
     * Dix commandés, sept livrés : la facture porte sept. Facturer la commande ferait payer ce qui
     * n'est pas arrivé, et le client s'en apercevrait avant nous.
     */
    public function testOnFactureCeQuiEstLivreEtNonCeQuiEstCommande(): void
    {
        $livraison = $this->quote([['Cours collectif', 10, '25.00']]);
        $livraison->setNature(DocumentNature::DeliveryNote);
        $livraison->getLignes()->first()->setQuantiteLivree(7);
        $livraison->recalculerTotaux();
        $livraison->transitionVers(DocumentStatus::Issued, $this->maintenant())
            ->transitionVers(DocumentStatus::Accepted, $this->maintenant());
        $this->em()->flush();

        self::assertSame('175.00', $livraison->getTotalHT(), '7 x 25,00 — le total suit la livraison');

        $facture = $this->chaine()->facturer($livraison, $this->auteur(), $this->maintenant());

        self::assertCount(1, $facture->getLignes());
        self::assertSame(7, $facture->getLignes()->first()->getQuantite());
        self::assertSame('175.00', $facture->getTotalHT());
        self::assertNotNull($facture->getNumero(), 'la facture passe par la vraie chaîne de scellement');
    }

    /** Une ligne livrée à zéro n'encombre pas la facture d'un montant nul. */
    public function testUneLigneNonLivreeNapparaitPasSurLaFacture(): void
    {
        $livraison = $this->quote([['Livré', 2, '10.00'], ['En rupture', 5, '30.00']]);
        $livraison->setNature(DocumentNature::DeliveryNote);
        $livraison->getLignes()->get(1)->setQuantiteLivree(0);
        $livraison->recalculerTotaux();
        $livraison->transitionVers(DocumentStatus::Issued, $this->maintenant())
            ->transitionVers(DocumentStatus::Accepted, $this->maintenant());
        $this->em()->flush();

        $facture = $this->chaine()->facturer($livraison, $this->auteur(), $this->maintenant());

        self::assertCount(1, $facture->getLignes());
        self::assertSame('Livré', $facture->getLignes()->first()->getDesignation());
    }

    /** Une pièce déjà facturée ne se refacture pas. */
    public function testUnePieceDejaFactureeNeSeRefacturePas(): void
    {
        $commande = $this->quote([['Cours collectif', 4, '25.00']]);
        $commande->setNature(DocumentNature::SalesOrder);
        $commande->transitionVers(DocumentStatus::Issued, $this->maintenant())
            ->transitionVers(DocumentStatus::Accepted, $this->maintenant());
        $this->em()->flush();

        $this->chaine()->facturer($commande, $this->auteur(), $this->maintenant());

        self::assertCount(1, $this->em()->getRepository(Facture::class)->findAll());

        $this->expectException(ForbiddenDocumentTransitionException::class);

        $this->chaine()->facturer($commande, $this->auteur(), $this->maintenant());
    }

    /**
     * Le destinataire est copié, pas partagé.
     *
     * Une pièce garde le destinataire tel qu'il était quand elle a été émise. Partager l'objet ferait
     * qu'un changement d'adresse aujourd'hui réécrirait le devis d'il y a six mois — et le document
     * qu'a le client ne correspondrait plus à celui qu'on a en base.
     */
    public function testLeDestinataireEstCopieEtNonPartage(): void
    {
        $quote = $this->quote([['Cours collectif', 1, '25.00']]);
        $quote->transitionVers(DocumentStatus::Issued, $this->maintenant())
            ->transitionVers(DocumentStatus::Accepted, $this->maintenant());
        $this->em()->flush();

        $commande = $this->chaine()->deriver($quote, $this->auteur(), $this->maintenant());

        self::assertNotSame($quote->getDestinataire(), $commande->getDestinataire());
        self::assertSame($quote->getDestinataire()?->getRaisonSociale(), $commande->getDestinataire()?->getRaisonSociale());

        // On modifie le destinataire de la commande : le devis ne bouge pas.
        $commande->getDestinataire()?->setRaisonSociale('Nouvelle raison sociale');
        $this->em()->flush();

        self::assertSame('Club de Padel', $quote->getDestinataire()?->getRaisonSociale());
    }

    // ---------------------------------------------------------------- montage

    private function maintenant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-15 10:00:00');
    }

    private function chaine(): DocumentChain
    {
        /** @var EmettreFactureDirecteHandler $emetteur */
        $emetteur = static::getContainer()->get(EmettreFactureDirecteHandler::class);

        return new DocumentChain($this->em(), $emetteur);
    }

    private function auteur(): Utilisateur
    {
        $auteur = $this->em()->getRepository(Utilisateur::class)->find($this->idAdmin());
        \assert($auteur instanceof Utilisateur);

        return $auteur;
    }

    /**
     * Un devis en draft, avec ses lignes.
     *
     * @param list<array{0: string, 1: int, 2: string}> $lignes désignation, quantité, prix HT
     */
    private function quote(array $lignes): CommercialDocument
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($etablissement instanceof Etablissement);

        $taux = $this->em()->getRepository(TauxTva::class)->find($this->idTauxTva('Taux normal 20 %'));
        \assert($taux instanceof TauxTva);

        $destinataire = (new DestinataireFacturation())
            ->setType(TypeDestinataire::PersonneMorale)
            ->setRaisonSociale('Club de Padel')
            // ⚠ SIRET et adresse : une facture B2B sans eux n'est pas conforme (RG-FACT-08), sans
            // seuil ni exception. Le test modelisait un document qui n'aurait jamais ete legal.
            ->setSiret('12345678900011')
            ->setAdresse(['rue' => '4 allee du Padel', 'cp' => '75015', 'ville' => 'Paris', 'pays' => 'FR']);
        $this->em()->persist($destinataire);

        $quote = (new CommercialDocument())
            ->setNature(DocumentNature::Quote)
            ->setEtablissement($etablissement)
            ->setProfilExploitant($this->profilExploitant())
            ->setDestinataire($destinataire)
            ->setCreePar($this->auteur());

        foreach ($lignes as [$designation, $quantite, $prix]) {
            $quote->addLigne(
                (new DocumentLine())
                    ->setDesignation($designation)
                    ->setQuantite($quantite)
                    ->setPrixUnitaireHT($prix)
                    ->setTauxTva($taux),
            );
        }

        $quote->recalculerTotaux();

        $this->em()->persist($quote);
        $this->em()->flush();

        return $quote;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
