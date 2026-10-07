<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\LegalVatRate;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\VatCategory;
use App\Compta\Enum\VatRateCategory;
use App\Compta\Repository\LegalVatRateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LES TAUX DE TVA D'UN EXPLOITANT, TIRES DU REFERENTIEL LEGAL DE SON PAYS.
 *
 * ── CE QU'IL REMPLACE, ET POURQUOI C'ETAIT FAUX ─────────────────────────────────────────────────
 *
 * Deux endroits posaient la meme constante `TAUX_TVA_FRANCE` — 20 / 10 / 5,5 / 2,1, libelles
 * francais — a toute structure qui s'ouvrait : `StructureOnboarding` et
 * `BackfillAccountingProfilesCommand`. Un exploitant belge recevait donc quatre taux francais, dont
 * deux qui n'existent pas chez lui, sous des libelles qui avaient l'air officiels. Rien ne pouvait
 * rougir : les jeux d'essai sont francais et `Etablissement::pays` vaut `FR` par defaut.
 *
 * ⚠ ET LES DEUX COPIES COMPTAIENT. Ne corriger que l'ouverture aurait laisse la reprise semer des
 * taux francais sur les structures deja ouvertes — celles-la memes qu'elle est censee reparer.
 *
 * Le docbloc de cette constante portait d'ailleurs son propre aveu : « France seulement.
 * `Etablissement` ne porte aucun pays : le jour ou un client belge arrivera, ce tableau ne saura
 * pas quoi proposer. C'est un prealable de modele, pas un oubli d'ici. » Ce prealable est leve
 * depuis T8 — `Etablissement::pays` et `::fiscalTerritory` sont des colonnes reelles, et
 * `accounting_legal_vat_rate` porte 62 taux sur 29 pays. La phrase est restee vraie plus longtemps
 * que le defaut qu'elle decrivait.
 *
 * ── IL NE RETOMBE JAMAIS SUR LA FRANCE ──────────────────────────────────────────────────────────
 *
 * ⚠ Un pays absent du referentiel ne recoit AUCUN taux legal. C'est deliberement plus brutal que
 * l'ancien comportement : une liste vide se voit, alors que quatre taux francais chez un exploitant
 * espagnol ont l'air d'une configuration terminee — et partent dans des factures scellees que seul
 * un avoir corrige.
 *
 * ── LE TERRITOIRE N'EST PAS UN DETAIL ───────────────────────────────────────────────────────────
 *
 * `inForce()` applique la regle de `LegalVatRateRepository::baremeComplet()` : un territoire qui
 * porte des taux declare son bareme ENTIER, il ne complete pas le droit commun. Un etablissement
 * guadeloupeen (`FR` + `DOM`) recoit donc 8,5 et 2,1, et surtout PAS le 5,5 metropolitain, qui
 * n'existe pas la-bas.
 *
 * ── IDEMPOTENT SUR LA FILIATION, PAS SUR LA VALEUR ──────────────────────────────────────────────
 *
 * Meme regle que {@see \App\Compta\State\AdoptLegalVatRateProcessor}, et pour la meme raison : deux
 * taux a 20 % peuvent etre deux choses differentes — un normal francais, un normal autrichien.
 * C'est l'origine legale qui dit s'il s'agit du meme. Repasser dessus ne cree donc rien, ce qui
 * permet a la reprise d'appeler exactement le meme code que l'ouverture, au lieu d'en entretenir
 * une seconde version qui divergera.
 *
 * ⚠ ET IL N'ECRASE RIEN. Un exploitant qui a renomme, desactive ou ajoute ses propres taux les
 * garde : on n'ajoute que ce qui manque.
 *
 * ── `missing()` ET `seed()` LISENT LA MEME SOURCE ───────────────────────────────────────────────
 *
 * La paire existe pour que le mode constat de la reprise ne puisse pas annoncer autre chose que ce
 * que le mode ecriture fera — c'est le patron de `AccountingChartSeeder::manquants()`/`poser()`.
 * Le contre-exemple est frais : le `--dry-run` de `sepa:echeances:facturer` sortait avant la
 * resolution du taux et annoncait « 5 a facturer, 0 refus » la ou le passage reel en refusait cinq.
 */
final readonly class VatRateSeeder
{
    public function __construct(
        private EntityManagerInterface $em,
        private LegalVatRateRepository $legalRates,
    ) {
    }

    /**
     * Pose les taux legaux manquants et rend celui qui fera defaut de facturation.
     *
     * ⚠ NE FLUSHE PAS. L'ouverture d'une structure est un seul geste : un flush intermediaire
     * laisserait, si la suite echoue, une structure a moitie ouverte — des taux sans plan de
     * comptes. L'appelant flushe une fois, ou rien n'est ecrit.
     *
     * @return TauxTva|null le taux NORMAL du pays — qu'il vienne d'etre cree ou qu'il existat deja —
     *                      ou `null` si le referentiel n'en connait pas. Dans ce cas aucun defaut de
     *                      facturation ne doit etre pose : mieux vaut un parametre vide, qui refuse
     *                      en nommant ce qui manque, qu'un taux choisi au hasard parmi ceux qui
     *                      restent.
     */
    public function seed(ProfilExploitant $profile, ?\DateTimeImmutable $on = null): ?TauxTva
    {
        $standard = null;

        foreach ($this->legalRatesFor($profile, $on) as $legal) {
            $rate = $this->existing($profile, $legal);

            if (!$rate instanceof TauxTva) {
                $rate = $this->build($profile, $legal);
                $this->em->persist($rate);
            }

            // ⚠ UN TAUX DESACTIVE NE FAIT PAS UN DEFAUT DE FACTURATION. `existing()` rend aussi les
            // taux inactifs, volontairement — pour ne pas en reposer un second a la meme valeur.
            // Mais en designer un comme defaut ferait facturer avec un taux que l'exploitant a
            // explicitement retire : mieux vaut aucun defaut, qui refuse en nommant ce qui manque.
            if (VatRateCategory::Standard === $legal->getCategory() && $rate->isActif()) {
                $standard = $rate;
            }
        }

        if (!$this->existingOutOfScope($profile) instanceof TauxTva) {
            $this->em->persist($this->buildOutOfScope($profile));
        }

        return $standard;
    }

    /**
     * Les libelles des taux que {@see seed()} poserait, dans le meme ordre.
     *
     * Sert au mode constat de la reprise. Il lit la meme source que l'ecriture — c'est la seule
     * facon qu'un « voici ce qui serait fait » ne devienne pas une affirmation autonome.
     *
     * @return list<string>
     */
    public function missing(ProfilExploitant $profile, ?\DateTimeImmutable $on = null): array
    {
        $labels = [];

        foreach ($this->legalRatesFor($profile, $on) as $legal) {
            if (!$this->existing($profile, $legal) instanceof TauxTva) {
                $labels[] = $legal->getLabel();
            }
        }

        if (!$this->existingOutOfScope($profile) instanceof TauxTva) {
            $labels[] = TauxTva::LIBELLE_HORS_CHAMP;
        }

        return $labels;
    }

    /**
     * Les taux legaux en vigueur pour le pays ET le territoire de cet exploitant.
     *
     * @return list<LegalVatRate>
     */
    private function legalRatesFor(ProfilExploitant $profile, ?\DateTimeImmutable $on): array
    {
        $establishment = $profile->getEtablissementPrincipal();

        if (null === $establishment) {
            // Sans etablissement principal, aucun pays a interroger. On ne devine pas : un profil
            // dans cet etat est deja anormal, et lui poser des taux francais serait exactement le
            // defaut qu'on corrige ici.
            return [];
        }

        return $this->legalRates->inForce(
            $establishment->getPays(),
            $on ?? new \DateTimeImmutable(),
            $establishment->getFiscalTerritory(),
        );
    }

    /**
     * Le taux de cet exploitant correspondant a ce taux legal, s'il existe deja.
     *
     * ── DEUX CLES, ET IL FAUT LES DEUX ──────────────────────────────────────────────────────────
     *
     * L'ORIGINE d'abord : c'est la cle juste. Deux taux a 20 % peuvent etre deux choses
     * differentes — un normal francais, un normal autrichien — et la valeur ne les distingue pas.
     *
     * ⚠ MAIS LA VALEUR ENSUITE, ET C'EST UN GARDE-FOU CONTRE MOI-MEME. Ma premiere version ne
     * comparait QUE l'origine. Mesure sur la preproduction le 14/09 : sur les 31 taux du parc,
     * **aucun ne porte d'origine legale** — ils viennent tous de l'ancienne constante, anterieure au
     * referentiel. La reprise aurait donc juge « manquants » les quatre taux de CHAQUE profil et
     * en aurait cree des doublons : six exploitants, deux « Taux normal 20 % » chacun. Or, comme
     * l'ecrit le docbloc de la commande de reprise, « le doublon d'un taux de TVA ne se voit pas
     * dans une liste et se voit tres bien sur une facture ».
     *
     * L'argument qui m'avait fait abandonner la valeur reste vrai — elle ne distingue pas deux pays
     * — mais il ne s'applique pas ici : un profil exploitant a UN etablissement principal, donc UN
     * pays. Les deux cles ne se concurrencent pas, elles se cumulent, et le resultat est strictement
     * plus prudent que chacune seule.
     *
     * ⚠ ON NE TOUCHE PAS AU TAUX TROUVE PAR SA VALEUR. Ni son libelle — l'exploitant a pu le
     * renommer — ni sa categorie, ni son origine. Le semeur ajoute ce qui manque ; il ne reecrit
     * pas ce qui est la. Un taux herite garde donc son origine vide, et c'est sans consequence :
     * l'ecran « reprendre un taux legal » permet de la poser si quelqu'un en a besoin.
     *
     * Le profil est compare par son IDENTIFIANT : a l'ouverture il n'est pas encore flushe, et une
     * entite non geree passee a `findOneBy` ne se compare pas de facon fiable.
     */
    private function existing(ProfilExploitant $profile, LegalVatRate $legal): ?TauxTva
    {
        $depot = $this->em->getRepository(TauxTva::class);

        $parOrigine = $depot->findOneBy([
            'profilExploitant' => $profile->getId(),
            'origineLegale' => $legal,
        ]);

        if ($parOrigine instanceof TauxTva) {
            return $parOrigine;
        }

        // ⚠ SANS FILTRE SUR `actif`. Un taux que l'exploitant a DESACTIVE est un taux qu'il connait
        // et dont il ne veut pas : en reposer un second, actif, le lui rendrait sans qu'il l'ait
        // demande — et lui en laisserait deux a la meme valeur.
        return $depot->findOneBy([
            'profilExploitant' => $profile->getId(),
            'taux' => $legal->getRate(),
        ]);
    }

    private function build(ProfilExploitant $profile, LegalVatRate $legal): TauxTva
    {
        return (new TauxTva())
            ->setProfilExploitant($profile)
            ->setTaux($legal->getRate())
            ->setLibelle($legal->getLabel())
            ->setOrigineLegale($legal)
            ->setActif(true)
            // La categorie EN 16931 se DEDUIT de la categorie juridique, elle ne se choisit pas :
            // voir `VatRateCategory::toInvoiceCategory()`. L'ancienne constante posait `Standard`
            // en dur sur tout le monde — juste pour les taux positifs francais, faux des qu'un
            // referentiel porte un taux zero ou une exoneration.
            ->setVatCategory($legal->getCategory()->toInvoiceCategory());
    }

    /**
     * Le hors-champ, qui ne vient d'aucun referentiel.
     *
     * Il n'est pas un taux a zero parmi d'autres : il dit « cette operation n'entre pas dans le
     * champ de la TVA ». Aucun Etat ne le publie comme un taux — c'est une qualification
     * d'operation — et pourtant tout exploitant en a besoin (cotisation d'association, subvention).
     * Son unicite se juge sur la CATEGORIE, pas sur la valeur : un taux zero AVEC droit a deduction
     * vaut aussi `0.00` et n'est pas la meme chose.
     *
     * ⚠ ET SUR LE LIBELLE AUSSI, POUR LE PARC EXISTANT. Mesure du 14/09 : les six hors-champ de la
     * preproduction portent `vat_category` a NULL — ils sont anterieurs a la colonne. Juger sur la
     * seule categorie en aurait donc cree un SECOND a chaque profil, a la meme valeur et sous le
     * meme nom. Le libelle est une cle faible, mais c'est la seule que ces lignes portent.
     */
    private function existingOutOfScope(ProfilExploitant $profile): ?TauxTva
    {
        $depot = $this->em->getRepository(TauxTva::class);

        $parCategorie = $depot->findOneBy([
            'profilExploitant' => $profile->getId(),
            'vatCategory' => VatCategory::OutOfScope,
        ]);

        if ($parCategorie instanceof TauxTva) {
            return $parCategorie;
        }

        return $depot->findOneBy([
            'profilExploitant' => $profile->getId(),
            'libelle' => TauxTva::LIBELLE_HORS_CHAMP,
        ]);
    }

    private function buildOutOfScope(ProfilExploitant $profile): TauxTva
    {
        return (new TauxTva())
            ->setProfilExploitant($profile)
            ->setTaux('0.00')
            ->setLibelle(TauxTva::LIBELLE_HORS_CHAMP)
            ->setActif(true)
            ->setVatCategory(VatCategory::OutOfScope);
    }
}
