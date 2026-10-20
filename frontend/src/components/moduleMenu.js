// LES ENTRÉES DU MENU QUI APPARTIENNENT À UN MODULE MASQUABLE (recentrage, PR 2).
//
// Une entrée de cette table n'apparaît que si son module figure dans `modulesActifs` de `/me` — la
// même règle que la garde serveur, qui rend 404 aux opérations d'un module éteint. Sans elle, le menu
// montrerait un écran dont chaque appel échoue.
//
// ⚠ UNE SEULE TABLE, ET UN TEST QUI LA TIENT. `moduleMenu.test.js` lit les manifestes PHP : une
// entrée dont les droits appartiennent à un module masqué et qui manque ici le fait échouer. Les
// valeurs sont les identifiants des manifestes (`ModuleManifest::id()`), pas des capacités.
export const MODULE_OF_MENU_ENTRY = {
  padel: 'padel',
  patinoire: 'patinoire',
  musee: 'musee',
  sejours: 'stay',
  campagnes: 'marketing',
  personnel: 'personnel',
  projets: 'project',
  finance: 'finance',
  caution: 'caution',
  relance_recettes: 'revenue_recovery',
  places_liberees: 'smart_flow',
  imports: 'import',
  social: 'social',
}

// `modules` absent (serveur qui ne le dit pas encore) : on ne cache rien. Le menu n'est pas le
// contrôle — la garde serveur l'est — et un menu vide enfermerait l'utilisateur hors de son logiciel.
export function moduleAllowsEntry(entryId, modules) {
  const module = MODULE_OF_MENU_ENTRY[entryId]
  return !module || !Array.isArray(modules) || modules.includes(module)
}
