// The bridge's signing key (Ed25519). The PRIVATE key never leaves the till PC; Fluvia only knows the
// PUBLIC key (pasted in Paramètres › Points de vente › Terminal de paiement), and verifies with it that
// an outcome relayed by the browser really comes from this bridge — the browser cannot forge one.
import crypto from 'node:crypto'
import fs from 'node:fs'

export function generateKeyFile(path) {
  const { privateKey } = crypto.generateKeyPairSync('ed25519')
  fs.writeFileSync(path, privateKey.export({ type: 'pkcs8', format: 'pem' }), { mode: 0o600, flag: 'wx' })
}

export function loadPrivateKey(path) {
  return crypto.createPrivateKey(fs.readFileSync(path, 'utf8'))
}

/** The raw 32-byte public key, base64: what Fluvia stores as `tpe.clePont`. */
export function publicKeyBase64(privateKey) {
  const jwk = crypto.createPublicKey(privateKey).export({ format: 'jwk' })
  return Buffer.from(jwk.x, 'base64url').toString('base64')
}

/**
 * Sign an outcome. The signed bytes are the JSON text itself, sent as a string: Fluvia verifies the
 * exact bytes, then reads them — no canonicalisation to get wrong on either side.
 */
export function signOutcome(privateKey, outcome) {
  const result = JSON.stringify(outcome)
  const signature = crypto.sign(null, Buffer.from(result, 'utf8'), privateKey).toString('base64')
  return { result, signature }
}
