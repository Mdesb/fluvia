<?php

declare(strict_types=1);

namespace App\Website\Service;

use App\Fonctionnalite\Enum\EstablishmentActivity;
use App\Fonctionnalite\Enum\Metier;
use App\Website\Entity\Trade;
use App\Website\Entity\TradeActivity;
use App\Website\Enum\PublicationStatus;
use App\Website\Exception\BuiltInTradeIsProtectedException;
use App\Website\Exception\PublishedSlugIsFrozenException;
use App\Website\Exception\TradeAlreadyExistsException;
use App\Website\Exception\UnknownActivityException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les règles d'écriture d'un métier du référentiel.
 *
 * ⚠ **ELLES VIVENT ICI, PAS DANS LE PROCESSEUR**, comme {@see BlogEditor} pour les articles : un
 * import, une reprise ou une commande doivent obtenir exactement les mêmes règles sans passer par
 * une requête HTTP. Une règle écrite dans un processeur n'existe que pour ceux qui passent par
 * l'écran — et ce sont rarement eux qui cassent les données.
 */
final readonly class TradeEditor
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param list<string> $activites
     *
     * @throws TradeAlreadyExistsException
     * @throws UnknownActivityException
     */
    public function creer(
        string $code,
        string $slug,
        string $nom,
        string $titre,
        string $chapo,
        int $rang,
        PublicationStatus $statut,
        array $activites,
    ): Trade {
        /*
         * ⚠ L'ADRESSE PAR DÉFAUT EST LE CODE, PAS UN SLUG DÉRIVÉ DU NOM. C'est ce que portent les
         *   cinq lignes d'aujourd'hui, et ça garde `/metiers/<code>` lisible. Dériver du nom
         *   donnerait « piscines-et-centres-aquatiques » là où le code dit « piscine ».
         */
        $slug = '' === $slug ? $code : $slug;

        /*
         * ⚠ LE DOUBLON SE REFUSE ICI, PAS PAR UNE ERREUR DE BASE. Les deux colonnes portent bien une
         *   contrainte unique — c'est elle qui fait foi — mais quelqu'un qui recrée un code existant
         *   doit lire POURQUOI c'est refusé, pas recevoir un 500 sans explication.
         */
        $this->refuserLeDoublon('code', $code);
        $this->refuserLeDoublon('slug', $slug);

        $ligne = (new Trade())
            ->setCode($code)
            ->setSlug($slug);

        $this->ecrireLesTextes($ligne, $nom, $titre, $chapo, $rang, $statut);
        $this->remplacerLesActivites($ligne, $activites);

        $this->em->persist($ligne);
        $this->em->flush();

        return $ligne;
    }

    /**
     * @param list<string> $activites
     *
     * @throws PublishedSlugIsFrozenException
     * @throws UnknownActivityException
     */
    public function mettreAJour(
        Trade $ligne,
        string $slug,
        string $nom,
        string $titre,
        string $chapo,
        int $rang,
        PublicationStatus $statut,
        array $activites,
    ): Trade {
        /*
         * ⚠ LE CODE NE SE MODIFIE PAS, ET SON REFUS EST SILENCIEUX PAR CONSTRUCTION : cette méthode
         *   ne le reçoit tout simplement pas. Un paramètre qu'on n'accepte pas ne peut pas être
         *   oublié dans une branche.
         *
         *   Le changer détacherait la page de son propre texte — la clé du bloc est
         *   `metier.<code>.body`. Le texte resterait en base, la page s'afficherait vide, et
         *   personne ne ferait le lien.
         */
        if ('' !== $slug && $slug !== $ligne->getSlug() && PublicationStatus::Published === $ligne->getStatus()) {
            throw new PublishedSlugIsFrozenException(sprintf(
                'L’adresse « %s » est publiée : elle ne peut plus changer. Les liens déjà partagés et les '
                .'pages déjà indexées pointeraient dans le vide, et rien ici ne le signalerait.',
                $ligne->getSlug(),
            ));
        }

        if ('' !== $slug && $slug !== $ligne->getSlug()) {
            $this->refuserLeDoublon('slug', $slug);
            $ligne->setSlug($slug);
        }

        $this->ecrireLesTextes($ligne, $nom, $titre, $chapo, $rang, $statut);
        $this->remplacerLesActivites($ligne, $activites);

        $this->em->flush();

        return $ligne;
    }

    /**
     * ⚠ **LES CINQ MÉTIERS QUE L'APPLICATION CONNAÎT NE SE SUPPRIMENT PAS.**
     *
     * Leur code a un cas dans `Metier` et un préréglage dans `PresetVerticale` : le produit continue
     * de les vendre, un établissement de ce métier continue de s'ouvrir avec ses modules. Supprimer
     * la ligne ne retirerait pas le métier du produit — elle retirerait seulement sa PAGE, et
     * l'adresse indexée depuis des mois rendrait 404. Ça ne se voit pas d'ici ; ça se voit chez les
     * moteurs, des semaines plus tard.
     *
     * Le geste éditorial existe et reste ouvert : passer le métier en brouillon. Il disparaît du
     * site de la même façon, et il revient d'un clic.
     *
     * @throws BuiltInTradeIsProtectedException
     */
    public function supprimer(Trade $ligne): void
    {
        if (null !== Metier::tryFrom($ligne->getCode())) {
            throw new BuiltInTradeIsProtectedException(sprintf(
                'Le métier « %s » est porté par le produit lui-même : son préréglage vit dans le code et '
                .'un établissement de ce métier continuerait de s’ouvrir. Supprimer sa ligne ne le retirerait '
                .'pas du produit, seulement sa page — et son adresse, déjà indexée, rendrait 404. '
                .'Passez-le en brouillon : il disparaît du site, et il revient d’un clic.',
                $ligne->getCode(),
            ));
        }

        /*
         * ⚠ LE TEXTE DE LA PAGE N'EST PAS DÉTRUIT. Le bloc `metier.<code>.body` survit à la ligne :
         *   une suppression par erreur coûte alors un métier à recréer, pas une rédaction à refaire.
         *   Il redevient visible dès qu'un métier reprend ce code.
         */
        $this->em->remove($ligne);
        $this->em->flush();
    }

    /** @throws TradeAlreadyExistsException */
    private function refuserLeDoublon(string $champ, string $valeur): void
    {
        if (null === $this->em->getRepository(Trade::class)->findOneBy([$champ => $valeur])) {
            return;
        }

        throw new TradeAlreadyExistsException(sprintf(
            'Un métier porte déjà %s « %s » : %s',
            'code' === $champ ? 'le code' : 'l’adresse',
            $valeur,
            'code' === $champ
                ? 'le code compose la clé du texte de page, et les deux métiers se partageraient le même texte.'
                : 'l’adresse est ce que le visiteur tape, et une seule page peut lui répondre.',
        ));
    }

    private function ecrireLesTextes(
        Trade $ligne,
        string $nom,
        string $titre,
        string $chapo,
        int $rang,
        PublicationStatus $statut,
    ): void {
        $ligne
            ->setName($nom)
            ->setSearchTitle('' === $titre ? $nom : $titre)
            ->setLead($chapo)
            ->setPosition($rang)
            ->setStatus($statut);
    }

    /**
     * ⚠ **ON REMPLACE L'ENSEMBLE, ON NE COMPLÈTE PAS.**
     *
     * C'est l'inverse de `website:trades:seed`, et les deux ont raison : le déploiement ne doit rien
     * changer à ce qu'un humain a composé, l'humain doit pouvoir décocher. Une méthode qui ne ferait
     * qu'ajouter rendrait le décochage impossible depuis l'écran — la case se décocherait, la
     * requête partirait, et l'activité serait toujours là au rechargement, sans erreur.
     *
     * @param list<string> $activites
     *
     * @throws UnknownActivityException
     */
    private function remplacerLesActivites(Trade $ligne, array $activites): void
    {
        $voulues = [];

        foreach ($activites as $brut) {
            $activite = EstablishmentActivity::tryFrom($brut);

            if (null === $activite) {
                throw new UnknownActivityException($brut);
            }

            // Une activité citée deux fois n'est pas une erreur de saisie qui mérite un refus : la
            // contrainte d'unicité de la table, elle, refuserait — avec un 500.
            $voulues[$activite->value] = $activite;
        }

        $aRetirer = [];

        foreach ($ligne->getActivities() as $existante) {
            if (isset($voulues[$existante->getActivity()->value])) {
                // Déjà là : on la garde telle quelle plutôt que de la détruire et la recréer, ce qui
                // ferait tourner les identifiants à chaque enregistrement sans rien changer.
                unset($voulues[$existante->getActivity()->value]);

                continue;
            }

            $aRetirer[] = $existante;
        }

        // ⚠ On collecte avant de retirer : muter une collection pendant qu'on la parcourt en saute.
        foreach ($aRetirer as $existante) {
            $ligne->removeActivity($existante);
        }

        $rang = $ligne->getActivities()->count() * 10;

        foreach ($voulues as $activite) {
            $rang += 10;
            $ligne->addActivity((new TradeActivity())->setActivity($activite)->setPosition($rang));
        }
    }
}
