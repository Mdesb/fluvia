<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'instantané scellé, conservé plutôt que reconstruit — factures et écritures comptables.
 *
 * ── LE DÉFAUT QUE ÇA FERME ──────────────────────────────────────────────────────────────────────
 *
 * Trois chaînes de scellement NF525, et une seule faisait bien. `nf525_operation_scellee` STOCKE son
 * payload canonique : la vérification recalcule l'empreinte depuis lui, donc elle ne peut pas
 * dériver.
 *
 * Les deux autres reconstruisaient le payload depuis les entités VIVANTES à chaque vérification :
 *
 *     ScellementFactureHandler   'taux' => $ligne->getTauxTvaValeur()
 *     LigneFacture               return $this->tauxTva?->getTaux()      ← VIVANT
 *     TauxTva::$taux             exposé en PATCH, groupe `taux:write`
 *
 * Un exploitant qui corrige un taux — geste légitime, un décret change les taux — faisait dériver
 * l'empreinte recalculée de CHAQUE document scellé avec ce taux. Le logiciel répondait alors
 * « la donnée a été altérée », c'est-à-dire qu'il accusait son utilisateur de falsification pour un
 * geste qu'il l'autorise lui-même à faire.
 *
 * ⚠ Et le payload lit aussi `getDestinataire()?->denomination()`. Corriger un destinataire mal typé
 * — une école enregistrée en « particulier », relevé par allaccess-34 — casserait de même l'empreinte
 * de toutes ses factures. Deux problèmes, un seul remède.
 *
 * ── NULLABLE, ET C'EST LA PARTIE QUI COMPTE ─────────────────────────────────────────────────────
 *
 * `NULL` ne veut pas dire « vide » : il veut dire **scellé avant que l'instantané ne soit conservé**.
 * C'est ce qui permet à la vérification de distinguer deux choses que l'ancien code confondait :
 *
 *     instantané stocké, empreinte différente   → la donnée A ÉTÉ ALTÉRÉE, on peut l'affirmer
 *     instantané absent,  empreinte différente  → ON NE PEUT PAS CONCLURE, et il faut le dire
 *
 * ── LES LIGNES EXISTANTES RESTENT NULLES ────────────────────────────────────────────────────────
 *
 * Cette migration ne remplit rien. Le rattrapage est fait par `nf525:reprendre-instantanes`, et il ne
 * remplit QUE les documents dont l'empreinte se vérifie encore au moment du passage — auquel cas
 * l'instantané recalculé est *prouvé* être celui d'origine, la coïncidence étant la preuve. Les
 * autres restent nuls et se déclarent non vérifiables.
 *
 * Remplir ici, en SQL, obligerait à réécrire la canonicalisation une seconde fois — et une règle
 * recopiée diverge au premier correctif. Arbitré par Maxime le 31/08.
 *
 * Aucune donnée n'est détruite ni réécrite : deux colonnes ajoutées, nulles partout.
 */
final class Version20260831003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Instantané canonique conservé au scellement (factures, écritures) — NULL = scellé avant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facturation_facture ADD payload_canonique JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE compta_ecriture_comptable ADD payload_canonique JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_ecriture_comptable DROP payload_canonique');
        $this->addSql('ALTER TABLE facturation_facture DROP payload_canonique');
    }
}
