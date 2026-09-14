<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Regime\CompteLookupService;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * LE PARAMETRAGE DE FACTURATION D'UNE STRUCTURE NEUVE.
 *
 * ── LE DEFAUT QU'IL CORRIGE ─────────────────────────────────────────────────────────────────────
 *
 * `new ParametreFacturationEtablissement` n'existait que dans les jeux d'essai. Une structure creee
 * par `StructureOnboarding` n'avait donc AUCUNE ligne de parametres — et comme
 * `ResolveurComptesFacturation::parametre()` est un lookup sec qui rend `null`, chaque lecture
 * retombait sur `?->` sans que rien ne le signale. Consequences mesurees le 14/09 sur la preprod :
 *
 *   — `InstallmentInvoicer::tauxApplicable()` refusait toute echeance dont le produit ne porte pas
 *     de taux — « aucun taux par defaut n'est regle dans Parametres > Facturation » — et c'est ce
 *     refus, juste, qui a bloque le rattrapage de cinq echeances ;
 *   — `ResolveurComptesFacturation::compteProduit()` aurait refuse la ligne suivante pour la meme
 *     raison, un cran plus loin.
 *
 * Autrement dit : la structure s'ouvrait, paraissait complete, et ne pouvait rien facturer. Le
 * symptome n'arrivait qu'a la premiere facture — souvent des semaines plus tard, et par une tache
 * planifiee de nuit.
 *
 * ── CE QU'IL POSE, ET CE QU'IL NE POSE PAS ──────────────────────────────────────────────────────
 *
 * Deux valeurs, toutes deux de la NOMENCLATURE, pas des choix de gestion — meme arbitrage que
 * `AccountingChartSeeder` pour le plan de comptes :
 *
 *   — le TAUX PAR DEFAUT, qui est le taux NORMAL du pays de la structure. Il n'est pas devine : il
 *     vient de `VatRateSeeder`, donc du referentiel legal. Si le referentiel ne connait pas le
 *     pays, on ne pose RIEN — un parametre vide refuse en nommant ce qui manque, un taux choisi au
 *     hasard part dans une facture scellee ;
 *   — le COMPTE DE PRODUIT PAR DEFAUT (706), dernier repli de `compteProduit()`.
 *
 * ⚠ ET C'EST UN DEFAUT, PAS UNE DECISION FIGEE. L'ecran Parametres > Facturation les change tous
 * les deux. Le poser ici ne remplace pas le tunnel de premiere connexion ou l'exploitant CONFIRME
 * ses taux (ONB-1) : il garantit qu'il y a quelque chose a confirmer, et que rien n'est vide en
 * attendant.
 *
 * ⚠ IL N'ECRASE JAMAIS. Repasser dessus ne touche que ce qui est encore `null`, ce qui permet a la
 * reprise des structures deja ouvertes d'appeler le meme code que l'ouverture.
 */
final readonly class BillingSettingsSeeder
{
    /**
     * Le compte de produit par defaut.
     *
     * 706 = prestations de services, ce que `AccountingChartSeeder` pose sous `706000`. On resout
     * par PREFIXE et non par numero exact : un exploitant peut avoir subdivise son 706 en 706100 /
     * 706200, auquel cas le 706000 n'existe pas et un `findOneBy(['numero' => '706000'])` rendrait
     * `null` sur un plan parfaitement valide.
     */
    private const PREFIXE_COMPTE_PRODUIT = '706';

    public function __construct(
        private EntityManagerInterface $em,
        private CompteLookupService $comptes,
    ) {
    }

    /**
     * Pose ce qui manque au parametrage de ce profil, et rend la ligne.
     *
     * ⚠⚠ LE PLAN DE COMPTES DOIT ETRE FLUSHE AVANT D'APPELER CETTE METHODE. Le compte de produit se
     * resout par une REQUETE, et une requete ne voit pas ce que `AccountingChartSeeder::poser()`
     * vient de `persist()` sans flusher. Appelee trop tot, elle ne leve rien : elle laisse
     * simplement `compteProduitDefaut` a `null`, et la premiere facture est refusee des semaines
     * plus tard par une tache de nuit, pour « compte de produit indeterminable ». C'est exactement
     * la forme de defaut que ce fichier corrige — il serait sot de la reintroduire en le posant.
     *
     * ⚠ NE FLUSHE PAS elle-meme : l'appelant maitrise sa transaction.
     */
    public function seed(ProfilExploitant $profile, ?TauxTva $defaultVatRate): ParametreFacturationEtablissement
    {
        $settings = $this->em->getRepository(ParametreFacturationEtablissement::class)
            ->findOneBy(['profilExploitant' => $profile]);

        if (!$settings instanceof ParametreFacturationEtablissement) {
            $settings = new ParametreFacturationEtablissement();
            $settings->setProfilExploitant($profile);
            $this->em->persist($settings);
        }

        // `null` ne veut pas dire « pas de TVA », il veut dire « personne n'a decide ». On ne
        // remplace donc que le vide, et seulement si on a une valeur qui vient de la loi.
        if ($defaultVatRate instanceof TauxTva && null === $settings->getTauxTvaDefaut()) {
            $settings->setTauxTvaDefaut($defaultVatRate);
        }

        if (null === $settings->getCompteProduitDefaut()) {
            $compte = $this->compteProduit($profile);
            if ($compte instanceof CompteComptable) {
                $settings->setCompteProduitDefaut($compte);
            }
        }

        return $settings;
    }

    /**
     * Le compte de produit du plan, ou `null` s'il n'y en a pas encore.
     *
     * ⚠ ON INTERROGE `CompteLookupService` PLUTOT QUE DE REECRIRE SA REQUETE. Sa selection n'est pas
     * qu'un `LIKE` : elle filtre sur `actif`, ordonne par numero et prend le premier. Reecrire ces
     * trois regles ici produirait un semeur qui choisit un autre compte que celui que
     * `compteProduit()` resoudra ensuite — deux verites sur le meme sujet.
     *
     * Il leve quand il ne trouve rien, la ou nous voulons un `null` : l'absence de plan de comptes
     * ne doit pas faire echouer l'ouverture d'une structure, elle doit juste laisser le defaut vide.
     * D'ou le rattrapage, qui ne masque rien d'autre — c'est la seule cause de cette exception.
     */
    private function compteProduit(ProfilExploitant $profile): ?CompteComptable
    {
        try {
            return $this->comptes->compteParPrefixe($profile, self::PREFIXE_COMPTE_PRODUIT);
        } catch (UnprocessableEntityHttpException) {
            return null;
        }
    }
}
