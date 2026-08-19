<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Enum\StatutProduit;
use App\Offre\Service\GenerateurCodeProduit;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Duplique un produit (US-L1-11 / CA-14) : la copie reprend type, tarifs (grilles) et catégories,
 * régénère un code produit unique, naît au statut BROUILLON quel que soit l'original, et son
 * libellé est suffixé « – copie » (immédiatement modifiable).
 *
 * @implements ProcessorInterface<Produit, Produit>
 */
final class DupliquerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GenerateurCodeProduit $generateurCode,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Produit);

        $copie = new Produit();
        $copie->setType($data->getType());
        $copie->setStatut(StatutProduit::Brouillon);
        $copie->setCode($this->generateurCode->generer());
        $copie->setLibelle($this->suffixerLibelle($data->getLibelle()));
        $copie->setLibelleRecherche($data->getLibelleRecherche());
        $copie->setCanaux($data->getCanaux());
        $copie->setDureeValidite($data->getDureeValidite());
        $copie->setReglePca($data->getReglePca());
        $copie->setCompteComptable($data->getCompteComptable());
        $copie->setTauxTva($data->getTauxTva());

        foreach ($data->getEtablissements() as $etablissement) {
            $copie->addEtablissement($etablissement);
        }
        foreach ($data->getCategories() as $categorie) {
            $copie->addCategorie($categorie);
        }

        $this->em->persist($copie);

        // Reprise des tarifs (grilles) sur la copie.
        foreach ($data->getGrilles() as $grille) {
            $nouvelle = (new GrilleTarifaire())
                ->setProduit($copie)
                ->setTypeTarif($grille->getTypeTarif())
                ->setSaison($grille->getSaison())
                ->setTrancheQf($grille->getTrancheQf())
                ->setPrix($grille->getPrix());
            $copie->addGrille($nouvelle);
            $this->em->persist($nouvelle);
        }

        $this->em->flush();

        return $copie;
    }

    /**
     * @param array<string, string> $libelle
     *
     * @return array<string, string>
     */
    private function suffixerLibelle(array $libelle): array
    {
        if ($libelle === []) {
            return ['fr' => '– copie'];
        }

        return array_map(static fn (string $valeur): string => $valeur . ' – copie', $libelle);
    }
}
