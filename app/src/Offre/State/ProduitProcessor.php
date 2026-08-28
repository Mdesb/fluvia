<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\Produit;
use App\Securite\Service\ContexteEtablissement;
use App\Offre\Service\GenerateurCodeProduit;
use App\Offre\Service\ResolveurFacettes;
use App\Offre\Service\DefaultCategoryResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Traitement d'écriture d'un Produit (POST/PATCH) : génère un code unique si absent, met à jour
 * la projection de recherche (CA-1), purge les saisies orphelines selon le type (CA-3, RG-M1-02)
 * et rafraîchit la date de modification, puis délègue au persist processor Doctrine standard.
 *
 * @implements ProcessorInterface<Produit, Produit>
 */
final class ProduitProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Produit, Produit> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly ResolveurFacettes $facettes,
        private readonly DefaultCategoryResolver $categoriesParDefaut,
        private readonly GenerateurCodeProduit $generateurCode,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Produit) {
            if ($data->getCode() === '') {
                $data->setCode($this->generateurCode->generer());
            }
            $data->setLibelleRecherche($this->projeterLibelle($data));

            // ACT-5 : les categories par defaut du TYPE, sur les axes encore vides.
            //
            // L'axe comptable est OBLIGATOIRE POUR PUBLIER (RG-M1-05). Sans ce remplissage, un produit
            // cree sans lui reste bloque en brouillon, et l'exploitant qui ne connait pas la regle
            // cherche pourquoi son produit ne se vend pas.
            //
            // Un defaut n'est pas une regle : les axes deja renseignes ne sont jamais ecrases.
            $this->categoriesParDefaut->appliquer($data);
            $this->facettes->purgerOrphelins($data);
            $this->rattacherALEtablissementActif($data, $operation);
            $data->toucherModifieLe();
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * A LA CREATION, UN PRODUIT SANS ETABLISSEMENT DEVIENT LE PRODUIT DE L'ETABLISSEMENT ACTIF.
     *
     * `PerimetreProduitExtension` traite « aucun etablissement » comme SOCLE : visible depuis tous
     * les sites (D51, « socle + ajout local »). C'est voulu pour un catalogue fourni par l'editeur.
     * Ce ne l'est pas pour un produit qu'un exploitant vient de creer chez lui.
     *
     * L'ecran envoie deja l'etablissement actif ; ce rattachement ne le remplace pas, il le garantit.
     * Le cloisonnement ne doit pas dependre de ce que le client veut bien transmettre : un appel qui
     * omet le champ -- un import, un script, l'ecran avant que l'etablissement actif ne soit charge --
     * publierait le produit chez tous les clients. Et rien ne le signalerait : la creation reussit,
     * le produit apparait, il apparait seulement AUSSI ailleurs. Une fuite de cloisonnement ne produit
     * pas d'erreur, elle produit des lignes en trop.
     *
     * A LA CREATION SEULEMENT. Retirer tous les etablissements d'un produit existant reste possible
     * et reste un geste explicite -- c'est ainsi qu'on promeut un produit au socle.
     */
    private function rattacherALEtablissementActif(Produit $produit, Operation $operation): void
    {
        if (!$operation instanceof Post || !$produit->getEtablissements()->isEmpty()) {
            return;
        }

        $actif = $this->contexte->etablissementActif();
        if ($actif !== null) {
            $produit->addEtablissement($actif);
        }
    }

    private function projeterLibelle(Produit $produit): ?string
    {
        $valeurs = array_values($produit->getLibelle());
        if ($valeurs === []) {
            return null;
        }

        return mb_substr(implode(' ', array_map('strval', $valeurs)), 0, 512);
    }
}
