<?php

declare(strict_types=1);

namespace App\Import\Handler;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportType;
use App\Import\Port\ImportTypeHandler;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Reprise du type `customers` — la racine, à laquelle tout le reste se rattachera.
 */
final class CustomerImportHandler implements ImportTypeHandler
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function supports(): ImportType
    {
        return ImportType::Customers;
    }

    /** @return list<string> */
    public function requiredColumns(): array
    {
        // Les colonnes doivent EXISTER ; leur valeur peut être vide selon le type de la ligne. Une
        // colonne absente est une faute de l'extraction et se dit une fois, pour le fichier ; une
        // valeur manquante est une faute de donnée et se dit ligne par ligne.
        return ['type', 'nom', 'prenom', 'raisonSociale'];
    }

    /**
     * @param array<string, string> $row
     *
     * @return list<string>
     */
    public function validateRow(array $row): array
    {
        $fautes = [];

        $type = TypeClient::tryFrom($row['type'] ?? '');
        if ($type === null) {
            $fautes[] = sprintf(
                'Colonne « type » : « %s » n\'est pas un type de client (attendu : physique ou morale).',
                $row['type'] ?? '',
            );
        }

        if ($type === TypeClient::Physique && ($row['nom'] ?? '') === '') {
            $fautes[] = 'Le nom est requis pour un client physique.';
        }

        if ($type === TypeClient::Morale && ($row['raisonSociale'] ?? '') === '') {
            $fautes[] = 'La raison sociale est requise pour un client moral.';
        }

        $courriel = $row['email'] ?? '';
        if ($courriel !== '' && filter_var($courriel, \FILTER_VALIDATE_EMAIL) === false) {
            $fautes[] = sprintf('Courriel invalide : « %s ».', $courriel);
        }

        $naissance = $row['dateNaissance'] ?? '';
        if ($naissance !== '' && $this->date($naissance) === null) {
            $fautes[] = sprintf('Date de naissance illisible : « %s » (attendu AAAA-MM-JJ ou JJ/MM/AAAA).', $naissance);
        }

        return $fautes;
    }

    /** @param array<string, string> $row */
    public function create(array $row, Etablissement $establishment, ImportBatch $batch): object
    {
        $client = new Client();
        $client
            ->setGroupe($this->groupe($establishment))
            ->setEtablissementCreation($establishment)
            ->setType(TypeClient::from($row['type']))
            ->setExternalRef($row['externalRef'])
            ->setImportBatchRef($batch->getId());

        foreach (['nom', 'prenom', 'raisonSociale', 'email', 'telephone'] as $champ) {
            $valeur = $row[$champ] ?? '';
            if ($valeur !== '') {
                $client->{'set' . ucfirst($champ)}($valeur);
            }
        }

        $naissance = $row['dateNaissance'] ?? '';
        if ($naissance !== '') {
            $client->setDateNaissance($this->date($naissance));
        }

        $this->em->persist($client);

        return $client;
    }

    /** @return list<object> */
    public function createdBy(ImportBatch $batch): array
    {
        /** @var list<Client> $clients */
        $clients = $this->em->getRepository(Client::class)->findBy(['importBatchRef' => $batch->getId()]);

        return $clients;
    }

    /**
     * Ce client a-t-il servi depuis sa reprise ?
     *
     * ⚠ **On ne dresse PAS la liste de ce qui emploie un client.** Douze entités le référencent
     * aujourd'hui ; une treizième arrivera, et une liste écrite à la main ne la connaîtrait pas —
     * l'annulation supprimerait alors un client qui a servi, en silence. C'est exactement le défaut
     * qu'une liste blanche produit : elle ne protège que ce qu'on a pensé à y écrire.
     *
     * On interroge donc **le mapping**, qui sait toujours qui pointe vers `Client` parce qu'il est
     * la source de la base elle-même. Une entité ajoutée demain est couverte sans qu'on y pense.
     */
    public function hasBeenUsed(object $created): bool
    {
        if (!$created instanceof Client) {
            return false;
        }

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (!$metadata instanceof ClassMetadata || $metadata->isMappedSuperclass) {
                continue;
            }

            foreach ($metadata->getAssociationMappings() as $champ => $mapping) {
                if (($mapping['targetEntity'] ?? null) !== Client::class) {
                    continue;
                }
                // Seul le côté propriétaire porte la colonne : l'inverse n'est qu'une vue.
                if (($mapping['isOwningSide'] ?? false) !== true) {
                    continue;
                }

                $compte = (int) $this->em->createQueryBuilder()
                    ->select('COUNT(r.id)')
                    ->from($metadata->getName(), 'r')
                    ->andWhere(sprintf('IDENTITY(r.%s) = :client', $champ))
                    ->setParameter('client', $created->getId(), 'uuid')
                    ->getQuery()
                    ->getSingleScalarResult();

                if ($compte > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function groupe(Etablissement $establishment): Groupe
    {
        $groupe = $establishment->getRegion()?->getGroupe();
        if (!$groupe instanceof Groupe) {
            // Le client suit l'enseigne : sans groupe, on ne sait pas à qui il appartient. Refuser
            // ici plutôt que de rattacher au hasard — un fichier entier mal rattaché ne se répare
            // qu'à la main.
            throw new \RuntimeException("L'établissement actif n'est rattaché à aucun groupe : impossible de rattacher les clients repris.");
        }

        return $groupe;
    }

    private function date(string $valeur): ?\DateTimeImmutable
    {
        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $valeur);
            if ($date instanceof \DateTimeImmutable && $date->format($format) === $valeur) {
                return $date->setTime(0, 0);
            }
        }

        return null;
    }
}
