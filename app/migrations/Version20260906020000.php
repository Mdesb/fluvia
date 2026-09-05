<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ALIGNE LES TROIS TABLES DE L'API PUBLIQUE SUR CE QUE LE MAPPING PRODUIT.
 *
 * ── COMMENT L'ECART A ETE TROUVE ────────────────────────────────────────────────────────────────
 *
 * `Version20260906010000` a ete ecrite a la main — c'est la regle ici, un `migrations:diff` ratisse
 * la derive de toutes les sessions ouvertes. Ecrire a la main veut dire pouvoir se tromper : apres
 * l'avoir jouee, `doctrine:schema:update --dump-sql | grep public_api` rendait ONZE lignes.
 *
 * ⚠ **LE VERDICT GLOBAL DE `schema:validate` NE POUVAIT PAS SERVIR** : il etait deja rouge avant
 * moi, pour `App\Stock\Entity\ArticleStock`. Un instrument qui dit « rouge » sur un depot deja
 * rouge ne dit rien de ce qu'on vient d'ajouter. Il a fallu isoler mes tables au `grep`.
 *
 * ── SIX ECARTS SUR ONZE VENAIENT D'UNE VRAIE OMISSION, DEJA CORRIGEE ────────────────────────────
 *
 * `ON DELETE RESTRICT/CASCADE/SET NULL` vivait dans la migration et nulle part ailleurs. Doctrine,
 * qui ne lit que le mapping, proposait donc de l'effacer. Les `onDelete` sont passes dans les
 * entites — c'est la qu'on lit ce qui arrive quand on supprime, pas dans un fichier horodate.
 *
 * ── LES CINQ QUI RESTENT, ET POURQUOI ILS COMPTENT QUAND MEME ───────────────────────────────────
 *
 * Ils sont cosmetiques : un commentaire `(DC2Type:datetime_immutable)` que DBAL n'attend plus, et
 * deux index nes de mes noms de contrainte ecrits a la main la ou le depot en compte 582 a la
 * convention de Doctrine.
 *
 * ⚠ **UN ECART COSMETIQUE PERMANENT N'EST PAS SANS COUT.** Il laisse cinq lignes dans chaque
 * `schema:update --dump-sql` a venir, pour toujours. La prochaine personne qui cherchera une vraie
 * divergence les lira, les jugera inoffensives, et prendra l'habitude de sauter ce que la commande
 * affiche. C'est ainsi qu'un instrument cesse d'etre lu.
 *
 * Aucune donnee touchee : des types identiques, un commentaire retire, deux index renommes.
 */
final class Version20260906020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'API publique : aligner le DDL sur le mapping (commentaires DC2Type, noms d index).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_api_application CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE public_api_credential CHANGE issued_at issued_at DATETIME NOT NULL, CHANGE expires_at expires_at DATETIME DEFAULT NULL, CHANGE last_used_at last_used_at DATETIME DEFAULT NULL, CHANGE revoked_at revoked_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE public_api_grant CHANGE granted_at granted_at DATETIME NOT NULL, CHANGE revoked_at revoked_at DATETIME DEFAULT NULL');

        $this->addSql('ALTER TABLE public_api_credential RENAME INDEX fk_public_api_credential_revoked_by TO IDX_A22ECC6AFB8FE773');
        $this->addSql('ALTER TABLE public_api_grant RENAME INDEX fk_public_api_grant_granted_by TO IDX_C0B7E91D3151C11F');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_api_grant RENAME INDEX IDX_C0B7E91D3151C11F TO fk_public_api_grant_granted_by');
        $this->addSql('ALTER TABLE public_api_credential RENAME INDEX IDX_A22ECC6AFB8FE773 TO fk_public_api_credential_revoked_by');

        $this->addSql("ALTER TABLE public_api_grant CHANGE granted_at granted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE revoked_at revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("ALTER TABLE public_api_credential CHANGE issued_at issued_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE expires_at expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE last_used_at last_used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE revoked_at revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("ALTER TABLE public_api_application CHANGE created_at created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }
}
