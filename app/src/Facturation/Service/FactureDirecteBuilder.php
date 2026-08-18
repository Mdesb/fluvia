<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Enum\TypeDestinataire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Composition d'un brouillon de facture directe depuis un corps JSON libre (destinataire + lignes) —
 * utilisé par `CreerFactureDirecteProcessor` (création) et `ModifierFactureDirecteProcessor`
 * (modification, tant que brouillon, RG-FACT-04). Ne flush pas : l'appelant maîtrise la transaction.
 */
final class FactureDirecteBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param array<string, mixed> $donnees */
    public function appliquerDestinataire(Facture $facture, array $donnees): void
    {
        $destinataire = $facture->getDestinataire() ?? new DestinataireFacturation();

        $type = \is_string($donnees['type'] ?? null) ? TypeDestinataire::tryFrom($donnees['type']) : null;
        if ($type !== null) {
            $destinataire->setType($type);
        }
        if (\array_key_exists('nom', $donnees)) {
            $destinataire->setNom(\is_string($donnees['nom']) ? $donnees['nom'] : null);
        }
        if (\array_key_exists('prenom', $donnees)) {
            $destinataire->setPrenom(\is_string($donnees['prenom']) ? $donnees['prenom'] : null);
        }
        if (\array_key_exists('raisonSociale', $donnees)) {
            $destinataire->setRaisonSociale(\is_string($donnees['raisonSociale']) ? $donnees['raisonSociale'] : null);
        }
        if (\array_key_exists('siret', $donnees)) {
            $destinataire->setSiret(\is_string($donnees['siret']) ? $donnees['siret'] : null);
        }
        if (\array_key_exists('tvaIntracommunautaire', $donnees)) {
            $destinataire->setTvaIntracommunautaire(\is_string($donnees['tvaIntracommunautaire']) ? $donnees['tvaIntracommunautaire'] : null);
        }
        if (isset($donnees['adresse']) && \is_array($donnees['adresse'])) {
            $destinataire->setAdresse($donnees['adresse']);
        }
        if (isset($donnees['clientRef']) && \is_string($donnees['clientRef']) && Uuid::isValid($donnees['clientRef'])) {
            $destinataire->setClientRef(Uuid::fromString($donnees['clientRef']));
        }
        if (\array_key_exists('estOrganismePublic', $donnees)) {
            $destinataire->setEstOrganismePublic($donnees['estOrganismePublic'] === true);
        }

        $facture->setDestinataire($destinataire);
    }

    /** @param array<string, mixed> $donnees */
    public function appliquerLignes(Facture $facture, array $donnees): void
    {
        if (!isset($donnees['lignes']) || !\is_array($donnees['lignes'])) {
            return;
        }

        foreach ($facture->getLignes()->toArray() as $ligneExistante) {
            $facture->removeLigne($ligneExistante);
            $this->em->remove($ligneExistante);
        }

        foreach ($donnees['lignes'] as $donneesLigne) {
            if (!\is_array($donneesLigne)) {
                continue;
            }
            $ligne = new LigneFacture();
            $ligne->setDesignation(\is_string($donneesLigne['designation'] ?? null) ? $donneesLigne['designation'] : '');
            $ligne->setQuantite(\is_int($donneesLigne['quantite'] ?? null) ? $donneesLigne['quantite'] : (int) ($donneesLigne['quantite'] ?? 1));
            $ligne->setPrixUnitaireHT((string) ($donneesLigne['prixUnitaireHT'] ?? '0.00'));
            $ligne->setTauxTva($this->resoudreTauxTva($donneesLigne['tauxTva'] ?? null, $facture->getProfilExploitant()));

            if (isset($donneesLigne['categorieComptable']) && \is_string($donneesLigne['categorieComptable']) && Uuid::isValid($donneesLigne['categorieComptable'])) {
                $ligne->setCategorieComptable(Uuid::fromString($donneesLigne['categorieComptable']));
            }

            $ligne->recalculer();
            $facture->addLigne($ligne);
        }

        $facture->recalculerTotaux();
    }

    private function resoudreTauxTva(mixed $reference, ?ProfilExploitant $profil): TauxTva
    {
        $uuid = $this->uuidDepuis($reference);
        $taux = $uuid !== null ? $this->em->getRepository(TauxTva::class)->find($uuid) : null;
        if (!$taux instanceof TauxTva) {
            throw new UnprocessableEntityHttpException('Taux de TVA obligatoire et valide pour chaque ligne (RG-M6-05).');
        }
        // Cloisonnement (RG-SOCLE-05) : un taux de TVA d'un autre exploitant ne peut pas être utilisé
        // sur cette facture.
        if ($profil !== null && $taux->getProfilExploitant()?->getId()?->equals($profil->getId()) !== true) {
            throw new UnprocessableEntityHttpException('Ce taux de TVA n\'appartient pas à l\'exploitant de cette facture.');
        }

        return $taux;
    }

    private function uuidDepuis(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
