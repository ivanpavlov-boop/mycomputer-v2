// Real proxy test. NGINX_BINARY and NGINX_TEST_OUTPUT are isolated local paths.
import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { spawn } from 'node:child_process'
import { once } from 'node:events'
import { readFile, writeFile, mkdir, copyFile } from 'node:fs/promises'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'

const executable = process.env.NGINX_BINARY
const root = process.env.NGINX_TEST_OUTPUT?.replaceAll('\\', '/')
assert(executable && root, 'Provide NGINX_BINARY and a new NGINX_TEST_OUTPUT; no implicit download or skip')
await mkdir(root)
await mkdir(`${root}/logs`)
await mkdir(`${root}/temp`)
await mkdir(`${root}/public`)
const records = [{ runtime: { platform: process.platform, arch: process.arch, node: process.version, executable } }]
async function command(args) {
  const child = spawn(executable, args, { cwd: root, shell: false, windowsHide: true })
  let stdout = '', stderr = ''
  child.stdout.on('data', b => { stdout += b })
  child.stderr.on('data', b => { stderr += b })
  const [exit, signal] = await once(child, 'close')
  const result = { args, exit, signal, stdout, stderr, utc: new Date().toISOString() }
  records.push(result)
  return result
}
async function listen(server) {
  server.listen(0, '127.0.0.1')
  await once(server, 'listening')
  return server.address().port
}
const body = await sharp({ create: { width: 40, height: 20, channels: 3, background: '#317c4b' } }).webp().toBuffer()
const received = []
const counters = new Map()
const recovering = new Set()
const upstream = createServer(async (req, res) => {
  received.push({ url: req.url, headers: req.headers })
  const count = (counters.get(req.url) || 0) + 1
  counters.set(req.url, count)
  const name = req.url.split('/').pop()
  const headers = { 'Content-Type': 'image/webp', 'Cache-Control': 'public, max-age=60', 'X-Fixture-Generation': String(count) }
  let status = 200
  if (name === 'cold') await new Promise(r => setTimeout(r, 150))
  if (name === 'expires') headers['Cache-Control'] = 'public, max-age=1'
  if (name === 'private') headers['Cache-Control'] = 'private, max-age=60'
  if (name === 'no-store') headers['Cache-Control'] = 'no-store'
  if (name === 'cookie') headers['Set-Cookie'] = 'synthetic=test'
  if (name === 'vary') headers.Vary = 'Accept'
  if (name === 'vary-star') headers.Vary = '*'
  if (name === 'html') headers['Content-Type'] = 'text/html'
  if (name === 'redirect') { status = 302; headers.Location = '/not-followed' }
  if (name === 'error' || (name === 'recovery' && !recovering.has(name))) status = 503
  res.writeHead(status, headers).end(body)
})
const upstreamPort = await listen(upstream)
const reservation = createServer()
const proxyPort = await listen(reservation)
await new Promise(r => reservation.close(r))
const templatePath = fileURLToPath(new URL('../../../deploy/nginx/mycomputer.conf.template', import.meta.url))
const template = await readFile(templatePath, 'utf8')
const rendered = template
  .replaceAll('/var/cache/nginx/catalog-images', `${root}/cache`)
  .replaceAll('/var/www/html/public', `${root}/public`)
  .replaceAll('/var/www/html/storage/app/public/', `${root}/storage/`)
  .replaceAll('listen 80;', `listen 127.0.0.1:${proxyPort};`)
  .replaceAll('frontend:3000', `127.0.0.1:${upstreamPort}`)
  .replaceAll('app:9000', '127.0.0.1:9')
  .replace(/\$\{(?:PUBLIC_COMMERCE_ENABLED|PUBLIC_COMMERCE_CONFIRMATION_ENABLED|ABANDONED_CART_RECOVERY_ENABLED|LEGAL_CONTENT_APPROVED)\}/g, 'false')
// Distribution builds can have absolute /var/lib defaults; keep every temp path isolated.
const tempPaths = ['client_body', 'proxy', 'fastcgi', 'uwsgi', 'scgi']
  .map(name => `${name}_temp_path temp/${name};`).join('\n')
const config = `worker_processes 1;\npid logs/nginx.pid;\nerror_log logs/error.log notice;\nevents { worker_connections 128; }\nhttp { access_log logs/access.log;\n${tempPaths}\n${rendered}\n}\n`
await writeFile(`${root}/nginx.conf`, config, { flag: 'wx' })
await copyFile(process.env.NGINX_FASTCGI_PARAMS || resolve(dirname(executable), 'conf/fastcgi_params'), `${root}/fastcgi_params`)
const args = ['-p', root + '/', '-c', 'nginx.conf', '-e', `${root}/logs/error.log`]
let processHandle
try {
  assert.equal((await command(['-V'])).exit, 0)
  assert.equal((await command([...args, '-t'])).exit, 0, 'Real nginx configuration validation')
  processHandle = spawn(executable, [...args, '-g', 'daemon off;'], { cwd: root, shell: false, windowsHide: true })
  const close = once(processHandle, 'close')
  processHandle.stdout.on('data', b => records.push({ processStdout: b.toString() }))
  processHandle.stderr.on('data', b => records.push({ processStderr: b.toString() }))
  const origin = `http://127.0.0.1:${proxyPort}`
  for (let i = 0; ; i++) {
    try { await fetch(origin + '/catalog'); break } catch (error) {
      if (i >= 99) throw error
      await new Promise(r => setTimeout(r, 50))
    }
  }
  const get = async (name, options = {}) => {
    const response = await fetch(origin + '/_ipx/cards/' + name, { redirect: 'manual', ...options })
    const bytes = Buffer.from(await response.arrayBuffer())
    const result = { name, status: response.status, headers: Object.fromEntries(response.headers), bytes: bytes.length }
    records.push(result)
    return result
  }
  const first = await get('320/public')
  assert.equal(first.headers['x-image-cache'], 'MISS')
  assert.equal((await get('320/public')).headers['x-image-cache'], 'HIT')
  assert.equal((await get('640/public')).headers['x-image-cache'], 'MISS')
  assert.equal(counters.get('/_ipx/cards/320/public'), 1)
  assert.equal(first.headers['x-frame-options'], 'SAMEORIGIN')
  assert.equal(first.headers['x-content-type-options'], 'nosniff')
  assert.equal(first.headers['referrer-policy'], 'strict-origin-when-cross-origin')
  assert.equal(first.headers['permissions-policy'], 'camera=(), microphone=(), geolocation=()')
  const concurrent = await Promise.all(Array.from({ length: 6 }, () => get('320/cold')))
  assert.equal(counters.get('/_ipx/cards/320/cold'), 1, 'Cold requests share one upstream call')
  assert.equal(concurrent.filter(r => r.headers['x-image-cache'] === 'MISS').length, 1)
  assert.equal(concurrent.filter(r => r.headers['x-image-cache'] === 'HIT').length, 5)
  await get('320/expires')
  await new Promise(r => setTimeout(r, 2100))
  const expired = await get('320/expires')
  assert.equal(expired.headers['x-image-cache'], 'EXPIRED')
  assert.equal(expired.headers['x-fixture-generation'], '2')
  assert.equal((await get('320/expires')).headers['x-image-cache'], 'HIT')
  for (const name of ['private', 'no-store', 'cookie', 'vary', 'vary-star', 'html', 'redirect', 'error']) {
    await get('320/' + name)
    const again = await get('320/' + name)
    assert.notEqual(again.headers['x-image-cache'], 'HIT', name)
    assert.equal(counters.get('/_ipx/cards/320/' + name), 2, name)
  }
  assert.equal((await get('320/recovery')).status, 503)
  recovering.add('recovery')
  assert.equal((await get('320/recovery')).status, 200)
  assert.equal((await get('320/recovery')).headers['x-image-cache'], 'HIT')
  await get('320/anonymous', { headers: { Authorization: 'Bearer synthetic', Cookie: 'synthetic=user-a', 'X-Api-Key': 'synthetic', 'X-Locale': 'en' } })
  const anonymous = received.find(r => r.url === '/_ipx/cards/320/anonymous')
  for (const key of ['authorization', 'cookie', 'x-api-key', 'x-locale']) assert.equal(anonymous.headers[key], undefined)
  await get('320/anonymous', { headers: { Cookie: 'synthetic=user-b' } })
  assert.equal((await get('320/public', { headers: { 'Cache-Control': 'no-cache' } })).headers['x-image-cache'], 'BYPASS')
  await fetch(origin + '/catalog')
  await fetch(origin + '/catalog')
  assert.equal(counters.get('/catalog'), 3, 'HTML remains uncached')
  records.push({ result: 'PASS', proxyPort, upstreamPort, received, counters: Object.fromEntries(counters) })
  const shutdown = await command([...args, '-s', 'quit'])
  assert.equal(shutdown.exit, 0)
  const [exit, signal] = await close
  processHandle = undefined
  records.push({ nginxExit: exit, signal })
  assert.equal(exit, 0)
  console.log('PASS: actual Nginx config/proxy; variants, coalescing, expiry, recovery, exclusions, credentials and headers')
} catch (error) {
  records.push({ result: 'FAIL', error: error.stack })
  throw error
} finally {
  if (processHandle) await command([...args, '-s', 'quit'])
  await new Promise(r => upstream.close(r))
  await writeFile(`${root}/results.json`, JSON.stringify(records, null, 2) + '\n', { flag: 'wx' })
}
