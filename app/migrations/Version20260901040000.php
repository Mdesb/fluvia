<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UN MANDAT SEPA PEUT PORTER PLUSIEURS ABONNEMENTS — retrait de l'unicité.
 *
 * ── CE QUE L'UNICITÉ EMPÊCHAIT ────────────────────────────────────────────────────────────────
 *
 * `AbonnementFitness.mandatSepa` était un `OneToOne`, donc un mandat par abonnement. Souscrire deux
 * abonnements — un adulte et son enfant, deux formules dans la même famille — obligeait le payeur à
 * redonner son IBAN et produisait **deux RUM chez le même créancier pour le même débiteur**, ce qui
 * n'est pas la logique SEPA : un mandat autorise un créancier à prélever, il n'est pas attaché à ce
 * qu'on lui facture.
 *
 * Le mandat était déjà générique de son côté — rattaché au client et à l'établissement, jamais à un
 * abonnement. C'est le lien depuis l'abonnement qui imposait l'unicité, pas le mandat lui-même.
 *
 * ── ⚠ CE QUE L'UNICITÉ PROTÉGEAIT VRAIMENT, ET QUI N'EST PAS LE MANDAT ────────────────────────
 *
 * `DemanderResiliationHandler::executerEffet()` révoque le mandat à la date d'effet :
 *
 *     $mandat = $abonnement->getMandatSepa();
 *     $mandat?->setStatut(StatutMandatSepa::Revoque);
 *
 * Inconditionnel. C'était **correct tant qu'un mandat n'appartenait qu'à un abonnement**, et cette
 * migration seule le rendrait faux : résilier le premier abonnement révoquerait le mandat dont le
 * second a encore besoin. Ses prélèvements s'arrêteraient **sans erreur et sans message**, et
 * l'adhérent garderait son accès puisque son abonnement à lui reste actif — il cesserait simplement
 * d'être facturé, et personne ne le verrait avant le rapprochement bancaire.
 *
 * **La révocation conditionnelle part donc dans le même commit que cette migration.** Les séparer
 * est précisément le piège : chaque moitié est défendable seule, et leur ordre décide s'il y a un
 * défaut. Mesuré : `getMandatSepa()` n'a que quatre appelants, et un seul écrit le statut.
 *
 * ── ⚠ L'ORDRE DES DEUX INSTRUCTIONS N'EST PAS INTERCHANGEABLE ─────────────────────────────────
 *
 * `mandat_sepa_id` porte une clé étrangère (`FK_9D4AE73E610AFBEB`), et sur MariaDB une clé étrangère
 * exige un index qui la couvre. `UNIQ_9D4AE73E610AFBEB` est aujourd'hui le seul : le supprimer
 * d'abord échouerait avec « needed in a foreign key constraint ». On crée donc l'index simple
 * AVANT de retirer l'unique — à aucun instant la colonne n'est sans index.
 *
 * ── FENÊTRE DE DÉPLOIEMENT : CETTE MIGRATION EST SÛRE AVEC L'ANCIEN CODE ──────────────────────
 *
 * Le déploiement migre avant de redémarrer PHP, donc il existe un moment où le schéma est neuf et le
 * code ancien. Ici c'est sans risque : retirer une contrainte n'invalide rien de ce que l'ancien
 * code écrit — il continuera simplement à ne jamais partager un mandat. L'inverse (poser une
 * contrainte) est ce qui casse.
 *
 * @drop-voulu : UNIQ_9D4AE73E610AFBEB disparait parce que l'unicite d'un mandat par abonnement
 *   est precisement ce que ce lot leve -- un payeur ne donne plus son IBAN deux fois. Il est
 *   REMPLACE, dans la meme migration et avant sa suppression, par l'index simple
 *   IDX_9D4AE73E610AFBEB sur la meme colonne : la cle etrangere FK_9D4AE73E610AFBEB reste
 *   couverte a chaque instant, et aucune donnee n'est touchee.
 *
 * `down()` est écrit mais **ne repassera pas** si un mandat porte déjà deux abonnements, et c'est
 * juste : on ne peut pas revenir à une unicité que les données ont cessé de respecter.
 */
final class Version20260901040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Un mandat SEPA peut porter plusieurs abonnements : UNIQ remplacé par un index simple.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_9D4AE73E610AFBEB ON sport_abonnement_fitness (mandat_sepa_id)');
        $this->addSql('DROP INDEX UNIQ_9D4AE73E610AFBEB ON sport_abonnement_fitness');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9D4AE73E610AFBEB ON sport_abonnement_fitness (mandat_sepa_id)');
        $this->addSql('DROP INDEX IDX_9D4AE73E610AFBEB ON sport_abonnement_fitness');
    }
}
