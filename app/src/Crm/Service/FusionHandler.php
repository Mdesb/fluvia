<?php

declare(strict_types=1);

namespace App\Crm\Service;

use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\Crm\Entity\JournalFusion;
use App\Crm\Entity\MouvementPmv;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Enum\PorteeFusion;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\StatutFamille;
use App\Crm\Enum\StatutJournalFusion;
use App\Crm\Enum\StatutPmv;
use App\Crm\Enum\TypeMouvementPmv;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Fusion/défusion de fiches et de familles (RG-M4-06, US-L5-08, §4 plan-crm.md). Une fusion est
 * tracée intégralement (`JournalFusion`) et réversible : `snapshotAvant` capture l'état complet des
 * fiches sources **avant** toute mutation, condition de la restauration « à l'identique » (CA-14).
 */
final class FusionHandler
{
    /** @var list<string> Champs de Client arbitrables lors d'une fusion (§4.1 plan-crm.md). */
    private const CHAMPS_ARBITRABLES = ['civilite', 'nom', 'prenom', 'raisonSociale', 'siret', 'email', 'telephone', 'adresse', 'dateNaissance'];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<Client>          $sources
     * @param array<string, mixed>  $champsArbitres
     */
    public function previsualiserClients(array $sources, Client $maitre): array
    {
        $divergences = [];
        foreach (self::CHAMPS_ARBITRABLES as $champ) {
            $getter = 'get' . ucfirst($champ);
            $valeurMaitre = $maitre->$getter();
            foreach ($sources as $source) {
                $valeurSource = $source->$getter();
                if ($this->serialise($valeurSource) !== $this->serialise($valeurMaitre)) {
                    $divergences[$champ] ??= ['maitre' => $this->serialise($valeurMaitre)];
                    $divergences[$champ]['sources'][(string) $source->getId()] = $this->serialise($valeurSource);
                }
            }
        }

        return $divergences;
    }

    /**
     * @param list<Client>         $sources
     * @param array<string, mixed> $champsArbitres
     */
    public function fusionnerClients(array $sources, Client $maitre, array $champsArbitres, ?string $motif, Utilisateur $auteur): JournalFusion
    {
        if ($sources === []) {
            throw new UnprocessableEntityHttpException('Au moins une fiche source est requise pour une fusion.');
        }

        $snapshot = ['maitre' => [(string) $maitre->getId() => $this->snapshotClient($maitre)], 'sources' => []];
        foreach ($sources as $source) {
            if ((string) $source->getId() === (string) $maitre->getId()) {
                throw new UnprocessableEntityHttpException('La fiche maître ne peut pas être sa propre source.');
            }
            $snapshot['sources'][(string) $source->getId()] = $this->snapshotClient($source);
        }

        $this->appliquerArbitrage($maitre, $champsArbitres);

        foreach ($sources as $source) {
            $this->cumulerPmv($source, $maitre);
            $source->setStatut(StatutClient::Fusionne);
            $source->setFusionneDans($maitre);
        }

        $journal = new JournalFusion(PorteeFusion::Client);
        $journal->setFichesSources(array_map(static fn (Client $c): string => (string) $c->getId(), $sources));
        $journal->setFicheSurvivante($maitre->getId());
        $journal->setChampsArbitres($champsArbitres);
        $journal->setSnapshotAvant($snapshot);
        $journal->setMotif($motif);
        $journal->setEffectuePar($auteur);

        $this->em->persist($journal);

        return $journal;
    }

    public function fusionnerFamilles(Famille $maitre, Famille $source, Client $payeurPrincipal, ?string $motif, Utilisateur $auteur): JournalFusion
    {
        if ((string) $maitre->getId() === (string) $source->getId()) {
            throw new UnprocessableEntityHttpException('Une famille ne peut pas fusionner avec elle-même.');
        }

        $snapshot = [
            'maitre' => $this->snapshotFamille($maitre),
            'sources' => [(string) $source->getId() => $this->snapshotFamille($source)],
        ];

        // Fusion du payeur non retenu (s'il diffère du principal choisi) : cumule son PMV (§4.1/§4.2).
        $ancienPayeur = $maitre->getPayeurPrincipal();
        if ($ancienPayeur !== null && (string) $ancienPayeur->getId() !== (string) $payeurPrincipal->getId()) {
            $this->cumulerPmv($ancienPayeur, $payeurPrincipal);
            $ancienPayeur->setStatut(StatutClient::Fusionne);
            $ancienPayeur->setFusionneDans($payeurPrincipal);
        }
        $payeurSource = $source->getPayeurPrincipal();
        if ($payeurSource !== null && (string) $payeurSource->getId() !== (string) $payeurPrincipal->getId()) {
            $this->cumulerPmv($payeurSource, $payeurPrincipal);
            $payeurSource->setStatut(StatutClient::Fusionne);
            $payeurSource->setFusionneDans($payeurPrincipal);
        }
        $maitre->setPayeurPrincipal($payeurPrincipal);

        // Déduplication des bénéficiaires communs (CA-15) : un client déjà actif dans la famille
        // maître n'est pas dupliqué ; sinon son rattachement est déplacé (retrait tracé + nouvel ajout).
        $clientsActifsMaitre = array_map(
            static fn (Beneficiaire $b): string => (string) $b->getClient()?->getId(),
            $maitre->membresActifs(),
        );
        foreach ($source->membresActifs() as $beneficiaire) {
            $clientId = (string) $beneficiaire->getClient()?->getId();
            $beneficiaire->setDateRetrait(new \DateTimeImmutable());
            if (\in_array($clientId, $clientsActifsMaitre, true)) {
                continue; // Doublon : déjà présent dans la famille maître (dédupliqué).
            }
            $nouveau = new Beneficiaire();
            $nouveau->setFamille($maitre);
            $nouveau->setClient($beneficiaire->getClient());
            $nouveau->setRole($beneficiaire->getRole());
            $nouveau->setAutorisations($beneficiaire->getAutorisations());
            $this->em->persist($nouveau);
            $maitre->addBeneficiaire($nouveau);
        }

        $source->setStatut(StatutFamille::Fusionnee);

        $journal = new JournalFusion(PorteeFusion::Famille);
        $journal->setFichesSources([(string) $source->getId()]);
        $journal->setFicheSurvivante($maitre->getId());
        $journal->setChampsArbitres(['payeurPrincipal' => (string) $payeurPrincipal->getId()]);
        $journal->setSnapshotAvant($snapshot);
        $journal->setMotif($motif);
        $journal->setEffectuePar($auteur);

        $this->em->persist($journal);

        return $journal;
    }

    public function defusionner(JournalFusion $journal, Utilisateur $administrateur): void
    {
        if ($journal->getStatut() !== StatutJournalFusion::Active) {
            throw new ConflictHttpException('Seule une fusion active (non déjà défaite) peut être défaite.');
        }

        $snapshot = $journal->getSnapshotAvant();

        if ($journal->getPortee() === PorteeFusion::Client) {
            $this->restaurerClient($journal->getFicheSurvivante(), $snapshot['maitre'][(string) $journal->getFicheSurvivante()] ?? []);
            foreach ($snapshot['sources'] ?? [] as $idStr => $etat) {
                $this->restaurerClient(Uuid::fromString($idStr), $etat);
            }
        } else {
            $this->restaurerFamille($journal->getFicheSurvivante(), $snapshot['maitre'] ?? []);
            foreach ($snapshot['sources'] ?? [] as $idStr => $etat) {
                $this->restaurerFamille(Uuid::fromString($idStr), $etat);
            }
        }

        $journal->setStatut(StatutJournalFusion::Defusionnee);
        $journal->setDateDefusion(new \DateTimeImmutable());
        $journal->setDefusionnePar($administrateur);
    }

    // --- Snapshot / restauration ---

    /** @return array<string, mixed> */
    private function snapshotClient(Client $client): array
    {
        $pmv = $this->em->getRepository(PorteMonnaieVirtuel::class)->findOneBy(['client' => $client]);

        return [
            'civilite' => $client->getCivilite(),
            'nom' => $client->getNom(),
            'prenom' => $client->getPrenom(),
            'raisonSociale' => $client->getRaisonSociale(),
            'siret' => $client->getSiret(),
            'email' => $client->getEmail(),
            'telephone' => $client->getTelephone(),
            'adresse' => $client->getAdresse(),
            'dateNaissance' => $client->getDateNaissance()?->format('Y-m-d'),
            'statut' => $client->getStatut()->value,
            'pmv' => $pmv === null ? null : [
                'solde' => $pmv->getSolde(),
                'dateEcheance' => $pmv->getDateEcheance()?->format('Y-m-d'),
                'statut' => $pmv->getStatut()->value,
            ],
        ];
    }

    /** @param array<string, mixed> $etat */
    private function restaurerClient(Uuid $clientId, array $etat): void
    {
        if ($etat === []) {
            return;
        }
        $client = $this->em->getRepository(Client::class)->find($clientId);
        if (!$client instanceof Client) {
            return;
        }

        $client->setCivilite($etat['civilite'] ?? null);
        $client->setNom($etat['nom'] ?? null);
        $client->setPrenom($etat['prenom'] ?? null);
        $client->setRaisonSociale($etat['raisonSociale'] ?? null);
        $client->setSiret($etat['siret'] ?? null);
        $client->setEmail($etat['email'] ?? null);
        $client->setTelephone($etat['telephone'] ?? null);
        $client->setAdresse($etat['adresse'] ?? null);
        $client->setDateNaissance(isset($etat['dateNaissance']) ? new \DateTimeImmutable($etat['dateNaissance']) : null);
        $client->setStatut(StatutClient::from($etat['statut'] ?? 'actif'));
        $client->setFusionneDans(null);

        $pmvEtat = $etat['pmv'] ?? null;
        $pmv = $this->em->getRepository(PorteMonnaieVirtuel::class)->findOneBy(['client' => $client]);
        if ($pmvEtat !== null) {
            if ($pmv === null) {
                $pmv = new PorteMonnaieVirtuel();
                $pmv->setClient($client);
                $this->em->persist($pmv);
            }
            $ancienSolde = $pmv->getSolde();
            $pmv->setSolde($pmvEtat['solde']);
            $pmv->setDateEcheance(isset($pmvEtat['dateEcheance']) ? new \DateTimeImmutable($pmvEtat['dateEcheance']) : null);
            $pmv->setStatut(StatutPmv::from($pmvEtat['statut']));
            $this->journaliserAjustement($pmv, MontantUtil::soustraction($pmv->getSolde(), $ancienSolde), 'Défusion : restauration du solde d\'origine.');
        } elseif ($pmv !== null) {
            $this->journaliserAjustement($pmv, MontantUtil::soustraction('0.00', $pmv->getSolde()), 'Défusion : PMV inexistant avant fusion.');
            $pmv->setSolde('0.00');
            $pmv->setStatut(StatutPmv::Expire);
        }
    }

    /** @return array<string, mixed> */
    private function snapshotFamille(Famille $famille): array
    {
        return [
            'statut' => $famille->getStatut()->value,
            'payeurPrincipal' => (string) $famille->getPayeurPrincipal()?->getId(),
            'membres' => array_map(static fn (Beneficiaire $b): array => [
                'id' => (string) $b->getId(),
                'client' => (string) $b->getClient()?->getId(),
            ], $famille->membresActifs()),
        ];
    }

    /** @param array<string, mixed> $etat */
    private function restaurerFamille(Uuid $familleId, array $etat): void
    {
        if ($etat === []) {
            return;
        }
        $famille = $this->em->getRepository(Famille::class)->find($familleId);
        if (!$famille instanceof Famille) {
            return;
        }

        $famille->setStatut(StatutFamille::from($etat['statut'] ?? 'active'));
        if (isset($etat['payeurPrincipal']) && $etat['payeurPrincipal'] !== '') {
            $payeur = $this->em->getRepository(Client::class)->find(Uuid::fromString($etat['payeurPrincipal']));
            if ($payeur instanceof Client) {
                $famille->setPayeurPrincipal($payeur);
            }
        }

        // Réactive les rattachements actifs au moment du snapshot (dateRetrait=null) et retire ceux
        // ajoutés depuis (créés par la fusion, absents du snapshot).
        $idsSnapshot = array_column($etat['membres'] ?? [], 'id');
        foreach ($this->em->getRepository(Beneficiaire::class)->findBy(['famille' => $famille]) as $beneficiaire) {
            if (\in_array((string) $beneficiaire->getId(), $idsSnapshot, true)) {
                $beneficiaire->setDateRetrait(null);
            } elseif ($beneficiaire->estActif()) {
                $beneficiaire->setDateRetrait(new \DateTimeImmutable());
            }
        }
    }

    // --- PMV ---

    private function cumulerPmv(Client $source, Client $maitre): void
    {
        $pmvSource = $this->em->getRepository(PorteMonnaieVirtuel::class)->findOneBy(['client' => $source]);
        if ($pmvSource === null || MontantUtil::comparer($pmvSource->getSolde(), '0.00') === 0) {
            return;
        }

        $pmvMaitre = $this->em->getRepository(PorteMonnaieVirtuel::class)->findOneBy(['client' => $maitre]);
        if ($pmvMaitre === null) {
            $pmvMaitre = new PorteMonnaieVirtuel();
            $pmvMaitre->setClient($maitre);
            $pmvMaitre->setStatut(StatutPmv::Actif);
            $this->em->persist($pmvMaitre);
        }

        $solde = $pmvSource->getSolde();

        // Échéance retenue = la plus tardive des deux (⚠ HYPOTHÈSE, §4.2/§10.2 plan-crm.md).
        $echeanceMaitre = $pmvMaitre->getDateEcheance();
        $echeanceSource = $pmvSource->getDateEcheance();
        if ($echeanceSource !== null && ($echeanceMaitre === null || $echeanceSource > $echeanceMaitre)) {
            $pmvMaitre->setDateEcheance($echeanceSource);
        }
        if ($pmvMaitre->getDateEcheance() !== null) {
            $pmvMaitre->setStatut(StatutPmv::Actif);
        }

        $pmvMaitre->setSolde(MontantUtil::addition($pmvMaitre->getSolde(), $solde));
        $this->journaliserAjustement($pmvMaitre, $solde, sprintf('Fusion depuis %s', $source->getId()));

        $pmvSource->setSolde('0.00');
        $this->journaliserAjustement($pmvSource, '-' . $solde, sprintf('Fusion vers %s', $maitre->getId()));
    }

    private function journaliserAjustement(PorteMonnaieVirtuel $pmv, string $montant, string $motif): void
    {
        if (MontantUtil::comparer($montant, '0.00') === 0) {
            return;
        }
        $mouvement = new MouvementPmv(TypeMouvementPmv::Ajustement);
        $mouvement->setPmv($pmv);
        $mouvement->setMontant($montant);
        $mouvement->setSoldeApres($pmv->getSolde());
        $mouvement->setMotif($motif);
        // L'établissement du client, jamais « le premier venu » (04/10/2026).
        $etablissement = $pmv->getClient()?->getEtablissementCreation();
        if ($etablissement === null) {
            throw new \LogicException('Mouvement de porte-monnaie refusé : le client n’a pas d’établissement.');
        }
        $mouvement->setEtablissement($etablissement);
        $this->em->persist($mouvement);
    }

    private function appliquerArbitrage(Client $maitre, array $champsArbitres): void
    {
        foreach ($champsArbitres as $champ => $valeur) {
            if (!\in_array($champ, self::CHAMPS_ARBITRABLES, true)) {
                continue;
            }
            $setter = 'set' . ucfirst($champ);
            if ($champ === 'dateNaissance' && \is_string($valeur)) {
                $maitre->$setter(new \DateTimeImmutable($valeur));
            } else {
                $maitre->$setter($valeur);
            }
        }
    }

    private function serialise(mixed $valeur): mixed
    {
        return $valeur instanceof \DateTimeImmutable ? $valeur->format('Y-m-d') : $valeur;
    }
}
