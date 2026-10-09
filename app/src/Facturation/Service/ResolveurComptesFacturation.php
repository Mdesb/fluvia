<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Regime\CompteLookupService;
use App\Compta\Service\PeriodeComptableResolver;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Frontière avec M6 (`plan-facturation.md` §0.2) : résout **en lecture** les objets du plan de
 * comptes nécessaires à l'émission d'une facture directe. **Aucun fichier `App\Compta\*` n'est
 * modifié** — ce service ne fait que consommer des classes déjà publiques/autowirables, exactement
 * comme M6 lit déjà M2 via `ProjectionVenteDoctrineAdapter`.
 *
 * Le journal `FAC` est normalement créé par la migration de données (§4 du plan) ; à défaut (profil
 * créé après cette migration, §7 point 6 du plan — non automatisé), il est créé paresseusement ici.
 *
 * ⚠ Risque n°1 du plan, non levé : le compte de tiers « client » (411) est résolu par **préfixe
 * générique** (`CompteLookupService::compteParPrefixe`), hors `RegimeComptableInterface` (qui reste
 * propriétaire du compte d'encaissement *immédiat*, sémantique incompatible avec une vente à terme).
 * Si un expert-comptable tranche qu'il doit dépendre du régime, l'extension est additive :
 * `compteClient(ProfilExploitant)` sur l'interface M6 + 3 implémentations (non fait ici).
 */
final class ResolveurComptesFacturation
{
    public const CODE_JOURNAL = 'FAC';
    public const LIBELLE_JOURNAL = 'Journal des factures directes';
    public const PREFIXE_COMPTE_CLIENT = '411';
    public const PREFIXE_COMPTE_TVA = '4457';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompteLookupService $lookup,
        private readonly PeriodeComptableResolver $periodes,
    ) {
    }

    /** Profil exploitant couvrant l'établissement (RG-SOCLE-01 + périmètre comptable M6). */
    public function profilPour(Etablissement $etablissement): ProfilExploitant
    {
        $profils = $this->em->getRepository(ProfilExploitant::class)->findAll();
        foreach ($profils as $profil) {
            if ($profil->couvre($etablissement)) {
                return $profil;
            }
        }

        throw new UnprocessableEntityHttpException(
            sprintf('Aucun profil exploitant ne couvre l\'établissement « %s » : facturation impossible.', $etablissement->getNom()),
        );
    }

    /**
     * Exercice couvrant la date (cas limite « facture émise à cheval sur deux exercices » : c'est la
     * **date d'émission** qui tranche, cohérent avec `PeriodeComptable::couvre` côté M6).
     */
    public function periodePour(ProfilExploitant $profil, \DateTimeImmutable $date): PeriodeComptable
    {
        $periodes = $this->em->getRepository(PeriodeComptable::class)->findBy(['profilExploitant' => $profil->getId()]);
        foreach ($periodes as $periode) {
            if ($periode->couvre($date)) {
                return $periode;
            }
        }

        // ⚠ UNE STRUCTURE NEUVE N'A AUCUNE PERIODE, ET SA PREMIERE FACTURE ETAIT REFUSEE EN 422.
        // `POST /organisation/structures` ouvre profil, comptes, taux et parametrage, pas de periode,
        // et aucun ecran n'en cree une (seule l'API le permet, `POST /periode_comptables`) : mesure du
        // 08/10. L'emission est une ecriture generee, comme celles des ventes et des achats : elle
        // ouvre le mois par le meme chemin qu'eux. Dans la transaction d'emission, une emission
        // annulee n'en laisse aucune.
        return $this->periodes->resoudreOuCreer($profil, $date);
    }

    /** Journal `FAC` (migration de données §4 du plan), créé paresseusement si absent. */
    public function journalFactures(ProfilExploitant $profil): Journal
    {
        $journal = $this->em->getRepository(Journal::class)->findOneBy([
            'profilExploitant' => $profil->getId(),
            'code' => self::CODE_JOURNAL,
        ]);
        if ($journal instanceof Journal) {
            return $journal;
        }

        $journal = (new Journal())
            ->setProfilExploitant($profil)
            ->setCode(self::CODE_JOURNAL)
            ->setLibelle(self::LIBELLE_JOURNAL);
        $this->em->persist($journal);

        return $journal;
    }

    public function parametre(ProfilExploitant $profil): ?ParametreFacturationEtablissement
    {
        return $this->em->getRepository(ParametreFacturationEtablissement::class)
            ->findOneBy(['profilExploitant' => $profil->getId()]);
    }

    /** Compte de tiers « client » (411) portant la créance née de la facture directe (§0.2 du plan). */
    public function compteClient(ProfilExploitant $profil): CompteComptable
    {
        return $this->lookup->compteParPrefixe($profil, self::PREFIXE_COMPTE_CLIENT);
    }

    public function compteTvaCollectee(ProfilExploitant $profil): CompteComptable
    {
        return $this->lookup->compteParPrefixe($profil, self::PREFIXE_COMPTE_TVA);
    }

    /**
     * Compte de produit d'une ligne, par ordre de priorité :
     *  1. `MappingComptable` (M6) résolu depuis `LigneFacture::$categorieComptable` ;
     *  2. `ParametreFacturationEtablissement::$compteProduitDefaut`.
     * Aucun repli implicite au-delà : une ligne dont le compte est indéterminable est **rejetée
     * explicitement** (422), plutôt que de produire une écriture fausse.
     */
    public function compteProduit(ProfilExploitant $profil, LigneFacture $ligne): CompteComptable
    {
        $categorie = $ligne->getCategorieComptable();
        if ($categorie !== null) {
            $mapping = $this->em->getRepository(MappingComptable::class)->findOneBy([
                'profilExploitant' => $profil->getId(),
                'categorie' => $categorie,
            ]);
            $compte = $mapping?->getCompteProduit();
            if ($compte instanceof CompteComptable) {
                return $compte;
            }
        }

        $defaut = $this->parametre($profil)?->getCompteProduitDefaut();
        if ($defaut instanceof CompteComptable) {
            return $defaut;
        }

        throw new UnprocessableEntityHttpException(sprintf(
            'Compte de produit indéterminable pour la ligne « %s » : renseignez une catégorie comptable mappée, ou un compte de produit par défaut dans le paramétrage de facturation.',
            $ligne->getDesignation(),
        ));
    }
}
