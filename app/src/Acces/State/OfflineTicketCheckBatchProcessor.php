<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\Passage;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use App\Acces\Service\VerdictBilletHandler;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Remontée des contrôles manuels faits hors connexion (POST /acces/controle-billet/lot, #338).
 *
 * ⚠ CE QUI S'EST PASSÉ À LA PORTE EST UN FAIT, ET LE REJEU NE LE RÉÉCRIT PAS. L'agent a laissé entrer
 * ou a refusé, sur la foi de sa liste locale. Le serveur enregistre CETTE décision, à l'heure du
 * contrôle, et la confronte à ce qu'il sait maintenant :
 *
 *   - admis hors ligne, valide au serveur   → passage valide, crédit décompté comme en ligne ;
 *   - admis hors ligne, refusé au serveur   → passage valide EN CONFLIT, avec la raison (billet
 *     consommé ailleurs pendant la coupure, crédit épuisé, droit dévalidé) : un responsable le voit,
 *     personne n'est « dé-entré » ;
 *   - refusé hors ligne                     → passage refusé, avec le motif local.
 *
 * Même philosophie que `SynchroPassageHandler` (« sans annulation rétroactive du passage déjà
 * advenu ») et même anti-doublon : une `cleIdempotence` déjà connue est un no-op.
 *
 * Cloisonnement : `VerdictBilletHandler::evaluer(site: établissement actif)` rend « inconnu » un support
 * d'un autre site ; le passage est rattaché à l'établissement ACTIF, jamais à une valeur du corps.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class OfflineTicketCheckBatchProcessor implements ProcessorInterface
{
    private const ATTENTE_VERROU_SECONDES = 10;
    private const DERIVE_HORLOGE_SECONDES = 300;
    /** Au-delà, l'heure du poste n'est pas crue (un poste resté hors ligne une semaine est suspect). */
    private const ANCIENNETE_MAX_JOURS = 7;
    private const TAILLE_MAX_LOT = 2000;

    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly VerdictBilletHandler $verdict,
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Etablissement actif requis (en-tete X-Etablissement) : un controle est rattache a un site.');
        }

        $corps = $this->lecteur->corps();
        $controles = array_values(array_filter(\is_array($corps['controles'] ?? null) ? $corps['controles'] : [], '\is_array'));
        if (\count($controles) > self::TAILLE_MAX_LOT) {
            throw new UnprocessableEntityHttpException(sprintf('Lot trop grand (%d au plus) : le poste découpe.', self::TAILLE_MAX_LOT));
        }

        // Rejeu chronologique : l'ordre décide qui a consommé une entrée unique en premier.
        usort($controles, static fn (array $a, array $b): int => strcmp((string) ($a['horodatage'] ?? ''), (string) ($b['horodatage'] ?? '')));

        // Deux remontées simultanées du même lot heurteraient la contrainte d'unicité de la clé, et un
        // gestionnaire d'entités fermé ferait échouer tout le reste. Verrou par site, en base.
        $verrou = 'fluvia_controle_hl_' . $etablissement->getId()->toRfc4122();
        $connexion = $this->em->getConnection();
        if ((int) $connexion->fetchOne('SELECT GET_LOCK(?, ?)', [$verrou, self::ATTENTE_VERROU_SECONDES]) !== 1) {
            throw new ConflictHttpException('Une remontée de contrôles est déjà en cours pour ce site : réessayez dans un instant.');
        }

        try {
            $resultats = [];
            foreach ($controles as $controle) {
                $resultats[] = $this->rejouer($controle, $etablissement);
            }
        } finally {
            $connexion->fetchOne('SELECT RELEASE_LOCK(?)', [$verrou]);
        }

        return new JsonResponse(['recus' => \count($controles), 'resultats' => $resultats], JsonResponse::HTTP_OK);
    }

    /**
     * @param array<string, mixed> $controle
     *
     * @return array{cleIdempotence: string, statut: string, enConflit: bool, motif?: string}
     */
    private function rejouer(array $controle, Etablissement $etablissement): array
    {
        $cleBrute = $controle['cleIdempotence'] ?? null;
        $cle = \is_string($cleBrute) && Uuid::isValid($cleBrute) ? Uuid::fromString($cleBrute) : null;
        if ($cle === null) {
            // Une clé absente ou illisible n'est jamais remplacée par une neuve : le prochain envoi du
            // même lot créerait un second passage (défaut mesuré le 31/08 sur `SynchroPassageHandler`).
            return ['cleIdempotence' => \is_string($cleBrute) ? mb_substr($cleBrute, 0, 64) : '', 'statut' => 'rejete', 'enConflit' => false, 'motif' => 'cle_idempotence_invalide'];
        }

        $existant = $this->em->getRepository(Passage::class)->findOneBy(['cleIdempotence' => $cle]);
        // Une clé déjà portée par un passage d'un AUTRE site : refusée sans dire ce qu'est ce passage
        // (son conflit éventuel renseignerait sur un site tiers).
        if ($existant instanceof Passage && (string) $existant->getEtablissement()?->getId() !== (string) $etablissement->getId()) {
            return ['cleIdempotence' => (string) $cle, 'statut' => 'rejete', 'enConflit' => false, 'motif' => 'cle_deja_utilisee'];
        }
        if ($existant instanceof Passage) {
            return ['cleIdempotence' => (string) $cle, 'statut' => 'doublon', 'enConflit' => $existant->isEnConflit()];
        }

        $identifiant = \is_string($controle['identifiantSupport'] ?? null) ? trim($controle['identifiantSupport']) : '';
        if ($identifiant === '') {
            return ['cleIdempotence' => (string) $cle, 'statut' => 'rejete', 'enConflit' => false, 'motif' => 'identifiant_manquant'];
        }

        [$horodatage, $heureEcartee] = $this->horodatage($controle['horodatage'] ?? null);
        $admisLocalement = ($controle['verdictLocal'] ?? null) === 'valide';

        $verdict = $this->verdict->evaluer($identifiant, ignorerRevocationSiPosterieure: true, horodatage: $horodatage, site: $etablissement);
        $droit = $verdict->droit;

        $passage = (new Passage())
            ->setHorodatage($horodatage)
            ->setEspace(null)
            ->setEtablissement($etablissement)
            ->setSupport($verdict->support)
            ->setDroit($droit)
            ->setSens(SensPassage::Entree)
            ->setCleIdempotence($cle)
            ->setOrigineHorsLigne(true)
            ->setAgent(($agent = $this->security->getUser()) instanceof Utilisateur ? $agent : null);

        $conflit = null;
        if (!$admisLocalement) {
            $passage->setResultat(ResultatPassage::Refuse)
                ->setCodeMotif($this->motifLocal($controle['motifLocal'] ?? null))
                ->setMotif('Refuse hors ligne.');
        } else {
            if (!$verdict->valide) {
                $conflit = 'refuse au rejeu : ' . $verdict->message;
            } elseif ($droit !== null) {
                $credit = $droit->getCreditRestant();
                if ($credit === null) {
                    if ($this->dejaControle($verdict->support?->getId(), $horodatage)) {
                        $conflit = 'billet deja controle avant ce passage';
                    }
                } elseif ($credit <= 0) {
                    $conflit = 'credit epuise au rejeu';
                } else {
                    $droit->setCreditRestant($credit - 1);
                }
            }
            if ($verdict->enConflitRevocation) {
                $conflit ??= 'support bloque apres le passage';
            }
            // Le passage a eu lieu : il est VALIDE, et le désaccord est porté par `enConflit`.
            $passage->setResultat(ResultatPassage::Valide)
                ->setMotif($conflit === null ? 'Controle manuel hors ligne.' : 'Controle manuel hors ligne — conflit : ' . $conflit);
        }
        if ($heureEcartee) {
            $passage->setMotif($passage->getMotif() . ' Heure du poste ecartee.');
        }
        $passage->setEnConflit($conflit !== null);

        $this->em->persist($passage);
        $this->em->flush();

        $retour = ['cleIdempotence' => (string) $cle, 'statut' => 'accepte', 'enConflit' => $conflit !== null];
        if ($conflit !== null) {
            $retour['motif'] = $conflit;
        }

        return $retour;
    }

    /**
     * L'heure du contrôle, telle que le poste l'a notée — bornée, comme pour la caisse.
     *
     * @return array{0: \DateTimeImmutable, 1: bool} l'heure retenue, et si celle du poste a été écartée
     */
    private function horodatage(mixed $brut): array
    {
        $maintenant = new \DateTimeImmutable();
        if (!\is_string($brut) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $brut) !== 1) {
            return [$maintenant, true];
        }
        try {
            $heure = (new \DateTimeImmutable($brut))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return [$maintenant, true];
        }
        $plusTot = $maintenant->modify(sprintf('-%d days', self::ANCIENNETE_MAX_JOURS));
        $plusTard = $maintenant->modify(sprintf('+%d seconds', self::DERIVE_HORLOGE_SECONDES));
        if ($heure < $plusTot || $heure > $plusTard) {
            return [$maintenant, true];
        }

        return [$heure, false];
    }

    /** Une entrée unique déjà validée AVANT ce passage (en ligne ou par un autre poste). */
    private function dejaControle(?Uuid $supportId, \DateTimeImmutable $avant): bool
    {
        if (!$supportId instanceof Uuid) {
            return false;
        }
        foreach ($this->em->getRepository(Passage::class)->findBy(['support' => $supportId, 'resultat' => ResultatPassage::Valide]) as $precedent) {
            if ($precedent->getHorodatage() <= $avant) {
                return true;
            }
        }

        return false;
    }

    private function motifLocal(mixed $brut): CodeMotifRefus
    {
        return match ($brut) {
            'deja_consomme' => CodeMotifRefus::DejaConsomme,
            'credit_epuise' => CodeMotifRefus::CreditEpuise,
            default => CodeMotifRefus::DroitInvalide,
        };
    }
}
