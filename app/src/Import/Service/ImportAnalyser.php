<?php

declare(strict_types=1);

namespace App\Import\Service;

use App\Crm\Entity\Client;
use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportStatus;
use App\Import\Enum\ImportType;
use App\Import\Port\ImportTypeHandler;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Premier temps de la reprise : juger un fichier **sans rien écrire en base métier**.
 *
 * ── POURQUOI CE TEMPS EXISTE SÉPARÉMENT ─────────────────────────────────────────────────────────
 *
 * La décision « tout refuser en nommant les lignes » l'impose. Un import qui écrit et valide en même
 * temps ne peut pas tout refuser : quand il découvre la ligne 4 217, les 4 216 premières sont déjà
 * en base. Séparer donne le refus total **et** la prévisualisation, sans qu'on ait à choisir.
 *
 * ── UNE RÉFÉRENCE DÉJÀ CONNUE N'EST PAS UNE FAUTE ───────────────────────────────────────────────
 *
 * C'est ce qui rend le rejeu d'un fichier corrigé sans danger : les lignes déjà entrées sont
 * reconnues et passées, les nouvelles sont créées. Sans ça, corriger trois lignes sur quatre mille
 * obligerait à découper le fichier à la main — et personne ne le fait sans se tromper.
 */
final class ImportAnalyser
{
    /** @param iterable<ImportTypeHandler> $handlers */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CsvReader $reader,
        #[AutowireIterator('import.type_handler')]
        private readonly iterable $handlers,
    ) {
    }

    public function analyse(ImportBatch $batch, Etablissement $establishment): ImportBatch
    {
        $erreurs = [];

        $handler = $this->handlerFor($batch->getType());
        if ($handler === null) {
            $batch->setStatus(ImportStatus::Rejected)->setErrors([
                0 => sprintf(
                    'Le type « %s » n\'est pas encore repris. Types disponibles : %s.',
                    $batch->getType()->value,
                    implode(', ', array_map(static fn (ImportType $t): string => $t->value, ImportType::implemented())),
                ),
            ]);

            return $batch;
        }

        $lu = $this->reader->read($batch->getContent());
        $erreurs += $lu['errors'];

        // Les colonnes manquantes se disent UNE FOIS, pour le fichier — pas quatre mille fois.
        $attendues = array_merge(['externalRef'], $handler->requiredColumns());
        $manquantes = array_values(array_diff($attendues, $lu['header']));
        if ($manquantes !== []) {
            $erreurs[1] = sprintf('Colonne(s) absente(s) de l\'en-tête : %s.', implode(', ', $manquantes));

            return $this->verdict($batch, count($lu['rows']), $erreurs);
        }

        $vuesDansLeFichier = [];
        $dejaEnBase = $this->referencesConnues($establishment);

        foreach ($lu['rows'] as $ligne) {
            $numero = $ligne['line'];
            $reference = $ligne['data']['externalRef'] ?? '';

            if ($reference === '') {
                // ⚠ Sans elle, le rapprochement redeviendrait une devinette sur le nom — et c'est
                // précisément ce que la spécification refuse.
                $erreurs[$numero] = 'La référence de l\'ancien système (« externalRef ») est obligatoire.';

                continue;
            }

            if (isset($vuesDansLeFichier[$reference])) {
                $erreurs[$numero] = sprintf(
                    'La référence « %s » apparaît déjà ligne %d de ce fichier.',
                    $reference,
                    $vuesDansLeFichier[$reference],
                );

                continue;
            }
            $vuesDansLeFichier[$reference] = $numero;

            if (isset($dejaEnBase[$reference])) {
                // Déjà repris : ni faute, ni création. C'est ce qui rend le rejeu sans effet.
                continue;
            }

            foreach ($handler->validateRow($ligne['data']) as $faute) {
                // Une ligne peut porter plusieurs fautes ; on garde la première pour rester lisible,
                // et on ne cache pas les autres lignes derrière elle.
                $erreurs[$numero] = $faute;

                break;
            }
        }

        return $this->verdict($batch, count($lu['rows']), $erreurs);
    }

    /** @param array<int, string> $erreurs */
    private function verdict(ImportBatch $batch, int $lignes, array $erreurs): ImportBatch
    {
        ksort($erreurs);

        return $batch
            ->setRowCount($lignes)
            ->setErrors($erreurs)
            ->setStatus($erreurs === [] ? ImportStatus::Validated : ImportStatus::Rejected);
    }

    public function handlerFor(ImportType $type): ?ImportTypeHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports() === $type) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Les références déjà reprises pour ce groupe.
     *
     * Le groupe, pas l'établissement : un client appartient à l'enseigne, et une référence connue
     * sur un site l'est sur tous. Voir le commentaire porté par la contrainte d'unicité de `Client`.
     *
     * @return array<string, true>
     */
    private function referencesConnues(Etablissement $establishment): array
    {
        $groupe = $establishment->getRegion()?->getGroupe();
        if ($groupe === null) {
            return [];
        }

        /** @var list<array{externalRef: string}> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('c.externalRef')
            ->from(Client::class, 'c')
            ->andWhere('IDENTITY(c.groupe) = :groupe')
            ->andWhere('c.externalRef IS NOT NULL')
            ->setParameter('groupe', $groupe->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();

        $connues = [];
        foreach ($lignes as $l) {
            $connues[$l['externalRef']] = true;
        }

        return $connues;
    }
}
