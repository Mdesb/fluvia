<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Terminal de paiement, lot 1 (23/10/2026, spec §6, CA-7) : `caisse_point_de_vente.tpe` devient
 * `{ connecteur, …paramètres }`, et TOUS les points de vente existants passent au terminal EXTERNE —
 * celui que le caissier déclare, sans simulation ni variable d'environnement.
 *
 * Ce qu'on trouve avant, et ce que ça devient (rien n'est perdu, la descente le rend à l'identique) :
 * - NULL → `{connecteur: externe}` ;
 * - une liste `[{marque, ref}, …]` → `{connecteur: externe, marque, ref}` (du premier) et la liste
 *   d'origine sous `terminaux` ;
 * - toute autre valeur — le booléen qu'écrivait l'écran Paramètres — sous `ancien` ;
 * - une valeur déjà structurée (porte `connecteur`) ne bouge pas.
 *
 * La descente rend `terminaux`, sinon `ancien`, sinon `[{marque, ref}]` s'ils existent, sinon NULL.
 *
 * Aucun changement de schéma : la lecture se fait à l'appel de `up()`/`down()`, avant les écritures.
 * Écrite à la main, horodatée en UTC, jouée dans les deux sens sur une copie de la sauvegarde de préprod.
 */
final class Version20261023130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Points de vente : terminal de paiement structuré, connecteur externe par défaut';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT LOWER(HEX(id)) id, tpe FROM caisse_point_de_vente') as $ligne) {
            $avant = $ligne['tpe'] === null ? null : json_decode((string) $ligne['tpe'], true);
            if (\is_array($avant) && !array_is_list($avant) && isset($avant['connecteur'])) {
                continue;
            }
            $apres = ['connecteur' => 'externe'];
            if (\is_array($avant) && array_is_list($avant)) {
                $premier = \is_array($avant[0] ?? null) ? $avant[0] : [];
                foreach (['marque', 'ref'] as $cle) {
                    if (isset($premier[$cle]) && \is_string($premier[$cle])) {
                        $apres[$cle] = $premier[$cle];
                    }
                }
                $apres['terminaux'] = $avant;
            } elseif ($ligne['tpe'] !== null) {
                $apres['ancien'] = $avant;
            }
            $this->ecrire($ligne['id'], $apres);
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT LOWER(HEX(id)) id, tpe FROM caisse_point_de_vente WHERE tpe IS NOT NULL') as $ligne) {
            $reglage = json_decode((string) $ligne['tpe'], true);
            if (!\is_array($reglage) || array_is_list($reglage)) {
                continue;
            }
            if (\array_key_exists('terminaux', $reglage)) {
                $avant = $reglage['terminaux'];
            } elseif (\array_key_exists('ancien', $reglage)) {
                $avant = $reglage['ancien'];
            } elseif (isset($reglage['marque']) || isset($reglage['ref'])) {
                $avant = [array_filter(['marque' => $reglage['marque'] ?? null, 'ref' => $reglage['ref'] ?? null], static fn ($v): bool => $v !== null)];
            } else {
                $avant = null;
            }
            $this->ecrire($ligne['id'], $avant);
        }
    }

    private function ecrire(string $id, mixed $tpe): void
    {
        $this->addSql('UPDATE caisse_point_de_vente SET tpe = :tpe WHERE id = UNHEX(:id)', [
            'tpe' => $tpe === null ? null : json_encode($tpe, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            'id' => $id,
        ]);
    }
}
