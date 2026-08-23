<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\SessionCaisse;
use App\Vente\Entity\Vente;
use App\Vente\Service\GenerateurNumero;
use App\Vente\Service\LecteurCorps;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Ouvre un panier (POST /ventes, CA-1). Refuse hors session ouverte (RG-M2-01). La clé d'idempotence
 * (générée côté client en mode dégradé) rend l'ouverture rejouable sans doublon (RG-M2-08). Corps :
 *   { "session": iri|uuid, "client"?: uuid, "cleIdempotence"?: uuid, "origineHorsLigne"?: bool, "id"?: uuid }
 *
 * @implements ProcessorInterface<mixed, Vente>
 */
final class CreerVenteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly GenerateurNumero $generateur,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Vente
    {
        $corps = $this->lecteur->corps();

        // Anti-doublon idempotent : si la clé existe déjà, renvoyer la vente correspondante (no-op).
        $cle = $this->uuid($corps['cleIdempotence'] ?? null);
        if ($cle !== null) {
            $existante = $this->em->getRepository(Vente::class)->findOneBy(['cleIdempotence' => $cle]);

            // D8 — trouve par le garde-fou de cloisonnement le 23/08, **pendant** que je corrigeais la
            // resolution de session dans ce meme fichier : mon correctif a rendu la seconde resolution
            // visible a la regle « le controle porte sur l'entite resolue ».
            //
            // La cle d'idempotence vient du corps, la colonne n'est pas unique en base, et la vente
            // trouvee etait **renvoyee telle quelle** : connaitre la cle d'une vente d'un autre
            // etablissement en rendait le contenu — montant, lignes, client.
            //
            // On ignore une vente hors perimetre plutot que de refuser : le comportement devient
            // identique a celui d'une cle inconnue, et la creation se poursuit normalement. Refuser en
            // 404 aurait distingue « cle inconnue » de « cle utilisee ailleurs », donc renseigne
            // l'appelant sur l'existence d'une vente qu'il n'a pas le droit de voir.
            if ($existante !== null
                && (string) $existante->getEtablissement()?->getId()
                   !== (string) $this->contexte->etablissementActif()?->getId()) {
                $existante = null;
            }

            if ($existante !== null) {
                return $existante;
            }
        }

        $session = $this->resoudreSession($corps['session'] ?? null);
        if (!$session->estOuverte()) {
            throw new ConflictHttpException('Aucune session ouverte : vente impossible (RG-M2-01).');
        }

        $vente = new Vente();
        if (($id = $this->uuid($corps['id'] ?? null)) !== null) {
            $vente->setId($id);
        }
        if ($cle !== null) {
            $vente->setCleIdempotence($cle);
        }
        $vente->setSession($session)
            ->setEtablissement($session->getEtablissement())
            ->setNumero($this->generateur->numeroVente($session))
            ->setOrigineHorsLigne(($corps['origineHorsLigne'] ?? false) === true);
        if (($client = $this->uuid($corps['client'] ?? null)) !== null) {
            $vente->setClient($client);
        }

        $this->em->persist($vente);
        $this->em->flush();

        return $vente;
    }

    private function resoudreSession(mixed $reference): SessionCaisse
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException('Référence de session obligatoire pour ouvrir une vente.');
        }
        $session = $this->em->getRepository(SessionCaisse::class)->find($uuid);
        if ($session === null) {
            throw new UnprocessableEntityHttpException('Session introuvable.');
        }

        // D8 — la session vient d'un identifiant fourni par le client et etait resolue par un `find()`
        // direct, sans aucun controle. Ce n'est pas qu'une fuite : plus bas, **l'etablissement de la
        // session determine celui de l'objet cree**. Passer la session d'un autre etablissement n'y
        // donnait donc pas seulement acces — cela y creait une ecriture.
        //
        // Troisieme et derniere porte de la meme famille (n10) : les deux autres,
        // `MouvementCaisseProcessor` et `EmettreVenteNoShowProcessor`, ont ete fermees le 19 et le 23/08.
        //
        // Echec ferme en 404 : un 403 confirmerait l'existence de la session ailleurs. Une session sans
        // etablissement echoue aussi — fermeture par defaut.
        $actif = $this->contexte->etablissementActif();
        if ((string) $session->getEtablissement()?->getId() !== (string) $actif?->getId()) {
            throw new NotFoundHttpException('Session introuvable.');
        }

        return $session;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
