<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Personnel\Command\CheckQualificationsCommand;
use App\Personnel\Entity\Qualification;
use App\Tests\Personnel\PersonnelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `personnel:qualifications:verifier` voit ce qui change **après** l'affectation.
 *
 * **⚠ MA PREMIÈRE VERSION DE CE TEST REPOSAIT SUR UNE PRÉMISSE FAUSSE, ET LE TEST L'A DIT.**
 *
 * J'avais écrit que le contrôle d'affectation vérifie la qualification « à la date d'affectation », et
 * que le cas dangereux était donc un brevet expirant entre la planification et le créneau. **C'est
 * faux** : `AffecterEmployeProcessor::qualificationValide()` interroge `estValideA($debut)` — la
 * validité **au jour du créneau**. On ne peut pas planifier quelqu'un dont le brevet aura expiré.
 *
 * J'avais lu que le garde existait, pas ce qu'il comparait. **Vérifier qu'un contrôle est là n'est pas
 * vérifier ce qu'il contrôle** — c'est la faute que je corrigeais chez les autres depuis deux jours.
 *
 * **Ce qui reste vrai, et que rien ne surveille :** l'affectation est vérifiée **une fois**, au moment
 * où on la crée. Rien ne la revoit ensuite. Une qualification **révoquée**, **raccourcie** ou
 * **supprimée** après coup laisse l'affectation en place, et `RosterHebdomadaire` ne recalcule que si
 * quelqu'un ouvre le planning ce jour-là.
 *
 * Le cas est plus étroit que je ne l'ai écrit. Il n'est pas moins réel : une qualification qui se
 * raccourcit — suspension, contrôle médical, erreur de saisie corrigée — est exactement ce qu'un
 * responsable ne pense pas à rapprocher d'un planning déjà monté.
 *
 * **Les deux cas sont dans le même fichier, délibérément.** Une commande vérifiée seulement sur le cas
 * sain rend un vert qui ne prouve rien.
 */
final class CheckQualificationsCommandTest extends PersonnelApiTestCase
{
    public function testUneQualificationRaccourcieApresCoupEstSignalee(): void
    {
        $sortie = $this->monterUnCreneauEtLancerLaVerification(raccourcirApresAffectation: true);

        self::assertStringContainsString('qualification expirée', $sortie);
        self::assertStringContainsString('Surveillance bassin', $sortie);
    }

    public function testUneQualificationQuiCouvreLeCreneauNestPasSignalee(): void
    {
        $sortie = $this->monterUnCreneauEtLancerLaVerification(raccourcirApresAffectation: false);

        self::assertStringContainsString('aucune expirée', $sortie);
        self::assertStringNotContainsString('Surveillance bassin', $sortie);
    }

    private function monterUnCreneauEtLancerLaVerification(bool $raccourcirApresAffectation): string
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientPlanning, $entetePlanning] = $this->planningSurA();

        $debutCreneau = (new \DateTimeImmutable('+10 days'))->setTime(8, 0);
        $idEmploye = $this->creerEmploye($clientRh, $enteteRh);

        // Valide bien au-delà du créneau : l'affectation DOIT passer, sinon on ne teste rien.
        $clientRh->request('POST', '/api/qualifications', $enteteRh + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'type' => 'MNS',
                'dateValidite' => (new \DateTimeImmutable('+60 days'))->format('Y-m-d'),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $creneau = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Surveillance bassin 1',
                'debut' => $debutCreneau->format(DATE_ATOM),
                'fin' => $debutCreneau->modify('+4 hours')->format(DATE_ATOM),
                'qualificationRequise' => 'MNS',
            ],
        ])->toArray()['id'];

        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => [
                'creneauTravail' => '/api/creneau_travails/' . $creneau,
                'employe' => '/api/employes/' . $idEmploye,
            ],
        ]);
        self::assertResponseIsSuccessful();

        if ($raccourcirApresAffectation) {
            // Ce que le garde d'affectation ne peut pas voir : il a déjà eu lieu. Suspension, contrôle
            // médical, erreur de saisie corrigée — la date recule, l'affectation reste.
            /** @var EntityManagerInterface $em */
            $em = static::getContainer()->get('doctrine')->getManager();
            $qualification = $em->getRepository(Qualification::class)->findOneBy([]);
            self::assertNotNull($qualification);
            $qualification->setDateValidite(new \DateTimeImmutable('+2 days'));
            $em->flush();
        }

        // Les commandes sont des services privés : on l'instancie avec sa seule dépendance plutôt
        // que de la rendre publique pour un test.
        /** @var EntityManagerInterface $emCommande */
        $emCommande = static::getContainer()->get('doctrine')->getManager();
        $commande = new CheckQualificationsCommand($emCommande);

        $sortie = new BufferedOutput();
        $commande->run(new ArrayInput(['--jours' => '30']), $sortie);

        return $sortie->fetch();
    }

    private function creerEmploye(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete): string
    {
        return $client->request('POST', '/api/employes', $entete + [
            'json' => [
                'nom' => 'Nageur',
                'prenom' => 'Test',
                'poste' => 'MNS',
                'typeContrat' => 'cdi',
                'dateEntree' => '2024-01-01',
            ],
        ])->toArray()['id'];
    }
}
