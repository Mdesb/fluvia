// Recentrage, PR 2 (b) — une entrée de menu d'un module masqué ne s'affiche que si le module est actif.
// Lancer : `cd frontend && node --test src/components/`
//
// La correspondance « entrée → module » vit dans UNE table (`moduleMenu.js`). Ce test la confronte à
// la source qui fait foi côté serveur : les manifestes PHP (`app/src/*/*Module.php`) et le cœur
// déclaré par la garde (`ModuleGuard::CORE_MODULES`). Une entrée du menu dont les droits appartiennent
// à un module masqué, et qui manque à la table, le fait échouer : elle resterait affichée module
// éteint, et mènerait à des 404.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync, existsSync } from 'node:fs'
import { join } from 'node:path'
import { MODULE_OF_MENU_ENTRY, MODULE_OF_SUBTAB, moduleAllowsEntry, moduleAllowsSubtab, isModuleActive } from './moduleMenu.js'

const FRONT = new URL('../../', import.meta.url).pathname
const SRC_PHP = join(FRONT, '../app/src')

function maskedModules() {
  const guard = readFileSync(join(SRC_PHP, 'Platform/Module/ModuleGuard.php'), 'utf8')
  const core = [...(guard.match(/CORE_MODULES = \[([^\]]*)\]/)?.[1] ?? '').matchAll(/'([a-z_]+)'/g)].map((m) => m[1])
  assert.ok(core.length >= 5, 'témoin : le cœur doit être lu dans ModuleGuard.php')

  const masked = new Map()
  for (const dir of readdirSync(SRC_PHP)) {
    const path = join(SRC_PHP, dir)
    const files = existsSync(path) && !dir.endsWith('.php') ? readdirSync(path).filter((f) => /^[A-Z]\w*Module\.php$/.test(f)) : []
    for (const file of files) {
      const php = readFileSync(join(path, file), 'utf8')
      const body = (name) => php.match(new RegExp(`function ${name}\\(\\)[^{]*\\{([\\s\\S]*?)\\n    \\}`))?.[1] ?? ''
      const id = body('id').match(/return '([a-z_]+)'/)?.[1]
      const capability = body('capability').match(/return '([a-z_]+)'/)?.[1]
      if (!id || !capability || core.includes(id)) continue
      masked.set(id, [...body('permissions').matchAll(/'([a-z_]+\.[a-z_]+)'/g)].map((m) => m[1]))
    }
  }
  assert.ok(masked.size >= 10, `témoin : trop peu de modules masqués lus (${masked.size})`)
  return masked
}

function menuEntries() {
  const shell = readFileSync(join(FRONT, 'src/components/AppShell.jsx'), 'utf8')
  const nav = shell.slice(shell.indexOf('const NAV = ['))
  const entries = [...nav.matchAll(/\{\s*id: '([a-z_]+)'([^{}]*)\}/g)].map(([, id, rest]) => ({
    id,
    perms: [...rest.matchAll(/'([a-z_*]+\.[a-z_*]+)'/g)].map((m) => m[1]),
  }))
  assert.ok(entries.length >= 30, `témoin : trop peu d'entrées de menu lues (${entries.length})`)
  return entries
}

test('chaque entrée de menu d’un module masqué figure dans la table', () => {
  const masked = maskedModules()
  const owner = new Map([...masked].flatMap(([id, perms]) => perms.map((p) => [p, id])))
  const missing = menuEntries()
    .filter((e) => masked.has(e.id) || e.perms.some((p) => owner.has(p)))
    .filter((e) => !(e.id in MODULE_OF_MENU_ENTRY))
    .map((e) => e.id)
  assert.deepEqual(missing, [])
})

test('la table ne nomme que des entrées réelles et des modules masqués', () => {
  const masked = maskedModules()
  const ids = new Set(menuEntries().map((e) => e.id))
  for (const [entry, module] of Object.entries(MODULE_OF_MENU_ENTRY)) {
    assert.ok(ids.has(entry), `« ${entry} » n'est pas une entrée du menu`)
    assert.ok(masked.has(module), `« ${module} » n'est pas un module masqué`)
  }
})

test('le menu se sert de la table pour filtrer ses entrées', () => {
  const shell = readFileSync(join(FRONT, 'src/components/AppShell.jsx'), 'utf8')
  assert.match(shell, /moduleAllowsEntry\(it\.id, modules\)/)
})

test('une entrée de module masqué n’apparaît que si son module est actif', () => {
  assert.equal(moduleAllowsEntry('padel', ['padel', 'vente']), true)
  assert.equal(moduleAllowsEntry('padel', ['vente']), false)
  // Une entrée du cœur ne dépend d'aucun module.
  assert.equal(moduleAllowsEntry('caisse', []), true)
  // Serveur qui ne dit pas encore ses modules : on ne cache rien, la garde serveur reste le contrôle.
  assert.equal(moduleAllowsEntry('padel', undefined), true)
})

// ── Relecture de la PR 2 (20/10) : les écrans du cœur qui touchent un module masqué ─────────────

test('les sous-onglets de module existent et nomment un module masqué', () => {
  const masked = maskedModules()
  for (const [key, module] of Object.entries(MODULE_OF_SUBTAB)) {
    const [page, tab] = key.split('.')
    const src = readFileSync(join(FRONT, `src/pages/${page}.jsx`), 'utf8')
    assert.ok(src.includes(`['${tab}', `), `« ${tab} » n'est pas un sous-onglet de ${page}.jsx`)
    assert.ok(masked.has(module), `« ${module} » n'est pas un module masqué`)
  }
  assert.equal(MODULE_OF_SUBTAB['Parametres.connecteurs'], 'connecteurs')
})

test('Paramètres filtre ses sous-onglets par la table', () => {
  const src = readFileSync(join(FRONT, 'src/pages/Parametres.jsx'), 'utf8')
  assert.match(src, /moduleAllowsSubtab\(`Parametres\.\$\{id\}`, modules\)/)
})

test('la fiche produit ne lit les expositions que si Musée est actif', () => {
  const src = readFileSync(join(FRONT, 'src/components/ProduitFiche.jsx'), 'utf8')
  assert.match(src, /isModuleActive\('musee', modules\) && <AgendaProduit/)
})

test('la fiche client ne lit fidélité et parrainage que si Marketing est actif', () => {
  const src = readFileSync(join(FRONT, 'src/pages/Clients.jsx'), 'utf8')
  assert.match(src, /!isModuleActive\('marketing', modules\)/)
  assert.match(src, /isModuleActive\('marketing', modules\) && <BlocParrainage/)
})

test('un sous-onglet ou un appel de module ne dépend que de son module', () => {
  assert.equal(moduleAllowsSubtab('Parametres.connecteurs', ['connecteurs']), true)
  assert.equal(moduleAllowsSubtab('Parametres.connecteurs', ['vente']), false)
  assert.equal(moduleAllowsSubtab('Parametres.caisse', []), true)
  assert.equal(isModuleActive('musee', ['musee']), true)
  assert.equal(isModuleActive('musee', []), false)
  // Serveur muet : rien n'est caché, rien n'est coupé (la garde serveur reste le contrôle).
  assert.equal(isModuleActive('musee', undefined), true)
})
