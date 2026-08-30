<?php

declare(strict_types=1);

namespace App\Acces\Adapter;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\TypeSupport as TypeSupportAcces;
use App\Acces\Port\ProjectionDroitInterface;
use App\Acces\Service\AppairageHandler;
use App\Acces\Service\ProductAccessZoneResolver;
use App\Vente\Entity\BilletSupport;
use App\Vente\Enum\StatutAppairage;
use App\Vente\Enum\TypeSupport as TypeSupportVente;
use App\Vente\Port\AppairageAccesInterface;
use App\Vente\Service\GenerateurCodeSupport;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Le pont manquant : un billet vendu devient un droit d'accès, sans passer par l'appairage manuel.
 *
 * ── CE QUI MANQUAIT, ET CE QUI NE MANQUAIT PAS ─────────────────────────────────────────────────
 *
 * `ValiderVenteService:149` appelle `$this->appairage->appairer($support)` **à chaque vente**, depuis
 * toujours. Le port était simplement câblé sur `App\Vente\Port\AppairageAccesStub`, qui bascule un
 * statut sur le `BilletSupport` et **ne parle jamais au module Accès**. Résultat mesuré le 30/08 :
 * cinq billets vendus, cinq supports d'accès, et **aucun croisement** — le tourniquet répondait
 * « Support inconnu » à un code qu'il savait pourtant vérifier (la signature HMAC est reconnue par
 * `ValidationPassageHandler`, c'est le `Acces\Entity\Support` qui n'existait pas).
 *
 * Rien n'était donc à écrire du côté vente, ni à inventer côté accès : la projection existe
 * (`ProjectionDroitInterface`), l'appairage existe (`AppairageHandler`), et tous deux fonctionnent.
 * Il manquait **l'implémentation du port**, c'est-à-dire précisément ce que le stub annonçait tenir
 * en attendant.
 *
 * ── ⚠ POURQUOI ON NE PROJETTE PAS QUAND LE PRODUIT NE DÉCLARE AUCUNE ZONE ──────────────────────
 *
 * `DroitAcces::ouvre()` rend `true` quand la collection d'espaces est vide : **un droit sans espace
 * ouvre tout**. C'est un choix assumé et documenté — pour l'appairage manuel, où refuser par défaut
 * aurait fermé des portes devant des porteurs déjà équipés.
 *
 * Ce raisonnement ne se transporte pas ici. Mesuré le 30/08 : **0 produit sur 17 déclare une zone**,
 * et 8 espaces existent. Projeter sans condition ferait donc de **chaque billet vendu un
 * passe-partout des huit espaces** — une régression de sécurité à l'échelle de toutes les ventes, et
 * silencieuse. La compatibilité protège l'existant ; elle n'autorise pas un chemin neuf à ouvrir
 * plus grand que ce que l'exploitant a déclaré.
 *
 * ── ⚠ ET POURQUOI « PAS DE ZONE » N'EST PAS UN ÉCHEC ──────────────────────────────────────────
 *
 * `ValiderVenteService` traite `false` comme un échec d'appairage : le support reste en « echec » et
 * **sa remise est bloquée**. Rendre `false` pour un produit sans zone bloquerait donc la remise de
 * tous les billets de tous les produits — c'est-à-dire casserait toutes les ventes aujourd'hui.
 *
 * Et ce serait faux au fond : une bouteille d'eau, un cadenas, un article de boutique n'ouvrent
 * aucune porte, et c'est normal. **Un produit sans zone ne rate pas son appairage : il n'en a pas.**
 * On rend donc `true` sans rien créer, exactement comme le stub, et le comportement de ces produits
 * est strictement inchangé.
 *
 * `false` reste réservé à un vrai échec — un code déjà appairé, un support bloqué — c'est-à-dire aux
 * cas où remettre le billet serait une faute.
 */
final class SaleAccessPairingAdapter implements AppairageAccesInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectionDroitInterface $projection,
        private readonly AppairageHandler $pairings,
        private readonly ProductAccessZoneResolver $zones,
        private readonly GenerateurCodeSupport $codeGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function appairer(BilletSupport $support): bool
    {
        // Filet défensif hérité du stub : l'identifiant est normalement posé par
        // `ValiderVenteService::creerSupport()`. Un appelant direct du port (ré-appairage) peut
        // arriver sans.
        if ($support->getIdentifiantSupport() === null) {
            $support->setIdentifiantSupport($this->codeGenerator->genererPourType($support->getType()));
        }

        $identifier = (string) $support->getIdentifiantSupport();

        // Conservé du stub : `ECHEC…` simule un refus d'appairage (CA-12). Ce n'est pas décoratif —
        // c'est le seul moyen d'éprouver le chemin d'échec depuis un test de bout en bout.
        if (str_starts_with($identifier, 'ECHEC')) {
            $support->setStatutAppairage(StatutAppairage::Echec);

            return false;
        }

        $establishment = $support->getVente()?->getEtablissement();

        // ⚠ `LigneVente::getProduit()` REND UN `Uuid`, PAS UN `Produit`.
        //
        // C'est le patron D2 : une référence vers un autre module est une colonne `uuid` nue, sans
        // relation Doctrine. Le nom du getter est trompeur pour qui vient d'`Offre`, où `getProduit()`
        // rend bien l'entité — deux méthodes homonymes, deux formes. Une première version appelait
        // `->getId()` dessus : « Call to undefined method UuidV4::getId() », en pleine validation de
        // vente. Vérifier la signature, jamais deviner d'après le nom.
        $productRef = $support->getLigne()?->getProduit();

        if ($establishment === null) {
            // Sans établissement, ni la projection ni le cloisonnement ne sont calculables. On ne
            // devine pas : on n'ouvre rien, et on ne bloque pas la remise pour autant.
            $support->setStatutAppairage(StatutAppairage::Actif);

            return true;
        }

        // ⚠ LA DÉCISION TIENT ICI, ET ELLE EST VOLONTAIREMENT AVANT LA PROJECTION.
        //
        // Interroger les zones d'abord évite de créer un `DroitAcces` qu'on ne veut pas : la
        // projection écrit en base, et un droit sans espace ouvre tout. On ne fabrique donc jamais
        // l'objet dangereux, plutôt que de le fabriquer puis de tenter de le neutraliser.
        if ($this->zones->spacesFor($productRef, $establishment) === []) {
            $support->setStatutAppairage(StatutAppairage::Actif);

            return true;
        }

        try {
            $right = $this->projection->projeter($support->getId(), $establishment);

            $this->pairings->appairer(
                $identifier,
                $this->accessType($support->getType()),
                $right,
                ModeAppairage::Caisse,
                $establishment,
            );
        } catch (\Throwable $e) {
            // ⚠ UN ÉCHEC ICI NE DOIT PAS ANNULER LA VENTE. `appairer()` est appelé DANS la
            // transaction de validation : laisser remonter un conflit ferait échouer un encaissement
            // déjà accepté par le client. Le contrat du port prévoit exactement ce cas — `false`
            // bloque la remise du support, ce qui est la bonne sanction : le billet n'ouvrira rien,
            // donc il ne doit pas être remis comme s'il ouvrait.
            $this->logger->error('Appairage à la vente refusé par le module Accès.', [
                'support' => (string) $support->getId(),
                'identifiant' => $identifier,
                'raison' => $e->getMessage(),
            ]);

            $support->setStatutAppairage(StatutAppairage::Echec);

            return false;
        }

        $support->setStatutAppairage(StatutAppairage::Actif);

        return true;
    }

    public function invalider(BilletSupport $support): void
    {
        $support->setStatutAppairage(StatutAppairage::Invalide);

        $identifier = $support->getIdentifiantSupport();
        if ($identifier === null) {
            return;
        }

        // ⚠ ET ON RÉVOQUE AUSSI CÔTÉ ACCÈS, SANS QUOI L'ANNULATION SERAIT DÉCORATIVE.
        //
        // Le stub se contentait du statut côté vente. Maintenant que l'appairage existe réellement,
        // ne pas le révoquer laisserait un billet annulé continuer d'ouvrir la porte — un défaut que
        // le stub ne pouvait pas avoir, et que son remplacement introduirait si on l'oubliait.
        $accessSupport = $this->em->getRepository(Support::class)->findOneBy(['identifiant' => $identifier]);
        if (!$accessSupport instanceof Support) {
            return;
        }

        $activePairing = $this->em->getRepository(Appairage::class)->findOneBy(['support' => $accessSupport, 'actif' => true]);
        if ($activePairing instanceof Appairage) {
            $this->pairings->revoquer($activePairing);
        }
    }

    /**
     * Le type côté vente décrit ce qu'on remet au client ; le type côté accès décrit la technologie
     * lue par l'équipement. La correspondance est de présentation — la validation d'un passage
     * retrouve le support par son identifiant, jamais par son type.
     */
    private function accessType(TypeSupportVente $type): TypeSupportAcces
    {
        return match ($type) {
            TypeSupportVente::Wallet => TypeSupportAcces::Wallet,
            TypeSupportVente::Carte, TypeSupportVente::Bracelet => TypeSupportAcces::Rfid,
            default => TypeSupportAcces::Qr,
        };
    }
}
