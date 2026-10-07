<?php

declare(strict_types=1);

namespace App\Marketing\Service;

use App\Marketing\Entity\LoyaltyEntry;
use App\Marketing\Entity\Referral;
use App\Marketing\Entity\ReferralCode;
use App\Marketing\Entity\ReferralProgram;
use App\Marketing\Enum\LoyaltyMovement;
use App\Organisation\Entity\Etablissement;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * LE PARRAINAGE — trois refus, et c'est eux qui font le programme.
 *
 *   1. **on ne se parraine pas soi-même** ;
 *   2. **on ne parraine pas quelqu'un qui achète déjà** — ce n'est pas un parrainage, c'est une
 *      remise offerte à un client acquis ;
 *   3. **on ne récompense pas une inscription, seulement un achat encaissé.**
 *
 * Sans le troisième, un programme de parrainage devient une usine à faux comptes en quelques jours,
 * et les points sont déjà versés quand on s'en aperçoit.
 *
 * > **On récompense ce qui a été encaissé, jamais ce qui a été déclaré.**
 */
final readonly class ReferralService
{
    private const VENTE_RETENUE = 'validee';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Le code du parrain — créé au premier appel.
     *
     * Demander son code EST le geste qui l'attribue : un écran qui afficherait « aucun code » sans
     * rien pour en obtenir un serait une impasse.
     */
    public function codeDe(Uuid $parrain, Etablissement $etablissement): ReferralCode
    {
        $existant = $this->entityManager->getRepository(ReferralCode::class)->createQueryBuilder('rc')
            ->andWhere('IDENTITY(rc.establishment) = :etab')
            ->andWhere('rc.sponsorRef = :parrain')
            // ⚠ D58 vaut aussi pour l'association : passer l'ENTITÉ lie son identifiant sans son
            // type `uuid`, la recherche ne trouve rien, et on recrée un code à chaque appel jusqu'à
            // ce que la contrainte d'unicité tranche.
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            // ⚠ D58 — sans le type, la comparaison ne trouve RIEN et ne lève pas : on créerait un
            // second code à chaque appel, jusqu'à ce que la contrainte d'unicité tranche.
            ->setParameter('parrain', $parrain, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        if ($existant instanceof ReferralCode) {
            return $existant;
        }

        $code = (new ReferralCode())
            ->setEstablishment($etablissement)
            ->setSponsorRef($parrain)
            ->setCode($this->codeLibre($etablissement));

        $this->entityManager->persist($code);
        $this->entityManager->flush();

        return $code;
    }

    /** Déclare le lien. Les trois refus sont ici, et le quatrième est tenu par la base. */
    public function declarer(string $code, Uuid $filleul, Etablissement $etablissement): Referral
    {
        $porteur = $this->entityManager->getRepository(ReferralCode::class)
            ->findOneBy(['establishment' => $etablissement, 'code' => strtoupper(trim($code))]);

        if (!$porteur instanceof ReferralCode) {
            throw new UnprocessableEntityHttpException('Ce code de parrainage n’existe pas.');
        }

        $parrain = $porteur->getSponsorRef();
        if ($parrain === null || $parrain->equals($filleul)) {
            throw new UnprocessableEntityHttpException('On ne se parraine pas soi-même.');
        }

        // Un client qui achetait DÉJÀ n'est pas un filleul : le parrainage paierait une relation
        // qui existait avant lui.
        //
        // « Déjà » se compte au jour, pas à la seconde. Au comptoir, l'agent déclare le parrainage
        // et encaisse dans la même minute — souvent dans le désordre. Une borne à la seconde
        // refuserait la moitié des parrainages selon l'ordre des gestes, ce qu'aucun exploitant ne
        // saurait expliquer à son client.
        if ($this->centimes($this->totalAchete($filleul, $etablissement, null, $this->debutDuJour($etablissement))) > 0) {
            throw new UnprocessableEntityHttpException(
                'Cette personne est déjà cliente : un parrainage récompense une NOUVELLE relation.',
            );
        }

        // Le doublon est refusé par la contrainte d'unicité, pas par une lecture préalable : deux
        // requêtes simultanées passeraient toutes deux un `findOneBy` avant que l'une n'écrive.
        $parrainage = (new Referral())
            ->setEstablishment($etablissement)
            ->setSponsorRef($parrain)
            ->setRefereeRef($filleul)
            ->setCode($porteur->getCode());

        try {
            $this->entityManager->persist($parrainage);
            $this->entityManager->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            throw new ConflictHttpException('Cette personne a déjà été parrainée : une seule fois, jamais deux.');
        }

        return $parrainage;
    }

    /**
     * L'état, calculé — jamais lu en base.
     *
     * @return array{etat: string, libelle: string, achatDepuisLeParrainage: string}
     */
    public function etat(Referral $parrainage): array
    {
        if ($parrainage->getRewardedAt() !== null) {
            return [
                'etat' => 'recompense',
                'libelle' => 'Récompensé',
                'achatDepuisLeParrainage' => '0.00',
            ];
        }

        $etablissement = $parrainage->getEstablishment();
        $filleul = $parrainage->getRefereeRef();
        if ($etablissement === null || $filleul === null) {
            return ['etat' => 'en_attente', 'libelle' => 'En attente', 'achatDepuisLeParrainage' => '0.00'];
        }

        // Depuis le DÉBUT DU JOUR du parrainage : l'achat conclu dans la foulée compte, et c'est
        // le cas le plus courant — le filleul donne le code au comptoir, puis paie.
        $achat = $this->totalAchete(
            $filleul,
            $etablissement,
            $this->debutDuJour($etablissement, $parrainage->getCreatedAt()),
        );
        $programme = $this->programme($etablissement);
        $seuil = $programme?->getMinimumPurchase() ?? '0.00';

        $eligible = $programme !== null
            && $programme->isEnabled()
            && $this->centimes($achat) >= $this->centimes($seuil)
            && $this->centimes($achat) > 0;

        return [
            'etat' => $eligible ? 'eligible' : 'en_attente',
            'libelle' => $eligible ? 'À récompenser' : 'En attente du premier achat',
            'achatDepuisLeParrainage' => $achat,
        ];
    }

    /** Verse la récompense — une seule fois, et seulement si le filleul a acheté. */
    public function recompenser(Referral $parrainage): LoyaltyEntry
    {
        if ($parrainage->getRewardedAt() !== null) {
            throw new ConflictHttpException('Ce parrainage a déjà été récompensé.');
        }

        $etat = $this->etat($parrainage);
        if ($etat['etat'] !== 'eligible') {
            throw new UnprocessableEntityHttpException(
                'Le filleul n’a pas encore fait d’achat suffisant : rien à récompenser.',
            );
        }

        $etablissement = $parrainage->getEstablishment();
        $programme = $etablissement === null ? null : $this->programme($etablissement);
        if ($etablissement === null || $programme === null) {
            throw new UnprocessableEntityHttpException('Aucun programme de parrainage n’est défini.');
        }

        $points = $programme->getRewardPoints();

        // La récompense arrive dans le registre de fidélité comme n'importe quel geste : même
        // solde, même historique, même motif lisible. Un second compteur « points de parrainage »
        // obligerait le client à comprendre deux monnaies.
        $ecriture = (new LoyaltyEntry())
            ->setEstablishment($etablissement)
            ->setCustomerRef($parrainage->getSponsorRef())
            ->setPoints($points)
            ->setMovement(LoyaltyMovement::Ajustement)
            ->setReason('Parrainage — code ' . $parrainage->getCode());

        $this->entityManager->persist($ecriture);

        // Figé au versement : changer le programme demain ne réécrit pas ce qu'on a payé hier.
        $parrainage->marquerRecompense($points, new \DateTimeImmutable());

        $this->entityManager->flush();

        return $ecriture;
    }

    private function programme(Etablissement $etablissement): ?ReferralProgram
    {
        return $this->entityManager->getRepository(ReferralProgram::class)
            ->findOneBy(['establishment' => $etablissement]);
    }

    /**
     * Minuit à l'établissement, en heure du serveur — celui du jour donné, ou celui d'aujourd'hui.
     *
     * ⚠ PAS `setTime(0, 0)`, QUI DONNE MINUIT UTC. Le jour du parrainage commençait à 01:00 ou 02:00
     * à Paris : un achat de 00:30 comptait comme « d'avant » et refusait un vrai filleul (mesuré le
     * 07/10/2026, `ReferralTest`).
     */
    private function debutDuJour(Etablissement $etablissement, ?\DateTimeImmutable $quand = null): \DateTimeImmutable
    {
        return Etablissement::debutDuJour($etablissement, Etablissement::jourCivil($etablissement, $quand));
    }

    private function totalAchete(
        Uuid $client,
        Etablissement $etablissement,
        ?\DateTimeImmutable $depuis,
        ?\DateTimeImmutable $avant = null,
    ): string {
        $sql = 'SELECT COALESCE(SUM(total), 0) AS total FROM vente_vente '
            . 'WHERE client = ? AND etablissement_id = ? AND statut = ?';
        $parametres = [$client->toBinary(), $etablissement->getId()?->toBinary(), self::VENTE_RETENUE];
        $types = [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING];

        if ($depuis !== null) {
            // Borne basse INCLUSIVE : la journée du parrainage compte en entier.
            $sql .= ' AND date >= ?';
            $parametres[] = $depuis->format('Y-m-d H:i:s');
            $types[] = ParameterType::STRING;
        }

        if ($avant !== null) {
            // Borne haute EXCLUSIVE : « avant aujourd'hui » n'inclut pas aujourd'hui.
            $sql .= ' AND date < ?';
            $parametres[] = $avant->format('Y-m-d H:i:s');
            $types[] = ParameterType::STRING;
        }

        return (string) $this->entityManager->getConnection()
            ->executeQuery($sql, $parametres, $types)
            ->fetchOne();
    }

    /**
     * Un code encore libre.
     *
     * La collision est improbable — 32^8 possibilités — mais « improbable » n'est pas « impossible »,
     * et la contrainte d'unicité transformerait une collision en erreur 500 devant un client.
     */
    private function codeLibre(Etablissement $etablissement): string
    {
        $depot = $this->entityManager->getRepository(ReferralCode::class);

        for ($essai = 0; $essai < 10; ++$essai) {
            $code = ReferralCode::tirer();
            if ($depot->findOneBy(['establishment' => $etablissement, 'code' => $code]) === null) {
                return $code;
            }
        }

        throw new \RuntimeException('Impossible de tirer un code de parrainage libre après dix essais.');
    }

    /** bcmath n'est pas installé sur l'image PHP du projet : on compte en centimes. */
    private function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
