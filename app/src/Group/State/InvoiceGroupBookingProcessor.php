<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\TauxTva;
use App\Crm\Entity\Client;
use App\Facturation\Entity\CommercialDocument;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\DocumentLine;
use App\Facturation\Enum\DocumentNature;
use App\Facturation\Enum\TypeDestinataire;
use App\Facturation\Service\ResolveurComptesFacturation;
use App\Group\Entity\GroupBooking;
use App\Group\Entity\GroupBookingItem;
use App\Group\Entity\GroupGratuite;
use App\Group\Enum\GroupBookingStatus;
use App\Group\Enum\GroupPaymentStatus;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Facture une réservation de groupe (POST /group/bookings/{id}/invoice) : génère un **devis**
 * (`DocumentNature::Quote`) pour le payeur, qui entre ensuite dans la chaîne existante
 * devis → bon de commande → facture NF525 (arbitrage Maxime, 08/09). On ne forge jamais le scellé.
 *
 * Le montant part du **tarif de référence de l'activité × effectif** (option 1 de Maxime) ; un
 * `prixUnitaireHT` du corps le remplace pour un cas négocié. La grille tarifaire GROUPE par catégorie
 * (option 2) viendra brancher son propre calcul ici sans changer ce point d'entrée.
 *
 * Corps : { "tauxTva": iri|uuid (obligatoire), "prixUnitaireHT"?: string, "designation"?: string }.
 * Le payeur est `payer`, à défaut le `client` du groupe. Le taux de TVA est confronté au profil de
 * l'établissement ACTIF (RG-SOCLE-05), comme le fait `CreateDocumentProcessor`.
 *
 * @implements ProcessorInterface<GroupBooking, GroupBooking>
 */
final class InvoiceGroupBookingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly ResolveurComptesFacturation $comptes,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupBooking
    {
        \assert($data instanceof GroupBooking);

        if ($data->getStatus() === GroupBookingStatus::Cancelled) {
            throw new UnprocessableEntityHttpException('Une réservation annulée ne se facture pas.');
        }
        if ($data->getCommercialDocument() !== null) {
            throw new UnprocessableEntityHttpException('Cette réservation a déjà un devis rattaché.');
        }

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $payeur = $data->getPayer() ?? $data->getGroup()?->getClient();
        if (!$payeur instanceof Client) {
            throw new UnprocessableEntityHttpException(
                'Aucun payeur : renseignez le payeur de la réservation ou le client du groupe avant de facturer.'
            );
        }

        $corps = $this->lecteur->corps();

        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);

        // Le panier d'abord : s'il y a des articles (forfait appliqué ou lignes à la carte), chacun
        // devient une ligne de devis, avec SON prix et SA TVA. Sinon, repli « à la tête ».
        $items = $this->em->getRepository(GroupBookingItem::class)->findBy(['booking' => $data]);

        $lignes = [];
        if ($items !== []) {
            foreach ($items as $item) {
                $lignes[] = (new DocumentLine())
                    ->setDesignation($item->getProduit()?->getLibelleRecherche() ?? 'Produit')
                    ->setQuantite(max(1, $item->getQuantite()))
                    ->setPrixUnitaireHT($item->getPrixUnitaireHT())
                    ->setTauxTva($item->getTauxTva());
            }
        } else {
            $prixUnitaire = \is_string($corps['prixUnitaireHT'] ?? null) && $corps['prixUnitaireHT'] !== ''
                ? $corps['prixUnitaireHT']
                : $data->getActivite()?->getTarifReferenceMontant();
            if ($prixUnitaire === null || $prixUnitaire === '') {
                throw new UnprocessableEntityHttpException(
                    'Aucun montant : composez un panier (forfait ou produits), affectez une activité tarifée, ou fournissez « prixUnitaireHT ».'
                );
            }
            // Les gratuités accordées sortent du décompte payant (nbPayants = effectif − gratuités),
            // comme le fait le musée. Interaction avec le panier laissée de côté (v1) : le panier se
            // compose déjà ligne à ligne.
            $gratuites = 0;
            foreach ($this->em->getRepository(GroupGratuite::class)->findBy(['booking' => $data]) as $g) {
                $gratuites += $g->getQuantite();
            }
            $payant = $data->getEffectif() - $gratuites;
            if ($payant < 1) {
                throw new UnprocessableEntityHttpException('Toutes les entrées sont gratuites : rien à facturer.');
            }
            $designation = \is_string($corps['designation'] ?? null) && trim($corps['designation']) !== ''
                ? trim($corps['designation'])
                : $this->designationParDefaut($data);
            $lignes[] = (new DocumentLine())
                ->setDesignation($designation)
                ->setQuantite($payant)
                ->setPrixUnitaireHT((string) $prixUnitaire)
                ->setTauxTva($this->taux($corps['tauxTva'] ?? null, $etablissement));
        }

        $document = (new CommercialDocument())
            ->setNature(DocumentNature::Quote)
            ->setEtablissement($etablissement)
            ->setProfilExploitant($this->comptes->profilPour($etablissement))
            ->setDestinataire($this->destinataire($payeur))
            ->setCreePar($auteur);
        foreach ($lignes as $ligne) {
            $document->addLigne($ligne);
        }
        $document->recalculerTotaux();
        $this->em->persist($document);

        $data->setCommercialDocument($document);
        if ($data->getPaymentStatus() === GroupPaymentStatus::Pending) {
            $data->setPaymentStatus(GroupPaymentStatus::PurchaseOrder);
        }

        $this->em->flush();

        return $data;
    }

    private function designationParDefaut(GroupBooking $booking): string
    {
        $libelle = $booking->getGroup()?->getLabel() ?? 'groupe';
        $activite = $booking->getActivite()?->getLibelle();

        return $activite !== null && $activite !== ''
            ? sprintf('Groupe « %s » — %s', $libelle, $activite)
            : sprintf('Groupe « %s »', $libelle);
    }

    private function destinataire(Client $payeur): DestinataireFacturation
    {
        $raisonSociale = $payeur->getRaisonSociale();
        $morale = \is_string($raisonSociale) && trim($raisonSociale) !== '';

        $destinataire = (new DestinataireFacturation())
            ->setType($morale ? TypeDestinataire::PersonneMorale : TypeDestinataire::Particulier)
            ->setRaisonSociale($morale ? trim($raisonSociale) : null)
            ->setNom($payeur->getNom())
            ->setPrenom($payeur->getPrenom())
            ->setSiret($payeur->getSiret());

        $adresse = $payeur->getAdresse();
        if (\is_array($adresse) && $adresse !== []) {
            $destinataire->setAdresse($adresse);
        }

        $this->em->persist($destinataire);

        return $destinataire;
    }

    private function taux(mixed $reference, Etablissement $etablissement): TauxTva
    {
        $segment = \is_string($reference) && str_contains($reference, '/') ? basename($reference) : $reference;
        if (!\is_string($segment) || !Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException('Un devis porte un taux de TVA : fournissez « tauxTva » (RG-M6-05).');
        }

        $taux = $this->em->getRepository(TauxTva::class)->find(Uuid::fromString($segment));
        if (!$taux instanceof TauxTva) {
            throw new UnprocessableEntityHttpException('Ce taux de TVA n\'existe pas.');
        }

        $profil = $this->comptes->profilPour($etablissement);
        if ($taux->getProfilExploitant()?->getId()?->equals($profil->getId()) !== true) {
            throw new UnprocessableEntityHttpException('Ce taux de TVA n\'appartient pas à cet exploitant.');
        }

        return $taux;
    }
}
