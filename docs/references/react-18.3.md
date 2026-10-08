# React 18.3 — fiche de référence

- Version du projet : react 18.3.1 et react-dom 18.3.1 (source : frontend/package-lock.json, lignes 1687 et 1699)
- Vérifiée le : 2026-10-07 · Revoir avant le : 2027-01-05
- Sources :
  - [officielle] https://18.react.dev/reference/react/StrictMode (documentation de la version 18, lu le 2026-10-07)
  - [officielle] https://18.react.dev/reference/react/useEffect (lu le 2026-10-07)
  - [officielle] https://18.react.dev/learn/synchronizing-with-effects (lu le 2026-10-07)
  - [officielle] https://react.dev/blog/2024/04/25/react-19-upgrade-guide (section React 18.3, lu le 2026-10-07)
- Questions couvertes : `StrictMode` en développement et effets de montage ; nettoyage d'un minuteur dans `useEffect`.

## À utiliser

### `StrictMode` en développement
- OUI : en développement, React exécute pour CHAQUE effet un cycle supplémentaire « mise en place → nettoyage → mise en place » dès le montage. [officielle] StrictMode (18)
- Les composants (et les fonctions passées à `useState`, `useMemo`…) sont aussi appelés deux fois en développement. [officielle] StrictMode (18)
- Ces contrôles n'existent qu'en développement ; aucun effet sur la version de production. [officielle] StrictMode (18)
- Réponse attendue : écrire un nettoyage qui défait exactement la mise en place. Bloquer la seconde exécution avec une `ref` est explicitement présenté comme un piège courant. [officielle] synchronizing-with-effects

### `useEffect(setup, dependencies?)` — nettoyage
- Le nettoyage s'exécute avant chaque nouvelle exécution (dépendances changées) et au démontage ; en Strict Mode développement, une fois de plus avant la vraie mise en place. [officielle] useEffect (18)
- Minuteur : créer dans `setup`, annuler dans la fonction renvoyée — `setInterval` → `clearInterval(id)`, `setTimeout` → `clearTimeout(id)` (la doc demande d'annuler le délai en attente). [officielle] useEffect + synchronizing-with-effects (18)
- Lire l'état dans le rappel du minuteur : utiliser la mise à jour fonctionnelle (`setCount(c => c + 1)`) pour éviter une valeur figée et une dépendance inutile. [officielle] useEffect (18)
- Requête réseau dans un effet : drapeau `ignore` positionné dans le nettoyage, ou `AbortController` annulé dans le nettoyage. [officielle] synchronizing-with-effects

Forme documentée (reformulée) :
```js
useEffect(() => {
  const id = setInterval(() => setCount(c => c + 1), 1000);
  return () => clearInterval(id);
}, []);
```

## Obsolète ou retiré dans cette version — ne pas utiliser
React 18.3 = 18.2 + avertissements de dépréciation pour les API retirées en 19. [officielle] guide de migration React 19

| Ancien | Remplacé par | Depuis | Source |
|---|---|---|---|
| `ReactDOM.render()` | `createRoot()` | avertissement 18.3 | [officielle] guide React 19 |
| `ReactDOM.hydrate()` | `hydrateRoot()` | 18.3 | idem |
| `unmountComponentAtNode()` | `root.unmount()` | 18.3 | idem |
| `findDOMNode()` | refs (`useRef`) | 18.3 | idem |
| `defaultProps` sur composant fonction | paramètres par défaut ES6 | 18.3 | idem |
| `propTypes` sur composant fonction | TypeScript ou autre contrôle de types | 18.3 | idem |
| refs chaîne | refs fonction | 18.3 | idem |
| contexte hérité (`contextTypes`, `getChildContext`) | `createContext()` | 18.3 | idem |
| `act` de `react-dom/test-utils` | `act` de `react` | 18.3 | idem |
| `react-test-renderer/shallow` | paquet `react-shallow-renderer` | 18.3 | idem |

## Pièges connus
- Un minuteur sans nettoyage tourne DEUX fois en développement (double montage Strict Mode) : symptôme typique d'un décompte qui va deux fois trop vite. [officielle] StrictMode + useEffect (18)
- Une requête lancée au montage part deux fois en développement : prévoir `ignore` ou `AbortController`. [officielle] synchronizing-with-effects
- react.dev décrit React 19 ; pour ce projet, lire 18.react.dev.
