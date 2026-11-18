# Faits établis (chercheur, 18/11/2026) — nf525-ordre-cles (#369)

Lecture seule. VERIFIED = lu dans le code (fichier:ligne) ; UNVERIFIED = non mesuré.

## Trousseau
- VERIFIED — `SealKeyring` (app/src/Platform/Nf525/SealKeyring.php) : id de clé = 16 premiers hex de `HMAC-SHA256('fluvia-nf525-seal-key-id', clé)` (:70-73). Trois trousseaux (vente, compta, facturation) déclarés dans app/config/services.yaml:159-179 ; clé active obligatoire, clés retirées optionnelles (`*_RETIRED_KEYS`, séparées par virgules ou espaces).
- VERIFIED — Aucune notion de rang, de date d'activation ou de retrait d'une clé. L'ordre de `*_RETIRED_KEYS` n'est ni documenté ni utilisé (infra/README.md:265-316).
- VERIFIED — `check()` (:117-137) : id NULL → essaie la clé active puis toutes les retirées sur la signature non liée ; id connu → signature liée `HMAC(empreinte|id)` ; id inconnu → `UnknownKey`.
- VERIFIED — Conséquence : un maillon forgé avec une clé retirée passe, qu'il porte l'id de cette clé ou NULL.

## Chaînes
- Vente : `nf525_operation_scellee.seal_key_id` ; `HashChainSignataire::verifieSegment` (tri par séquence, contrôle de clé seulement si l'empreinte est juste) ; rapport `RapportVerification` ; consommé par `POST /nf525/verifier-chaine` (409 si rompue) et par `SynchroOperationsProcessor::rejouer` (refuse l'ancrage hors ligne si rompue → **une nouvelle anomalie bloque la synchro hors ligne**).
- Compta : `compta_ecriture_comptable.seal_key_id` ; `ScellementEcritureHandler::verifieChaine` (:93-150) ; `GET /compta/ecritures/verifier-chaine` (200 + rapport).
- Facturation : `facturation_facture.seal_key_id` ; `ScellementFactureHandler::verifieChaine` (:110-201) ; `GET /factures/verifier-chaine`.
- Les trois vérifications sont dupliquées volontairement (commentaires ScellementEcritureHandler.php:13-21).
- `assertCanExtend` (refus de sceller) ne voit que le dernier maillon.

## Archive fiscale
- VERIFIED — Le ZIP est signé SANS id (`signWithoutId`), vérifié contre toutes les clés (FiscalArchiveSigner.php:32-40) : une clé retirée fuitée permet de forger une archive entière.
- VERIFIED — Le CSV des maillons de l'archive porte `seal_key_id` ; `FiscalArchiveChecker::verifierChaine` (:86-129) rejoue `verifieSegment` par point de vente, ancré sur le premier maillon du segment (ne voit pas la clé du maillon qui précède le segment).

## Déploiement
- VERIFIED — `app:nf525:check-keys` ne contrôle que le dernier maillon de chaque chaîne ; lancé par les deux scripts de déploiement (verdict en fin).
- VERIFIED — `nf525_guard_key_generation` (infra/nf525-key-guard.sh) refuse de générer une clé si une chaîne existe ; sans lien avec la rotation volontaire.

## Données réelles
- UNVERIFIED — Présence de maillons à `seal_key_id` NULL en préprod et en production (à mesurer par `SELECT … WHERE seal_key_id IS NULL`). Les commentaires de #361 (lus par la session principale) disent : préprod purgée le 08/11, production 0 opération scellée.
- VERIFIED — Aucun chemin de code n'écrit un `seal_key_id` NULL aujourd'hui.

## Aucune API tierce
PHP natif (`hash_hmac`, `hash_equals`) et code maison : aucune fiche `docs/references/` requise.
