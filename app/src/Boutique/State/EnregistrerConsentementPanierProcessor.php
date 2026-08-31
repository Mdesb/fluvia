<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\ConfirmerCommandeHandler;
use App\Crm\Entity\Consentement;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\EtatConsentement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * POST /boutique/paniers/{id}/consentement — étape 3 du tunnel (US-L8-06, RG-M3-07/13, CA-6/CA-8).
 * Le consentement RGPD (horodaté, `App\Crm\Entity\Consentement`, canal `boutique` via `source`,
 * réutilisé sans redéfinition) est requis avant paiement ; résout/crée le `Client` du payeur (§0
 * décision n°4 du plan). Corps : { "rgpd": bool, "autorisationsParentales"?: {ligneId: bool} }.
 *
 * @implements ProcessorInterface<PanierEnLigne, PanierEnLigne>
 */
final class EnregistrerConsentementPanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PanierProprietaireGuard $guard,
        private readonly ConfirmerCommandeHandler $confirmerCommande,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        $corps = $this->lecteur->corps();
        $rgpd = ($corps['rgpd'] ?? false) === true;
        /** @var array<string, mixed> $autorisations */
        $autorisations = \is_array($corps['autorisationsParentales'] ?? null) ? $corps['autorisationsParentales'] : [];

        if ($rgpd) {
            $client = $this->confirmerCommande->resoudreClient($data);
            $consentement = new Consentement(CanalConsentement::Email, EtatConsentement::Accorde);
            $consentement->setClient($client)->setSource('boutique');
            $this->em->persist($consentement);
            $data->setConsentementRgpdHorodatage(new \DateTimeImmutable());

            // ON ENREGISTRE CE QUI A ETE ACCEPTE, PAS SEULEMENT QU'IL L'A ETE.
            //
            // Le consentement RGPD etait horodate, et `LegalDocument` conserve chaque version publiee
            // des CGV -- mais rien ne reliait les deux. On savait QUAND le client avait accepte, pas
            // CE QU'IL AVAIT ACCEPTE.
            //
            // Des CGV ne sont opposables que dans la version que le client a pu lire au moment ou il a
            // paye. Sans ce lien, l'exploitant ne peut meme pas montrer laquelle s'appliquait.
            $cgv = $this->cgvPubliees($data);
            if ($cgv !== null) {
                $data->accepterCgv($cgv['id'], $cgv['version']);
            }
        }

        foreach ($data->getLignes() as $ligne) {
            \assert($ligne instanceof LignePanierEnLigne);
            if (!$ligne->isAutorisationParentaleRequise()) {
                continue;
            }
            $id = (string) $ligne->getId();
            if (($autorisations[$id] ?? false) === true) {
                $ligne->setAutorisationParentaleHorodatage(new \DateTimeImmutable());
            }
        }

        $this->em->flush();

        return $data;
    }

    /**
     * Les CGV publiees de l'etablissement de ce panier, ou `null` s'il n'y en a pas.
     *
     * **`null` ne bloque pas le paiement, et c'est un choix.** Un exploitant qui n'a pas encore publie
     * ses CGV a un probleme de conformite -- mais refuser la vente le decouvrirait un samedi soir, sur
     * un client qui n'y est pour rien. L'ecran des mentions legales le signale la ou c'est reparable.
     *
     * La lecture passe par du SQL : `LegalDocument` vit dans `App" + chr(92) + "Legal`, et une relation Doctrine
     * creerait une dependance de mapping entre deux modules qui doivent vivre separement (D2).
     *
     * @return array{id: Uuid, version: int}|null
     */
    private function cgvPubliees(PanierEnLigne $panier): ?array
    {
        $etablissement = $panier->getVitrine()?->getEtablissement()?->getId();
        if ($etablissement === null) {
            return null;
        }

        $ligne = $this->em->getConnection()->executeQuery(
            'SELECT id, version FROM legal_document
             WHERE establishment_id = ? AND type = ? AND status = ?
             ORDER BY version DESC LIMIT 1',
            [$etablissement->toBinary(), 'terms_of_sale', 'published'],
        )->fetchAssociative();

        if ($ligne === false) {
            return null;
        }

        return ['id' => Uuid::fromBinary($ligne['id']), 'version' => (int) $ligne['version']];
    }
}
