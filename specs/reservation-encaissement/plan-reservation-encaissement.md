# Plan technique — Réservation payante → facturation/encaissement en caisse (`M5 × M2`, lien transverse)

- **Spec source :** specs/reservation-encaissement/spec-reservation-encaissement.md (US-RESAENC-01 à 06,
  RG-RESAENC-01 à 10)
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Nature de ce lot :** **comblement de gaps** sur un mécanisme déjà largement en place
  (`VenteReservationHandler::creerVente()` déjà branché dans `ReserverProcessor`, RG-RESAENC-01/02/06 déjà
  satisfaites par le code actuel). Ce plan ne couvre **que** les 3 gaps minimum demandés (G1, G2, G3 —
  G3 est une non-régression à préserver, pas un développement) ; les articulations signalées mais « hors
  détail » de la spec (§4.5 PaiementDistance, §4.7 Facturation à terme) ne sont **pas** implémentées ici.

## 0. Décisions structurantes (résumé)

1. **G1 (avoir sur annulation payée) et G2 (nettoyage vente pendante) sont traités par UN SEUL nouveau
   service**, `App\Reservation\Service\AnnulationVenteReservationHandler`, appelé de façon **additive**
   depuis `AnnulerReservationProcessor` (aucune ligne existante supprimée). Il **réutilise**
   `App\Vente\Service\ContrePassationHandler::rembourser()` (déjà existant, inchangé) pour G1, et
   réplique la logique minimale de `ViderPanierProcessor` (vidage de lignes + recalcul) pour G2, car
   `ContrePassationHandler::annuler()` exige une Vente déjà `validee` (RG-RESAENC-10, §4.9 de la spec :
   « pas directement réutilisable en l'état ») — aucune modification de `ContrePassationHandler` n'est
   nécessaire ni proposée.
2. **Portée délai franc vs hors délai (RG-RESAENC-09/10) :**
   - **Avoir de remboursement (G1)** : déclenché **seulement** dans la branche `$dansDelai` (annulation
     libre) — hors délai franc, `RG-M5-09` s'applique tel quel, **aucun remboursement** (comportement
     explicitement demandé par la spec §4.8, non-régression CA-5/CA-9).
   - **Nettoyage de la vente pendante `en_cours` (G2)** : déclenché **dans les deux branches** (délai
     franc **et** hors délai/tardif). Justification : une Vente `en_cours` n'a **jamais** été encaissée
     (aucune recette acquise), donc la nettoyer ne contredit **jamais** le principe RG-M5-09 « la recette
     déjà encaissée reste acquise hors délai » — ce principe ne concerne que les Ventes `validee`. Ce
     choix élimine aussi un panier fantôme dans le cas no-show/tardif (risque symétrique non couvert
     explicitement par la spec mais cohérent avec son intention, §4.9). Non-régression vérifiée : les
     tests no-show existants (`AnnulationNoShowTest`) n'assertent pas sur `venteRattachee` et restent
     verts.
3. **G2 : pas d'Avoir émis**, la Vente pendante est simplement marquée `annulee` (lignes vidées,
   recalcul à 0,00 €) — choix par défaut retenu explicitement par la spec §4.9 parmi les deux options
   proposées (« vider + laisser orpheline » vs « marquer annulée ») : *marquer annulée*, plus explicite
   pour un agent qui consulterait cette Vente a posteriori.
4. **Statut de paiement observable (RG-RESAENC-03) : calculé, jamais persisté.** Nouvelle méthode
   `Reservation::statutPaiement(): StatutPaiementReservation`, dérivée en lecture seule de
   `modeDecompte` + `venteRattachee.statut` (source de vérité unique = `Vente.statut`, jamais un second
   champ qui pourrait diverger — exigence explicite de la spec §4.3). Choisi plutôt qu'un champ
   matérialisé pour respecter la contrainte « migration additive minimale, éviter si dérivable » :
   **aucune migration** n'est nécessaire pour ce lot.
5. **Aucune nouvelle permission.** Le déclenchement de l'avoir/nettoyage est un effet de bord
   **automatique et systématique** de `reservation.annuler` / `reservation.annuler_soi` (déjà gérés par
   `AnnulerReservationProcessor` + `ReservationSoiVoter`) — **même patron** que la création de la Vente
   rattachée par `ReserverProcessor`, qui ne requiert pas `vente.creer`. Aucun contrôle `vente.rembourser`
   n'est donc ajouté sur ce chemin (voir Risque n°1 : passage volontaire à côté des « Autorisations
   graduées » `App\Autorisation`).
6. **G3 (produit gratuit → aucune vente) : préservé sans modification.** `ReserverProcessor` ne crée déjà
   aucune `Vente` quand `tarifReference() <= 0` (`modeDecompte = Gratuit`) — confirmé par lecture de code,
   aucune régression possible car ce chemin n'est pas touché par G1/G2 (`AnnulationVenteReservationHandler`
   fait un **no-op immédiat** si `venteRattachee === null`, qui est précisément le cas `Gratuit`/`QuotaFormule`).

## 1. Entités & schéma

**Aucune migration requise pour ce lot** (conforme à la consigne « éviter une migration si dérivable » et
à la décision n°4 ci-dessus). Aucune colonne, table ou index n'est ajouté(e)/modifié(e).

| Entité (`App\<Module>\Entity\*`) | Champ | Type | Statut | Notes |
|---|---|---|---|---|
| `App\Reservation\Entity\Reservation` | `venteRattachee` | ManyToOne `Vente`, nullable | **existant, inchangé** | déjà porté, réutilisé tel quel pour G1/G2 (source de vérité) |
| | `statutPaiement()` | méthode dérivée → `StatutPaiementReservation` | **nouveau, non persisté** | pas de colonne ; calculée à l'appel, exposée en lecture via `#[Groups(['reservation:read'])]` sur le getter (même patron que `DemandePaiement::getJetonClair()`, `App\PaiementDistance\Entity\DemandePaiement.php:466-470`) |
| `App\Reservation\Enum\StatutPaiementReservation` *(nouvel enum)* | cases | `sans_objet`, `a_payer`, `payee`, `annulee` | **nouveau fichier PHP, pas de colonne** | miroir exact de RG-RESAENC-03 (valeurs ASCII, convention du dépôt — ex. `annulee_libre` sans accent dans `StatutReservation`) |
| `App\Vente\Entity\Vente` | `statut` | enum `StatutVente` | **existant, inchangé** | source de vérité seule (`en_cours`/`validee`/`annulee`/`avoir_emis`), lue mais jamais redéfinie par ce lot |
| `App\Vente\Entity\Avoir` | — | — | **existant, inchangé** | un Avoir `nature = remboursement` est créé par `ContrePassationHandler::rembourser()` (réutilisé tel quel) pour G1 |

> id = UUID (`symfony/uid`), déjà en place. Rattachement multi-entités : inchangé, hérité de
> `Reservation.etablissement` / `Vente.etablissement` déjà porté par le code existant.

### Nouvelle logique dérivée (`Reservation::statutPaiement()`)

```php
public function statutPaiement(): StatutPaiementReservation
{
    if ($this->modeDecompte !== ModeDecompteReservation::VenteUnite || $this->venteRattachee === null) {
        return StatutPaiementReservation::SansObjet; // gratuit / quota_formule (G3 préservé)
    }

    return match ($this->venteRattachee->getStatut()) {
        StatutVente::EnCours => StatutPaiementReservation::APayer,
        StatutVente::Validee, StatutVente::AvoirEmis => StatutPaiementReservation::Payee, // §4.3 : avoir_emis reste "payée" (payée puis remboursée), pas un retour à "à payer"
        StatutVente::Annulee => StatutPaiementReservation::Annulee,
    };
}
```

Exposé côté entité :

```php
#[Groups(['reservation:read'])]
public function getStatutPaiement(): string
{
    return $this->statutPaiement()->value;
}
```

## 2. API (API Platform)

**Aucune nouvelle opération.** Les opérations existantes de `Reservation` (`GetCollection`, `Get`,
`Post /reservation/reservations`, `Post /reservation/reservations/{id}/annuler`) sont réutilisées telles
quelles ; seul le **corps de réponse** change (nouveau champ `statutPaiement` en lecture, additif, aucun
champ retiré).

| Ressource | Opération | Changement | security: (inchangé) |
|---|---|---|---|
| `Reservation` | `GET /reservation/reservations`, `GET /reservation/reservations/{id}` | + champ `statutPaiement` (`sans_objet`\|`a_payer`\|`payee`\|`annulee`) dans `reservation:read` | `reservation.lire` ou (`reservation.lire_soi` + `ReservationSoiVoter`) — inchangé |
| `Reservation` | `POST /reservation/reservations/{id}/annuler` | Réponse inchangée en forme (toujours l'objet `Reservation` sérialisé, donc `statutPaiement` y apparaît désormais aussi) ; **effet de bord additif** côté serveur : Avoir M2 émis (G1) ou Vente pendante nettoyée (G2) selon le cas, cf. §3 ci-dessous | `reservation.annuler` ou (`reservation.annuler_soi` + `ReservationSoiVoter`) — **inchangé** |

Aucun filtre (`ApiFilter`) ajouté sur `statutPaiement` : champ calculé (non colonne SQL), non filtrable
par `SearchFilter` Doctrine en l'état (cf. Risque n°6).

## 3. Sécurité & droits

- **Aucune nouvelle permission.** Réutilise strictement `reservation.annuler` / `reservation.annuler_soi`
  (+ `ReservationSoiVoter`), déjà en place et inchangés.
- **Décision explicite (n°5 ci-dessus) :** le remboursement (G1) et le nettoyage (G2) sont des
  **effets de bord système**, non gatés par `vente.rembourser`/`vente.annuler` — cohérent avec le tableau
  Acteurs §3 de la spec (ligne « Système : … ne jamais valider/encaisser seul » — ici on rembourse/nettoie,
  on n'encaisse rien de nouveau). Voir Risque n°1 pour la question ouverte des Autorisations graduées.
- **Auteur tracé sur l'Avoir** : `ContrePassationHandler::creerAvoir()` exige un `Utilisateur $auteur`
  non nul — `Security::getUser()` dans `AnnulerReservationProcessor` est **toujours** une instance
  `Utilisateur` à ce point (agent **ou** client self-service, `ReservationSoiVoter` le confirme déjà en
  exigeant `Utilisateur::getClientLie()`), donc `\assert($utilisateur instanceof Utilisateur)` est sûr
  (même patron que `AnnulerVenteProcessor`/`RembourserVenteProcessor`).
- Pas de voter supplémentaire : `AnnulationVenteReservationHandler` est un service interne sans exposition
  API directe, appelé uniquement depuis `AnnulerReservationProcessor` déjà sécurisé.

## 4. Migrations

**Aucune migration.** Ce lot n'ajoute ni ne modifie de colonne/table/index (décision n°4, §1). Conforme à
la Definition of Done constitution §8.2 : « migration(s) fournie(s) » n'implique pas d'en créer une
inutile quand rien n'est persisté — validé explicitement par la spec elle-même (§4.3 : « calculé ou
persisté, choix laissé au plan technique »).

## 5. Modifications additives précises

### 5.1 Nouveau fichier `App\Reservation\Enum\StatutPaiementReservation`
```php
declare(strict_types=1);

namespace App\Reservation\Enum;

/** Statut de paiement observable d'une Reservation, dérivé de Vente.statut (RG-RESAENC-03). */
enum StatutPaiementReservation: string
{
    case SansObjet = 'sans_objet';
    case APayer = 'a_payer';
    case Payee = 'payee';
    case Annulee = 'annulee';
}
```

### 5.2 `App\Reservation\Entity\Reservation` (additif)
- Ajout de `statutPaiement(): StatutPaiementReservation` et `getStatutPaiement(): string` (§1) —
  **aucune** propriété/colonne ajoutée, **aucun** setter (dérivé uniquement).
- Imports additionnels : `App\Reservation\Enum\StatutPaiementReservation`, `App\Vente\Enum\StatutVente`.

### 5.3 Nouveau fichier `App\Reservation\Service\AnnulationVenteReservationHandler`
Service dédié, **injecté** dans `AnnulerReservationProcessor` (pas de logique métier dans l'entité,
constitution §7). Réutilise `ContrePassationHandler` (M2, inchangé) et `PanierCalculateur` (M2, inchangé).

```php
declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Reservation;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Service\ContrePassationHandler;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Traite la Vente rattachée d'une Reservation au moment de son annulation (RG-RESAENC-09/10, gaps
 * G1/G2 du plan) : émet un avoir de remboursement si elle était déjà encaissée et dans le délai franc
 * (G1, réutilise ContrePassationHandler::rembourser — inchangé), nettoie une Vente pendante jamais
 * réglée pour éviter un panier fantôme en caisse (G2, ContrePassationHandler::annuler exige une Vente
 * déjà validee — non réutilisable en l'état pour ce cas, §4.9 de la spec). No-op si aucune Vente
 * rattachée (produit gratuit / quota de formule, G3 préservé) ou si elle est déjà annulee/avoir_emis
 * (idempotence défensive).
 */
final class AnnulationVenteReservationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContrePassationHandler $contrePassation,
        private readonly PanierCalculateur $calc,
    ) {
    }

    public function traiter(Reservation $reservation, Utilisateur $auteur, bool $remboursementAutorise): void
    {
        $vente = $reservation->getVenteRattachee();
        if ($vente === null) {
            return; // gratuit / quota_formule (G3) : rien à faire
        }

        match ($vente->getStatut()) {
            StatutVente::EnCours => $this->nettoyer($vente),
            StatutVente::Validee => $remboursementAutorise ? $this->rembourser($reservation, $vente, $auteur) : null,
            StatutVente::Annulee, StatutVente::AvoirEmis => null, // idempotent, déjà traité
        };
    }

    /** G1 (RG-RESAENC-09) : avoir de remboursement intégral, même mécanisme qu'un remboursement M2 standard. */
    private function rembourser(Reservation $reservation, Vente $vente, Utilisateur $auteur): void
    {
        $motif = sprintf('Annulation réservation %s dans le délai franc (RG-RESAENC-09)', (string) $reservation->getId());
        $avoir = $this->contrePassation->rembourser($vente, null, $motif, $auteur); // null = montant total
        $this->em->persist($avoir);
    }

    /** G2 (RG-RESAENC-10) : nettoyage d'une Vente jamais réglée, sans Avoir (rien n'a été encaissé). */
    private function nettoyer(Vente $vente): void
    {
        foreach ($vente->getLignes()->toArray() as $ligne) {
            $vente->removeLigne($ligne);
            $this->em->remove($ligne);
        }
        $this->calc->recalculerVente($vente);
        $vente->setStatut(StatutVente::Annulee);
    }
}
```

### 5.4 `App\Reservation\State\AnnulerReservationProcessor` (additif, aucune ligne existante supprimée)
- Constructeur : + `private readonly AnnulationVenteReservationHandler $annulationVente`.
- Import additionnel : `App\Securite\Entity\Utilisateur`.
- Corps de `process()` — insertion entre le bloc `if ($dansDelai) {...} else {...}` existant et le bloc
  `if ($ressource !== null) {...}` existant :

```php
// --- code existant, inchangé ---
if ($dansDelai) {
    $data->setStatut(StatutReservation::AnnuleeLibre);
    $this->em->flush();
} else {
    $this->facturationHandler->declencher($data, StatutReservation::AnnuleeTardiveFacturee);
}

// --- ajout G1/G2 (RG-RESAENC-09/10) ---
$utilisateur = $this->security->getUser();
\assert($utilisateur instanceof Utilisateur);
$this->annulationVente->traiter($data, $utilisateur, $remboursementAutorise: $dansDelai);
$this->em->flush();

// --- code existant, inchangé ---
if ($ressource !== null) {
    $this->jaugeMere->decrementer($ressource);
    $this->em->flush();
}

if ($creneau !== null) {
    $this->promotion->promouvoirSiPlaceDisponible($creneau);
}

return $data;
```

Aucun autre fichier de `App\Reservation`/`App\Vente` n'est modifié. `ContrePassationHandler`,
`PanierCalculateur`, `VenteReservationHandler`, `ReserverProcessor`, `ViderPanierProcessor`,
`AnnulerVenteProcessor`, `RembourserVenteProcessor` restent **strictement inchangés**.

## 6. Tests

| Test | Type | Couvre |
|---|---|---|
| `Api\ReservationEncaissementTest::testCa1ReservationPayanteExposeStatutAPayer` | Fonctionnel API | CA-1 (statutPaiement = `a_payer` en plus des assertions déjà couvertes par `ReservationQuotaVenteTest::testCa3ReservationDeclencheVenteUniteSansQuota`) |
| `Api\ReservationEncaissementTest::testCa2EncaissementGuichetPasseReservationPayee` | Fonctionnel API | CA-2 (paiement + validation Vente → `Vente.statut = validee`, `statutPaiement = payee`) |
| `Api\ReservationEncaissementTest::testCa3ProduitGratuitAucuneVenteStatutSansObjet` | Fonctionnel API | CA-3 / G3 non-régression explicite (`statutPaiement = sans_objet`, aucune Vente créée) |
| `Api\ReservationEncaissementTest::testCa4AnnulationResaPayeeDansDelaiGenereAvoir` | Fonctionnel API | CA-4 / **G1** (Avoir `nature=remboursement`, `montant` = total, `Vente.statut = avoir_emis`, `Reservation.statut = annulee_libre`, jauge libérée) |
| `Api\ReservationEncaissementTest::testCa5AnnulationResaPayeeHorsDelaiAucunRemboursement` | Fonctionnel API | CA-5 (hors délai franc : `Vente.statut` reste `validee`, aucun Avoir créé, `Reservation.statut = annulee_tardive_facturee`) |
| `Api\ReservationEncaissementTest::testCa6AnnulationResaAPayerNonRegleeNettoieVente` | Fonctionnel API | CA-6 / **G2** (`Vente.statut = annulee`, lignes vidées, `total = 0.00`, aucun Avoir créé) |
| `Api\ReservationEncaissementTest::testCa7FactureJustificativeSurVenteEncaisseeNeDoublePasLaRecette` | Fonctionnel API | CA-7, non-régression `RG-FACT-03.1` (`POST /factures/depuis-vente` sur la Vente rattachée d'une réservation encaissée → facture `acquittee`, pas de nouvelle recette) |
| `Api\ReservationEncaissementTest::testCa9NoShowSurReservationDejaPayeeNeRembourseRienNiDoubleFacture` | Fonctionnel API | CA-9, non-régression (réservation payée puis bascule no-show : `Vente.statut` reste `validee`, `FacturationNoShow` créée normalement, aucun avoir) — cas non couvert par les tests M5 existants (ceux-ci ne paient jamais la Vente avant le no-show) |
| `Api\ReservationEncaissementTest::testCa10VenteEnCoursNettoyeeAussiHorsDelaiFranc` | Fonctionnel API | Décision n°2 (G2 appliqué aussi en branche tardive) — annulation hors délai d'une réservation « à payer » jamais réglée : `Vente.statut = annulee`, `FacturationNoShow` créée normalement (les deux mécanismes coexistent sans conflit) |
| `Unit\StatutPaiementReservationTest::testDerivationDesQuatreCas` | Unitaire | RG-RESAENC-03, les 4 branches du `match` (`sans_objet`/`a_payer`/`payee`/`annulee`) sans dépendance DB |
| `Api\AnnulationNoShowTest::testCa8AnnulationGratuiteDansDelaiFranc` *(existant, renforcé)* | Fonctionnel API | Non-régression + assertion additive : la Vente rattachée (PADEL payant, jamais réglée) passe bien à `annulee` après l'annulation dans le délai franc — vérifie que G2 ne casse pas ce test déjà vert |
| `Api\AnnulationNoShowTest::*` *(existants, 5 tests)* | Fonctionnel API | Non-régression stricte : aucune modification attendue de comportement observable (statuts `Reservation`/`FacturationNoShow` inchangés) |
| `Vente\Api\ContrePassationTest::*` *(existants)* | Fonctionnel API | Non-régression : `ContrePassationHandler` non modifié, ses tests propres restent la garantie de non-régression du mécanisme réutilisé |

## 7. Ordonnancement

1. **T1** — Créer `App\Reservation\Enum\StatutPaiementReservation` (aucune dépendance).
2. **T2** — Ajouter `statutPaiement()`/`getStatutPaiement()` sur `Reservation` (dépend de T1) ; vérifier
   la sérialisation via un test unitaire simple (pas de kernel requis pour cette partie).
3. **T3** — Créer `App\Reservation\Service\AnnulationVenteReservationHandler` (dépend de rien côté M5,
   réutilise `ContrePassationHandler`/`PanierCalculateur` existants tels quels).
4. **T4** — Brancher `AnnulationVenteReservationHandler` dans `AnnulerReservationProcessor` (dépend de T3
   ; modification additive, cf. §5.4).
5. **T5** — Tests fonctionnels `ReservationEncaissementTest` (CA-1 à CA-10 du tableau §6) — dépend de
   T1-T4.
6. **T6** — Test unitaire `StatutPaiementReservationTest` — dépend de T1-T2, peut être fait en parallèle
   de T3-T4.
7. **T7** — Renforcement additif de `AnnulationNoShowTest::testCa8AnnulationGratuiteDansDelaiFranc`
   (une assertion supplémentaire sur `venteRattachee.statut`) — dépend de T4, dernier, pour confirmer la
   non-régression avec la suite existante déjà verte.
8. **T8** — Relecture complète de la suite `App\Tests\Reservation\**` et `App\Tests\Vente\**` (non-
   régression globale) avant merge.

## 8. Risques / à valider

1. **Contournement potentiel des « Autorisations graduées » (`App\Autorisation`)** — `AnnulerVenteProcessor`
   et `RembourserVenteProcessor` passent tous deux par `ServiceAutorisation::evaluer('vente.annuler'|
   'vente.rembourser', …)` avant `ContrePassationHandler`, avec gestion d'escalade (`EscaladeRequiseException`,
   jeton de rejeu). Le remboursement **automatique** déclenché par l'annulation d'une réservation payée (G1)
   **ne passe pas** par ce garde-fou (décision n°5, §3). Si un établissement configure une limite sur
   `vente.rembourser`, l'annulation d'une réservation payée la contourne. **À valider avec le
   métier/sécurité** : faut-il router ce remboursement automatique via `ServiceAutorisation` (et comment
   gérer une décision `EscaladeRequise` dans un flux non interactif — pas de requête HTTP dédiée où
   proposer un jeton de rejeu) ? Retenu par défaut pour ce lot : **non**, cohérent avec le fait que la
   création de la Vente rattachée elle-même (`VenteReservationHandler::creerVente`) ne passe déjà par
   aucune autorisation graduée.
2. **`ContrePassationHandler::rembourser()` n'invalide jamais le support/billet émis** (contrairement à
   `annuler()`, qui le fait si `Vente.isImprime()`) — comportement M2 **existant et non modifié** par ce
   lot, mais avec un impact direct ici : si un billet/QR d'accès a été émis à la validation de la Vente
   rattachée (`spec-vente.md` §4.6), il **reste valide** après l'avoir de remboursement d'une réservation
   annulée (G1). Risque d'accès résiduel après remboursement intégral. Hérité de M2, pas introduit par ce
   lot, mais **à signaler au métier** car le cas d'usage réservation le rend concret (contrairement à une
   vente boutique classique où le produit n'ouvre pas nécessairement un accès physique).
3. **« Payée » recouvre aussi « remboursée » (`avoir_emis`)** — choix **explicite** de la spec §4.3
   (« le passage à avoir_emis reste "payée puis remboursée", pas un retour à "à payer" »), reproduit tel
   quel (§0 décision n°4, §1). Risque UX : un écran naïf affichant `statutPaiement = payee` pour une
   réservation en fait remboursée serait trompeur (constitution §2, règle d'or de simplicité) — **à
   trancher côté IHM** (ex. afficher aussi `Vente.statut` brut, ou distinguer visuellement `avoir_emis` de
   `validee` même si la valeur API `statutPaiement` est la même) ; aucun changement recommandé côté API
   dans ce lot, qui suit la spec littéralement.
4. **Remboursement d'un règlement partiel sur une Vente jamais `validee`** (paiement scindé commencé,
   `RG-M2-03`, puis annulation de la réservation avant validation) — **non couvert** par ce lot (§7 cas
   limite de la spec, point ouvert n°8) : G2 marque la Vente `annulee` sans rembourser le(s) `Paiement`
   déjà enregistré(s) dessus (`ContrePassationHandler` exige `Vente.statut ∈ {validee, avoir_emis}`, donc
   inapplicable ici). Risque réel si un agent encaisse un acompte partiel puis annule avant validation —
   **à confirmer avec le métier** si ce cas doit être traité dans un lot ultérieur.
5. **Filtrage back-office sur `statutPaiement` non supporté** (champ calculé, pas de colonne SQL) — si un
   écran « réservations à encaisser » nécessite un filtre serveur performant, il faudra soit une extension
   Doctrine dédiée (```QueryCollectionExtensionInterface``` filtrant sur un `JOIN` vers `Vente.statut`),
   soit matérialiser le champ (migration future) — différé volontairement (décision n°4, cohérent avec la
   spec qui laisse ce choix ouvert).
6. **Réservation en ligne payante sans session de caisse** (§7 spec, point ouvert majeur) — **hors
   périmètre** de ce lot (gaps minimum G1-G3 uniquement) ; non traité ici.
7. **Auteur de l'Avoir potentiellement un compte client self-service** (`reservation.annuler_soi`) plutôt
   qu'un agent — cohérent avec CA-4 (« le bénéficiaire ou un agent l'annule ») et non bloquant
   techniquement (`Avoir.auteur` accepte tout `Utilisateur`, NF525 exige seulement un auteur identifié,
   satisfait), mais **à confirmer** que ce n'est pas un point de vigilance métier/compta (un
   remboursement tracé « auto-déclenché par le client lui-même » plutôt que par un opérateur).
8. **`ModeMontantAnnulation`/pénalité d'annulation dans le délai franc** — la spec (RG-RESAENC-09, §4.8)
   précise un remboursement **intégral** dans le délai franc (pas de retenue) ; ce plan applique donc
   `ContrePassationHandler::rembourser($vente, null, …)` = montant total, **sans** consulter
   `RegleAnnulation.valeurMontant`/`modeMontant` (ceux-ci ne s'appliquent qu'à `RG-M5-09`, hors délai). Si
   le métier souhaitait un jour une pénalité même dans le délai franc, ce serait un changement de règle de
   gestion (RG-RESAENC-09 actuelle), pas un défaut de ce plan.
