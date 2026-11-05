#!/usr/bin/env node
// fluvia-bridge — the till's local bridge: Caisse-AP payment terminal + cash drawer. See README.md.
//
//   node bin/fluvia-bridge.js init               create config.json and the signing key (once)
//   node bin/fluvia-bridge.js key                print the public key to paste into Fluvia
//   node bin/fluvia-bridge.js start              run the bridge
//   node bin/fluvia-bridge.js drawer             open the drawer once (installation check)
//   node bin/fluvia-bridge.js fake-terminal [mode] [port]   run a SIMULATED terminal (demo, no hardware)
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createDrawer } from '../src/drawer.js'
import { createFakeTerminal } from '../src/fake-terminal.js'
import { createBridge, listen, VERSION } from '../src/server.js'
import { generateKeyFile, loadPrivateKey, publicKeyBase64 } from '../src/signer.js'

const here = path.dirname(fileURLToPath(import.meta.url))
const configPath = process.env.FLUVIA_BRIDGE_CONFIG || path.join(here, '..', 'config.json')
const dir = path.dirname(configPath)
const stamp = () => new Date().toISOString()
const log = (m) => console.log(`${stamp()} ${m}`)

function readConfig() {
  if (!fs.existsSync(configPath)) {
    console.error(`Pas de configuration (${configPath}) : lancez d'abord « init ».`)
    process.exit(2)
  }
  const config = JSON.parse(fs.readFileSync(configPath, 'utf8'))
  config.keyPath = path.resolve(dir, config.keyPath ?? 'bridge-key.pem')
  if (config.journalPath) config.journalPath = path.resolve(dir, config.journalPath)
  return config
}

const [command = 'start', ...args] = process.argv.slice(2)

if (command === 'init') {
  const keyPath = path.join(dir, 'bridge-key.pem')
  if (!fs.existsSync(configPath)) {
    fs.copyFileSync(path.join(here, '..', 'config.example.json'), configPath)
    console.log(`Configuration créée : ${configPath} — renseignez allowedOrigins, terminal et drawer.`)
  }
  if (!fs.existsSync(keyPath)) generateKeyFile(keyPath)
  console.log(`Clé du pont, à coller dans Fluvia (Paramètres › Points de vente › Clé du pont) :\n${publicKeyBase64(loadPrivateKey(keyPath))}`)
} else if (command === 'key') {
  console.log(publicKeyBase64(loadPrivateKey(readConfig().keyPath)))
} else if (command === 'drawer') {
  const r = await createDrawer(readConfig().drawer, { minIntervalMs: 0 }).open()
  console.log(r.opened ? 'Tiroir : impulsion envoyée.' : 'Tiroir : rien envoyé.')
} else if (command === 'fake-terminal') {
  const fake = createFakeTerminal({ mode: args[0] || 'accept', delayMs: 1500 })
  const port = await fake.listen(Number(args[1] || 8888))
  log(`Terminal SIMULÉ (mode ${fake.state.mode}) sur 127.0.0.1:${port} — aucune carte réelle n'est débitée.`)
} else if (command === 'start') {
  const config = readConfig()
  if (!config.allowedOrigins?.length) {
    console.error('allowedOrigins est vide : indiquez l\'adresse de Fluvia (ex. « https://fluvia.example.fr »).')
    process.exit(2)
  }
  const server = createBridge({ config, privateKey: loadPrivateKey(config.keyPath), drawer: createDrawer(config.drawer), log })
  const port = config.listen?.port ?? 8765
  await listen(server, port, config.listen?.host ?? '127.0.0.1')
  log(`Pont Fluvia ${VERSION} à l'écoute sur http://127.0.0.1:${port} — terminal ${config.terminal?.host ?? '(aucun)'}:${config.terminal?.port ?? 8888}`)
} else {
  console.error(`Commande inconnue : ${command}`)
  process.exit(2)
}
