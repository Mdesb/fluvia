<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\CommercialDocument;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\DocumentLine;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Enum\DocumentNature;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Enum\OrigineFacture;
use App\Facturation\Enum\DocumentStatus;
use App\Facturation\Exception\ForbiddenDocumentTransitionException;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La chaîne devis → bon de commande → bon de livraison → facture (FAC-1).
 *
 * **Chaque étape reprend la précédente sans la ressaisir.** C'est toute la valeur du lot : un club
 * qui vend sans caisse propose, fait confirmer, livre, puis facture — et ressaisir les mêmes lignes
 * quatre fois est à la fois du temps perdu et la garantie qu'elles finiront par différer. Un client
 * qui reçoit une facture ne correspondant pas à son devis appelle, et il a raison.
 *
 * **Chaque étape peut être la dernière.** Un devis refusé n'appelle rien ; une commande peut se
 * facturer sans bon de livraison quand il n'y a rien à livrer. La chaîne est un chemin possible, pas
 * un parcours obligatoire — c'est pourquoi `facturer()` accepted n'importe quelle nature.
 *
 * **La filiation est écrite, jamais déduite.** Chaque pièce garde son origine et chaque ligne la
 * sienne. Reconstituer la chaîne par les montants ou les dates marcherait jusqu'au jour où deux devis
 * identiques partent le même matin.
 */
final class DocumentChain
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmettreFactureDirecteHandler $emetteur,
    ) {
    }

    /**
     * Produit la pièce suivante depuis celle-ci, en reprenant ses lignes.
     *
     * @throws ForbiddenDocumentTransitionException si la pièce n'est pas acceptée, ou si elle est
     *                                              déjà la dernière de la chaîne
     */
    public function deriver(CommercialDocument $source, Utilisateur $auteur, \DateTimeImmutable $quand): CommercialDocument
    {
        if (!$source->getStatut()->peutEtreConvertie()) {
            throw new ForbiddenDocumentTransitionException(sprintf(
                'Seule une pièce acceptée produit la suivante. Ce %s est « %s ».',
                $source->getNature()->libelle(),
                $source->getStatut()->value,
            ));
        }

        $suivante = $source->getNature()->suivante();
        if (null === $suivante) {
            throw new ForbiddenDocumentTransitionException(sprintf(
                'Un %s est la dernière pièce avant la facture : il n\'y a rien à en dériver.',
                $source->getNature()->libelle(),
            ));
        }

        $cible = (new CommercialDocument())
            ->setNature($suivante)
            ->setEtablissement($source->getEtablissement())
            ->setProfilExploitant($source->getProfilExploitant())
            ->setDestinataire($this->copierDestinataire($source->getDestinataire()))
            ->setDocumentOrigine($source)
            ->setCreePar($auteur);

        foreach ($source->getLignes() as $ligne) {
            $cible->addLigne($this->copierLigne($ligne));
        }

        $cible->recalculerTotaux();

        $this->em->persist($cible);

        // La source est convertie : elle a produit sa suite, et ne peut plus en produire une seconde.
        // Sans cela, deux clics fabriquent deux commandes pour un devis — ce qui se découvre à la
        // livraison, quand le client reçoit tout en double.
        $source->transitionVers(DocumentStatus::Converted, $quand);

        $this->em->flush();

        return $cible;
    }

    /**
     * Facture cette pièce, quelle que soit sa place dans la chaîne.
     *
     * **Une pièce déjà facturée ne se refacture pas.** Le lien est porté par `factureId`, posé au
     * moment de l'émission : c'est le même raisonnement que le registre des factures d'abonnement,
     * et pour la même raison — le second clic ne doit pas produire un second prélèvement.
     *
     * @throws ForbiddenDocumentTransitionException si la pièce n'est pas acceptée, ou déjà facturée
     */
    public function facturer(CommercialDocument $source, Utilisateur $auteur, \DateTimeImmutable $quand): Facture
    {
        if (null !== $source->getFactureId()) {
            throw new ForbiddenDocumentTransitionException(sprintf(
                'Ce %s a déjà été facturé.',
                $source->getNature()->libelle(),
            ));
        }

        if (!$source->getStatut()->peutEtreConvertie()) {
            throw new ForbiddenDocumentTransitionException(sprintf(
                'Seule une pièce acceptée se facture. Ce %s est « %s ».',
                $source->getNature()->libelle(),
                $source->getStatut()->value,
            ));
        }

        $facture = new Facture();
        $facture->setNature(NatureFacture::Facture);
        $facture->setOrigine(OrigineFacture::VenteATerme);
        $facture->setEtablissement($source->getEtablissement());
        $facture->setProfilExploitant($source->getProfilExploitant());
        $facture->setDestinataire($this->copierDestinataire($source->getDestinataire()));
        $facture->setCreePar($auteur);

        foreach ($source->getLignes() as $ligne) {
            $quantite = $this->quantiteFacturable($source, $ligne);

            // Une ligne livrée à zéro ne se facture pas : elle encombrerait la facture d'un montant
            // nul que le client devrait interpréter.
            if (0 === $quantite) {
                continue;
            }

            $ligneFacture = new LigneFacture();
            $ligneFacture->setDesignation($ligne->getDesignation())
                ->setQuantite($quantite)
                ->setPrixUnitaireHT($ligne->getPrixUnitaireHT())
                ->setTauxTva($ligne->getTauxTva());
            $ligneFacture->recalculer();

            $facture->addLigne($ligneFacture);
        }

        $facture->recalculerTotaux();

        $this->em->persist($facture);
        $this->em->flush();

        $emise = $this->emetteur->emettre($facture);

        $source->setFactureId($emise->getId())
            ->transitionVers(DocumentStatus::Converted, $quand);
        $this->em->flush();

        return $emise;
    }

    /**
     * Ce qu'on facture réellement sur cette ligne.
     *
     * **Depuis un bon de livraison, c'est la quantité LIVRÉE.** C'est la raison d'être du document :
     * une commande de dix dont sept arrivent se facture sept. Facturer la commande ferait payer ce
     * qui n'est pas arrivé, et le client s'en apercevrait avant nous.
     */
    private function quantiteFacturable(CommercialDocument $source, DocumentLine $ligne): int
    {
        return DocumentNature::DeliveryNote === $source->getNature()
            ? $ligne->getQuantiteLivree()
            : $ligne->getQuantite();
    }

    /**
     * Copie le destinataire plutôt que de le partager.
     *
     * Une pièce garde le destinataire **tel qu'il était** au moment où elle a été émise. Partager
     * l'objet ferait qu'un changement d'adresse aujourd'hui réécrirait le devis d'il y a six mois —
     * et le document qu'a le client ne correspondrait plus à celui qu'on a en base.
     */
    private function copierDestinataire(?DestinataireFacturation $source): DestinataireFacturation
    {
        $copie = new DestinataireFacturation();

        if (null === $source) {
            return $copie;
        }

        return $copie->setType($source->getType())
            ->setNom($source->getNom())
            ->setPrenom($source->getPrenom())
            ->setRaisonSociale($source->getRaisonSociale())
            ->setSiret($source->getSiret())
            ->setTvaIntracommunautaire($source->getTvaIntracommunautaire())
            ->setAdresse($source->getAdresse())
            ->setClientRef($source->getClientRef())
            ->setEstOrganismePublic($source->isEstOrganismePublic());
    }

    private function copierLigne(DocumentLine $source): DocumentLine
    {
        return (new DocumentLine())
            ->setDesignation($source->getDesignation())
            ->setQuantite($source->getQuantite())
            ->setPrixUnitaireHT($source->getPrixUnitaireHT())
            ->setTauxTva($source->getTauxTva())
            ->setLigneOrigine($source->getId());
    }
}
