<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Enum\StatutFacture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * FACTURES D'ACOMPTE — la somme perçue d'avance, et sa déduction du solde.
 *
 * Demandé par Maxime : « On n'a pas encore parlé des factures d'acompte mais il faut le mettre en
 * place. » Rien n'existait dans le dépôt.
 *
 * ── LE DANGER DE CETTE FONCTIONNALITÉ TIENT EN UNE PHRASE ───────────────────────────────────────
 *
 * **Un acompte non déduit facture le client deux fois.** Une fois à l'acompte, une fois au solde.
 * C'est pour cela que la déduction n'est pas une option de l'écran mais un effet de l'émission :
 * elle n'a pas à être demandée, et surtout elle ne doit pas pouvoir être oubliée.
 *
 * ── LA VENTILATION DE TVA SE FAIT AU PRORATA, PAR GROUPE ────────────────────────────────────────
 *
 * Une commande peut porter plusieurs taux. Un acompte à taux unique serait faux dès qu'il y en a
 * deux : la TVA collectée d'avance ne correspondrait pas à ce qui sera livré.
 *
 * L'acompte reprend donc les groupes (catégorie comptable, taux) du solde, chacun au prorata. Le
 * reste de l'arrondi va au plus gros groupe — même technique que la ventilation des encaissements,
 * et pour la même raison : la somme des parts doit égaler le montant demandé, exactement.
 *
 * ── LA DÉDUCTION EST LA NÉGATION EXACTE DES LIGNES DE L'ACOMPTE ─────────────────────────────────
 *
 * Pas un pourcentage recalculé, pas un montant global : **ligne à ligne, à l'identique, en négatif**.
 * La catégorie comptable et le taux de TVA sont repris tels quels. Aucun arrondi n'est refait, donc
 * aucun centime ne peut apparaître ou disparaître entre l'acompte et sa reprise.
 *
 * ── SEUL UN ACOMPTE ÉMIS SE DÉDUIT ──────────────────────────────────────────────────────────────
 *
 * Un brouillon n'est pas un document : il n'a pas de numéro, le client ne l'a jamais reçu, et rien
 * ne prouve qu'il a payé. Le déduire diminuerait le solde d'une somme que personne n'a versée.
 */
final class DepositInvoiceHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Crée un acompte, en brouillon, rattaché à la facture de solde.
     *
     * Il reste à émettre par le chemin ordinaire (`POST /factures/{id}/emettre`) : c'est lui qui
     * attribue le numéro, ouvre l'écriture et scelle. Aucun second chemin d'émission n'est créé.
     */
    public function creer(Facture $solde, string $montantHT): Facture
    {
        if (!$solde->estBrouillon()) {
            // Une fois le solde émis, il est numéroté et scellé : un acompte pris après coup ne
            // pourrait plus en être déduit, et laisserait le client facturé deux fois.
            throw new ConflictHttpException('Un acompte se prend avant l’émission du solde.');
        }
        if ($solde->estAcompte()) {
            throw new UnprocessableEntityHttpException('Un acompte ne porte pas d’acompte.');
        }
        if ($solde->getLignes()->isEmpty()) {
            throw new UnprocessableEntityHttpException('Le solde n’a aucune ligne : il n’y a rien sur quoi prendre un acompte.');
        }

        $demande = $this->centimes($montantHT);
        if ($demande <= 0) {
            throw new UnprocessableEntityHttpException('Le montant de l’acompte doit être strictement positif.');
        }

        $totalSolde = $this->centimes($solde->getTotalHT());
        $dejaPris = $this->acomptesHT($solde);

        // ⚠ LE CONTRÔLE QUI PROTÈGE L'ARGENT. Sans lui, deux acomptes successifs dépasseraient le
        // montant de la commande, et la déduction rendrait le solde NÉGATIF — une facture qui doit
        // de l'argent au client, sans que personne l'ait décidé.
        if ($demande > $totalSolde - $dejaPris) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Acompte supérieur au reste à facturer : %s disponible sur %s.',
                $this->decimal($totalSolde - $dejaPris),
                $this->decimal($totalSolde),
            ));
        }

        $acompte = (new Facture())
            ->setNature(NatureFacture::Acompte)
            ->setFactureSoldee($solde)
            ->setProfilExploitant($solde->getProfilExploitant())
            ->setEtablissement($solde->getEtablissement())
            // ⚠ LE DESTINATAIRE SE COPIE, IL NE SE PARTAGE PAS.
            //
            // C'est un instantané figé, propre à chaque facture (RG-FACT-08), en relation UNIQUE :
            // le partager viole la contrainte et, pire, ferait bouger l'adresse de l'acompte le jour
            // où celle du solde change. Un document émis ne change plus, jamais.
            ->setDestinataire($this->copierDestinataire($solde->getDestinataire()))
            // `creePar` est obligatoire en base : on reprend l'auteur du solde plutôt que d'exiger
            // un paramètre de plus. C'est la même personne qui prépare la commande et son acompte,
            // et inventer un auteur serait pire que d'en reprendre un vrai.
            ->setCreePar($solde->getCreePar());

        foreach ($this->repartir($solde, $demande) as [$ligneSource, $partCentimes]) {
            if ($partCentimes === 0) {
                continue;
            }
            $acompte->addLigne(
                (new LigneFacture())
                    ->setDesignation('Acompte — ' . $ligneSource->getDesignation())
                    ->setCategorieComptable($ligneSource->getCategorieComptable())
                    ->setTauxTva($ligneSource->getTauxTva())
                    ->setQuantite(1)
                    ->setPrixUnitaireHT($this->decimal($partCentimes))
            );
        }

        $acompte->recalculerTotaux();
        $this->em->persist($acompte);
        $this->em->flush();

        return $acompte;
    }

    /**
     * Ajoute au solde les lignes de déduction des acomptes ÉMIS.
     *
     * Appelée à l'émission du solde, avant la résolution des comptes : les lignes de déduction sont
     * des lignes comme les autres et doivent recevoir leur compte produit.
     *
     * ⚠ IDEMPOTENTE. L'émission peut être retentée après un échec en aval ; déduire deux fois
     * transformerait le solde en créance sur le client.
     */
    public function deduire(Facture $solde): void
    {
        foreach ($this->acomptesEmis($solde) as $acompte) {
            if ($this->dejaDeduit($solde, $acompte)) {
                continue;
            }

            foreach ($acompte->getLignes() as $ligne) {
                $solde->addLigne(
                    (new LigneFacture())
                        // Le numéro de l'acompte est DANS le libellé : c'est ce que le client
                        // cherchera pour rapprocher sa facture de ce qu'il a déjà payé.
                        ->setDesignation(sprintf('Déduction acompte %s — %s', $acompte->getNumero() ?? '', $ligne->getDesignation()))
                        ->setCategorieComptable($ligne->getCategorieComptable())
                        ->setTauxTva($ligne->getTauxTva())
                        ->setQuantite(1)
                        // La négation EXACTE : aucun arrondi n'est refait, donc aucun centime ne
                        // peut apparaître ou disparaître entre l'acompte et sa reprise.
                        ->setPrixUnitaireHT($this->decimal(-$this->centimes($ligne->getMontantHT())))
                );
            }
        }

        $solde->recalculerTotaux();
    }

    /** @return list<Facture> */
    private function acomptesEmis(Facture $solde): array
    {
        $emis = [];
        foreach ($this->em->getRepository(Facture::class)->findBy(['factureSoldee' => $solde->getId()]) as $acompte) {
            \assert($acompte instanceof Facture);
            if ($acompte->getStatut() !== StatutFacture::Brouillon && $acompte->getNumero() !== null) {
                $emis[] = $acompte;
            }
        }

        return $emis;
    }

    private function dejaDeduit(Facture $solde, Facture $acompte): bool
    {
        $marque = 'Déduction acompte ' . ($acompte->getNumero() ?? '');
        foreach ($solde->getLignes() as $ligne) {
            if (str_starts_with($ligne->getDesignation(), $marque)) {
                return true;
            }
        }

        return false;
    }

    private function acomptesHT(Facture $solde): int
    {
        $total = 0;
        foreach ($this->em->getRepository(Facture::class)->findBy(['factureSoldee' => $solde->getId()]) as $acompte) {
            \assert($acompte instanceof Facture);
            $total += $this->centimes($acompte->getTotalHT());
        }

        return $total;
    }

    /**
     * Répartit le montant demandé sur les lignes du solde, au prorata de leur HT.
     *
     * Le reste de l'arrondi va à la plus grosse ligne, où il se dilue le mieux. Tri stable sur la
     * désignation à montant égal : deux exécutions doivent produire le même acompte.
     *
     * @return list<array{0: LigneFacture, 1: int}>
     */
    private function repartir(Facture $solde, int $demande): array
    {
        $lignes = $solde->getLignes()->toArray();
        usort($lignes, function (LigneFacture $a, LigneFacture $b): int {
            return [$this->centimes($b->getMontantHT()), $a->getDesignation()]
                <=> [$this->centimes($a->getMontantHT()), $b->getDesignation()];
        });

        $total = 0;
        foreach ($lignes as $ligne) {
            $total += $this->centimes($ligne->getMontantHT());
        }
        if ($total <= 0) {
            throw new UnprocessableEntityHttpException('Le solde est à zéro : aucun acompte ne peut s’y rapporter.');
        }

        $parts = [];
        $reste = $demande;
        $dernier = \count($lignes) - 1;

        foreach ($lignes as $rang => $ligne) {
            // La dernière ligne prend ce qui reste : c'est ce qui garantit que la somme des parts
            // égale EXACTEMENT le montant demandé, quel que soit le comportement de l'arrondi.
            $part = $rang === $dernier
                ? $reste
                : (int) round($demande * $this->centimes($ligne->getMontantHT()) / $total);
            $reste -= $part;
            $parts[] = [$ligne, $part];
        }

        return $parts;
    }

    /**
     * Duplique l'instantané du destinataire.
     *
     * `orphanRemoval` et `OneToOne` interdisent le partage : chaque facture porte le sien, et c'est
     * ce qui garantit qu'un document émis ne bouge plus quand la fiche client évolue.
     */
    private function copierDestinataire(?DestinataireFacturation $source): DestinataireFacturation
    {
        if (!$source instanceof DestinataireFacturation) {
            throw new UnprocessableEntityHttpException('Le solde n’a pas de destinataire : l’acompte ne saurait pas à qui s’adresser.');
        }

        return (new DestinataireFacturation())
            ->setType($source->getType())
            ->setNom($source->getNom())
            ->setPrenom($source->getPrenom())
            ->setRaisonSociale($source->getRaisonSociale())
            ->setSiret($source->getSiret())
            ->setTvaIntracommunautaire($source->getTvaIntracommunautaire())
            ->setAdresse($source->getAdresse())
            ->setClientRef($source->getClientRef())
            ->setEstOrganismePublic($source->isEstOrganismePublic());
    }

    private function centimes(string $montant): int
    {
        return (int) round(((float) $montant) * 100);
    }

    private function decimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }
}
