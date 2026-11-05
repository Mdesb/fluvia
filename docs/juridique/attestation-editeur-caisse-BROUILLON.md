# BROUILLON — Attestation individuelle de l'éditeur (logiciel de caisse Fluvia)

> **NON SIGNÉ. NE PAS REMETTRE À UN CLIENT EN L'ÉTAT.** Ce document prépare, pour Maxime, l'attestation
> que l'article 286 I 3° bis du CGI permet de nouveau depuis le 21/02/2026. La signature engage le
> représentant légal de l'éditeur : délivrer une attestation inexacte est pénalement sanctionné
> (rappel porté par le modèle officiel lui-même). Rédigé le 05/11/2026 par la session `ao-clotures`
> (#342), à relire et à compléter par Maxime.

## 1. Le texte, vérifié à la source (05/11/2026)

| Source | Ce qu'elle dit | Lien |
|---|---|---|
| CGI art. 286 I 3° bis, version en vigueur **du 21/02/2026 au 27/06/2026** (Légifrance) | Le logiciel de caisse doit satisfaire à des conditions d'inaltérabilité, de sécurisation, de conservation et d'archivage des données, attestées par un certificat d'un organisme accrédité **ou par une attestation individuelle de l'éditeur, conforme à un modèle fixé par l'administration**. | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000053546765/2026-02-21 |
| Loi n° 2026-103 du 19/02/2026 de finances pour 2026, **art. 125** (JORF) | Complète le 3° bis par les mots « ou par une attestation individuelle de l'éditeur, conforme à un modèle fixé par l'administration » ; ajoute « de l'attestation ou » à l'art. 1770 duodecies. | https://www.legifrance.gouv.fr/jorf/article_jo/JORFARTI000053508893 |
| BOFiP ACTU-2026-00073 du 25/03/2026 | Rétablissement de l'attestation éditeur, applicable **à compter du 21/02/2026** ; certificat **ou** attestation, au choix. | https://bofip.impots.gouv.fr/bofip/15035-PGP.html/ACTU-2026-00073 |
| BOI-LETTRE-000242 du 25/03/2026 | **Le modèle officiel** de l'attestation (partie I éditeur, partie II entreprise utilisatrice). | https://bofip.impots.gouv.fr/doctrine/pgp/10692-PGP |
| BOI-TVA-DECLA-30-10-30 du 25/03/2026 | Les quatre conditions détaillées : inaltérabilité (§ 80-120), sécurisation (§ 130-150), conservation et clôtures (§ 155-200), archivage (§ 220-260). | https://bofip.impots.gouv.fr/doctrine/pgp/10691-PGP |

**Historique.** La loi de finances pour 2025 (art. 43) avait supprimé l'attestation éditeur au profit du
seul certificat ; la loi de finances pour 2026 (art. 125) l'a rétablie. Ce qui circulait dans la presse
est donc confirmé au texte.

**Trois points NON vérifiés au texte, à vérifier avant signature :**

1. **La version de l'art. 286 au-delà du 27/06/2026.** Légifrance borne la version vérifiée au
   27/06/2026 sans afficher la suivante. La presse spécialisée annonce le transfert des règles de TVA
   du CGI vers le code des impositions sur les biens et services (CIBS), reporté au **01/01/2027**
   (ordonnance n° 2025-1247 du 17/12/2025, ajustée par une ordonnance de juillet 2026). Si c'est
   exact, la référence légale à citer pour une mise en service au 01/04/2027 ne sera plus « CGI
   art. 286 I 3° bis » mais un article du CIBS. Le contenu de l'obligation serait inchangé (rescrit
   BOI-RES-TVA-000253 du 18/02/2026, selon la presse : la doctrine CGI reste applicable aux articles
   CIBS correspondants). **À relire sur Légifrance avant de signer.**
2. **Le montant de l'amende de l'art. 1770 duodecies** (7 500 € par logiciel selon la presse) : non
   relu au texte.
3. **Les conditions de forme exactes du modèle** : le présent brouillon en reprend la structure ; la
   version signée doit être établie **sur le modèle BOI-LETTRE-000242 lui-même**, pas sur ce fichier.

## 2. Partie I du modèle — à remplir par l'éditeur

| Rubrique du modèle | Proposition | À faire par Maxime |
|---|---|---|
| Représentant légal (nom, qualité) et raison sociale de l'éditeur | `[À COMPLÉTER]` | Identité exacte de la société éditrice de Fluvia. |
| Nom et références du logiciel ; tout le produit ou seulement ses fonctions de caisse | **Fluvia**, fonctions d'encaissement (module Vente/Caisse) | Décider si l'attestation couvre le produit entier ou seulement le module caisse (recommandé : le module caisse, voir § 3). |
| Date de mise sur le marché | `[À COMPLÉTER]` | |
| Numéro de version et références d'identification | `[À COMPLÉTER — ex. version majeure + empreinte de commit]` | Arrêter une règle de numérotation majeure/mineure (rubrique facultative du modèle, qui ouvre une tolérance selon BOI-TVA-DECLA-30-10-30). |
| Numéro de licence | Sans objet si pas de licence nominative | |
| Déclaration de conformité | Le logiciel satisfait aux conditions d'inaltérabilité, de sécurisation, de conservation et d'archivage des données en vue du contrôle de l'administration fiscale. | **Ne signer qu'après les levées du § 4.** |
| Fonctions couvertes | voir § 3 | |
| Fonctions non couvertes | voir § 3 | |
| Lieu, date, signature du représentant légal de l'éditeur | — | Signature manuscrite ou électronique de Maxime / du représentant légal. |

La **partie II** (entreprise utilisatrice : date d'acquisition, distributeur, date de début d'utilisation,
signature de son représentant légal) est remplie par **chaque client**, par exemple la régie des
piscines de l'agglomération de Maubeuge. Sans partie II signée, l'attestation n'est pas valable.

## 3. Ce que le code fait réellement (relevé au 05/11/2026, branche `feat/ao-clotures`)

| Condition (BOI-TVA-DECLA-30-10-30) | Mécanisme dans Fluvia | Où |
|---|---|---|
| Inaltérabilité (§ 80-120) : corrections par écritures « plus/moins », intégrité prouvée (empreinte, chaînage) | Toute vente validée, avoir, correction de règlement et clôture est un maillon chaîné : empreinte SHA-256 du contenu canonique + empreinte précédente, signée HMAC-SHA256. Aucune modification ni suppression par l'application (garde ORM) ; corrections par avoir. | `app/src/Vente/Nf525/HashChainSignataire.php`, `InalterabiliteListener.php` |
| Sécurisation (§ 130-150) : pas de suppression sans trace, auteur des opérations | Vérification de chaîne à la demande (trou de séquence, empreinte, signature). Auteur sur chaque clôture. | `app/src/Vente/State/VerifierChaineProcessor.php` |
| Conservation et clôtures (§ 155-200) : clôtures journalière, mensuelle, annuelle, cumulatives ; grand total de période et total perpétuel | Arrêté de journée (D57) ; **clôtures mensuelle et annuelle (#342)** avec total de période, cumul d'exercice et cumul perpétuel, scellées dans la chaîne. | `DailyClosureHandler.php`, `PeriodClosureHandler.php` |
| Archivage (§ 220-260) : au plus annuel, intégrité dans le temps, format ouvert, notice en français | **Archive annuelle (#342/#343)** : ZIP de CSV + notice + manifeste d'empreintes, empreinte SHA-256 et signature HMAC, vérification à la demande. Pas de purge des données actives. | `FiscalArchiveBuilder.php`, `FiscalArchiveChecker.php` |

**Fonctions à déclarer NON couvertes** (proposition prudente) : les modules hors encaissement (CRM,
réservation, comptabilité générale, facturation électronique B2B/B2G, contrôle d'accès hors
fréquentation archivée), et tout connecteur de terminal de paiement (le montant est saisi sur le TPE
autonome).

## 4. À lever AVANT de signer

1. **Clé de signature symétrique.** La chaîne et l'archive sont signées par HMAC avec une clé détenue
   côté serveur (`NF525_SEAL_KEY`). Le BOFiP cite en exemple une empreinte « à clé privée » ; un
   référent conformité doit dire si le HMAC suffit ou s'il faut une signature asymétrique (le code
   est prévu pour en changer sans toucher au reste : `SignataireOperation`, `FiscalArchiveSigner`).
2. **Mode formation / test (§ 150).** Non vérifié dans ce lot : s'assurer qu'un éventuel mode de test
   est clairement identifié avec l'opérateur, ou qu'il n'existe pas.
3. **Mode hors ligne.** Vérifier que les ventes faites hors ligne entrent dans la chaîne au retour du
   réseau sans trou (lot distinct).
4. **Déclenchement des clôtures.** Les clôtures mensuelle et annuelle sont **manuelles** dans ce lot
   (l'arrêté de journée est planifié). Décider s'il faut les planifier aussi, ou documenter la
   procédure chez le client.
5. **Conservation six ans** de l'archive chez le client (le fichier se télécharge ; il reste aussi en
   base) et des sauvegardes côté hébergeur.
6. **Référence légale à jour** au moment de la signature (point 1 du § 1).
