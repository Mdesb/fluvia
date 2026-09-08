<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `vente_ligne` : le taux de TVA gravé sur la ligne, comme l'est déjà le libellé du produit.
 *
 * Le module Vente ne connaissait la TVA sous aucune forme — vérifié par un grep insensible à la
 * casse sur tout `app/src/Vente`, dont les seules occurrences étaient des faux positifs
 * (`impac**tVa**leur`). Or la ventilation par taux est exigée deux fois : par NF525, et par le
 * régime de la facture simplifiée qui fait qu'un ticket de moins de 150 € HT sert de justificatif.
 * Sans elle, aucun rendu papier ne peut être conforme, quel que soit son format.
 *
 * ⚠ **Gravé, jamais relu du catalogue.** Un taux légal change — la restauration est passée de 19,6 à
 * 5,5 puis à 10. Recalculer la ventilation d'une vente ancienne depuis le catalogue d'aujourd'hui
 * ferait mentir rétroactivement tous les tickets déjà émis, et un duplicata tiré six mois plus tard
 * annoncerait une TVA qui n'a jamais été collectée. C'est mot pour mot l'argument de
 * `LigneVente::$libelleProduit`, et c'est pour ça que le taux est gravé au même endroit et par le
 * même mécanisme (`LineLabelStamper`, prePersist) : six appelants construisent une ligne et aucun ne
 * dispose de l'entité `Produit`.
 *
 * Nullable, et le rester : `Produit::$tauxTva` l'est aussi, et presque aucun produit ne le renseigne
 * aujourd'hui. Un `NOT NULL DEFAULT '20.00'` aurait rempli l'historique d'un taux que personne n'a
 * choisi. Une ventilation incomplète se voit et se corrige ; une ventilation fausse ne se voit pas.
 *
 * `DECIMAL(5,2)` — la forme exacte de `off_produit.taux_tva`, pour que la copie ne perde rien.
 *
 * Écrite à la main d'après le mapping, jamais par `doctrine:migrations:diff`.
 */
final class Version20260908094500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'vente_ligne : taux_tva grave a la creation, copie de off_produit.taux_tva.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente_ligne ADD taux_tva NUMERIC(5, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente_ligne DROP taux_tva');
    }
}
