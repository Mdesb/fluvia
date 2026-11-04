<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Organisation\Entity\Etablissement;
use App\Platform\Security\EstablishmentScopeAsserter;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
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
        private readonly EntityManagerInterface $em,
        private readonly EstablishmentScopeAsserter $scope,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Produit) {
            if ($data->getCode() === '') {
                $data->setCode($this->generateurCode->generer());
            }
            $this->preparer($data, $operation);
            $this->refuserSitesHorsDePortee($data);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * ON NE RATTACHE PAS UN PRODUIT À UN SITE QU'ON N'ATTEINT PAS — ET ON NE L'EN RETIRE PAS NON PLUS.
     *
     * `EstablishmentScopeWriteGuard` ne regarde que `getEtablissement()`, au singulier : la collection
     * `etablissements` lui échappe. Depuis le 04/11/2026, la lecture rend TOUS les sites du produit
     * (`PerimetreProduitExtension`) : un appel peut donc renvoyer la liste sans un site qu'il ne gère
     * pas, et le retirer de la vente là-bas. Retirer un produit d'un site reste un geste de ce site.
     *
     * Seuls les sites qui CHANGENT sont contrôlés : un produit partagé avec un site qu'on n'atteint pas
     * reste modifiable pour tout le reste. 404, pas 403 (D3) : un 403 confirmerait que le site existe.
     */
    private function refuserSitesHorsDePortee(Produit $produit): void
    {
        $sites = $produit->getEtablissements();
        $avant = $sites instanceof PersistentCollection ? $sites->getSnapshot() : [];
        $apres = $sites->toArray();

        $changes = array_merge(
            array_udiff($apres, $avant, static fn (Etablissement $a, Etablissement $b): int => strcmp((string) $a->getId(), (string) $b->getId())),
            array_udiff($avant, $apres, static fn (Etablissement $a, Etablissement $b): int => strcmp((string) $a->getId(), (string) $b->getId())),
        );

        foreach ($changes as $site) {
            $this->scope->assertReachable($site);
        }
    }

    /**
     * REFUSE une saisie qui contredit le type — et ne detruit JAMAIS ce qui existait deja.
     *
     * ⚠ CETTE METHODE REMPLACE UN APPEL A `purgerOrphelins()` QUI DETRUISAIT EN SILENCE.
     *
     * Jusqu'au 30/08, chaque enregistrement purgeait : modifier la **couleur de caisse** d'un produit
     * suffisait a lui faire perdre son stock. Reponse 200, aucun message, aucune trace — et a l'ecran,
     * la cause et l'effet n'ont aucun rapport. Deux produits de la preproduction etaient dans ce cas.
     *
     * ── POURQUOI REFUSER EST DESORMAIS LE BON CHOIX ────────────────────────────────────────────
     *
     * Il ne l'etait pas tant qu'on ignorait ou vivait une jauge : refuser un stock sur une entree
     * aurait rendu « Place limitee, 200 places » inexprimable. Maxime a tranche — **la capacite vit
     * sur l'événement**, parce que plusieurs produits (plein, reduit, scolaire) doivent decompter le
     * MEME compteur : sinon vendre 150 pleins et 60 reduits met 210 personnes dans une salle de 200.
     *
     * Un stock sur une entree unitaire est donc une erreur de modele, pas un besoin a accueillir.
     * On peut le refuser sans rien rendre impossible.
     *
     * ── ET ON NE REFUSE QUE CE QUI VIENT D'ETRE ECRIT ──────────────────────────────────────────
     *
     * Une donnee contradictoire deja en base est laissee en place. La refuser rendrait le produit
     * inmodifiable — on ne pourrait plus corriger son libelle — jusqu'a ce que quelqu'un repare la
     * donnee par un autre chemin. Ce serait pire que le defaut d'origine : le silence detruisait une
     * donnee, ce refus-la bloquerait un produit.
     *
     * L'instantane Doctrine (`getOriginalEntityData`) porte l'etat charge depuis la base, AVANT la
     * deserialisation de la requete. Il est vide a la creation, ce qui est correct : tout ce qui est
     * present vient alors d'etre ecrit.
     */
    private function refuserSaisieContradictoire(Produit $produit): void
    {
        $conflits = $this->facettes->conflits($produit);

        if ($conflits === []) {
            return;
        }

        $instantane = $this->em->getUnitOfWork()->getOriginalEntityData($produit);
        $nouveaux = [];

        foreach ($conflits as $facette => $propriete) {
            $avant = $instantane[$propriete] ?? null;

            if ($avant !== $produit->{'get'.ucfirst($propriete)}()) {
                $nouveaux[] = $facette;
            }
        }

        if ($nouveaux === []) {
            return;
        }

        $message = sprintf(
            'Le type « %s » ne gère pas : %s. Ce champ ne peut pas être renseigné sur ce produit.',
            $produit->getType()?->getLibelle() ?? '(sans type)',
            implode(', ', $nouveaux),
        );

        if (in_array(TypeProduit::FACETTE_STOCK, $nouveaux, true)) {
            $message .= ' Un stock de marchandise appartient à un produit de type boutique ; le nombre'
                .' de places d\'un événement se pose sur l\'événement, pas sur le produit.';
        }

        throw new UnprocessableEntityHttpException($message);
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
    /**
     * Ce qu'une création fait au produit avant de l'écrire : projection de recherche, catégories par
     * défaut du type, refus des saisies contradictoires, rattachement au site actif. PUBLIQUE pour
     * `ReadinessPreviewProcessor`, qui doit juger le produit tel que la création l'écrirait — et non
     * tel que l'écran l'envoie.
     */
    public function preparer(Produit $produit, Operation $operation): void
    {
        $produit->setLibelleRecherche($this->projeterLibelle($produit));

        // ACT-5 : les categories par defaut du TYPE, sur les axes encore vides.
        //
        // L'axe comptable est OBLIGATOIRE POUR PUBLIER (RG-M1-05). Sans ce remplissage, un produit
        // cree sans lui reste bloque en brouillon, et l'exploitant qui ne connait pas la regle
        // cherche pourquoi son produit ne se vend pas.
        //
        // Un defaut n'est pas une regle : les axes deja renseignes ne sont jamais ecrases.
        $this->categoriesParDefaut->appliquer($produit);
        $this->refuserSaisieContradictoire($produit);
        $this->rattacherALEtablissementActif($produit, $operation);
        $produit->toucherModifieLe();
    }

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
