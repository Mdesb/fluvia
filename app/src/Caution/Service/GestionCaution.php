<?php

declare(strict_types=1);

namespace App\Caution\Service;

use App\Caution\Entity\Caution;
use App\Caution\Entity\GrilleRetenue;
use App\Caution\Entity\MouvementCaution;
use App\Caution\Enum\StatutCaution;
use App\Caution\Enum\TypeMouvementCaution;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Moteur générique du patron « caution » (module socle `App\Caution`), refactor de la logique
 * dupliquée par `App\Piscine` (casiers), `App\Padel` (matériel) et `App\Patinoire` (patins) :
 * consignation, restitution, application d'une grille de retenue paramétrable (proposition puis
 * validation, garde-fou RG-SOCLE-07 « montant hors barème réservé au forçage »), relance et forçage
 * administratif. Ne connaît aucune verticale : les cibles sont désignées par le couple opaque
 * `typeCible`/`referenceCible` (même patron que `App\Recouvrement\Service\RedevableRegistry`/
 * `IncidentImpaye`). N'appelle jamais `flush()` — laissé aux handlers/processors appelants (même
 * convention que `App\Piscine\Service\AttribuerCasierHandler` et consorts).
 */
final class GestionCaution
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function consigner(
        Etablissement $etablissement,
        string $typeCible,
        Uuid $referenceCible,
        int $montantCentimes,
        ?string $moyenEncaissement = null,
    ): Caution {
        $caution = new Caution();
        $caution->setEtablissement($etablissement)
            ->setTypeCible($typeCible)
            ->setReferenceCible((string) $referenceCible)
            ->setMontantCentimes($montantCentimes)
            ->setMoyenEncaissement($moyenEncaissement)
            ->setStatut(StatutCaution::Consignee)
            ->setDateConsignation(new \DateTimeImmutable());
        $this->em->persist($caution);

        return $caution;
    }

    /** Caution active (statut ≠ restituée) pour une cible donnée, s'il en existe une. */
    public function cautionActivePour(string $typeCible, Uuid $referenceCible): ?Caution
    {
        return $this->em->getRepository(Caution::class)->findOneBy([
            'typeCible' => $typeCible,
            'referenceCibleActive' => (string) $referenceCible,
        ]);
    }

    public function restituer(Caution $caution): Caution
    {
        $mouvement = new MouvementCaution();
        $mouvement->setCaution($caution)->setType(TypeMouvementCaution::Restitution)->setMontantCentimes($caution->getMontantCentimes());
        $this->em->persist($mouvement);

        $caution->setStatut(StatutCaution::Restituee)->setDateRestitution(new \DateTimeImmutable());

        return $caution;
    }

    /**
     * Résout la `GrilleRetenue` applicable (priorité à une règle spécifique `sousCible` sur la règle
     * générale de l'établissement, `sousCible` NULL) — même priorité que
     * `App\Patinoire\Service\ResolveurGrilleRetenueHandler` (code réel lu).
     */
    public function resoudreGrille(Etablissement $etablissement, string $typeCible, string $motif, ?string $sousCible = null): ?GrilleRetenue
    {
        $repo = $this->em->getRepository(GrilleRetenue::class);
        if ($sousCible !== null) {
            $specifique = $repo->findOneBy([
                'etablissement' => $etablissement,
                'typeCible' => $typeCible,
                'sousCible' => $sousCible,
                'motif' => $motif,
                'actif' => true,
            ]);
            if ($specifique instanceof GrilleRetenue) {
                return $specifique;
            }
        }

        return $repo->findOneBy([
            'etablissement' => $etablissement,
            'typeCible' => $typeCible,
            'sousCible' => null,
            'motif' => $motif,
            'actif' => true,
        ]);
    }

    /**
     * Crée un mouvement de retenue *proposé* (non validé, `mouvementRegieRef` NULL) : montant résolu
     * via la grille (ou le montant explicite fourni, ou à défaut le montant total de la caution).
     */
    public function proposerRetenue(Caution $caution, string $motif, ?string $sousCible = null, ?int $montantProposeCentimes = null): MouvementCaution
    {
        $grille = null;
        $montant = $montantProposeCentimes;
        if ($montant === null) {
            $etablissement = $caution->getEtablissement();
            $grille = $etablissement !== null ? $this->resoudreGrille($etablissement, $caution->getTypeCible(), $motif, $sousCible) : null;
            $montant = $grille?->getMontantCentimes() ?? $caution->getMontantCentimes();
        }

        $mouvement = new MouvementCaution();
        $mouvement->setCaution($caution)
            ->setType(TypeMouvementCaution::Retenue)
            ->setMontantCentimes($montant)
            ->setGrilleAppliquee($grille)
            ->setMotif($motif);
        $this->em->persist($mouvement);

        return $mouvement;
    }

    /**
     * Valide un mouvement de retenue proposé (US-PATIN-04, CA-4, garde-fou RG-SOCLE-07) : si le
     * montant soumis diffère du montant par défaut proposé, exige `$autoriseForcage` (permission de
     * forçage vérifiée par l'appelant), sinon 403. Met à jour le statut de la caution (retenue totale
     * si le montant validé couvre le montant consigné, partielle sinon) et génère la trace comptable
     * (`mouvementRegieRef`, référence logique non-FK).
     */
    public function validerRetenue(MouvementCaution $mouvement, ?int $montantSoumisCentimes, ?Utilisateur $agent, bool $autoriseForcage): MouvementCaution
    {
        if ($mouvement->getType() !== TypeMouvementCaution::Retenue) {
            throw new UnprocessableEntityHttpException('Seul un mouvement de type « retenue » peut être validé.');
        }
        if ($mouvement->estValidee()) {
            throw new ConflictHttpException('Ce mouvement a déjà été validé.');
        }

        $montantDefaut = $mouvement->getMontantCentimes() ?? 0;
        $montant = $montantSoumisCentimes ?? $montantDefaut;
        $forcee = $montant !== $montantDefaut;
        if ($forcee && !$autoriseForcage) {
            throw new AccessDeniedHttpException('Montant hors barème : permission de forçage requise (RG-SOCLE-07).');
        }

        $mouvement->setMontantCentimes($montant)
            ->setForcee($forcee)
            ->setAgent($agent)
            ->setMouvementRegieRef(Uuid::v4());

        $caution = $mouvement->getCaution();
        if ($caution instanceof Caution) {
            $totale = $montant >= $caution->getMontantCentimes();
            $caution->setStatut($totale ? StatutCaution::RetenueTotale : StatutCaution::RetenuePartielle)
                ->setMontantRetenuCentimes($montant)
                ->setRegieMouvementRef($mouvement->getMouvementRegieRef());
        }

        return $mouvement;
    }

    /**
     * Retenue en un temps (propose puis valide immédiatement) — patron `App\Padel`
     * (`RetournerMaterielProcessor`) : pas de phase de validation séparée côté verticale.
     */
    public function retenirImmediat(
        Caution $caution,
        string $motif,
        ?string $sousCible = null,
        ?int $montantForceCentimes = null,
        bool $autoriseForcage = false,
        ?Utilisateur $agent = null,
    ): MouvementCaution {
        $mouvement = $this->proposerRetenue($caution, $motif, $sousCible);

        return $this->validerRetenue($mouvement, $montantForceCentimes, $agent, $autoriseForcage);
    }

    /**
     * Forçage administratif (US-L6-09, décision actée) : retenue totale immédiate sans passage par
     * une grille, journalisée (agent, motif, horodatage) — patron `App\Piscine\Entity\ForcageCasier`.
     */
    public function forcer(Caution $caution, Utilisateur $agent, string $motif): MouvementCaution
    {
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Motif requis pour un forçage journalisé (RG-SOCLE-07).');
        }

        $mouvement = new MouvementCaution();
        $mouvement->setCaution($caution)
            ->setType(TypeMouvementCaution::Forcage)
            ->setAgent($agent)
            ->setMotif($motif)
            ->setMontantCentimes($caution->getMontantCentimes());
        $this->em->persist($mouvement);

        $caution->setStatut(StatutCaution::RetenueTotale)->setMontantRetenuCentimes($caution->getMontantCentimes());

        return $mouvement;
    }

    /**
     * Relance (délai avant forçage possible) — patron `App\Piscine\Entity\RelanceCasier`.
     */
    public function relancer(Caution $caution, int $delaiForcageJours): MouvementCaution
    {
        $mouvement = new MouvementCaution();
        $mouvement->setCaution($caution)
            ->setType(TypeMouvementCaution::Relance)
            ->setDelaiForcageJours($delaiForcageJours);
        $this->em->persist($mouvement);

        return $mouvement;
    }

    /** Vrai si le délai de la dernière relance de cette caution est dépassé à la date donnée. */
    public function forcageAutorise(Caution $caution, \DateTimeImmutable $maintenant): bool
    {
        $derniere = $this->em->getRepository(MouvementCaution::class)->createQueryBuilder('m')
            ->andWhere('m.caution = :caution')
            ->andWhere('m.type = :type')
            ->setParameter('caution', $caution->getId(), 'uuid')
            ->setParameter('type', TypeMouvementCaution::Relance)
            ->orderBy('m.horodatage', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $derniere instanceof MouvementCaution && $derniere->forcageAutoriseA($maintenant);
    }
}
