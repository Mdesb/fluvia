<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Dto\OuvertureContexte;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\JaugeFmi;
use App\Acces\Entity\JournalReconciliation;
use App\Acces\Entity\Passage;
use App\Acces\Entity\Support;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ModeSeuil;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\StatutSupport;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Port\PiloteAcces;
use App\Opening\Service\OpeningCalendar;
use App\Vente\Service\GenerateurCodeSupport;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Algorithme de validation d'un passage (§4.3 du plan, RG-ACC-01/02, CA-3/4). Synchrone, local,
 * exécuté sur ingestion (`POST /acces/passages`) et en rejeu hors-ligne (§4.6). Court-circuite au
 * premier refus ; les étapes crédit+FMI+enregistrement sont atomiques (transaction unique, UPDATE
 * conditionnels — aucun read-modify-write).
 */
final class ValidationPassageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly ResolveurAntiPassback $antiPassback,
        private readonly ResolveurMarges $marges,
        private readonly PiloteAcces $pilote,
        private readonly GenerateurCodeSupport $generateurCode,
        private readonly VersionSnapshotSequencer $sequencer,
        /**
         * Le planning d'ouverture du site. Injecté ici plutôt que consulté à la volée : une
         * dépendance explicite se voit dans la signature, et le jour où quelqu'un se demandera
         * pourquoi un passage est refusé, la réponse est dans la liste des collaborateurs.
         */
        private readonly OpeningCalendar $ouverture,
        /**
         * Plancher du crédit négatif borné (CA-8, plan-acces-terminal.md §4.2) — global MVP, pas encore
         * par établissement (§8 spec pt.5). N'a d'effet que si
         * `EvenementPassageDto::autoriserCreditNegatifSiHorsLigne = true` (flag hors-ligne uniquement).
         */
        #[Autowire(env: 'int:ACCES_PLANCHER_CREDIT_NEGATIF')] private readonly int $plancherCreditNegatif,
    ) {
    }

    public function valider(EvenementPassageDto $evt): Passage
    {
        $equipement = $this->em->getRepository(Equipement::class)->find($evt->equipementId);
        if (!$equipement instanceof Equipement) {
            throw new UnprocessableEntityHttpException('Équipement introuvable.');
        }
        $controleur = $equipement->getControleur();
        $espace = $controleur?->getEspace();
        if ($controleur === null || $espace === null) {
            throw new UnprocessableEntityHttpException('Équipement mal rattaché (topologie incohérente).');
        }

        // Sens : fourni explicitement, ou déduit d'un équipement non bidirectionnel.
        $sens = $evt->sens ?? match ($equipement->getSens()) {
            \App\Acces\Enum\SensEquipement::Entree => SensPassage::Entree,
            \App\Acces\Enum\SensEquipement::Sortie => SensPassage::Sortie,
            default => null,
        };
        if ($sens === null) {
            return $this->refuser($espace, $controleur, $equipement, null, null, SensPassage::Entree, $evt, CodeMotifRefus::SensInterdit, 'Sens requis (équipement bidirectionnel).');
        }

        // Étape 1 — résolution support/droit.
        if ($evt->identifiantSupport === null) {
            return $this->refuser($espace, $controleur, $equipement, null, null, $sens, $evt, CodeMotifRefus::DroitInvalide, 'Support requis.');
        }

        // Étape 1bis — vérification cryptographique (CA-12/RG-ACC-07) : un code au format d'un code
        // de support signé (`App\Vente\Service\GenerateurCodeSupport` — billet/carte/abonnement/billet
        // boutique) doit porter une signature HMAC valide, sinon il s'agit d'un code forgé/altéré —
        // refusé avant même la résolution en base (aucune fuite d'info « support inconnu » vs
        // « signature invalide »). Les identifiants historiques/manuels (RFID, QR de démonstration…)
        // ne correspondent pas à ce format et ne sont pas concernés (rétrocompatibilité totale).
        if ($this->generateurCode->estCodeSigne($evt->identifiantSupport) && !$this->generateurCode->verifier($evt->identifiantSupport)) {
            return $this->refuser($espace, $controleur, $equipement, null, null, $sens, $evt, CodeMotifRefus::SignatureInvalide, 'Code de support forgé ou altéré (signature invalide).');
        }

        $support = $this->em->getRepository(Support::class)->findOneBy(['identifiant' => $evt->identifiantSupport]);
        if (!$support instanceof Support) {
            return $this->refuser($espace, $controleur, $equipement, null, null, $sens, $evt, CodeMotifRefus::DroitInvalide, 'Support inconnu.');
        }

        // Étape 2 — support bloqué (statut = source de vérité locale = « liste embarquée »,
        // valable online ET hors-ligne, RG-ACC-07). Cas particulier synchro : révocation postérieure
        // au passage hors-ligne = conflit tracé, passage conservé (§4.6, hypothèse retenue).
        $enConflit = false;
        if ($support->getStatut() === StatutSupport::Bloque) {
            if ($evt->ignorerRevocationSiPosterieure && $this->revoqueApresPassage($support, $evt->horodatage)) {
                $enConflit = true;
            } else {
                return $this->refuser($espace, $controleur, $equipement, $support, null, $sens, $evt, CodeMotifRefus::SupportBloque, 'Support bloqué (perte/vol).');
            }
        }

        $appairage = $this->em->getRepository(Appairage::class)->findOneBy(['support' => $support, 'actif' => true]);
        if (!$appairage instanceof Appairage) {
            return $this->refuser($espace, $controleur, $equipement, $support, null, $sens, $evt, CodeMotifRefus::DroitInvalide, 'Support non appairé à un droit actif.');
        }
        $droit = $appairage->getDroit();
        if (!$droit instanceof DroitAcces) {
            return $this->refuser($espace, $controleur, $equipement, $support, null, $sens, $evt, CodeMotifRefus::DroitInvalide, 'Droit introuvable.');
        }

        // Étape 3 — droit valide (non dévalidé M2).
        if ($droit->getStatutProjection() !== StatutProjectionDroit::Valide) {
            return $this->refuser($espace, $controleur, $equipement, $support, $droit, $sens, $evt, CodeMotifRefus::DroitInvalide, 'Droit dévalidé.');
        }

        // Sous-réseau / fédération (US-L3-12, CA-13).
        //
        // ⚠ CE COMMENTAIRE DISAIT « SINON REFUS », ET LE CODE NE LE FAISAIT PAS. Corrigé le 29/08 :
        // c'est le commentaire qui était faux, pas le code. Le contrôle ne s'applique QUE lorsque
        // l'espace appartient au sous-réseau du droit — franchissement inter-entités. Un espace
        // hors du sous-réseau n'est pas refusé ici : `sousReseau` dit sous quelle fédération le
        // droit a été émis, il ne dit pas que le porteur perd l'accès à SON PROPRE site. Refuser
        // « sinon » fermerait la porte d'un adhérent chez lui parce que son abonnement porte une
        // mention de fédération.
        //
        // La restriction par zone, elle, est explicite et se trouve juste en dessous.
        if ($droit->getSousReseau() !== null) {
            $sousReseau = $droit->getSousReseau();
            $memeSousReseau = $espace->getSousReseau() !== null && $espace->getSousReseau()->getId()->equals($sousReseau->getId());
            if ($memeSousReseau) {
                if (!$sousReseau->isActif()) {
                    return $this->refuser($espace, $controleur, $equipement, $support, $droit, $sens, $evt, CodeMotifRefus::FederationInactive, 'Fédération désactivée : franchissement inter-entités refusé.');
                }
                $eligibles = $sousReseau->getDroitsEligiblesRef();
                if ($eligibles !== null && $eligibles !== [] && $droit->getProduitRef() !== null && !\in_array((string) $droit->getProduitRef(), $eligibles, true)) {
                    return $this->refuser($espace, $controleur, $equipement, $support, $droit, $sens, $evt, CodeMotifRefus::FederationInactive, 'Droit non éligible au sous-réseau.');
                }
            }
        }

        // ── CE DROIT OUVRE-T-IL CETTE ZONE ? ─────────────────────────────────────────────────
        //
        // La question ne se posait nulle part. L'espace était résolu dès l'entrée mais ne servait
        // qu'à la jauge et à l'enregistrement : un billet de piscine ouvrait la porte de la salle
        // de sport du même établissement. Sur un site multi-activités, c'est le cœur du contrôle
        // d'accès qui manquait.
        //
        // ⚠ UN DROIT SANS AUCUN ESPACE N'OUVRE RIEN (D87, 30/08/2026). Le sens sûr de l'erreur est
        // ici celui qui restreint : une porte fermée à tort se rouvre en déclarant une zone, une
        // porte ouverte à tort a déjà laissé passer quelqu'un.
        //
        // Ce paragraphe disait l'inverse jusqu'au 30/08, et son argument — « refuser fermerait des
        // portes devant des gens qui ont payé » — était déjà faux quand il servait encore : quatre
        // droits en base, trois sans espace, tous de test. Il pesait sur la discussion qui a mené à
        // D87. Une phrase périmée qui sert d'argument est la forme la plus coûteuse du genre.
        // ⚠ LA DECISION PORTE SUR TOUS LES ESPACES DESSERVIS, PAS SUR LE SEUL PRINCIPAL.
        //
        // Un tourniquet place entre deux activites dessert les deux : le titre passe s'il ouvre
        // l'une d'elles. Ce qui suit -- jauge, anti-passback, espace inscrit sur le passage --
        // continue de porter sur `$espace`, le principal : le porteur a franchi CETTE porte.
        $ouvertureAccordee = false;
        foreach ($controleur?->espacesOuverts() ?? [$espace] as $desservi) {
            if ($droit->ouvre($desservi)) {
                $ouvertureAccordee = true;
                break;
            }
        }

        if (!$ouvertureAccordee) {
            return $this->refuser(
                $espace, $controleur, $equipement, $support, $droit, $sens, $evt,
                CodeMotifRefus::ZoneNonAutorisee,
                sprintf('Ce titre n\'ouvre pas « %s ».', $espace->getLibelle()),
            );
        }

        // Étape 4 — sens compatible avec l'équipement.
        if (!$equipement->getSens()->accepte($sens)) {
            return $this->refuser($espace, $controleur, $equipement, $support, $droit, $sens, $evt, CodeMotifRefus::SensInterdit, 'Sens non autorisé sur cet équipement.');
        }

        // Étape 4-bis — le site est-il ouvert ? (module App\Opening, 28/08)
        //
        // ── PLACÉE AVANT LES MARGES, ET C'EST VOULU ────────────────────────────────────────────
        //
        // Les marges disent si LE DROIT vaut à cette heure ; le planning dit si LE SITE est ouvert.
        // Quand les deux refusent, le motif utile à l'exploitant est le second : « nous sommes
        // fermés » se comprend et se corrige, « hors fenêtre autorisée » envoie chercher un défaut
        // de tarification qui n'existe pas.
        //
        // ── NE REFUSE RIEN TANT QUE L'EXPLOITANT NE L'A PAS DEMANDÉ ────────────────────────────
        //
        // `autoriseLePassage()` rend `true` dès que le réglage de l'établissement n'est pas coché —
        // c'est-à-dire partout, tant que personne n'a activé la règle. Arbitrage de Maxime du
        // 28/08 : le sens sûr de l'erreur est d'ordinaire celui qui restreint, sauf quand
        // restreindre veut dire refuser des clients qui ont payé. Aucun site existant ne se met
        // donc à refuser du monde parce qu'on a déployé ce module.
        if (!$this->ouverture->autoriseLePassage($espace->getEtablissement(), $evt->horodatage, $espace)) {
            return $this->refuser($espace, $controleur, $equipement, $support, $droit, $sens, $evt, CodeMotifRefus::HorsHorairesOuverture, 'Site fermé à cette heure (planning d’ouverture).');
        }

        // Étape 5 — marges (intersection droit ∩ équipement, §4.2).
        if (!$this->marges->estDansMarges($droit, $equipement, $evt->horodatage)) {
            return $this->refuser($espace, $controleur, $equipement, $support, $droit, $sens, $evt, CodeMotifRefus::HorsMarge, 'Hors fenêtre/marges autorisées.');
        }

        // Étape 6 — anti-passback (résolution défaut → espace → équipement, ou sous-réseau fédéré).
        $resolution = $this->antiPassback->resoudre($equipement);
        if ($droit->getSousReseau()?->getAntiPassbackDelai() !== null && $espace->getSousReseau()?->getId()->equals($droit->getSousReseau()->getId()) === true) {
            $resolution['delai'] = $droit->getSousReseau()->getAntiPassbackDelai();
        }
        if ($resolution['actif']) {
            $seuil = $evt->horodatage->modify(sprintf('-%d seconds', $resolution['delai']));
            $dernier = $this->em->createQueryBuilder()
                ->select('p')->from(Passage::class, 'p')
                ->andWhere('IDENTITY(p.support) = :support')
                ->andWhere('p.resultat = :valide')
                ->andWhere('p.horodatage > :seuil')
                ->andWhere('p.horodatage <= :maintenant')
                ->setParameter('support', $support->getId(), 'uuid')
                ->setParameter('valide', ResultatPassage::Valide->value)
                ->setParameter('seuil', $seuil)
                ->setParameter('maintenant', $evt->horodatage)
                ->orderBy('p.horodatage', 'DESC')
                ->setMaxResults(1)
                ->getQuery()->getOneOrNullResult();
            if ($dernier !== null) {
                return $this->refuser($espace, $controleur, $equipement, $support, $droit, $sens, $evt, CodeMotifRefus::AntiPassback, 'Re-scan avant le délai anti-passback.');
            }
        }

        // Étapes 7-8-9 — décompte crédit + FMI + enregistrement, atomiques (rollback SQL sur refus).
        try {
            $passage = $this->connection->transactional(function () use ($droit, $sens, $espace, $controleur, $equipement, $support, $evt, $enConflit): Passage {
                // Réconciliation gracieuse du crédit épuisé hors-ligne (CA-8, plan-acces-terminal.md
                // §4.2) : le plancher n'est abaissé sous 0 que si `autoriserCreditNegatifSiHorsLigne`
                // est explicitement positionné (jamais par le chemin en ligne, défaut `false` = plancher
                // 0 = comportement strictement inchangé, zéro régression sur /acces/passages et
                // /terminal/passages).
                $enConflitCredit = false;
                if ($droit->getSourceType() === TypeDroitAcces::CarteQuota) {
                    $plancher = $evt->autoriserCreditNegatifSiHorsLigne ? $this->plancherCreditNegatif : 0;
                    if (!$evt->autoriserCreditNegatifSiHorsLigne && ($droit->getCreditRestant() ?? 0) <= 0) {
                        throw new PassageRefuseException(CodeMotifRefus::CreditEpuise, 'Carte épuisée.');
                    }
                    $affectees = (int) $this->connection->executeStatement(
                        'UPDATE acces_droit_acces SET credit_restant = credit_restant - 1 WHERE id = UNHEX(:hex) AND credit_restant > :plancher',
                        ['hex' => bin2hex($droit->getId()->toBinary()), 'plancher' => $plancher],
                    );
                    if ($affectees === 0) {
                        throw new PassageRefuseException(CodeMotifRefus::CreditEpuise, 'Carte épuisée (course concurrente).');
                    }
                    $droit->setCreditRestant(($droit->getCreditRestant() ?? 1) - 1);
                    if ($support instanceof Support) {
                        // Curseur delta snapshot (US-TERM-03/04, §1.4 du plan) : le compostagesRestants
                        // remonté au prochain snapshot doit refléter le solde réel.
                        $support->setVersionMaj($this->sequencer->suivant());
                    }
                    if ($evt->autoriserCreditNegatifSiHorsLigne && ($droit->getCreditRestant() ?? 0) < 0) {
                        $enConflitCredit = true;
                    }
                }

                // Le SGBD reste la source de vérité (UPDATE atomique) ; on mire le résultat sur l'objet
                // en mémoire pour éviter qu'un appelant relisant $jauge via la map d'identité (même
                // EntityManager) n'observe une valeur périmée (le raw SQL contourne le suivi ORM).
                $jauge = $this->em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espace]);
                if ($jauge instanceof JaugeFmi) {
                    $hexJauge = bin2hex($jauge->getId()->toBinary());
                    if ($sens === SensPassage::Entree) {
                        if ($jauge->getMode() === ModeSeuil::Blocage) {
                            $affectees = (int) $this->connection->executeStatement(
                                'UPDATE acces_jauge_fmi SET valeur_courante = valeur_courante + 1, cumul_jour = cumul_jour + 1 WHERE id = UNHEX(:hex) AND valeur_courante < seuil',
                                ['hex' => $hexJauge],
                            );
                            if ($affectees === 0) {
                                throw new PassageRefuseException(CodeMotifRefus::SeuilFmi, 'Seuil de jauge FMI atteint.');
                            }
                            $jauge->setValeurCourante($jauge->getValeurCourante() + 1)->setCumulJour($jauge->getCumulJour() + 1);
                        } else {
                            $this->connection->executeStatement(
                                'UPDATE acces_jauge_fmi SET valeur_courante = valeur_courante + 1, cumul_jour = cumul_jour + 1 WHERE id = UNHEX(:hex)',
                                ['hex' => $hexJauge],
                            );
                            $jauge->setValeurCourante($jauge->getValeurCourante() + 1)->setCumulJour($jauge->getCumulJour() + 1);
                        }
                    } else {
                        $this->connection->executeStatement(
                            'UPDATE acces_jauge_fmi SET valeur_courante = GREATEST(valeur_courante - 1, 0) WHERE id = UNHEX(:hex)',
                            ['hex' => $hexJauge],
                        );
                        $jauge->setValeurCourante(max(0, $jauge->getValeurCourante() - 1));
                    }
                }

                $passage = $this->construirePassage($espace, $controleur, $equipement, $support, $droit, $sens, $evt);
                $passage->setResultat(ResultatPassage::Valide);
                $passage->setEnConflit($enConflit || $enConflitCredit);
                if ($enConflitCredit) {
                    // Dérogation documentée (§4.2 du plan) : `Passage.codeMotif` est en temps normal
                    // réservé aux refus (`refuser()`), positionné ici sur un passage ACCEPTÉ pour
                    // caractériser le litige (Risque R-6, non activé par défaut — cf. flag ci-dessus).
                    $passage->setCodeMotif(CodeMotifRefus::CreditEpuiseHorsLigneLitige);
                    $passage->setMotif('Crédit négatif accepté malgré dépassement (réconciliation hors-ligne, litige tracé, CA-8).');
                } elseif ($enConflit) {
                    $passage->setMotif('Support révoqué après ce passage (conflit détecté au rejeu, RG-ACC-07).');
                }
                $this->em->persist($passage);

                if ($enConflitCredit) {
                    $journal = new JournalReconciliation();
                    $journal->setPassage($passage)
                        ->setDroit($droit)
                        ->setEcart($droit->getCreditRestant() ?? 0)
                        ->setEtablissement($passage->getEtablissement());
                    $this->em->persist($journal);
                }

                $this->em->flush();

                return $passage;
            });
        } catch (PassageRefuseException $e) {
            return $this->refuser($espace, $controleur, $equipement, $support, $droit, $sens, $evt, $e->codeMotif, $e->getMessage());
        }

        $this->pilote->ouvrir($equipement, new OuvertureContexte());

        return $passage;
    }

    private function revoqueApresPassage(Support $support, \DateTimeImmutable $horodatagePassage): bool
    {
        $declaration = $this->em->getRepository(\App\Acces\Entity\DeclarationPerteVol::class)
            ->findOneBy(['support' => $support, 'annulee' => false], ['horodatage' => 'DESC']);

        return $declaration instanceof \App\Acces\Entity\DeclarationPerteVol && $declaration->getHorodatage() > $horodatagePassage;
    }

    private function refuser(
        \App\Acces\Entity\EspaceAcces $espace,
        \App\Acces\Entity\Controleur $controleur,
        Equipement $equipement,
        ?Support $support,
        ?DroitAcces $droit,
        SensPassage $sens,
        EvenementPassageDto $evt,
        CodeMotifRefus $codeMotif,
        string $motif,
    ): Passage {
        $passage = $this->construirePassage($espace, $controleur, $equipement, $support, $droit, $sens, $evt);
        $passage->setResultat(ResultatPassage::Refuse);
        $passage->setCodeMotif($codeMotif);
        $passage->setMotif($motif);
        $this->em->persist($passage);
        $this->em->flush();

        return $passage;
    }

    private function construirePassage(
        \App\Acces\Entity\EspaceAcces $espace,
        \App\Acces\Entity\Controleur $controleur,
        Equipement $equipement,
        ?Support $support,
        ?DroitAcces $droit,
        SensPassage $sens,
        EvenementPassageDto $evt,
    ): Passage {
        $passage = new Passage();
        $passage->setEspace($espace)
            ->setControleur($controleur)
            ->setEquipement($equipement)
            ->setSupport($support)
            ->setDroit($droit)
            ->setSens($sens)
            ->setHorodatage($evt->horodatage)
            ->setOrigineHorsLigne($evt->origineHorsLigne)
            ->setCleIdempotence($evt->cleIdempotence);

        return $passage;
    }
}
