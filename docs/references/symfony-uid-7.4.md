# Symfony Uid 7.4 — fiche de référence

- Version du projet : v7.4.9 (source : app/composer.lock, ligne 9116)
- Vérifiée le : 2026-10-07 · Revoir avant le : 2027-01-05
- Sources :
  - [officielle] https://symfony.com/doc/7.4/components/uid.html (lu le 2026-10-07)
  - [officielle] https://raw.githubusercontent.com/symfony/uid/v7.4.9/CHANGELOG.md (tag v7.4.9, lu le 2026-10-07)
  - [officielle] code au tag v7.4.9 : `Uuid.php`, `AbstractUid.php` (lus le 2026-10-07)
- Questions couvertes : `Uuid::isValid()`, `Uuid::fromString()`, `->toBinary()`, `->equals()`, `Uuid::v4()` — dépréciations en 7.4 ?

## À utiliser
- **Aucune dépréciation** sur ces méthodes en 7.4 : ni `@deprecated` ni `trigger_deprecation()` dans `Uuid.php` ni `AbstractUid.php` au tag v7.4.9 ; le CHANGELOG 7.0→7.4 n'en annonce aucune. [officielle] code + CHANGELOG@v7.4.9
- `Uuid::v4(): UuidV4` (méthode `final static`). [officielle] Uuid.php@v7.4.9
- `Uuid::fromString(string $uuid): static` — accepte TOUS les formats (`FORMAT_ALL`) : RFC 4122/9562 (36 car.), base 58, base 32, binaire (16 octets) ; renvoie la sous-classe de la version détectée (`UuidV4`…), `NilUuid`, `MaxUuid`. Entrée invalide → `Symfony\Component\Uid\Exception\InvalidArgumentException`. [officielle] Uuid.php@v7.4.9 + doc
- `Uuid::isValid(string $uuid /* , int $format = self::FORMAT_RFC_9562 */): bool` — le 2e argument n'est pas déclaré (lu par `func_get_arg(1)`), ajouté en 7.2. PAR DÉFAUT seulement le format RFC (36 car. avec tirets, variante `[89ab]`, NIL et MAX admis) ; autres formats : `FORMAT_BINARY`, `FORMAT_BASE_32`, `FORMAT_BASE_58`, `FORMAT_RFC_4122` (= `FORMAT_RFC_9562`), `FORMAT_ALL`, combinables par `|`. Appelé sur une sous-classe (`UuidV4::isValid()`), vérifie aussi le numéro de version. [officielle] doc + CHANGELOG (7.2) + Uuid.php@v7.4.9
- `->toBinary(): string` (16 octets), `->toRfc4122(): string` (36 car.), `->toBase58()`, `->toBase32()`, `->toHex()`, `->toString()` (7.1+). [officielle] doc + AbstractUid.php@v7.4.9
- `->equals(mixed $other): bool` — `false` si `$other` n'est pas un `AbstractUid`, sinon comparaison stricte de la forme canonique interne. `->compare(self $other): int` pour ordonner. [officielle] AbstractUid.php@v7.4.9
- Doctrine : type `UuidType::NAME` pour une colonne ; en paramètre de requête, passer `UuidType::NAME` ou `->toBinary()` avec `ParameterType::BINARY`. [officielle] doc

## Pièges connus
- `isValid()` et `fromString()` n'ont pas le même périmètre par défaut : une chaîne base 58 est REFUSÉE par `isValid($x)` mais ACCEPTÉE par `fromString($x)`. Valider avec `isValid()` avant `fromString()` si l'on veut n'accepter que le format RFC. [officielle] Uuid.php@v7.4.9
- `equals()` avec une chaîne renvoie toujours `false` : comparer deux objets `Uuid`. [officielle] AbstractUid.php@v7.4.9
- 7.4 : la FABRIQUE `UuidFactory` produit désormais des UUID v7 par défaut (et v7 passe en précision microseconde) ; `Uuid::v4()` appelé directement n'est pas concerné. [officielle] CHANGELOG@v7.4.9 + doc
- 7.3 : hiérarchie d'exceptions propre au composant (`Symfony\Component\Uid\Exception\…`). `Symfony\Component\Uid\Exception\InvalidArgumentException extends \InvalidArgumentException` : un `catch (\InvalidArgumentException)` existant l'attrape toujours. [officielle] CHANGELOG + Exception/InvalidArgumentException.php@v7.4.9
