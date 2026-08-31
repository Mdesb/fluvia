<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\SensCompte;
use Doctrine\ORM\EntityManagerInterface;

/**
 * POSE LE PLAN DE COMPTES ET LES JOURNAUX D'UN PROFIL EXPLOITANT.
 *
 * ── POURQUOI CE N'EST PAS À L'EXPLOITANT DE LES SAISIR ──────────────────────────────────────────
 *
 * Un plan de comptes M57 ou PCG n'est pas un choix de gestion : c'est de la nomenclature, au même
 * titre que les taux de TVA légaux. Maxime l'a tranché pour les taux — « c'est de la base légale, un
 * client n'a pas à le créer lui-même » — et l'argument vaut mot pour mot ici.
 *
 * ── CE QUE LE MOTEUR EXIGE, ET QUI MANQUAIT ─────────────────────────────────────────────────────
 *
 * Sans ces objets, la génération d'écritures ne démarre pas. Les régimes les résolvent par PRÉFIXE
 * (`CompteLookupService::compteParPrefixe`) et par code de journal, et lèvent une 422 quand ils ne
 * trouvent rien :
 *
 *   511   encaissement en régie directe        487   produits constatés d'avance
 *   411   encaissement en régime privé         4457  TVA collectée
 *   512   banque (bordereau de versement)      706   produits (reprise de PCA)
 *   VTE ENC REG PCA EXT                        les cinq natures d'opération
 *
 * Sur un établissement réellement créé, aucun de ces objets n'existait : ils n'étaient posés que par
 * les jeux d'essai. L'écran de comptabilité annonçait « aucun profil d'exploitant » ; le profil une
 * fois posé, il aurait annoncé « aucun journal », puis « aucune période ». On levait le premier
 * verrou d'une série, pas la série.
 *
 * ── IDEMPOTENT, PARCE QU'IL Y A DEUX APPELANTS ET QUE L'UN SE RELANCE ───────────────────────────
 *
 * `StructureOnboarding` l'appelle une fois, à l'ouverture. La commande de reprise le rappelle sur
 * des profils déjà en service, autant de fois qu'on la lance. Un compte ou un journal déjà présent
 * n'est jamais recréé NI modifié : un exploitant a pu renommer « Redevances » en « Entrées piscine »,
 * et l'écraser au prochain passage de la commande serait pire que de ne rien faire.
 *
 * ⚠ Le rendu compte ce qui a été CRÉÉ, pas ce qui existe. C'est ce qui permet à la commande de dire
 * la vérité sur ce qu'elle a fait plutôt que sur ce qu'elle a vu.
 */
final class AccountingChartSeeder
{
    /**
     * Les cinq natures d'opération que `RegimeComptableInterface::journalPour()` résout, plus le
     * journal d'opérations diverses recommandé pour la saisie manuelle libre (RG-M6-11).
     */
    private const JOURNAUX = [
        'VTE' => 'Journal des ventes',
        'ENC' => 'Journal des encaissements',
        'REG' => 'Journal de la régie',
        'PCA' => 'Opérations diverses — produits constatés d’avance',
        'EXT' => 'Journal des extournes',
        'OD' => 'Journal des opérations diverses',
    ];

    /**
     * Le socle commun aux deux référentiels : ces numéros sont ceux que le moteur cherche par
     * préfixe, et leur absence bloque la génération d'écritures.
     *
     * ⚠ L'ORDRE DES NUMÉROS COMPTE. `compteParPrefixe()` prend le PREMIER compte actif dont le
     * numéro commence par le préfixe, trié croissant. `511000` sort donc avant `511200`, et c'est
     * voulu : le préfixe `511` doit désigner le compte d'attente d'encaissement, pas les chèques.
     * Intercaler un jour un `510xxx` ou un `511050` changerait silencieusement la cible.
     *
     * @var array<string, array{0: string, 1: SensCompte}>
     */
    private const COMPTES = [
        '401000' => ['Fournisseurs', SensCompte::Credit],
        '411000' => ['Clients', SensCompte::Debit],
        '445710' => ['TVA collectée', SensCompte::Credit],
        '487000' => ['Produits constatés d’avance', SensCompte::Credit],
        '511000' => ['Valeurs à l’encaissement', SensCompte::Debit],
        '511200' => ['Chèques à encaisser', SensCompte::Debit],
        '512000' => ['Banque', SensCompte::Debit],
        '531000' => ['Caisse', SensCompte::Debit],
        '627000' => ['Services bancaires', SensCompte::Debit],
        '706000' => ['Prestations de services', SensCompte::Credit],
    ];

    /**
     * Les libellés qu'une collectivité n'emploie pas. Les NUMÉROS ne changent pas — le moteur les
     * résout par préfixe, et en inventer d'autres casserait la résolution sans rien apporter.
     *
     * @var array<string, string>
     */
    private const LIBELLES_M57 = [
        '411000' => 'Redevables',
        '511000' => 'Recettes à classer — régie',
        '706000' => 'Redevances et droits d’entrée',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * CE QUI MANQUE À CE PROFIL, SANS RIEN ÉCRIRE.
     *
     * ⚠ SOURCE UNIQUE, ET C'EST LE POINT. La commande de reprise a un mode constat : elle annonce
     * ce qu'elle ferait avant qu'on l'y autorise. Si elle recopiait la liste pour la compter, les
     * deux listes divergeraient le jour où l'une évolue — et le constat mentirait sur l'action.
     *
     * @return array{journaux: list<string>, comptes: list<string>} codes et numéros absents
     */
    public function manquants(ProfilExploitant $profil): array
    {
        $journaux = [];
        foreach (array_keys(self::JOURNAUX) as $code) {
            $existant = $this->entityManager->getRepository(Journal::class)->findOneBy([
                'profilExploitant' => $profil->getId(),
                'code' => $code,
            ]);
            if ($existant === null) {
                $journaux[] = (string) $code;
            }
        }

        $comptes = [];
        foreach (array_keys(self::COMPTES) as $numero) {
            // PHP convertit en entier les clés de tableau qui ressemblent à des nombres : recast
            // explicite, sinon la comparaison et `setNumero()` reçoivent un int.
            $numero = (string) $numero;

            $existant = $this->entityManager->getRepository(CompteComptable::class)->findOneBy([
                'profilExploitant' => $profil->getId(),
                'numero' => $numero,
            ]);
            if ($existant === null) {
                $comptes[] = $numero;
            }
        }

        return ['journaux' => $journaux, 'comptes' => $comptes];
    }

    /**
     * Pose ce qui manque, et rien d'autre.
     *
     * L'existant n'est jamais modifié : un exploitant a pu renommer « Redevances » en « Entrées
     * piscine », et l'écraser au prochain passage de la commande de reprise serait pire que de ne
     * rien faire.
     *
     * @return array{journaux: int, comptes: int} ce qui a été CRÉÉ
     */
    public function poser(ProfilExploitant $profil): array
    {
        $manquants = $this->manquants($profil);
        $public = $profil->getReferentielComptable() === ReferentielComptable::M57;

        foreach ($manquants['journaux'] as $code) {
            $this->entityManager->persist(
                (new Journal())
                    ->setProfilExploitant($profil)
                    ->setCode($code)
                    ->setLibelle(self::JOURNAUX[$code])
            );
        }

        foreach ($manquants['comptes'] as $numero) {
            [$libelle, $sens] = self::COMPTES[$numero];
            $this->entityManager->persist(
                (new CompteComptable())
                    ->setProfilExploitant($profil)
                    ->setNumero($numero)
                    ->setLibelle($public ? (self::LIBELLES_M57[$numero] ?? $libelle) : $libelle)
                    ->setSens($sens)
                    ->setActif(true)
            );
        }

        return [
            'journaux' => \count($manquants['journaux']),
            'comptes' => \count($manquants['comptes']),
        ];
    }
}
