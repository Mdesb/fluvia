<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'IDENTITÉ LÉGALE DU VENDEUR — sans laquelle aucune facture n'est émettable au format européen.
 *
 * EN 16931 exige six termes côté vendeur. `ProfilExploitant` ne portait qu'un SIREN (BT-30), et
 * `Etablissement` aucune adresse. Mesuré le 31/08 : **0 facture émettable sur 2**.
 *
 *     raison_sociale            BT-27
 *     tva_intracommunautaire    BT-31
 *     adresse                   BT-35 / BT-37 / BT-38 / BT-40
 *
 * ── POURQUOI SUR LE PROFIL ET PAS SUR L'ÉTABLISSEMENT ────────────────────────────────────────────
 *
 * BT-35..40 est l'adresse de l'**entité légale** qui facture, pas du site où la prestation a lieu.
 * Le modèle le dit déjà : `ProfilExploitant` porte `etablissementPrincipal` **et** une collection
 * `etablissementsRattaches` — un profil couvre plusieurs sites par construction (mesuré par `8e` en
 * écrivant `ProfilExploitant::couvre()`). L'entité légale est donc au-dessus des sites, et l'adresse
 * va là où vit le SIREN.
 *
 * ⚠ **LE CAS QUI FERAIT CHANGER D'AVIS, ÉCARTÉ EXPLICITEMENT.** Un groupe qui facturerait au nom de
 * chaque site — adresses différentes selon le site sur la facture — voudrait l'adresse sur
 * `Etablissement`. Personne ne sait si ce cas existe chez un client. La décision est prise ici parce
 * qu'elle bloque tout le reste, et parce qu'**elle est bon marché à réviser** : le jour où ce cas
 * apparaît, on ajoute une adresse *optionnelle* sur `Etablissement` qui prime quand elle est
 * renseignée. C'est additif — aucune donnée à migrer, aucune colonne à retirer.
 *
 * ── POURQUOI TROIS ÉNONCÉS ET PAS UN ─────────────────────────────────────────────────────────────
 *
 * ⚠ `ADD adresse JSON NOT NULL` sur une table **peuplée** (6 profils) n'a pas de valeur à écrire
 * dans les lignes existantes. La table jumelle `facturation_destinataire` porte la même colonne en
 * NOT NULL, mais elle a été **créée** ainsi : aucune ligne n'existait. Ce n'est pas le même geste.
 *
 * On ajoute donc nullable, on remplit, on resserre. Le `'[]'` n'est pas de la donnée métier au sens
 * de D66-ter — c'est l'état vide d'une colonne structurelle, l'équivalent d'un défaut. Une migration
 * ne fabrique pas d'adresse ; elle écrit « pas d'adresse ».
 *
 * DDL relevé par `doctrine:schema:update --dump-sql` sur le mapping (D32), puis **réduit à ces trois
 * colonnes** : le relevé brut emportait la dérive d'autres sessions (`accounting_hidden_legal_vat_rate`,
 * `taux_tva_par_defaut_id`) qui ne m'appartient pas.
 */
final class Version20260831180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le vendeur porte son identité légale : raison sociale, TVA intracommunautaire, adresse du siège (EN 16931).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_profil_exploitant '
            . 'ADD raison_sociale VARCHAR(200) DEFAULT NULL, '
            . 'ADD tva_intracommunautaire VARCHAR(20) DEFAULT NULL, '
            . 'ADD adresse JSON DEFAULT NULL');

        // Les profils existants n'ont pas d'adresse — on écrit « pas d'adresse », pas une adresse.
        $this->addSql("UPDATE compta_profil_exploitant SET adresse = '[]' WHERE adresse IS NULL");

        $this->addSql('ALTER TABLE compta_profil_exploitant MODIFY adresse JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_profil_exploitant '
            . 'DROP raison_sociale, '
            . 'DROP tva_intracommunautaire, '
            . 'DROP adresse');
    }
}
