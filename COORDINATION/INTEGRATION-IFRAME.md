# Intégration en iframe : ce qui est fait, et ce qui demande nginx

*27/08/2026. Demandé par Maxime : « potentiellement une intégration en iframe », « il faut faire
attention je pense avec les différents navigateurs, il faut que ce soit nickel ».*

## Fait, côté application

**L'adresse.** `https://<hôte>/b/<nom-de-la-boutique>` — `piscine-a`, `patinoire-b`. Les anciennes
URL (`?vitrine=<uuid>`) continuent de fonctionner : `VitrineResolver` accepte les deux formes.

**Le code d'intégration**, prêt à copier depuis *Boutique › Vitrines*, avec `title` (sans quoi un
lecteur d'écran annonce un cadre anonyme) et une hauteur généreuse (une boutique dans 400 px produit
deux ascenseurs imbriqués, la façon la plus sûre de perdre un acheteur sur téléphone).

**Le stockage, qui est le vrai piège.** Dans une iframe servie depuis un autre domaine que la page
hôte, Safari et Firefox **cloisonnent** le stockage local, et Safari le **refuse** dans certaines
configurations — `localStorage.getItem` lève alors une `SecurityError`.

Sans précaution, le coût exact était : l'appel lève au premier rendu, l'application ne monte pas, et
**le client voit une page blanche**. Pas un message, pas un panier vide — rien.

Un repli en mémoire garde la boutique utilisable pendant toute la visite. Ce qui se perd, c'est la
persistance du panier d'une ouverture d'onglet à l'autre — une dégradation réelle, sans commune
mesure avec une page blanche. C'est écrit dans l'écran, sous le code d'intégration.

> **Un stockage indisponible est un cas courant, pas une panne. Ce qui casse, ce n'est pas son
> absence : c'est de ne pas l'avoir prévue.**

## Ce qui reste, et pourquoi je ne l'ai pas fait

**Aucun en-tête `frame-ancestors` n'est configuré.** La boutique est donc encapsulable **par
n'importe qui**, y compris dans un site qui la ferait passer pour le sien.

Le correctif est une ligne de configuration nginx, et je ne touche pas à la configuration système :

```
add_header Content-Security-Policy "frame-ancestors 'self' https://site-du-client.fr" always;
```

Deux remarques avant de l'appliquer :

1. **`X-Frame-Options: deny` est déjà envoyé sur les réponses de l'API** (visible dans les réponses
   Symfony). Il ne gêne pas l'intégration des pages, qui sont servies par nginx — mais il faut le
   savoir avant de conclure que « l'iframe ne marche pas ».
2. **La liste des domaines autorisés est par exploitant.** Une valeur unique en dur dans nginx
   interdirait à un second client d'intégrer sa propre boutique. Si l'intégration devient une
   fonctionnalité vendue, la liste doit vivre sur la `Vitrine` et l'en-tête être posé par
   l'application, pas par le serveur web.

## Le point non tranché

Le tunnel de paiement dans une iframe tierce reste à éprouver : certains prestataires refusent d'être
encapsulés, et une redirection 3-D Secure dans un cadre de 900 px est difficilement utilisable. La
pratique courante est d'ouvrir le paiement en haut niveau. **À décider avant de promettre
l'intégration complète à un client.**
