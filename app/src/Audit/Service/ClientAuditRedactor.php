<?php

declare(strict_types=1);

namespace App\Audit\Service;

use App\Audit\Entity\EntreeAudit;
use App\Crm\Entity\Client;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Caviardage de l'identité d'une fiche client dans le journal d'audit (#363, décision D2 → A).
 *
 * C'est le SEUL code qui écrit une valeur sur une entrée d'audit existante : l'exception assumée à
 * l'append-only, confinée ici, et qui ne fait que remplacer une valeur d'identité par le marqueur.
 */
final class ClientAuditRedactor
{
    /** Taille d'un lot de lecture des entrées d'une fiche. */
    private const BATCH_SIZE = 500;

    /** Ce qui remplace une valeur d'identité dans une entrée d'audit caviardée. */
    public const MARKER = '[caviardé]';

    /**
     * ⚠ L'ANONYMISATION D'UNE FICHE RECOPIAIT SON IDENTITÉ DANS LE JOURNAL D'AUDIT (#344).
     *
     * Une anonymisation est une modification de `Client` : l'instantané « avant » de son entrée
     * d'audit contenait donc le nom, le courriel, le téléphone, la date de naissance que l'on venait
     * d'effacer — dans une table append-only. Chaque demande d'effacement traitée depuis l'origine
     * déplaçait l'identité au lieu de l'effacer.
     *
     * Pour la modification qui ANONYMISE (statut final `anonymise`), ces champs sont exclus de
     * l'instantané (`AuditWriteSubscriber`) : l'entrée garde qui, quand, et le passage au statut
     * `anonymise`, sans l'identité.
     *
     * Les entrées ANTÉRIEURES (création, modifications passées) sont caviardées par ce service (#363) :
     * UNE SEULE LISTE pour les deux, sinon l'une oublierait ce que l'autre efface.
     *
     * @var list<string>
     */
    public const IDENTITY_FIELDS = [
        'civilite', 'nom', 'prenom', 'raisonSociale', 'siret', 'email', 'telephone', 'adresse',
        'dateNaissance', 'champManuel', 'externalRef',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAudit $journal,
    ) {
    }

    /**
     * Remplace par le marqueur chaque champ d'identité présent et non nul. Aucune clé ajoutée ni
     * retirée, l'ordre et les autres valeurs inchangés ; un tableau déjà caviardé ressort identique.
     *
     * @param array<string, mixed>|null $values
     *
     * @return array<string, mixed>|null
     */
    public function redactValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach (self::IDENTITY_FIELDS as $field) {
            if (\array_key_exists($field, $values) && $values[$field] !== null) {
                $values[$field] = self::MARKER;
            }
        }

        return $values;
    }

    /**
     * Caviarde les entrées d'audit de la fiche et en laisse la trace. Ne flushe JAMAIS : les entités
     * modifiées et la trace partent dans le flush de l'appelant, donc dans la même transaction que
     * l'anonymisation. Aucun UPDATE direct (il sortirait de cette transaction).
     *
     * @return int le nombre d'entrées modifiées ; 0 = rien à faire, et aucune trace n'est écrite
     */
    public function redact(Client $client, ?string $author = null): int
    {
        $count = 0;
        foreach ($this->entriesOf((string) $client->getId()) as $entry) {
            $before = $this->redactValues($entry->getValeurAvant());
            $after = $this->redactValues($entry->getValeurApres());
            if ($before === $entry->getValeurAvant() && $after === $entry->getValeurApres()) {
                // Rien à écrire : on ne la garde pas en mémoire. (Une entrée modifiée reste gérée jusqu'au
                // flush de l'appelant : c'est le prix de « ne jamais flusher ».)
                $this->em->detach($entry);

                continue;
            }
            $entry->setValeurAvant($before);
            $entry->setValeurApres($after);
            ++$count;
        }
        if ($count === 0) {
            return 0;
        }

        // Sans identité : l'UUID de la fiche (déjà dans chaque entrée) et un compteur.
        $this->journal
            ->enregistrer('crm.rgpd.caviardage', 'Client', (string) $client->getId(), $client->getEtablissementCreation()?->getId(), $author)
            ->setValeurApres(['entrees' => $count]);

        return $count;
    }

    /** Le nombre d'entrées que `redact()` modifierait, sans rien écrire (mode essai). */
    public function countRedactable(string $clientId): int
    {
        $count = 0;
        foreach ($this->entriesOf($clientId) as $entry) {
            if ($this->redactValues($entry->getValeurAvant()) !== $entry->getValeurAvant()
                || $this->redactValues($entry->getValeurApres()) !== $entry->getValeurApres()) {
                ++$count;
            }
            $this->em->detach($entry);
        }

        return $count;
    }

    /**
     * Les entrées de CETTE fiche, par son nom de classe complet : les entrées `JournalAudit` du CRM
     * portent le nom court `Client`, n'ont aucune valeur, et ne sont jamais sélectionnées ici.
     *
     * Lues par lots de 500, dans l'ordre des identifiants (la sélection ne change pas pendant la lecture :
     * caviarder une entrée ne modifie ni son type ni sa cible), pour ne pas charger d'un coup une fiche
     * qui en porte des milliers.
     *
     * @return \Generator<int, EntreeAudit>
     */
    private function entriesOf(string $clientId): \Generator
    {
        // `findBy` plutôt que du DQL : `cibleId` est un VARCHAR, et le garde-fou D58 juge `e.cibleId = :id`
        // par le NOM de la propriété (il la confond avec `OperationScellee::$cibleId`, qui est un `uuid`).
        // `findBy` est paramétré, et `(cible_type, cible_id)` est couvert par `idx_audit_cible`.
        $repository = $this->em->getRepository(EntreeAudit::class);
        $offset = 0;
        do {
            $batch = $repository->findBy(['cibleType' => Client::class, 'cibleId' => $clientId], ['id' => 'ASC'], self::BATCH_SIZE, $offset);
            yield from $batch;
            $offset += self::BATCH_SIZE;
        } while (\count($batch) === self::BATCH_SIZE);
    }
}
