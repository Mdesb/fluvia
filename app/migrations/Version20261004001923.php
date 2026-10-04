<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tunnel d'achat en 3 étapes (#101) : colonnes neuves, et reprise des consentements nés de la case
 * « gestion de commande ».
 *
 * 1. Schéma : la mention d'information et sa version, et l'état final de la case marketing, sur le
 *    panier ; la version du texte, le motif et
 *    le lot d'invalidation sur le consentement ; le réglage « autorisation parentale » du produit.
 *
 * 2. REPRISE. La case obligatoire « j'accepte que mes données soient traitées pour la gestion de ma
 *    commande » créait un `Consentement(Email, Accordé, source=boutique)`, lu par le moteur de
 *    campagnes comme un accord marketing. Une case obligatoire n'est pas un consentement libre (RGPD
 *    art. 7.4) : ces accords ne valent rien, et on les INVALIDE (décision CP-1) — ni « refusé » (la
 *    personne n'a rien refusé), ni « retiré » (elle n'a rien retiré).
 *
 *    `Consentement` est append-only (RG-M4-07) : on n'écrit pas sur la ligne d'origine, qui reste en
 *    base comme preuve de ce qui avait été recueilli. On AJOUTE, pour chacune, une ligne `invalide`
 *    plus récente, qui porte le motif et la référence du lot. L'état courant d'un canal étant sa
 *    dernière ligne, le client n'est plus joignable par campagne — jusqu'à ce qu'il coche la case
 *    marketing, qui ajoutera une ligne plus récente encore.
 *
 *    CIBLAGE EXACT, JAMAIS PAR LA SEULE SOURCE : `source = boutique`, canal e-mail, état accordé, ET le
 *    client est le `client_resolu` d'un panier dont `consentement_rgpd_horodatage` est, à la seconde,
 *    la date de recueil — le seul chemin de création vérifié (`EnregistrerConsentementPanierProcessor`
 *    posait les deux dans le même appel). Un accord marketing réel, recueilli depuis par la case
 *    facultative, n'a pas de tel panier : il n'est pas touché. Et seulement si cet accord est encore
 *    la DERNIÈRE ligne du client sur ce canal : une ligne plus récente (un accord donné ailleurs, un
 *    refus) est l'état courant, et l'invalidation, plus récente encore, la recouvrirait. Mesuré en
 *    préproduction le 04/10 : 8 lignes.
 *
 * down() : supprime exactement les lignes de CE lot (repérées par le motif et la référence), ce qui
 * rend aux 8 clients leur état « accordé » d'origine, puis retire les colonnes.
 */
final class Version20261004001923 extends AbstractMigration
{
    public const BATCH = 'tunnel-3-etapes-101';
    public const REASON = 'recueil non conforme — case de gestion de commande';

    public function getDescription(): string
    {
        return 'Tunnel 3 étapes (#101) : mention d\'information, réglage parental produit, invalidation des consentements de la case « gestion de commande ».';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bou_panier ADD privacy_notice_shown_at DATETIME DEFAULT NULL, ADD privacy_notice_version VARCHAR(40) DEFAULT NULL, ADD marketing_opt_in_version VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE crm_consentement ADD text_version VARCHAR(40) DEFAULT NULL, ADD invalidation_reason VARCHAR(255) DEFAULT NULL, ADD invalidation_batch VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE off_produit ADD parental_consent_required TINYINT DEFAULT 0 NOT NULL');

        // L'instant de l'invalidation, dans le fuseau de l'application (celui où `date_recueil` est
        // écrite) : la ligne doit être PLUS RÉCENTE que celle qu'elle invalide, comparée telle quelle.
        $this->addSql(
            'INSERT INTO crm_consentement (id, client_id, canal, etat, date_recueil, date_expiration, source, recueilli_par_representant, text_version, invalidation_reason, invalidation_batch)
             SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), c.client_id, \'email\', \'invalide\', ?, NULL, \'reprise\', 0, NULL, ?, ?
             FROM crm_consentement c
             WHERE c.source = \'boutique\'
               AND c.canal = \'email\'
               AND c.etat = \'accorde\'
               AND EXISTS (
                   SELECT 1 FROM bou_panier p
                   WHERE p.client_resolu = c.client_id
                     AND p.consentement_rgpd_horodatage = c.date_recueil
               )
               AND NOT EXISTS (
                   SELECT 1 FROM crm_consentement n
                   WHERE n.client_id = c.client_id
                     AND n.canal = c.canal
                     AND n.date_recueil > c.date_recueil
               )',
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), self::REASON, self::BATCH],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM crm_consentement WHERE etat = \'invalide\' AND invalidation_batch = ? AND invalidation_reason = ?',
            [self::BATCH, self::REASON],
        );
        $this->addSql('ALTER TABLE bou_panier DROP privacy_notice_shown_at, DROP privacy_notice_version, DROP marketing_opt_in_version');
        $this->addSql('ALTER TABLE crm_consentement DROP text_version, DROP invalidation_reason, DROP invalidation_batch');
        $this->addSql('ALTER TABLE off_produit DROP parental_consent_required');
    }
}
