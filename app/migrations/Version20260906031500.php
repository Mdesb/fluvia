<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE MODULE « CONNECTEURS » — les destinations sortantes Slack, Teams, Discord et génériques.
 *
 * ── ⚠ POURQUOI UNE TABLE, ET PAS UN RÉGLAGE DE MODULE ──────────────────────────────────────────
 *
 * On aurait spontanément mis ces URL dans `FonctionnaliteEtablissement::$parametres`, prévu pour
 * les réglages d'un module activable. **C'est impossible, et la raison est de sécurité** : ce champ
 * appartient au groupe `fonctionnalite:read`, dont la collection est lisible par TOUT UTILISATEUR
 * AUTHENTIFIÉ (`IS_AUTHENTICATED_FULLY`).
 *
 * Or une URL de webhook entrant n'est pas une adresse, c'est une CLÉ : qui la détient peut écrire
 * dans le canal au nom de l'établissement, sans authentification et indéfiniment. Un agent
 * d'accueil y aurait lu de quoi publier au nom de sa direction.
 *
 * Elle vit donc ici, `url_chiffree`, hors de tout groupe de sérialisation. Seul `hote` ressort —
 * « hooks.slack.com » dit vers quoi pointe la destination et ne permet rien.
 *
 * ⚠ **`INTEGRATIONS_WEBHOOK_KEY` DOIT EXISTER AVANT QUE CE CODE TOURNE.** Le conteneur refuse de
 * démarrer si la variable est absente — échec fermé, jamais un chiffrement de façade. La clé a été
 * ajoutée à la liste que `deploy-preprod.sh` génère au premier déploiement, dans le même commit :
 * les poser séparément aurait mis toute l'API à 500 entre les deux.
 *
 * ── ⚠ `evenements` VIDE VEUT DIRE « AUCUN », JAMAIS « TOUS » ───────────────────────────────────
 *
 * Une destination fraîchement créée ne déverse rien tant que personne n'a choisi quoi. La règle est
 * contre-intuitive dans le même sens que l'éligibilité des promotions — et pour la même raison :
 * l'autre lecture ferait partir en production ce que personne n'a validé.
 *
 * ── ⚠ CE QUE LA DESCENTE EMPORTE ───────────────────────────────────────────────────────────────
 *
 * Les URL, définitivement : elles sont chiffrées et n'existent nulle part ailleurs. Chaque
 * établissement devra les recoller depuis Slack ou Teams. C'est supportable — trente secondes par
 * canal — mais ça se sait avant de descendre, pas après.
 */
final class Version20260906031500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module « connecteurs » : destinations sortantes Slack, Teams, Discord, avec URL chiffrée au repos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "CREATE TABLE integrations_outbound_endpoint (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                establishment_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                kind VARCHAR(16) NOT NULL,
                libelle VARCHAR(120) NOT NULL,
                url_chiffree LONGTEXT NOT NULL,
                hote VARCHAR(180) NOT NULL,
                evenements JSON NOT NULL,
                actif TINYINT(1) DEFAULT 1 NOT NULL,
                cree_le DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                dernier_envoi_le DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                dernier_echec VARCHAR(200) DEFAULT NULL,
                INDEX idx_outbound_endpoint_establishment (establishment_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
        );
        $this->addSql(
            'ALTER TABLE integrations_outbound_endpoint
             ADD CONSTRAINT fk_outbound_endpoint_establishment
             FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integrations_outbound_endpoint DROP FOREIGN KEY fk_outbound_endpoint_establishment');
        $this->addSql('DROP TABLE integrations_outbound_endpoint');
    }
}
