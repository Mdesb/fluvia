<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\SessionCaisse;
use App\Vente\Entity\Vente;
use App\Vente\Service\DirectSalePoint;
use App\Vente\Service\GenerateurNumero;
use App\Vente\Service\LecteurCorps;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Ouvre un panier (POST /ventes, CA-1). Refuse hors session ouverte (RG-M2-01) — **sauf en vente
 * directe** (D44-bis), où l'absence de session est le mode de fonctionnement et non un oubli. La clé d'idempotence
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
        private readonly DirectSalePoint $venteDirecte,
        private readonly Security $securite,
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

        // D44-bis — **deux manières de vendre, pas une règle assouplie.** Ne pas fournir de session
        // n'est pas une omission qu'on tolérerait : c'est la demande d'une vente directe, et elle a son
        // propre droit. RG-M2-01 reste entière pour la caisse, juste en dessous.
        //
        // Le droit plutôt qu'un mode d'établissement : tranché par Maxime (D45-bis). Certains clubs
        // n'ont même pas le module de caisse ; d'autres ont un guichet ET un gérant qui vend trois
        // abonnements par mois. C'est une propriété de la personne, pas du lieu.
        $vente = new Vente();
        if (($id = $this->uuid($corps['id'] ?? null)) !== null) {
            $vente->setId($id);
        }
        if ($cle !== null) {
            $vente->setCleIdempotence($cle);
        }

        if (($corps['session'] ?? null) === null) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                throw new UnprocessableEntityHttpException('Établissement actif requis pour une vente directe.');
            }
            if (!$this->securite->isGranted('PERM', 'vente.vente_directe')) {
                // 403 et non 404 : la vente directe n'est pas une ressource dont on cacherait
                // l'existence, c'est un droit qu'on a ou qu'on n'a pas. Il n'y a rien à énumérer ici.
                throw new AccessDeniedHttpException('Vente sans session : droit vente.vente_directe requis (D44-bis).');
            }

            $pdv = $this->venteDirecte->forEstablishment($etablissement);
            $vente->setPointDeVente($pdv)
                ->setEtablissement($etablissement)
                ->setNumero($this->generateur->numeroVenteDirecte($pdv))
                ->setOrigineHorsLigne(false);

            $this->em->persist($vente);
            $this->em->flush();

            return $vente;
        }

        $session = $this->resoudreSession($corps['session']);
        if (!$session->estOuverte()) {
            throw new ConflictHttpException('Aucune session ouverte : vente impossible (RG-M2-01).');
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
