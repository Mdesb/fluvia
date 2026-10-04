<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Service\VitrineResolver;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Security\VitrineAccessibleGuard;
use App\Boutique\Service\DisponibiliteAffichageHandler;
use App\Boutique\Service\OnlineSellability;
use App\Offre\Entity\ProductPhoto;
use App\Offre\Entity\Produit;
use App\Offre\Enum\Canal;
use App\Offre\Enum\StatutProduit;
use App\Offre\Service\ResolveurPrix;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /boutique/vitrines/{id}/catalogue (US-L8-01, RG-M3-01/08, CA-1) : catalogue public — ne montre
 * que les produits **publiés** et **visibles au canal `en_ligne`** (RG-M1-07/09), prix/disponibilité
 * en temps réel (aucun décalage avec M1/le stock). Comble des manques boutique (tunnel public) :
 * expose désormais le **prix public** (fourchette « à partir de » calculée via `ResolveurPrix` M1, aucun
 * prix recodé) et le **visuel** du produit s'il existe (`Produit.champsPerso['visuelUrl']`, même
 * convention que `champsPerso['timedEntry']` déjà utilisée par ce module —
 * aucune facette visuel dédiée côté M1).
 *
 * (`champsPerso['ressourceId']` a longtemps voisiné avec celles-là et ne désigne plus rien : depuis
 * le 07/09, un produit trouve ses créneaux par l'activité qui le référence — voir
 * `CreneauxProduitProvider`. Le nommer ici évite qu'on le recopie comme une convention vivante.)
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class CatalogueVitrineProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VitrineResolver $resolver,
        private readonly DisponibiliteAffichageHandler $disponibilite,
        private readonly ResolveurPrix $resolveurPrix,
        private readonly OnlineSellability $vendabilite,
        private readonly VitrineAccessibleGuard $vitrineGuard,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        // Identifiant OU nom d'URL : meme resolution que `VitrinePubliqueProvider`.
        $vitrine = $this->resolver->resoudre($uriVariables['id'] ?? null);
        if (!$vitrine instanceof Vitrine) {
            throw new NotFoundHttpException('Vitrine introuvable.');
        }
        // Revue de sécurité — faille majeure : établissement inactif ou canal `en_ligne` coupé ->
        // catalogue jamais exposé publiquement (même règle que `VitrinesPubliquesProvider`).
        $this->vitrineGuard->verifier($vitrine);
        $etablissement = $vitrine->getEtablissement();

        // LA JOINTURE EST EXTERNE, ET C'EST TOUT LE CORRECTIF.
        //
        // En interne, un produit SANS etablissement ne satisfait aucune ligne. Or, au 27/08, les sept
        // produits publies et vendables en ligne du jeu de demonstration -- audioguide, abonnement,
        // pass musee, expo Egypte, boutique -- avaient TOUS un etablissement nul. Les deux seuls
        // produits rattaches a un site etaient en brouillon, guichet uniquement.
        //
        // Resultat : LES DEUX BOUTIQUES EN LIGNE N'AVAIENT JAMAIS RIEN EU A VENDRE, depuis la creation
        // du jeu de demonstration. Elles affichaient << Aucun billet en vente >> -- une phrase exacte,
        // qui ne ressemblait pas a un defaut.
        //
        // C'est la meme cause que la fuite du catalogue back-office corrigee le meme jour : dans ce
        // depot, << sans etablissement >> veut dire << socle, partage par tous >> pour cette entite --
        // elle n'a ni discriminant `portee` ni colonne `etablissement`, donc ni l'un ni l'autre des
        // deux patrons de D51. Une jointure interne la traite comme n'appartenant a personne.
        //
        // Regle retenue par Maxime le 27/08 : etablissement actif PLUS socle.
        $produits = $this->em->getRepository(Produit::class)->createQueryBuilder('p')
            ->leftJoin('p.etablissements', 'e')
            ->andWhere('(e.id = :etablissement OR SIZE(p.etablissements) = 0)')
            ->andWhere('p.statut = :publie')
            // Type `uuid` explicite : sur un identifiant a type personnalise, une comparaison sans type
            // ne compte rien et NE LEVE PAS (D58). Ici elle ne rendrait pas << moins >> mais le socle
            // seul, ce qui ressemble a un catalogue mal configure bien plus qu'a un bug.
            ->setParameter('etablissement', $etablissement?->getId(), 'uuid')
            ->setParameter('publie', StatutProduit::Publie->value)
            ->distinct()
            ->getQuery()
            ->getResult();

        $catalogue = [];
        foreach ($produits as $produit) {
            \assert($produit instanceof Produit);
            if (!$produit->aCanal(Canal::EnLigne)) {
                continue;
            }
            // ⚠ UN PRODUIT DONT AUCUN TARIF NE SE RÉSOUT N'EST PAS VENDABLE, QUEL QUE SOIT SON STATUT.
            //
            // Constaté sur la boutique publique : le billet « Audioguide » s'ajoutait au panier sans
            // aucun prix, sous la phrase « le tarif applicable est calculé et confirmé à l'étape de
            // paiement ». Il n'y avait aucun tarif du tout — donc rien à confirmer, et un client qui
            // s'engage sans savoir combien.
            //
            // `PublicationGuard` refuse pourtant de publier un produit sans prix valide (RG-M1-09).
            // La garde est bonne ; on ne passait simplement pas par elle : `MuseeFixtures` pose
            // `StatutProduit::Publie` en dur sur l'entité. Un import ou une reprise de données
            // produiraient le même état, d'où le contrôle ICI plutôt que dans la fixture — celle-ci
            // referme le cas, celui-là referme la famille.
            //
            // Et surtout pas côté écran : un filtre dans la boutique compenserait la garde manquante
            // en la rendant invisible, et on cesserait de la chercher.
            //
            // ⚠ Un produit GRATUIT n'est pas un produit sans prix : un tarif à 0,00 se résout et
            // continue d'être servi. Seul disparaît celui dont aucun tarif ne se résout en ligne.
            $prix = $this->prixPublic($produit);
            if ($prix === null) {
                continue;
            }

            $catalogue[] = [
                'produit' => (string) $produit->getId(),
                'code' => $produit->getCode(),
                'libelle' => $produit->getLibelle(),
                'timedEntry' => $this->disponibilite->estTimedEntry($produit),
                'disponibilite' => $this->disponibilite->disponibilitePourProduit($produit),
                'visuel' => $this->visuel($produit),
                'prix' => $prix,

                // ⚠ LE FAIT, PAS LA STRUCTURE. La boutique n'a pas besoin de savoir ce qu'est une
                // formule ni une facette SEPA : elle a besoin de savoir si ce produit **se
                // souscrit**. `SouscriptionAbonnementEnLigneHandler` refuse tout produit dont la
                // formule ne porte pas la facette — sans ce champ, un bouton « s'abonner »
                // apparaîtrait sur tout et refuserait au clic, ce qui fait chercher une panne là
                // où il n'y a qu'un produit qui ne s'abonne pas.
                'abonnement' => $produit->getFormule()?->isSepaActif() === true,

                // L'écran ne pose la case d'autorisation parentale que pour un produit qui l'exige
                // (#101) ; le serveur reste l'autorité (refus 422 au paiement).
                'parentalConsentRequired' => $produit->isParentalConsentRequired(),
            ];
        }

        return new JsonResponse([
            'vitrine' => (string) $vitrine->getId(),
            // Rendu pour que la boutique chargee par identifiant puisse afficher -- et faire
            // partager -- son adresse propre. Sans ca, un exploitant qui ouvre sa vitrine
            // depuis le back-office copierait l'URL en UUID et la donnerait a ses clients.
            'slug' => $vitrine->getSlug(),
            // Necessaire au pied de page public : c'est par l'etablissement que se lisent les mentions
            // legales publiees. Le deduire cote client demanderait un second appel pour une donnee que
            // le serveur a deja en main.
            'etablissement' => (string) $etablissement?->getId(),
            // A QUI PAIE-T-ON ? La facade publique n'avait pas la reponse.
            //
            // L'en-tete de la boutique affichait « Billetterie » en dur, faute de nom a lire : ce
            // catalogue ne portait que des identifiants. CA-1 demande pourtant l'inverse — chaque
            // vitrine porte sa propre identite, sans marque editeur commune — et un meme mot sur
            // toutes les boutiques EST cette marque commune.
            //
            // Meme source que `VitrinesPubliquesProvider`, qui rend deja ce nom sans authentification :
            // on n'expose rien de neuf, on cesse de le retenir sur le chemin ou il sert.
            'nom' => $etablissement?->getNom(),
            'logo' => $vitrine->getLogo(),
            'couleurs' => $vitrine->getCouleurs(),
            'langues' => $vitrine->getLangues(),
            'produits' => $catalogue,
        ]);
    }

    /**
     * LE VISUEL DU PRODUIT — la photo téléversée d'abord, l'URL saisie à la main ensuite.
     *
     * Jusqu'au 28/08, la seule façon de donner une image à un produit était d'écrire une adresse
     * dans `champsPerso['visuelUrl']` — un champ JSON libre, sans validation, pointant vers une
     * image hébergée ailleurs. L'affichage existait ; c'est le téléversement qui manquait.
     *
     * Les deux chemins cohabitent, et l'ordre n'est pas neutre : une vitrine qui a déjà renseigné
     * une URL continue de fonctionner sans qu'on y touche, et le jour où quelqu'un téléverse une
     * vraie photo, c'est elle qui prend la place. Supprimer l'ancien chemin aurait vidé des
     * catalogues en production le jour du déploiement.
     */
    private function visuel(Produit $produit): ?string
    {
        $photo = $this->em->getRepository(ProductPhoto::class)->createQueryBuilder('ph')
            // ⚠ D58 — l'entité liée sans son type ne trouverait rien, et toutes les boutiques
            // afficheraient un catalogue sans images sans qu'aucune erreur ne le dise.
            ->andWhere('IDENTITY(ph.produit) = :produit')
            ->setParameter('produit', $produit->getId(), 'uuid')
            ->orderBy('ph.position', 'ASC')
            ->addOrderBy('ph.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($photo instanceof ProductPhoto) {
            return $photo->getUrl();
        }

        $ancienne = $produit->getChampsPerso()['visuelUrl'] ?? null;

        return \is_string($ancienne) && $ancienne !== '' ? $ancienne : null;
    }

    /**
     * Prix public résolu pour chaque type de tarif commercialisé au canal `en_ligne` aujourd'hui
     * (`App\Offre\Service\ResolveurPrix`, moteur M1 réutilisé tel quel). `min`/`max` permettent au
     * front d'afficher « à partir de X € » dès qu'au moins deux tarifs distincts sont commercialisés ;
     * `null` si le produit publié n'a en réalité aucun prix résolu en ligne (anomalie de données,
     * ne doit normalement pas se produire pour un produit publié — RG-M1-09).
     *
     * @return array{min: string, max: string}|null
     */
    private function prixPublic(Produit $produit): ?array
    {
        // ⚠ LA REGLE VIT DANS `OnlineSellability`, PAS ICI. Elle etait ecrite a cet endroit, et
        // le processeur d'ajout au panier ne la consultait pas : un produit cache en vitrine
        // restait ajoutable par son identifiant. La recopier la-bas aurait donne deux
        // implementations qui divergent — la boutique afficherait un prix que le panier refuse,
        // ou l'inverse, qui est pire.
        $prix = $this->vendabilite->resolvedPrices($produit);
        if ($prix === []) {
            return null;
        }

        return [
            'min' => number_format(min($prix), 2, '.', ''),
            'max' => number_format(max($prix), 2, '.', ''),
        ];
    }
}
