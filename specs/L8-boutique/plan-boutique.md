# Plan technique — Boutique en ligne & tunnel d'achat (`M3` / lot `L8`)

- **Spec source :** specs/L8-boutique/spec-boutique.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Couvre :** US-L8-01 à 14 · RG-M3-01 à 18 · CA-1 à CA-19 (⚠ US non encore validées/numérotées
  officiellement dans le backlog — spec préambule, même réserve que `reservation`/`musee`)

> **Réutilisation socle L0 (à ne pas dupliquer)** — hiérarchie `App\Organisation\Entity\{Groupe,Region,
> Etablissement,Espace}` (RG-SOCLE-01) ; `PermissionVoter` (attribut `PERM`, sujet `"module.action"`,
> `app/src/Securite/Security/PermissionVoter.php`, RG-SOCLE-04) sur `Affectation`/`CalculateurDroits` ;
> `ContexteEtablissement` (en-tête `X-Etablissement`, RG-SOCLE-05) ; `Utilisateur` (`app/src/Securite/
> Entity/Utilisateur.php`, code réel) — **porte déjà** un champ `clientLie` (UUID logique vers
> `App\Crm\Entity\Client`) utilisé par L5/CRM et le module Réservation pour les droits `_soi`
> (`Client::estLieA()`, `ReservationSoiVoter`, `ReserverProcessor`) : **c'est le mécanisme d'auth
> client final déjà choisi par le dépôt**, réutilisé tel quel en L8 (§0 décision n°3) plutôt que de
> bâtir un firewall dédié. `App\Audit\Doctrine\AuditWriteSubscriber` append-only (RG-SOCLE-07) —
> **étendu** avec les entités sensibles Boutique (T14).
>
> **Réutilisation M1 Offre (code réel, `app/src/Offre/Entity/*`)** — `Produit` (catalogue, canal
> `Canal::EnLigne`, cycle de publication RG-M1-07/09), `Stock`/`Pool` (`disponibiliteEffective()`,
> décrément atomique déjà **conçu pour la vente en ligne** — voir `DecrementStockHandler`
> ci-dessous), `Formule` (`sepaActif`, pilote RG-M3-12/17), `Promotion`. **Aucun champ M1 dupliqué.**
>
> **Réutilisation module Réservation (code réel, `app/src/Reservation/Entity/*`)** — `Creneau`,
> `JaugeCreneauGuard`, `Reservation`, `ListeAttente` (`POST /reservation/creneaux/{id}/liste-attente`,
> réutilisé tel quel pour un créneau complet, §8 spec). L8 **crée des `Reservation` confirmées**
> directement (pas via `ReserverProcessor`, qui créerait une seconde `Vente` — §0 décision n°2),
> exactement comme le fait déjà `App\Musee\State\CreerReservationOtaProcessor` pour une vente OTA.
>
> **Réutilisation M2 Vente (code réel, `app/src/Vente/Entity/*`, `app/src/Vente/Service/*`)** —
> **découverte clé** : `App\Vente\Service\DecrementStockHandler` (décrément atomique du stock à la
> validation) porte déjà dans son commentaire de code *« évite la survente concurrente inter-caisses /
> avec la vente en ligne (M3) sans verrou long »* (`app/src/Vente/Service/DecrementStockHandler.php:19`)
> — **M2 a été conçu en anticipant L8**. `ValiderVenteService::valider()` (décrément stock + scellement
> NF525 + émission/appairage `BilletSupport`) est **directement réutilisé sans aucune modification**
> pour l'étape 4 du tunnel (§0 décision n°1). `App\Vente\Port\ClientM4Interface`
> (`ClientM4Adapter`, code réel L5) est réutilisé pour résoudre/créer le `Client` (M4) d'un **achat
> invité** sans jamais créer de compte de connexion (§0 décision n°4). `Avoir`/`ContrePassationHandler`
> réutilisés pour le remboursement (§0 décision n°6).
>
> **Réutilisation L3 Accès (code réel)** — `App\Vente\Port\AppairageAccesInterface` (frontière déjà
> posée en L2, bindée en stub `AppairageAccesStub` dans `config/services.yaml:40` — gap **hérité**,
> non résolu ici, cf. Risque n°7) ; `TypeSupport::{Qr,Wallet,Bracelet}` (`app/src/Vente/Enum/
> TypeSupport.php`) couvrent déjà QR dynamique / wallet / bracelet physique (RG-M3-04/14/18) — **aucune
> nouvelle valeur d'enum nécessaire**.
>
> **Réutilisation M4 CRM (code réel, `app/src/Crm/Entity/*`)** — `Client` (dateNaissance déjà porté,
> RG-M3-10 anti-homonyme réutilise ce champ **sans duplication**), `Beneficiaire`/`Famille`
> (RG-M4-02), `Consentement` (canal `boutique`, RG-M3-07), `PorteMonnaieVirtuel` (recharge app mobile).
>
> **Réutilisation M6 Compta (code réel)** — `App\Compta\Entity\ProfilExploitant.type`
> (`TypeExploitant::{RegieDirecte,Dsp,GroupePrive}`) pilote la commutation PayFiP/PSP (RG-M3-11) ;
> `App\Compta\Port\PayFipInterface`/`PayFipStubAdapter` **directement enveloppés** (pas réécrits) par
> l'adaptateur Boutique (§2.2).
>
> **Réutilisation `App\Sepa` (code réel)** — `MandatSepa`, `TokenisationIbanInterface`,
> `CreerMandatSepaProcessor` réutilisés tels quels pour RG-M3-17 (§2.4).
>
> **Réutilisation `symfony/mailer` (dépendance réelle, déjà utilisée)** — `MailerInterface` employé
> directement (patron `App\Securite\Notification\InvitationMailer`, code réel), pas de port/interface
> supplémentaire : la confirmation de commande et la relance panier sont des e-mails Symfony standards.
>
> **Non réutilisé intentionnellement (limite du lot, cf. §0 décision n°7)** — les objets OTA de
> `App\Musee\Entity\{PartenaireOTA,AllocationQuotaOTA,ReservationOTA}` (code réel, verticale) **ne sont
> ni déplacés ni modifiés** par ce lot : L8 définit ses **propres** objets génériques
> `App\Boutique\Entity\{PartenaireOTA,AllocationQuotaOTA,ReversementOTA}`, scopés `Vitrine`, réutilisant
> le **même mécanisme de décrément réel** (Stock M1 / JaugeCreneauGuard Réservation) que la vente
> directe — sans toucher à `App\Musee\*`. La réconciliation complète (déjà signalée comme point ouvert
> par `plan-musee.md` §7 risques n°5/n°10) reste un futur remaniement de specs, hors périmètre.

---

## 0. Décisions structurantes (résumé)

1. **Le tunnel ne réimplémente aucun moteur d'encaissement** — `App\Boutique\Service\
   ConfirmerCommandeHandler` construit une `Vente` M2 (canal `en_ligne`) à partir du panier (lignes,
   bénéficiaires), enregistre un `Paiement` (moyen `payfip`/`cb_psp`, `refTPE` = référence de
   transaction, `statutTPE` **réutilisé tel quel** — ses 4 valeurs `Accepte/Refuse/Annule/Timeout`
   couvrent exactement OK/échec/annulé/timeout d'un retour de paiement en ligne, aucune nouvelle énum),
   puis appelle **directement** `App\Vente\Service\ValiderVenteService::valider()` (code réel,
   inchangé) : décrément de stock atomique (`DecrementStockHandler`), scellement NF525, émission et
   appairage des `BilletSupport`. **La `CommandeEnLigne` du cahier = la `Vente` M2**, pas une nouvelle
   table — un satellite `SuiviCommandeEnLigne` (OneToOne `Vente`) porte les seuls champs boutique
   (`statutTunnel`, `vitrine`, `panierOrigine`).
2. **Le créneau timed-entry est réservé par une `Reservation` créée directement**, pas via
   `POST /reservation/reservations` (`ReserverProcessor`) — ce processor **créerait sa propre `Vente`**
   (`VenteReservationHandler`), ce qui doublonnerait le paiement déjà capturé par la `Vente` boutique.
   `ConfirmerCommandeHandler` instancie `Reservation` avec `venteRattachee = $venteBoutique` et
   `modeDecompte = VenteUnite`, exactement comme le fait déjà `App\Musee\State\
   CreerReservationOtaProcessor` pour une vente OTA (code réel, même patron, § de ce plan reconduit).
   La jauge est revérifiée avec `JaugeCreneauGuard::estComplet()` (réutilisé, non modifié) **juste avant**
   l'appel au paiement — cf. décision n°8 (survente).
3. **Authentification client final = `Utilisateur` (socle) + `clientLie`, réutilisée sans firewall
   dédié.** `App\Boutique\Entity\CompteClient` est un satellite fin (`OneToOne Utilisateur`) portant
   uniquement `franceConnectId` (nullable) — le mot de passe, le hachage, le MFA, l'émission JWT restent
   **entièrement** ceux du socle (`UtilisateurProcessor`, mécanisme déjà utilisé par L5/CRM pour l'espace
   client, cf. commentaire `Utilisateur::$clientLie` : *« lie un compte back-office/espace client (M3,
   non spécifié dans ce dépôt) »* — **ce dépôt anticipait déjà L8**). À la création d'un `CompteClient`,
   une `Affectation(utilisateur, etablissement=vitrine.etablissement, role=RoleClientFinal)` est créée
   (rôle système seedé par migration, bundle de permissions `*_soi`, §4) — **sans ce rattachement,
   aucune permission `_soi` (`PermissionVoter`) ne serait accordée**, le mécanisme socle l'exige (lu dans
   `CalculateurDroits::codesEffectifs()`, code réel : union par `Affectation`, pas de règle implicite
   pour un utilisateur « client »). Un **invité** (achat simple sans compte, RG-M3-06) n'a **aucun**
   `Utilisateur` : les endpoints panier/tunnel invité sont `PUBLIC_ACCESS`, protégés par un jeton de
   panier applicatif (§4, ⚠ Risque n°3), pas par `PermissionVoter`.
4. **Achat invité ≠ « pas de fiche client ».** RG-M3-06 exempte l'invité de **compte de connexion**
   (`Utilisateur`), pas de fiche `Client` (M4). `ConfirmerCommandeHandler` résout/crée le `Client` pour
   **tout** payeur — y compris invité — via `App\Vente\Port\ClientM4Interface::creerRapide()` (port +
   adaptateur réels, déjà utilisés en L2 guichet, US-L2-05), avant d'enregistrer le `Consentement` RGPD
   (RG-M3-07, qui exige un `Client` non nul, `Consentement.client` non nullable en base). Ceci résout
   proprement l'articulation RG-M3-06/07 sans étendre le schéma CRM.
5. **`Formule` porte la facette « sepa » via `Formule.sepaActif` (booléen déjà existant, pas une
   facette `TypeProduit` distincte).** RG-M3-12/17 (« produit portant la facette sepa ») se traduit
   donc par : `Produit.type.aFacette(TypeProduit::FACETTE_FORMULE) && Produit.formule.sepaActif ===
   true` — lu tel quel, aucune modification M1.
6. **Le remboursement en ligne ne réimplémente pas la contre-passation.** `TraiterDemandeRemboursementHandler`
   (accepter) appelle `App\Vente\Service\ContrePassationHandler::rembourser()` (code réel, identique à
   `RembourserVenteProcessor` du guichet M2) — `DemandeRemboursement.avoirRattache` référence l'`Avoir`
   produit. Aucune ligne de calcul de remboursement n'est dupliquée en Boutique.
7. **OTA — objets génériques propres à L8, `App\Musee\*` non touché** (cf. bandeau réutilisation
   ci-dessus). `App\Boutique\Entity\AllocationQuotaOTA` référence **soit** un `Produit` (M1, vente
   simple) **soit** un `Creneau` (Réservation, timed-entry) — jamais les deux — et ne porte **aucun**
   compteur d'inventaire réel : c'est une **plafond contractuel par partenaire**, contrôlé *avant*
   d'emprunter exactement le même chemin qu'une vente directe (`DecrementStockHandler` ou
   `JaugeCreneauGuard`/`Reservation`), garantissant RG-M3-09 (« même compteur réel ») sans nouvelle
   mécanique de stock.
8. **Résolution retenue pour la friction « survente vs aucun remboursement automatique »** (spec §8
   cas limites point 7, ⚠ non tranchée par les sources) : (a) la jauge/le stock sont **revérifiés de
   manière synchrone** juste avant l'appel à `PaiementEnLigneInterface::initierPaiement()` — **aucun
   paiement n'est même initié** si la place n'est plus disponible (RG-M3-08, refus avant capture
   d'argent, fenêtre de risque réduite aux quelques secondes du retour PSP/PayFiP) ; (b) si — malgré
   tout — un retour paiement `OK` arrive alors que la place a été prise entre-temps par une autre
   commande payée plus vite (fenêtre résiduelle non nulle, paiement déjà capturé côté PSP), **aucun
   remboursement n'est déclenché automatiquement** (conforme RG-M3-15) : le système crée à la place une
   `DemandeRemboursement(statut=recue, motif="conflit d'inventaire à la confirmation")` **pré-remplie
   automatiquement**, mise en tête de file pour traitement humain prioritaire
   (`boutique.traiter_remboursement`). Ceci respecte à la lettre « aucun remboursement automatique »
   tout en garantissant qu'aucune vente en survente ne reste sans suite — ⚠ **résolution proposée par ce
   plan, à valider avec le produit** (spec point ouvert n°7, non tranché par les sources).
9. **Session de caisse pour une vente 24/7 sans opérateur humain.** `Vente.session` (M2) est un
   `ManyToOne SessionCaisse` **non nullable**, lui-même dépendant d'un `PointDeVente`/`Caisse` et d'un
   `Utilisateur` régisseur/opérateur (code réel, `app/src/Vente/Entity/Vente.php:142-145`,
   `app/src/Caisse/Entity/SessionCaisse.php:82-90`) — **incompatible tel quel** avec une commande en
   ligne sans agent. Le module Réservation a déjà rencontré et résolu ce même gap pour la facturation
   no-show sans agent (`App\Reservation\Service\SessionSystemeResolver`, code réel) : une session de
   caisse **technique et permanente** par établissement, portée par un `PointDeVente`/`Utilisateur`
   système dédiés. L8 **reproduit ce même patron** avec son propre espace de nommage
   (`App\Boutique\Service\SessionSystemeBoutiqueResolver`, utilisateur `systeme.boutique@
   itcotation.internal`) plutôt que de réutiliser directement la classe Réservation (couplage
   sémantique différent : facturation no-show ≠ vente boutique). **Le même ⚠ avertissement NF525/
   comptable que celui déjà documenté dans le code réel de `SessionSystemeResolver`
   s'applique ici à l'identique** (vente sans opérateur humain identifié — à valider par un expert
   compta/NF525 avant mise en production, cf. Risque n°1).

---

## 1. Entités & schéma

Namespace : **`App\Boutique\Entity\*`** (+ `App\Boutique\Enum\*`, `App\Boutique\Port\*`,
`App\Boutique\Service\*`). `id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`).
`declare(strict_types=1)` partout. Noms métier en français. Toute entité racine porte un `ManyToOne`
vers `Etablissement` (socle, RG-SOCLE-01), cloisonnée par `App\Boutique\Doctrine\
PerimetreBoutiqueExtension` (même patron Doctrine que L1/L2/L3/L4/L6/Musée/Sport).

### 1.1 Vitrine (US-L8-01, RG-M3-01/08)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **Vitrine** (`bou_vitrine`) | id | uuid | non | PK | RG-M3-01 |
| | etablissement | `OneToOne` → `Etablissement` (socle) | non | unique | 1 vitrine / établissement |
| | logo, couleurs | string(255), json | oui | — | white-label |
| | langues | `json` (`list<string>`, ISO) | non, défaut `["fr"]` | `≥ 1` | i18n |
| | canauxActifs | `json` (`list<string>` ⊂ {en_ligne, app}) | non, défaut `["en_ligne"]` | — | filtre l'affichage |
| | delaiExpirationPanierMinutes | smallint | non, défaut **15** ⚠ non chiffré par les sources | `Assert\Positive` | RG-M3-03, décision actée « X minutes » |
| | etablissement (dénorm) | `ManyToOne` → `Etablissement` | non | — | cloisonnement (redondant avec le OneToOne ci-dessus, même patron que `ParametreMuseeEtablissement`) |

### 1.2 Panier & réservation temporaire (US-L8-02/03, RG-M3-02/03/16)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **PanierEnLigne** (`bou_panier`) | id | uuid | non | PK | RG-M3-03 |
| | vitrine | `ManyToOne` → `Vitrine` | non | — | — |
| | compteClient | `ManyToOne` → `CompteClient` | oui | requis si identifié en cours de parcours | §4.3 spec, rattachement sans perte |
| | sessionClient | `ManyToOne` → `SessionClient` | oui | requis si invité | jeton de panier, §4 |
| | statut | `string(20)` enum `StatutPanier` {ouvert, expire, transforme_en_commande} | non, défaut `ouvert` | — | §4.3 |
| | dateCreation, dateExpiration | `datetime_immutable`, `datetime_immutable` | non/non | `dateExpiration = dateCreation + vitrine.delaiExpirationPanierMinutes` | RG-M3-03/16 |
| | contactConnu | string(180) | oui | e-mail saisi tôt (identification) ou `compteClient.utilisateur.email` | condition de la relance CA-3/RG-M3-16 |
| | relanceEnvoyee | bool | non, défaut false | — | idempotence commande `boutique:liberer-paniers-expires` |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénorm | — |
| **LignePanierEnLigne** (`bou_ligne_panier`) | id, panier | uuid, `ManyToOne` → `PanierEnLigne` | non | requis | RG-M3-03 |
| | produit | `ManyToOne` → `App\Offre\Entity\Produit` (**réutilisé**, FK réelle) | non | index | RG-M1-01 |
| | quantite | smallint ≥ 1 | non | `≤` disponibilité affichée (garde applicative, pas DB) | §4.2 |
| | creneau | `ManyToOne` → `App\Reservation\Entity\Creneau` (**réutilisé**) | oui | requis si `produit.type.aFacette('timed_entry')` ⚠ voir Risque n°2 | RG-M3-02 |
| | beneficiaireRef | `ManyToOne` → `App\Crm\Entity\Beneficiaire` (**réutilisé**) | oui | — | payeur avec compte, §4.5 |
| | beneficiaireSimple | `json` (`{nom,prenom,dateNaissance?}`) | oui | requis si `beneficiaireRef` null et payeur invité | §4.5 |
| | champsPersonnalises | `json` (`map<string,mixed>`) | oui | — | §4.5 (taille, niveau…) |
| | autorisationParentaleRequise | bool | non, défaut false | dérivé de `beneficiaireSimple.dateNaissance`/`beneficiaireRef.estMineur()` | RG-M3-13 |
| | autorisationParentaleHorodatage | `datetime_immutable` | oui | requis avant paiement si `autorisationParentaleRequise` | RG-M3-13, CA-8 |
| | expirationA | `datetime_immutable` | non | = `panier.dateExpiration`, dénormalisé | réservation temporaire (§0 décision n°2 spec, non-atomique — cf. Risque n°2) |

> **Disponibilité affichée (RG-M3-08)** — calculée en lecture, jamais stockée comme compteur dur :
> produit simple → `Stock::disponibiliteEffective() − Σ(quantite des LignePanierEnLigne actives non
> expirées sur ce produit)` ; timed-entry → `JaugeCreneauGuard::placesRestantes($creneau) −
> Σ(quantite des LignePanierEnLigne actives non expirées sur ce créneau)`. **Non atomique** (même
> niveau de rigueur que `JaugeCreneauGuard` lui-même, qui est un `COUNT` sans verrou — cf. Risque n°2) ;
> le **verrou réel** est au paiement (§0 décisions n°1/8), pas au panier.

### 1.3 Commande en ligne — satellites de la Vente M2 (US-L8-04 à 09, RG-M3-04/06/07/10/11/12/13/14/17)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **SuiviCommandeEnLigne** (`bou_suivi_commande`) | id, vente | uuid, `OneToOne` → `App\Vente\Entity\Vente` (**réutilisée, non dupliquée**) | non | unique | « CommandeEnLigne » du cahier = `Vente` M2, canal `en_ligne` |
| | vitrine | `ManyToOne` → `Vitrine` | non | — | — |
| | panierOrigine | `ManyToOne` → `PanierEnLigne` | non | — | traçabilité |
| | compteClient | `ManyToOne` → `CompteClient` | oui | null si invité | RG-M3-06 |
| | statutTunnel | `string(10)` enum `StatutTunnel` {panier, identifie, paye, confirme} | non, défaut `panier` | — | §6 États |
| | origineOta | bool | non, défaut false | — | §1.6 |
| | partenaireOta | `ManyToOne` → `PartenaireOTA` | oui | requis si `origineOta` | §1.6 |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénorm | — |
| **LigneCommandeMeta** (`bou_ligne_commande_meta`) | id, ligneVente | uuid, `OneToOne` → `App\Vente\Entity\LigneVente` (**réutilisée**) | non | unique | « LigneCommande » du cahier = `LigneVente` M2 |
| | creneau | `ManyToOne` → `Creneau` | oui | copié de `LignePanierEnLigne.creneau` | §4.8, source de la `Reservation` créée |
| | champsPersonnalises | `json` | oui | copié du panier | §4.5 |
| | beneficiaireSimple | `json` | oui | copié du panier | §4.5, invité mineur |
| **BilletQrMeta** (`bou_billet_qr_meta`) | id, billetSupport | uuid, `OneToOne` → `App\Vente\Entity\BilletSupport` (**réutilisé**) | non | unique | « BilletQR » du cahier = `BilletSupport` M2 |
| | qrDynamique | string(255) | non | requis | RG-M3-04, lié à `identifiantSupport` |
| | passWalletDisponible | bool | non, défaut false | — | RG-M3-04 |
| | passWalletUrl | string(255) | oui | requis si `passWalletDisponible` | — |
| | repliQr | bool | non, défaut false | true si wallet indisponible | RG-M3-14, CA-12 |
| | validiteDebut, validiteFin | `datetime_immutable`, `datetime_immutable` | oui/oui | héritée créneau/produit | — |
| | statutRetraitPhysique | `string(14)` enum `StatutRetraitPhysique` {non_applicable, a_retirer, retire} | oui | requis si support physique | RG-M3-18, §1.5 |

> **Pourquoi des satellites `OneToOne` et non de nouvelles tables autonomes** — même patron que
> `Exposition`/`Audioguide` (satellites `Produit`, `plan-musee.md` §1.3) ou `StatutAccesFitness`
> (satellite `AbonnementFitness`↔`DroitAcces`, `plan-sport.md` §1.6) : **zéro champ M2 dupliqué**,
> la `Vente`/`LigneVente`/`BilletSupport` restent le modèle canonique unique (constitution §4 point 4,
> « pas de logique dupliquée »).

### 1.4 Authentification client final (US-L8-04/09/10, RG-M3-06/10/12/17)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **CompteClient** (`bou_compte_client`) | id, utilisateur | uuid, `OneToOne` → `App\Securite\Entity\Utilisateur` (**réutilisé**, socle) | non | unique | §0 décision n°3 |
| | client | `ManyToOne` → `App\Crm\Entity\Client` (**réutilisé**, dénorm de `utilisateur.clientLie`) | non | — | jointures rapides |
| | franceConnectId | string(255) | oui | **unique** si non null | RG-M3-06, ⚠ intégration à cadrer |
| | vitrineCreation | `ManyToOne` → `Vitrine` | non | — | traçabilité multi-établissement |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénorm | — |
| **SessionClient** (`bou_session_client`) | id | uuid | non | PK | invité/FranceConnect en cours, §4.4 |
| | token | string(64) | non | **unique**, haché | jeton de panier applicatif, §4/Risque n°3 |
| | type | `string(20)` enum `TypeSessionClient` {invite, franceconnect_en_cours} | non | requis | — |
| | contactEmail | string(180) | oui | saisi à l'identification | alimente `PanierEnLigne.contactConnu` |
| | expiration | `datetime_immutable` | non | — | TTL court (24 h ⚠ non chiffré) |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénorm | — |

> **`RoleClientFinal` (rôle système, pas une entité Boutique)** — seedé par la migration de données
> (§5), bundle `{crm.lire_soi, crm.modifier_soi, crm.pmv_lire_soi, crm.pmv_recharger_soi,
> crm.consentement_gerer_soi, reservation.reserver_soi, reservation.lire_soi, reservation.annuler_soi,
> boutique.acheter_soi, boutique.lire_soi, boutique.gerer_famille_soi,
> boutique.demander_remboursement_soi}` — réutilise le modèle `Role`/`Permission`/`Affectation` du
> socle (`app/src/Securite/Entity/`), **aucune nouvelle table de rôle**.

### 1.5 Remboursement & retrait physique (US-L8-12/13, RG-M3-15/18)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **DemandeRemboursement** (`bou_demande_remboursement`) | id, vente | uuid, `ManyToOne` → `Vente` | non | requis | RG-M3-15 |
| | ligne | `ManyToOne` → `LigneVente` | oui | — | remboursement partiel possible |
| | motif | text | non | requis | déposé par le client |
| | piecesJustificatives | `json` (`list<string>` chemins) | oui | — | — |
| | statut | `string(10)` enum `StatutDemandeRemboursement` {recue, en_cours, acceptee, refusee} | non, défaut `recue` | — | §6 États |
| | origineAutomatique | bool | non, défaut false | true si créée par §0 décision n°8 | traçabilité conflit d'inventaire |
| | dateDemande, dateTraitement | `datetime_immutable`, `datetime_immutable` | non/oui | requis si traitée | — |
| | traitePar | `ManyToOne` → `Utilisateur` | oui | requis si traitée | `boutique.traiter_remboursement` |
| | motifRefus | string(255) | oui | requis si `refusee` | — |
| | avoirRattache | `ManyToOne` → `App\Vente\Entity\Avoir` (**réutilisé**) | oui | requis si `acceptee` | RG-M2-07, §0 décision n°6 |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénorm | — |
| **RetraitClickCollect** (`bou_retrait_click_collect`) | id, billetSupport | uuid, `OneToOne` → `BilletSupport` | non | unique | RG-M3-18 |
| | pointRetrait | `ManyToOne` → `App\Organisation\Entity\Espace` (**réutilisé**, socle) | non | — | guichet/borne |
| | codeRetrait | string(12) | non | **unique** | présenté au retrait |
| | statut | `string(10)` enum `StatutRetraitClickCollect` {a_retirer, retire} | non, défaut `a_retirer` | — | §6 États |
| | dateRetrait | `datetime_immutable` | oui | — | — |
| | traitePar | `ManyToOne` → `Utilisateur` | oui | requis si `retire` | `boutique.traiter_retrait` (agent) ou borne autonome (§4.13 spec, mode `autonome`, module Accès) |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénorm | — |

### 1.6 Connecteurs OTA — objets génériques L8 (US-L8-14, RG-M3-09, §0 décision n°7)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **PartenaireOTA** (`bou_partenaire_ota`) | id, vitrine | uuid, `ManyToOne` → `Vitrine` | non | requis | RG-M3-09 |
| | nom | string(120) | non | requis | — |
| | tarifNet | decimal(10,2) | non | ≥ 0 | — |
| | commission | decimal(5,2) | non | ≥ 0 (%) | — |
| | codeConnecteur | string(60) | oui | — | identifiant technique du port stub |
| | actif | bool | non, défaut true | — | activable/désactivable par établissement (§3 spec) |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénorm | — |
| **AllocationQuotaOTA** (`bou_allocation_quota_ota`) | id, partenaire | uuid, `ManyToOne` → `PartenaireOTA` | non | requis | RG-M3-09 |
| | produit | `ManyToOne` → `Produit` | oui | exactement un de `produit`/`creneau` (validateur applicatif) | vente simple |
| | creneau | `ManyToOne` → `Creneau` | oui | idem | timed-entry |
| | quotaAlloue | int ≥ 0 | non | requis | plafond **contractuel**, pas un stock séparé |
| | quotaConsomme | int ≥ 0 | non, défaut 0 | `≤ quotaAlloue` | incrémenté après succès du chemin standard (Stock/Reservation) |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénorm | — |
| **ReversementOTA** (`bou_reversement_ota`) | id, partenaire | uuid, `ManyToOne` → `PartenaireOTA` | non | requis | RG-M3-09 |
| | periodeDebut, periodeFin | `date_immutable`, `date_immutable` | non | requis | — |
| | montant | decimal(10,2) | non | ≥ 0 | tarif net + commission sur les ventes OTA confirmées de la période |
| | statut | `string(10)` enum {a_verser, verse} | non, défaut `a_verser` | — | — |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénorm | — |

**Enums (`App\Boutique\Enum\*`)** : `StatutPanier`, `StatutTunnel`, `TypeSessionClient`,
`StatutDemandeRemboursement`, `StatutRetraitClickCollect`, `StatutRetraitPhysique`.

**Objets référencés, non redéfinis** (constitution §4) : `App\Offre\Entity\{Produit,Stock,Pool,Formule,
Promotion}` (M1), `App\Vente\Entity\{Vente,LigneVente,Paiement,BilletSupport,Avoir}` (M2),
`App\Reservation\Entity\{Creneau,Reservation,ListeAttente}`, `App\Acces\Entity\Support` (via
`AppairageAccesInterface`), `App\Crm\Entity\{Client,Beneficiaire,Famille,Consentement,
PorteMonnaieVirtuel}` (M4), `App\Compta\Entity\ProfilExploitant`/`App\Compta\Port\PayFipInterface`
(M6), `App\Sepa\Entity\MandatSepa` (module partagé).

---

## 2. Ports & adaptateurs

Tous les ports suivent le pattern déjà établi par `App\Compta\Port\PayFipInterface` +
`App\Compta\Adapter\PayFipStubAdapter` / `App\Musee\Port\ConnecteurOtaInterface` +
`StubConnecteurOtaAdapter` (code réel lu) : interface fine, adaptateur stub par défaut, remplaçable
sans toucher le domaine.

### 2.1 Identité — FranceConnect (§4.4 spec, ⚠ intégration à cadrer)

```php
namespace App\Boutique\Identite;

interface FournisseurIdentiteInterface
{
    public function urlAutorisation(string $redirectUri): string;
    /** Échange le code de retour OIDC contre l'identité ; lève une exception applicative si invalide/expiré. */
    public function authentifier(string $code, string $redirectUri): IdentiteFranceConnect; // {sub, email, nom, prenom, dateNaissance?}
}
```

- **Adaptateur par défaut** `FournisseurIdentiteStubAdapter` — ne contacte aucun IdP réel, renvoie une
  identité déterministe à partir d'un `sub` fourni en test. **Aucune intégration DINUM réelle** — cf.
  Risque n°4.
- Consommé par `App\Boutique\Service\IdentificationFranceConnectHandler` : recherche un `CompteClient`
  existant par `franceConnectId` ; sinon, recherche par e-mail (même règle « une valeur déjà saisie
  prévaut » que `RG-M4-01`, ⚠ HYPOTHÈSE reprise de la spec §8) ; sinon crée une `SessionClient(type=
  franceconnect_en_cours)` **sans** `CompteClient` (RG-M3-06 : identifié sans compte imposé) — un
  `CompteClient` n'est créé que si l'utilisateur poursuit vers un produit `sepa`/abonnement (RG-M3-12,
  redirection création de compte, §4.9).

### 2.2 Paiement en ligne commuté — PayFiP / PSP CB (US-L8-07, RG-M3-11)

```php
namespace App\Boutique\Paiement;

interface PaiementEnLigneInterface
{
    public function initierPaiement(Uuid $venteId, int $montantCentimes, string $urlRetour): InitiationPaiementEnLigne; // {referenceTransaction, urlRedirection}
    public function traiterRetour(array $donneesRetour): ResultatRetourPaiementEnLigne; // {referenceTransaction, statut: StatutTPE, montantCentimes}
}
```

- **`PayFipBoutiqueAdapter`** — **enveloppe directement** `App\Compta\Port\PayFipInterface` (code réel,
  déjà injecté) : `initierPaiement()` délègue à `PayFipInterface::initierPaiement()` puis adapte le
  DTO. Réutilise donc de facto `PayFipStubAdapter` tant que M6 n'a pas de vrai partenaire technique
  (même statut que côté M6, non aggravé par L8).
- **`PspCbStubAdapter`** — même patron que `PayFipStubAdapter` (référence déterministe, URL factice) ;
  **⚠ PSP CB non nommé par les sources** (spec §4.7/récapitulatif final point 4) — Risque n°5.
- **`SelecteurPaiementEnLigne`** — construit `[TypeExploitant => PaiementEnLigneInterface]` à partir
  d'un itérateur taggé `boutique.paiement_en_ligne` (même patron *« aucun switch »* que
  `App\Compta\Regime\RegimeComptableResolver`, code réel) : lit `ProfilExploitant.type` de
  l'établissement de la vitrine (`RegieDirecte` → `PayFipBoutiqueAdapter`, `Dsp`/`GroupePrive` →
  `PspCbStubAdapter`) — **seul point de lecture** de ce discriminant en Boutique (RG-M3-11, « change de
  profil exploitant bascule sans ressaisie »).

### 2.3 Connecteur OTA (§1.6, §0 décision n°7)

```php
namespace App\Boutique\Ota;

interface ConnecteurOtaInterface
{
    public function notifierAllocation(AllocationQuotaOTA $allocation): void;
    public function notifierVenteConfirmee(PartenaireOTA $partenaire, Vente $vente): void;
    public function notifierReversement(ReversementOTA $reversement): void;
}
```

- **Adaptateur par défaut** `StubConnecteurOtaAdapter` — journalise uniquement, aucune intégration
  technique par plateforme (Tiqets, Weezevent…) — ⚠ hors périmètre applicatif, cf. Risque n°6.

### 2.4 SEPA en ligne (§4.9 spec, RG-M3-17) — réutilisation intégrale, aucun nouveau port

`App\Boutique\Service\SouscriptionAbonnementEnLigneHandler` appelle directement
`App\Sepa\State\CreerMandatSepaProcessor` (code réel) via le même contrat d'entrée (IBAN clair
transitoire → `TokenisationIbanInterface::tokeniser()`, jamais persisté) — **aucun port Boutique
supplémentaire**, le module `App\Sepa` est déjà la frontière correcte (constitution §4, pas de
duplication).

### 2.5 Résumé — pourquoi ce découpage ne duplique pas le socle

- FranceConnect et le PSP CB privé **n'existent nulle part ailleurs** dans le dépôt → ports neufs
  légitimes (mêmes gaps déjà signalés côté M6/Sport pour PayFiP/PSP CB fitness).
- Le connecteur OTA générique **n'existe pas** hors du contexte spécifique Musée → port neuf légitime,
  scopé Boutique, sans toucher `App\Musee\*`.
- Paiement, stock, jauge, billet, avoir, mandat SEPA sont **entièrement réutilisés** (M2/M1/Réservation/
  Sepa) : la seule mécanique réellement neuve est la **composition** du tunnel (panier → identification
  → paiement commuté → confirmation), pas les moteurs sous-jacents.

---

## 3. API (API Platform)

Toutes ressources : `#[ApiResource]`. `security:` via `is_granted('PERM', 'boutique.<action>')` (module
`boutique`) pour le back-office, `PUBLIC_ACCESS` + garde applicative de jeton de panier pour le tunnel
public, `is_granted('PERM', 'boutique.<action>_soi')` pour l'espace client authentifié. Cadrage
établissement : `ContexteEtablissement`/`PerimetreBoutiqueExtension` côté back-office ; côté tunnel
public, l'établissement est résolu depuis la `Vitrine`/le `Panier`, pas depuis l'en-tête `X-Etablissement`
(un visiteur anonyme ne porte pas cet en-tête).

| Ressource | Opérations | `security:` | Groupes | Notes |
|---|---|---|---|---|
| **Vitrine** | `GET /boutique/vitrines/{etablissement}` | `PUBLIC_ACCESS` | `vitrine:read` | catalogue public (CA-1) |
| | PATCH | `boutique.gerer_vitrine` | `vitrine:write` | logo/couleurs/langues/délai panier |
| **Produit (catalogue en ligne)** | `GET /boutique/vitrines/{etablissement}/catalogue` (provider composant `Produit` M1 filtré `canal=en_ligne`+publié) | `PUBLIC_ACCESS` | `catalogue:read` | filtres catégorie/activité/tri (RG-M1-07/09, réutilisés) |
| | `GET /boutique/produits/{id}/creneaux` (provider, délègue à `Creneau` Réservation) | `PUBLIC_ACCESS` | `creneau_public:read` | CA-2, exclut `publicReserve` non vide (RG-M5-08) |
| **PanierEnLigne** | `POST /boutique/paniers` (ouverture) | `PUBLIC_ACCESS` | `panier:write/read` | crée `SessionClient` + jeton retourné (Risque n°3) |
| | GET item | `PUBLIC_ACCESS` + jeton | `panier:read` | — |
| | `POST /boutique/paniers/{id}/lignes` / `PATCH .../lignes/{ligneId}` / `DELETE .../lignes/{ligneId}` | `PUBLIC_ACCESS` + jeton | `panier:write` | CA-2/CA-3, custom processors (`AjouterLignePanierProcessor`…) |
| | `POST /boutique/paniers/{id}/vider` | `PUBLIC_ACCESS` + jeton | — | — |
| **CompteClient** | `POST /boutique/comptes` (création) | `PUBLIC_ACCESS` | `compte:write` | RG-M3-10 anti-homonyme, crée `Utilisateur`+`Client`+`Affectation(RoleClientFinal)` |
| | `GET /boutique/comptes/me` | `IS_AUTHENTICATED_FULLY` | `compte:read` | espace client (US-L8-10) |
| | `POST /boutique/identification/franceconnect/callback` | `PUBLIC_ACCESS` | — | §2.1, CA-4 |
| **Tunnel (opérations sur `Panier`/`Vente`)** | `POST /boutique/paniers/{id}/identifier` | `PUBLIC_ACCESS` + jeton | — | étape 1, compte/invité/FranceConnect (CA-4/CA-5) |
| | `POST /boutique/paniers/{id}/beneficiaires` | `PUBLIC_ACCESS` + jeton | — | étape 2 (CA-7) |
| | `POST /boutique/paniers/{id}/consentement` | `PUBLIC_ACCESS` + jeton | — | étape 3, RGPD + autorisation parentale (CA-6/CA-8) |
| | `POST /boutique/paniers/{id}/payer` | `PUBLIC_ACCESS` + jeton | — | étape 3→4, `SelecteurPaiementEnLigne` (CA-9/CA-10) |
| | `POST /boutique/paiements/retour/{moyen}` (webhook/retour PayFiP ou PSP) | `PUBLIC_ACCESS` (signature/HMAC applicative) | — | déclenche `ConfirmerCommandeHandler` (CA-11/CA-12) |
| | `POST /boutique/abonnements/souscrire` | `boutique.acheter_soi` (compte requis, RG-M3-17) | — | étape paiement spécialisée abonnement/SEPA (CA-13) |
| **Vente/BilletSupport (consultation)** | `GET /boutique/comptes/me/commandes` | `boutique.lire_soi` | `commande:read` | historique (US-L8-10, CA-14) |
| | `GET /boutique/comptes/me/billets` | `boutique.lire_soi` | `billet:read` | QR/PDF (CA-14) |
| **DemandeRemboursement** | `POST /boutique/demandes-remboursement` | `boutique.demander_remboursement_soi` | `demande_remb:write` | CA-17 |
| | GET coll/item | `boutique.lire` ou (`boutique.lire_soi` + `object.venteEstDe(user)`) | `demande_remb:read` | — |
| | `POST /boutique/demandes-remboursement/{id}/accepter` | `boutique.traiter_remboursement` | — | custom, appelle `ContrePassationHandler` (§0 n°6) |
| | `POST /boutique/demandes-remboursement/{id}/refuser` | `boutique.traiter_remboursement` | — | motif requis |
| **RetraitClickCollect** | GET coll/item | `boutique.lire` ou `boutique.lire_soi` | `retrait:read` | — |
| | `POST /boutique/retraits/{id}/valider` | `boutique.traiter_retrait` ou `acces.controler` (borne autonome) | — | CA-18, appairage `AppairageAccesInterface` |
| **PartenaireOTA / AllocationQuotaOTA / ReversementOTA** | GET coll/item ; POST ; PATCH | `boutique.lire` / `boutique.gerer_connecteur_ota` | `partenaire_ota:*`/`allocation_ota:*`/`reversement_ota:*` | CA-19 |
| | `POST /boutique/ota/ventes` (ingestion vente OTA) | `boutique.gerer_connecteur_ota` (le connecteur technique s'authentifie côté back-office/API-key hors périmètre) | — | custom, réutilise `DecrementStockHandler` ou `JaugeCreneauGuard`+`Reservation` (§0 n°7) |
| **App mobile — Ma carte/Mon abonnement/Recharger** | endpoints génériques réutilisés | `boutique.lire_soi`/`crm.pmv_recharger_soi` | — | US-L8-11 : aucune ressource Boutique dédiée, compose `ServiceInclus`/`CarteMultiEntrees` (M1) + `PorteMonnaieVirtuel` (M4) + billets ci-dessus |

- **Groupes de sérialisation** : pattern read/write par ressource, comme L3/L6/Musée. `CompteClient`
  n'expose **jamais** le mot de passe (déjà garanti par `Utilisateur`, aucun champ dupliqué).
  `MandatSepa.ibanToken`/`ibanChiffre` restent **sans aucun groupe** (garde héritée du module `App\Sepa`,
  non touchée).
- **Custom vs CRUD** : tout le tunnel (identifier, beneficiaires, consentement, payer, retour paiement,
  accepter/refuser remboursement, valider retrait, ingestion OTA) est une **opération métier** (State
  Processors → handlers testables), pas du CRUD Doctrine brut — même logique que tous les plans
  précédents.
- **Endpoints génériques réutilisés sans ressource Boutique dédiée** : liste d'attente créneau complet
  (`POST /reservation/creneaux/{id}/liste-attente`), consultation `MandatSepa`
  (`GET /sepa/mandats`), retrait/dépôt du consentement (`POST /clients/{id}/consentements`, réutilisé
  via `Client`), recharge PMV (`POST /clients/{id}/pmv/recharger`).

---

## 4. Sécurité & droits

- **Permissions `boutique.*`** (⚠ HYPOTHÈSE de nommage, non littérales dans les sources, à figer avec
  M8, même réserve que tous les modules verticaux) : `gerer_vitrine`, `gerer_promo`,
  `gerer_connecteur_ota`, `lire`, `traiter_remboursement`, `traiter_retrait`, `gerer` (administrateur,
  surensemble), et les permissions `_soi` du bundle `RoleClientFinal` (§1.4) :
  `acheter_soi`, `lire_soi`, `gerer_famille_soi`, `demander_remboursement_soi`.
- **`acheter_invite` n'est PAS une permission `PermissionVoter`** — un invité n'a aucun `Utilisateur`,
  donc aucune `Affectation`. Les endpoints panier/tunnel invité sont `PUBLIC_ACCESS`, protégés par un
  **jeton de panier applicatif** (`SessionClient.token`, transmis en en-tête `X-Panier-Token`, vérifié
  impérativement dans chaque processor via `App\Boutique\Security\PanierProprietaireGuard`) — même
  esprit que les contrôles impératifs déjà pratiqués par `FicheClient360Provider`/`PmvProvider` (code
  réel, commentaires explicites « contrôle `_soi` impératif, pas déclaratif ») lorsque `object` n'est
  pas exploitable par le voter déclaratif. ⚠ Risque n°3 (robustesse du jeton, CSRF, durée de vie).
- **Permissions réutilisées (non redéfinies)** — `offre.lire` (catalogue), `reservation.reserver_soi`/
  `lire_soi`/`annuler_soi` (créneaux, liste d'attente), `crm.lire_soi`/`modifier_soi`/`pmv_lire_soi`/
  `pmv_recharger_soi`/`consentement_gerer_soi` (espace client, famille, PMV, RGPD), `sepa.lire`/`gerer`
  (mandat), `acces.controler` (retrait click & collect en borne autonome), `vente.rembourser` **non**
  réutilisée directement (le remboursement en ligne passe par `boutique.traiter_remboursement` →
  `ContrePassationHandler`, même moteur, permission dédiée par cohérence avec le modèle `_soi`/back-
  office du cahier M3-§2).
- **Voters** — **aucun voter nouveau pour le back-office** (réutilise `PermissionVoter`). Un voter léger
  `App\Boutique\Security\DemandeRemboursementSoiVoter` (attribut `DEMANDE_REMB_SOI`) vérifie que
  `DemandeRemboursement.vente.client` correspond au `clientLie` de l'utilisateur connecté — même patron
  que `ReservationSoiVoter`/`Client::estLieA()`.
- **Cadrage établissement** — back-office : `ContexteEtablissement` + `PerimetreBoutiqueExtension`
  (même mécanique que L1/L3/L4/L6). Tunnel public : établissement résolu par la `Vitrine`/le `Panier`
  eux-mêmes (aucune confiance dans un en-tête côté visiteur anonyme).

---

## 5. Migrations

- **Migration structurelle** `VersionBoutique_structure` : tables `bou_vitrine`, `bou_panier`,
  `bou_ligne_panier`, `bou_suivi_commande`, `bou_ligne_commande_meta`, `bou_billet_qr_meta`,
  `bou_compte_client`, `bou_session_client`, `bou_demande_remboursement`, `bou_retrait_click_collect`,
  `bou_partenaire_ota`, `bou_allocation_quota_ota`, `bou_reversement_ota`.
  - **Index/contraintes** : `OneToOne` unique sur `Vitrine.etablissement`, `SuiviCommandeEnLigne.vente`,
    `LigneCommandeMeta.ligneVente`, `BilletQrMeta.billetSupport`, `CompteClient.utilisateur`,
    `RetraitClickCollect.billetSupport` ; **unique** `CompteClient.franceConnectId` (partiel, si non
    null), `SessionClient.token`, `RetraitClickCollect.codeRetrait` ; checks `quotaConsomme ≤
    quotaAlloue` (AllocationQuotaOTA), `delaiExpirationPanierMinutes > 0`. FK sortantes vers `App\Offre\
    Entity\Produit` (M1), `App\Reservation\Entity\Creneau` (Réservation), `App\Vente\Entity\{Vente,
    LigneVente,BilletSupport,Avoir}` (M2), `App\Crm\Entity\{Client,Beneficiaire}` (M4), `App\Securite\
    Entity\{Utilisateur,Affectation}` (socle), `App\Organisation\Entity\{Etablissement,Espace}` (socle)
    — **suppose les migrations socle L0 + M1 + L2 + Réservation + L3 + L5 + L4 jouées d'abord** (ordre
    de construction, comme tous les modules verticaux déjà livrés).
- **Migration de données** `VersionBoutique_permissions` : insère `Permission(module='boutique', action
  ∈ {gerer_vitrine, gerer_promo, gerer_connecteur_ota, lire, traiter_remboursement, traiter_retrait,
  gerer, acheter_soi, lire_soi, gerer_famille_soi, demander_remboursement_soi})`, crée le `Role`
  système `RoleClientFinal` (bundle `_soi`, §1.4) — **rejouable, idempotente** (vérifie l'existence
  avant insertion, même garde que les migrations de permissions L1-L7).
- **Modification de fichier partagé (hors migration DB)** — ajout des classes Boutique sensibles
  (`CompteClient`, `DemandeRemboursement`, `RetraitClickCollect`, `PartenaireOTA`) à
  `App\Audit\Doctrine\AuditWriteSubscriber::CLASSES_SURVEILLEES`, extension additive comme tous les
  lots précédents. Ajout de `App\Vente\Port\AppairageAccesInterface`/`ClientM4Interface` : **aucune
  modification**, injection standard des implémentations déjà bindées dans `config/services.yaml`.
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force`.

---

## 6. Tests (PHPUnit + ApiTestCase)

| Test | Type | Couvre |
|---|---|---|
| Vitrine white-label : 2 établissements → logo/couleurs/langues propres, aucune marque éditeur, prix/dispo temps réel reflètent M1/Réservation | API | CA-1, RG-M3-01/08 |
| Timed-entry : ajout au panier sans créneau → refusé ; avec créneau disponible → accepté, « Reste : n » décroît pour un second visiteur | API + Unit (`JaugeCreneauGuard` réutilisé) | CA-2, RG-M3-02 |
| Panier expiré : ligne timed-entry + délai établissement dépassé → `boutique:liberer-paniers-expires` libère la place (aucune `Reservation` créée), relance e-mail envoyée si contact connu, absente sinon | Unit (commande) + Mailer (transport `null://null`, assertion d'émission) | CA-3, RG-M3-03/16 |
| Identification : FranceConnect (stub) identifie sans mot de passe ni compte imposé ; invité poursuit un achat simple sans compte | API (`FournisseurIdentiteStubAdapter`) | CA-4, RG-M3-06 |
| Anti-homonyme : même nom/prénom, date de naissance différente → 2 `Client` distincts, jamais fusionnés | API + Unit | CA-5, RG-M3-10 |
| Consentement bloquant : case RGPD non cochée → paiement bloqué (422) ; cochée → horodaté, consentement accessible | API | CA-6, RG-M3-07 |
| Bénéficiaires : panier 3 articles, chaque ligne doit porter un bénéficiaire avant paiement | API | CA-7, RG-M4-02 |
| Autorisation parentale : ligne bénéficiaire mineur → case requise et horodatée en plus du RGPD général | API | CA-8, RG-M3-13 |
| Paiement commuté : `ProfilExploitant.type=RegieDirecte` → `PayFipBoutiqueAdapter` (enveloppe `PayFipInterface`) ; `type=Dsp/GroupePrive` → `PspCbStubAdapter` ; changement de profil bascule sans ressaisie | Unit (`SelecteurPaiementEnLigne`) | CA-9, RG-M3-11 |
| Paiement échoué/timeout : aucun `Paiement` enregistré, `Vente.statut=en_cours`, panier/réservations temporaires valides jusqu'à expiration, retentative possible | API (`StatutTPE::{Refuse,Timeout,Annule}`) | CA-10 |
| Confirmation : paiement réussi → `ValiderVenteService::valider()` (réutilisé) émet les `BilletSupport`, e-mail de confirmation envoyé, `Reservation` confirmée créée par ligne timed-entry avec `venteRattachee` = la vente boutique (pas de doublon) | API + intégration (`App\Vente`, `App\Reservation`) | CA-11, RG-M3-04 |
| Repli wallet : appareil sans wallet → `repliQr=true`, QR + PDF proposés, confirmation non interrompue | API | CA-12, RG-M3-14 |
| Abonnement/SEPA : invité bloqué et redirigé vers création de compte à l'étape paiement ; titulaire de compte → `MandatSepa` créé (IBAN jamais en clair, réutilise `TokenisationIbanInterface`) et rattaché au payeur | API | CA-13, RG-M3-12/17 |
| Espace client : chaque billet payé visible immédiatement en QR/facture, consentements consultables/retirables | API | CA-14 |
| Hors-ligne app mobile : badge QR déjà téléchargé validé sans réseau au tourniquet (réutilise intégralement la suite de tests L3, un scénario Boutique de plus) | API (intégration `App\Acces`) | CA-15, RG-M3-04 |
| Quota formule app mobile : réservation de cours décompte le quota semaine calendaire sans encaissement ; quota épuisé → vente à l'unité proposée | API (intégration `App\Reservation`) | CA-16, RG-M3-05 |
| Remboursement : demande déposée → jamais de remboursement auto ; acceptée → `Avoir` M2 généré via `ContrePassationHandler` (réutilisé) ; refusée → motif communiqué | API | CA-17, RG-M3-15 |
| Click & collect : confirmation → QR provisoire immédiat ; retrait avec code → support physique appairé (`AppairageAccesInterface`), remplace le QR provisoire | API | CA-18, RG-M3-18 |
| OTA : vente OTA décrémente le **même** stock/jauge que la vente directe (`DecrementStockHandler`/`JaugeCreneauGuard`, aucun compteur séparé) ; `AllocationQuotaOTA` plafonne sans dupliquer l'inventaire ; `ReversementOTA` calculé sur les ventes confirmées de la période | API + Unit | CA-19, RG-M3-09 |
| Survente panier (§0 décision n°8) : jauge revérifiée juste avant paiement → refus **avant** initiation du paiement dans le cas nominal ; simulation de la fenêtre résiduelle → `DemandeRemboursement(origineAutomatique=true)` créée automatiquement, jamais d'avoir automatique | Unit (`ConfirmerCommandeHandler`) | Cas limite §8 spec point 7, résolution proposée |
| Session système Boutique : `SessionSystemeBoutiqueResolver` crée/réutilise **une seule** session technique par établissement (idempotence) | Unit | §0 décision n°9 |
| Cloisonnement établissement : agent sans affectation → 403/absent sur toutes les ressources `boutique.*` back-office ; un `CompteClient` ne voit jamais les données d'un autre (`_soi`) | API | RG-SOCLE-05, §3 spec |
| Architecture : aucun fichier `App\Musee\*`/`App\Reservation\*`/`App\Vente\*`/`App\Compta\*`/`App\Sepa\*`/`App\Crm\*` modifié par ce lot (contrôle statique diff) | Unit (architecture) | non-régression socle/modules réutilisés |

---

## 7. Tâches (voir tasks-boutique.md)

T1 enums/`Vitrine` → T2 `SessionSystemeBoutiqueResolver` + `RoleClientFinal` (permissions) → T3
`CompteClient`/`SessionClient` + `CreationCompteHandler` (anti-homonyme) → T4 port identité
FranceConnect (stub) + `IdentificationFranceConnectHandler` → T5 `PanierEnLigne`/`LignePanierEnLigne` +
disponibilité en lecture + gardes timed-entry → T6 commande `boutique:liberer-paniers-expires` +
relance mail → T7 port paiement commuté (`PaiementEnLigneInterface` + adaptateurs PayFiP/PSP CB) +
`SelecteurPaiementEnLigne` → T8 `SuiviCommandeEnLigne`/`LigneCommandeMeta` + `ConfirmerCommandeHandler`
(construction Vente + revérification jauge + `ValiderVenteService` réutilisé) → T9 création
`Reservation` confirmée par ligne timed-entry (§0 n°2) → T10 `BilletQrMeta` + repli wallet → T11
souscription abonnement/SEPA en ligne (réutilise `App\Sepa`) → T12 `DemandeRemboursement` +
`ContrePassationHandler` réutilisé → T13 `RetraitClickCollect` + appairage → T14 audit + sécurité
(permissions `boutique.*`, voter `DemandeRemboursementSoiVoter`) → T15 `PartenaireOTA`/
`AllocationQuotaOTA`/`ReversementOTA` + port connecteur OTA + ingestion vente OTA → T16 API Platform
(ressources restantes, vitrine/catalogue public) → T17 migrations → T18 tests (ordonnées, cf. fichier
tâches).

---

## 8. Risques / à valider

1. **⚠ Session de caisse système sans opérateur humain (priorité haute, hérité et reproduit du même gap
   déjà documenté par le module Réservation).** `SessionSystemeBoutiqueResolver` (§0 décision n°9)
   permet à `Vente.session` (non nullable en base) d'exister pour une commande en ligne 24/7, mais crée
   des ventes **sans opérateur physique identifié** — implications NF525/comptables à faire valider par
   un expert compta/NF525 **avant mise en production réelle** (constitution §4 point 5). Ce risque
   n'est **pas nouveau** (le code réel `SessionSystemeResolver` le documente déjà pour le no-show
   Réservation) mais L8 l'étend à un volume potentiellement bien plus important (toute vente en ligne).
2. **⚠ Réservation temporaire de panier non atomique (spec §4.3/§8 point 6, confirmé au plan).** Le
   calcul de disponibilité affichée (§1.2) est un calcul de lecture (COUNT), pas un verrou ; le
   **même niveau de rigueur** que `JaugeCreneauGuard` existant (également un COUNT sans verrou) est
   retenu par cohérence — pas de régression, mais pas de garantie renforcée non plus. Le verrou réel
   reste au paiement (décrément atomique M2 / revérification jauge, §0 décisions n°1/8).
3. **⚠ Jeton de panier applicatif pour les visiteurs anonymes** (§4) — mécanisme retenu par défaut
   (en-tête `X-Panier-Token`, `SessionClient.token` haché) mais **la robustesse exacte** (rotation,
   durée de vie, protection CSRF pour les endpoints `PUBLIC_ACCESS` mutants) n'est pas spécifiée par les
   sources — à faire valider par la sécurité applicative avant mise en service, même famille de risque
   que le bouton SOS Sport (`plan-sport.md` Risque n°7).
4. **⚠ FranceConnect non intégré réellement** (protocole OIDC exact, scope de données, gestion
   callback/erreur) — `FournisseurIdentiteStubAdapter` uniquement, à cadrer avec la DINUM (spec
   récapitulatif point 3).
5. **⚠ PSP CB non nommé** pour le régime DSP/privé — `PspCbStubAdapter` par défaut, contrat calqué sur
   PayFiP par analogie stricte (spec récapitulatif point 4), à choisir/contractualiser (Stripe, PayPlug,
   Systempay…) avant mise en production réelle.
6. **⚠ Connecteur OTA technique hors périmètre** — `ConnecteurOtaInterface`/`StubConnecteurOtaAdapter`
   ne modélisent que le comportement observable (plafond, décrément partagé, reversement) ; l'intégration
   réelle par plateforme reste non cadrée (spec récapitulatif point 5), comme pour Musée.
7. **⚠ `AppairageAccesInterface` toujours bindé au stub** (`AppairageAccesStub`,
   `config/services.yaml:40`) alors que le module Accès (L3) est construit — gap **hérité de M2**, non
   introduit ni aggravé par L8, mais **bloquant** pour un vrai appairage QR/wallet/bracelet en
   production tant qu'un adaptateur réel `App\Acces\*` n'est pas branché sur ce port.
8. **⚠ Réconciliation OTA Musée/Boutique non faite** (§0 décision n°7) — `App\Boutique\Entity\
   {PartenaireOTA,AllocationQuotaOTA,ReversementOTA}` et `App\Musee\Entity\{PartenaireOTA,
   AllocationQuotaOTA,ReservationOTA}` **coexistent** sans consolidation ; un établissement Musée qui
   active un connecteur OTA aujourd'hui via `spec-musee.md` continuera d'utiliser les objets Musée, pas
   ceux de Boutique, tant qu'un futur remaniement de specs ne les unifie pas (déjà signalé par
   `plan-musee.md` §7 risques n°5/n°10 — ce plan ne fait qu'ajouter le pendant générique demandé côté
   L8, sans trancher la fusion).
9. **⚠ Friction survente/remboursement automatique (§0 décision n°8)** — la résolution proposée
   (revérification pré-paiement + `DemandeRemboursement` automatique en cas de fenêtre résiduelle) est
   une **proposition de ce plan**, pas une décision actée dans les sources — à valider avec le produit
   avant implémentation (spec §8 cas limites point 7, priorité explicitement demandée par le
   commanditaire).
10. **⚠ Continuité du QR provisoire pour un support physique exigé** (piscine notamment, RG-M3-18) —
    hérité tel quel de la spec (§4.13, ⚠ HYPOTHÈSE) : ce plan modélise le `statutRetraitPhysique` comme
    une information, **sans bloquer** l'usage du QR provisoire par défaut (le blocage éventuel serait un
    paramètre par verticale, hors périmètre de ce plan générique) — à confirmer verticale par verticale.
11. **⚠ Rôle `RoleClientFinal` : premier rattachement systématique `Utilisateur`↔`Affectation` pour un
    client final dans ce dépôt.** Bien que le champ `Utilisateur.clientLie` et les permissions `_soi`
    existent déjà (L5/CRM, Réservation), **aucun mécanisme automatique de création d'`Affectation`** à
    la création d'un compte client n'existe encore en code réel — ce plan l'introduit (T3). À vérifier
    qu'aucun autre module (CRM, Réservation) n'a une attente différente de ce même mécanisme avant
    implémentation (risque de divergence si L5/M5 avaient anticipé un autre schéma non lu ici).
12. **⚠ Délai d'expiration panier et durée de session invité non chiffrés par les sources** (15 min /
    24 h retenus par défaut, cf. §1.1/§1.4) — à ajuster avec l'exploitant.
13. **US-L8-01 à 14 non encore validées/numérotées officiellement** dans le backlog (spec préambule) —
    ajustement mineur de nommage possible sans impact structurel attendu sur le modèle de données.
