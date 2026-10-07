<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\Support;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Fait avancer la version du snapshot de TOUS les supports appairés à un droit modifié en SQL direct.
 *
 * Les décomptes et recharges de crédit s'écrivent par un `UPDATE` conditionnel (jamais de
 * lire-modifier-écrire, voir `ValidationPassageHandler`), puis rechargent le droit par `refresh()` :
 * Doctrine ne voit donc aucun changement, et `AccessProjectionVersionListener` ne peut rien pour eux.
 * Ces chemins appellent ce service dans LA MÊME transaction que leur `UPDATE`.
 *
 * ⚠ TOUS LES SUPPORTS, PAS « LE » SUPPORT. Avant ce service, chacun de ces chemins faisait avancer un
 * seul support : celui qu'il venait de scanner, ou celui que rendait un `findOneBy()` sans ordre sur
 * une clé UUID — l'un ou l'autre selon le tirage. Un même droit peut être porté par un QR et par un
 * badge (un appairage actif PAR SUPPORT, pas par droit) : le second gardait l'ancien solde sur la
 * borne.
 *
 * L'`updated_at` du DROIT est posé par l'`UPDATE` du chemin lui-même (même requête que le crédit) ;
 * celui des supports, ici.
 */
final class SnapshotVersionBumper
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly VersionSnapshotSequencer $sequencer,
    ) {
    }

    /** @return int|null la version posée, ou null si aucun support n'est appairé au droit */
    public function bumpPairedSupports(Uuid $rightId): ?int
    {
        $hex = bin2hex($rightId->toBinary());
        /** @var list<string> $supportIds identifiants en hexadécimal */
        $supportIds = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(support_id)) FROM acces_appairage WHERE droit_id = UNHEX(:hex) AND actif = 1',
            ['hex' => $hex],
        );
        if ($supportIds === []) {
            return null;
        }

        $version = $this->sequencer->suivant();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->connection->executeStatement(
            'UPDATE acces_support s JOIN acces_appairage a ON a.support_id = s.id AND a.actif = 1 '
            . 'SET s.version_maj = :v, s.updated_at = :at WHERE a.droit_id = UNHEX(:hex)',
            ['v' => $version, 'at' => $now->format('Y-m-d H:i:s'), 'hex' => $hex],
        );

        // Mirage des supports déjà chargés : valeur ET instantané Doctrine, pour qu'un `flush()`
        // ultérieur n'y voie ni un changement à réécrire, ni un retour à l'ancienne version.
        $touches = array_flip($supportIds);
        $uow = $this->em->getUnitOfWork();
        foreach ($uow->getIdentityMap()[Support::class] ?? [] as $support) {
            if ($support instanceof Support && isset($touches[bin2hex($support->getId()->toBinary())])) {
                $support->setVersionMaj($version)->setUpdatedAt($now);
                $uow->setOriginalEntityProperty(spl_object_id($support), 'versionMaj', $version);
                $uow->setOriginalEntityProperty(spl_object_id($support), 'updatedAt', $now);
            }
        }

        return $version;
    }
}
