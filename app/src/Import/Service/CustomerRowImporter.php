<?php

declare(strict_types=1);

namespace App\Import\Service;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\Import\Dto\ParsedImportRow;
use App\Import\Entity\ImportBatch;
use App\Import\Entity\ImportedEntityRef;
use App\Import\Enum\ImportType;
use App\Import\Port\RowImporterInterface;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reprise du type `customers` (plan-import-i1.md §0.5, SPEC-REPRISE-INITIALE.md §3/§4) — **écriture
 * cross-module directe assumée** vers `App\Crm\Entity\Client` (`new Client()`, `$em->persist()`),
 * contradiction littérale de D2 (« jamais par appel direct module→module »). Assumé et nommé
 * explicitement (§7 point 1 du plan) : la reprise doit savoir, dans la même transaction et avant de
 * répondre, combien de lignes ont été créées et lesquelles ont échoué — un événement asynchrone ne peut
 * pas porter cette garantie transactionnelle. **À confirmer par claude-A/l'architecte avant merge.**
 *
 * D100 impose un rapprochement exact par `externalRef` : `apply()` fait un **upsert** ligne à ligne
 * (§0.5) — `externalRef` inconnu -> création + `Client.importBatchRef` posé (une seule fois) ;
 * `externalRef` déjà connu -> mise à jour du `Client` existant, `importBatchRef` **non touché**.
 *
 * Validation propre à `customers` (§0.5, dupliquée volontairement de `Client::validerCoherenceType()` —
 * §7 point 2 du plan, risque de divergence documenté, pas un oubli) : `externalRef` obligatoire et
 * unique **dans le fichier**, `type` valide, `nom`/`raisonSociale` requis selon le type. **Aucune**
 * colonne « établissement »/« etablissement » n'est jamais lue (D41, §0.7).
 */
final class CustomerRowImporter implements RowImporterInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReverseReferenceChecker $checker,
    ) {
    }

    public function type(): ImportType
    {
        return ImportType::Customers;
    }

    public function validate(array $rows, Etablissement $establishment): array
    {
        $occurrences = [];
        foreach ($rows as $row) {
            $ref = trim($row->columns['externalref'] ?? '');
            if ($ref !== '') {
                $occurrences[$ref] = ($occurrences[$ref] ?? 0) + 1;
            }
        }

        $errors = [];
        foreach ($rows as $row) {
            $ligne = $row->lineNumber;
            $ref = trim($row->columns['externalref'] ?? '');

            if ($ref === '') {
                $errors[$ligne] = 'externalRef est obligatoire.';

                continue;
            }

            if (($occurrences[$ref] ?? 0) > 1) {
                $errors[$ligne] = sprintf('externalRef « %s » est dupliqué dans ce fichier.', $ref);

                continue;
            }

            $type = $this->resoudreType($row);
            if ($type === null) {
                $errors[$ligne] = 'type doit valoir « physique » ou « morale ».';

                continue;
            }

            if ($type === TypeClient::Physique && trim($row->columns['nom'] ?? '') === '') {
                $errors[$ligne] = 'nom est requis pour un client physique.';

                continue;
            }

            if ($type === TypeClient::Morale && trim($row->columns['raisonsociale'] ?? '') === '') {
                $errors[$ligne] = 'raisonSociale est requise pour un client moral.';
            }
        }

        return $errors;
    }

    public function apply(array $rows, ImportBatch $batch): int
    {
        $establishment = $batch->getEstablishment();
        \assert($establishment instanceof Etablissement);
        $groupe = $establishment->getRegion()?->getGroupe();

        $created = 0;
        $maintenant = new \DateTimeImmutable();

        foreach ($rows as $row) {
            $externalRef = trim($row->columns['externalref'] ?? '');
            if ($externalRef === '') {
                // Ne peut pas survenir sur un lot validé (§0.2) — garde défensive silencieuse.
                continue;
            }

            $ref = $this->em->getRepository(ImportedEntityRef::class)->findOneBy([
                'establishment' => $establishment->getId(),
                'type' => ImportType::Customers,
                'externalRef' => $externalRef,
            ]);

            if ($ref === null) {
                $client = new Client();
                $client->setGroupe($groupe);
                $client->setEtablissementCreation($establishment);
                $client->setImportBatchRef($batch->getId());
                $this->hydrater($client, $row);
                $this->em->persist($client);

                $ref = new ImportedEntityRef();
                $ref->setEstablishment($establishment);
                $ref->setType(ImportType::Customers);
                $ref->setExternalRef($externalRef);
                $ref->setTargetId($client->getId());
                $ref->setImportBatchRef($batch->getId());
                $this->em->persist($ref);

                ++$created;

                continue;
            }

            // externalRef déjà connu -> mise à jour, `Client.importBatchRef` n'est PAS touché (§0.6) :
            // il reste pointé sur le lot qui l'a créé, condition nécessaire à une annulation exacte.
            $client = $this->em->getRepository(Client::class)->find($ref->getTargetId());
            if ($client instanceof Client) {
                $this->hydrater($client, $row);
            }
            $ref->setImportBatchRef($batch->getId());
            $ref->setUpdatedAt($maintenant);
        }

        return $created;
    }

    public function countCreated(Uuid $batchId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Client::class, 'c')
            ->andWhere('c.importBatchRef = :batchId')
            ->setParameter('batchId', $batchId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function isReferenced(Uuid $batchId): bool
    {
        foreach ($this->clientsDuLot($batchId) as $client) {
            if ($this->checker->isReferenced(Client::class, $client->getId())) {
                return true;
            }
        }

        return false;
    }

    public function revert(Uuid $batchId): void
    {
        foreach ($this->clientsDuLot($batchId) as $client) {
            $ref = $this->em->getRepository(ImportedEntityRef::class)->findOneBy([
                'type' => ImportType::Customers,
                'targetId' => $client->getId(),
            ]);
            if ($ref !== null) {
                $this->em->remove($ref);
            }
            $this->em->remove($client);
        }
    }

    /** @return list<Client> uniquement les clients CRÉÉS par ce lot (§0.6), jamais ceux seulement mis à jour. */
    private function clientsDuLot(Uuid $batchId): array
    {
        return $this->em->getRepository(Client::class)->findBy(['importBatchRef' => $batchId]);
    }

    private function resoudreType(ParsedImportRow $row): ?TypeClient
    {
        return match (strtolower(trim($row->columns['type'] ?? ''))) {
            'physique' => TypeClient::Physique,
            'morale' => TypeClient::Morale,
            default => null,
        };
    }

    /**
     * Hydrate un `Client` depuis une ligne du fichier — **aucune** colonne « établissement »/
     * « etablissement » n'est jamais lue ici, même présente dans le fichier (D41, §0.7, testé
     * explicitement).
     */
    private function hydrater(Client $client, ParsedImportRow $row): void
    {
        $type = $this->resoudreType($row) ?? TypeClient::Physique;
        $client->setType($type);

        if (\array_key_exists('nom', $row->columns)) {
            $client->setNom($this->videEnNull($row->columns['nom']));
        }
        if (\array_key_exists('prenom', $row->columns)) {
            $client->setPrenom($this->videEnNull($row->columns['prenom']));
        }
        if (\array_key_exists('raisonsociale', $row->columns)) {
            $client->setRaisonSociale($this->videEnNull($row->columns['raisonsociale']));
        }
        if (\array_key_exists('siret', $row->columns)) {
            $client->setSiret($this->videEnNull($row->columns['siret']));
        }
        if (\array_key_exists('email', $row->columns)) {
            $client->setEmail($this->videEnNull($row->columns['email']));
        }
        if (\array_key_exists('telephone', $row->columns)) {
            $client->setTelephone($this->videEnNull($row->columns['telephone']));
        }
        if (\array_key_exists('civilite', $row->columns)) {
            $client->setCivilite($this->videEnNull($row->columns['civilite']));
        }
        if (\array_key_exists('datenaissance', $row->columns) && trim($row->columns['datenaissance']) !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($row->columns['datenaissance']));
            if ($date instanceof \DateTimeImmutable) {
                $client->setDateNaissance($date);
            }
        }
    }

    private function videEnNull(string $valeur): ?string
    {
        $valeur = trim($valeur);

        return $valeur === '' ? null : $valeur;
    }
}
