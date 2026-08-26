<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\TauxTva;
use App\Facturation\Entity\CommercialDocument;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\DocumentLine;
use App\Facturation\Enum\DocumentNature;
use App\Facturation\Enum\TypeDestinataire;
use App\Facturation\Service\ResolveurComptesFacturation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Création d'une pièce commerciale en brouillon (FAC-1).
 *
 * **On ne crée qu'un devis par cette route.** Les deux autres natures se dérivent : un bon de commande
 * naît d'un devis accepté, un bon de livraison d'une commande. Permettre de créer une commande de
 * toutes pièces ouvrirait le chemin qui contourne la chaîne — et la filiation, qui est ce que le lot
 * apporte, deviendrait facultative.
 *
 * **L'établissement vient de la session, jamais du corps de la requête** (D3). C'est la famille d'IDOR
 * trouvée seize fois ici : un identifiant d'établissement accepté depuis l'entrée client permet
 * d'écrire chez le voisin.
 */
final class CreateDocumentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly ResolveurComptesFacturation $comptes,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommercialDocument
    {
        $etablissement = $this->contexte->etablissementActif();
        if (null === $etablissement) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);

        $corps = $this->lecteur->corps();

        $document = (new CommercialDocument())
            ->setNature(DocumentNature::Quote)
            ->setEtablissement($etablissement)
            ->setProfilExploitant($this->comptes->profilPour($etablissement))
            ->setDestinataire($this->destinataire($corps['destinataire'] ?? []))
            ->setCreePar($auteur);

        if (\is_string($corps['dateValidite'] ?? null) && '' !== $corps['dateValidite']) {
            $document->setDateValidite(new \DateTimeImmutable($corps['dateValidite']));
        }

        foreach ($this->lignes($corps) as $ligne) {
            $document->addLigne($ligne);
        }

        $document->recalculerTotaux();

        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    /**
     * @param mixed $donnees
     */
    private function destinataire($donnees): DestinataireFacturation
    {
        if (!\is_array($donnees)) {
            $donnees = [];
        }

        $raisonSociale = \is_string($donnees['raisonSociale'] ?? null) ? trim($donnees['raisonSociale']) : '';
        $nom = \is_string($donnees['nom'] ?? null) ? trim($donnees['nom']) : '';

        if ('' === $raisonSociale && '' === $nom) {
            throw new UnprocessableEntityHttpException(
                'Un devis a un destinataire : indiquez une raison sociale ou un nom.'
            );
        }

        $destinataire = (new DestinataireFacturation())
            ->setType('' !== $raisonSociale ? TypeDestinataire::PersonneMorale : TypeDestinataire::Particulier)
            ->setRaisonSociale('' !== $raisonSociale ? $raisonSociale : null)
            ->setNom('' !== $nom ? $nom : null)
            ->setPrenom(\is_string($donnees['prenom'] ?? null) ? $donnees['prenom'] : null)
            ->setSiret(\is_string($donnees['siret'] ?? null) ? $donnees['siret'] : null);

        if (\is_array($donnees['adresse'] ?? null)) {
            $destinataire->setAdresse($donnees['adresse']);
        }

        $this->em->persist($destinataire);

        return $destinataire;
    }

    /**
     * @param array<string, mixed> $corps
     *
     * @return list<DocumentLine>
     */
    private function lignes(array $corps): array
    {
        $brutes = $corps['lignes'] ?? null;

        if (!\is_array($brutes) || [] === $brutes) {
            throw new UnprocessableEntityHttpException('Un devis sans ligne ne propose rien : ajoutez au moins une ligne.');
        }

        $lignes = [];

        foreach ($brutes as $brute) {
            if (!\is_array($brute)) {
                throw new UnprocessableEntityHttpException('Chaque ligne est un objet.');
            }

            $designation = \is_string($brute['designation'] ?? null) ? trim($brute['designation']) : '';
            if ('' === $designation) {
                throw new UnprocessableEntityHttpException('Chaque ligne porte une désignation : c\'est ce que le client lira.');
            }

            $lignes[] = (new DocumentLine())
                ->setDesignation($designation)
                ->setQuantite(max(1, (int) ($brute['quantite'] ?? 1)))
                ->setPrixUnitaireHT((string) ($brute['prixUnitaireHT'] ?? '0.00'))
                ->setTauxTva($this->taux($brute['tauxTva'] ?? null));
        }

        return $lignes;
    }

    /**
     * @cloisonnement-verifie: le taux est confronte au profil de l etablissement ACTIF, resolu depuis
     * la session et jamais depuis le corps de la requete. Un taux d un autre exploitant est donc
     * refuse, meme si son identifiant est valide — c est le meme controle que
     * `FactureDirecteBuilder` applique ligne par ligne (RG-SOCLE-05).
     */
    private function taux(mixed $reference): ?TauxTva
    {
        if (!\is_string($reference) || !Uuid::isValid($reference)) {
            throw new UnprocessableEntityHttpException('Chaque ligne porte un taux de TVA (RG-M6-05).');
        }

        $taux = $this->em->getRepository(TauxTva::class)->find(Uuid::fromString($reference));
        $etablissement = $this->contexte->etablissementActif();

        if (!$taux instanceof TauxTva || null === $etablissement) {
            throw new UnprocessableEntityHttpException('Ce taux de TVA n\'existe pas.');
        }

        $profil = $this->comptes->profilPour($etablissement);
        if (true !== $taux->getProfilExploitant()?->getId()?->equals($profil->getId())) {
            throw new UnprocessableEntityHttpException('Ce taux de TVA n\'appartient pas à cet exploitant.');
        }

        return $taux;
    }
}
