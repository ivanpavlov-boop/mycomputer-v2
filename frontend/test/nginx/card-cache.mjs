// Actual Nginx configuration/proxy checks with local synthetic images only.
import assert from 'node:assert/strict'
import { createServer, request, Agent } from 'node:http'
import { spawn } from 'node:child_process'
import { once } from 'node:events'
import { createHash } from 'node:crypto'
import { openSync, writeSync, closeSync } from 'node:fs'
import { readFile, writeFile, mkdir, copyFile, stat } from 'node:fs/promises'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'
import { startApacheProxy } from './apache-chain.mjs'

const executable = process.env.NGINX_BINARY
const root = process.env.NGINX_TEST_OUTPUT?.replaceAll('\\', '/')
assert(executable && root, 'Provide NGINX_BINARY and a new NGINX_TEST_OUTPUT; no download or implicit skip')
await mkdir(root)
for (const name of ['logs', 'temp', 'public']) await mkdir(root + '/' + name)
const journal = openSync(root + '/events.jsonl', 'wx')
const records = []
const record = data => {
  const event = { utc: new Date().toISOString(), ...data }
  records.push(event)
  writeSync(journal, JSON.stringify(event) + '\n')
}
const hash = bytes => createHash('sha256').update(bytes).digest('hex')
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))
const deadline = async (promise, ms, label) => {
  let timer
  try { return await Promise.race([promise, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(label)), ms) })]) }
  finally { clearTimeout(timer) }
}
const alpineRelease = await readFile('/etc/alpine-release', 'utf8').then(value => value.trim()).catch(error => {
  if (error.code === 'ENOENT') return null
  throw error
})
if (process.env.REQUIRE_ALPINE === '1') assert(alpineRelease && process.getuid?.() > 0, 'Alpine fixture requires actual Alpine and a non-root runtime user')
record({ runtime: { platform: process.platform, arch: process.arch, node: process.version, executable,
  uid: process.getuid?.(), gid: process.getgid?.(), alpineRelease, outputOwner: (await stat(root)).uid, sharp: sharp.versions } })
async function command(args) {
  const child = spawn(executable, args, { cwd: root, shell: false, windowsHide: true })
  let stdout = '', stderr = ''
  child.stdout.on('data', b => { stdout += b })
  child.stderr.on('data', b => { stderr += b })
  const [exit, signal] = await deadline(once(child, 'close'), 15000, 'Nginx command timeout')
  const result = { args, exit, signal, stdout, stderr }
  record(result)
  return result
}
async function listen(server) {
  server.listen(0, '127.0.0.1')
  await once(server, 'listening')
  return server.address().port
}
async function unusedPort() {
  const server = createServer()
  const port = await listen(server)
  await new Promise(resolve => server.close(resolve))
  return port
}
function gate() {
  let release, reached
  return { wait: new Promise(r => { release = r }), entered: new Promise(r => { reached = r }),
    release: () => release(), reached: () => reached() }
}
const slow = gate(), refresh = gate(), timedOut = gate()
const pending = []
const bodies = await Promise.all(['#317c4b', '#83495c'].map(background =>
  sharp({ create: { width: 40, height: 20, channels: 3, background } }).webp().toBuffer()))
const received = [], counters = new Map(), recovering = new Set()
// This URL is only an opaque path token. No fixture ever fetches it.
const encodedPath = '/_ipx/cards/320/' + encodeURIComponent('http://127.0.0.1:9/media/catalog/product/synthetic+wide.png')
const acceptedPaths = new Set(['/catalog', encodedPath, '/_ipx/cards/640/public',
  ...['public', 'other-url', 'cold', 'aged', 'slow', 'refresh', 'timeout', 'becomes-error', 'private',
    'no-store', 'no-cache', 'cookie', 'vary', 'vary-star', 'html', 'redirect', 'error', 'recovery', 'anonymous']
    .map(name => '/_ipx/cards/320/' + name)])
const sourceHeaders = new Map()
let rollback = false
const upstream = createServer(async (req, res) => {
  received.push({ url: req.url, headers: req.headers })
  const count = (counters.get(req.url) || 0) + 1
  counters.set(req.url, count)
  if (!acceptedPaths.has(req.url)) {
    record({ event: 'fixture.rejected-path', path: req.url })
    res.writeHead(404, { 'Content-Type': 'text/plain', 'Cache-Control': 'private, no-store', 'X-Accel-Expires': '0' }).end('Unknown synthetic image path')
    return
  }
  const name = req.url.split('/').pop()
  const now = Math.floor(Date.now() / 1000)
  let date = now, browser = 60, shared = 60, status = 200
  if (['expires', 'refresh', 'timeout', 'becomes-error'].includes(name)) browser = shared = 1
  if (name === 'aged') { date -= 6; browser = 3; shared = 14 }
  if (req.url === encodedPath) { date -= 6; browser = 3; shared = 14 }
  if (rollback) browser = shared = 3
  const headers = { 'Content-Type': 'image/webp', Date: new Date(date * 1000).toUTCString(),
    'Cache-Control': 'public, max-age=' + browser + ', s-maxage=' + shared + ', must-revalidate',
    'X-Accel-Expires': '@' + (date + shared), 'X-Fixture-Generation': String(count) }
  if (name === 'aged') headers.Age = '2'
  if (name === 'cold') await sleep(150)
  if (name === 'slow') { slow.reached(); await slow.wait }
  if (name === 'refresh' && count > 1) { refresh.reached(); await refresh.wait }
  if (name === 'timeout' && count > 1) { timedOut.reached(); await timedOut.wait }
  if (name === 'private') headers['Cache-Control'] = 'private, max-age=60'
  if (name === 'no-store') headers['Cache-Control'] = 'no-store'
  if (name === 'no-cache') headers['Cache-Control'] = 'no-cache'
  if (name === 'cookie') headers['Set-Cookie'] = 'synthetic=test'
  if (name === 'vary') headers.Vary = 'Accept'
  if (name === 'vary-star') headers.Vary = '*'
  if (name === 'html') headers['Content-Type'] = 'text/html'
  if (name === 'redirect') { status = 302; headers.Location = '/not-followed' }
  if (name === 'error' || (name === 'recovery' && !recovering.has(name)) || (name === 'becomes-error' && count > 1)) status = 503
  sourceHeaders.set(req.url, headers)
  if (!res.destroyed) res.writeHead(status, headers).end(bodies[count === 1 ? 0 : 1])
})
const upstreamPort = await listen(upstream)
const proxyPort = await unusedPort(), outerPort = await unusedPort()
const templatePath = fileURLToPath(new URL('../../../deploy/nginx/mycomputer.conf.template', import.meta.url))
const template = await readFile(templatePath, 'utf8')
const common = template.slice(0, template.indexOf('server {'))
const serverTemplate = template.slice(template.indexOf('server {'))
const render = (port, upstreamPort, outer = false) => serverTemplate
  .replaceAll('/var/www/html/public', root + '/public')
  .replaceAll('/var/www/html/storage/app/public/', root + '/storage/')
  .replaceAll('listen 80;', 'listen 127.0.0.1:' + port + ';')
  .replaceAll('frontend:3000', '127.0.0.1:' + upstreamPort)
  .replaceAll('app:9000', '127.0.0.1:9')
  .replace(/\$\{(?:PUBLIC_COMMERCE_ENABLED|PUBLIC_COMMERCE_CONFIRMATION_ENABLED|ABANDONED_CART_RECOVERY_ENABLED|LEGAL_CONTENT_APPROVED)\}/g, 'false')
  .replace('add_header X-Image-Cache', 'add_header ' + (outer ? 'X-Outer-Image-Cache' : 'X-Image-Cache'))
  .replace('proxy_cache_background_update off;', 'proxy_cache_background_update off;\nadd_header X-Fixture-Connection "$connection:$remote_port" always;')
  .replaceAll('access_log off;', 'access_log logs/connections.log fixture;')
const tempPaths = ['client_body', 'proxy', 'fastcgi', 'uwsgi', 'scgi'].map(name => name + '_temp_path temp/' + name + ';').join('\n')
const config = 'worker_processes 1;\npid logs/nginx.pid;\nerror_log logs/error.log notice;\n'
  + 'events { worker_connections 128; }\nhttp { access_log logs/access.log;\n'
  + 'log_format fixture \'$time_iso8601 $connection $connection_requests $remote_port $request_uri $upstream_cache_status\';\n'
  + tempPaths + '\n' + common.replaceAll('/var/cache/nginx/catalog-images', root + '/cache')
  + render(proxyPort, upstreamPort) + render(outerPort, proxyPort, true) + '\n}\n'
await writeFile(root + '/nginx.conf', config, { flag: 'wx' })
await writeFile(root + '/rollback.conf', config.replaceAll('card-shared-ttl-v1|', 'card-shared-ttl-rollback-fixture-v1|'), { flag: 'wx' })
await copyFile(process.env.NGINX_FASTCGI_PARAMS || resolve(dirname(executable), 'conf/fastcgi_params'), root + '/fastcgi_params')
const args = file => ['-p', root + '/', '-c', file, '-e', root + '/logs/error.log']
let processHandle, processClose, activeConfig
const agents = new Set()
const sockets = new WeakMap()
let socketNumber = 0
const agent = () => { const value = new Agent({ keepAlive: true, maxSockets: 1 }); agents.add(value); return value }
function get(name, { port = proxyPort, agent: connection = false, headers = {}, rawPath = false } = {}) {
  return new Promise((resolve, reject) => {
    const start = performance.now()
    let socket, localPort, socketId
    const req = request({ hostname: '127.0.0.1', port, path: rawPath ? name : '/_ipx/cards/' + name,
      agent: connection, headers, signal: AbortSignal.timeout(15000) }, res => {
      const chunks = []
      res.on('data', b => chunks.push(b))
      res.on('error', reject)
      res.on('end', () => {
        const bytes = Buffer.concat(chunks)
        const result = { name, port, status: res.statusCode, headers: res.headers, bytes: bytes.length,
          sha256: hash(bytes), socketId, localPort, reusedSocket: req.reusedSocket, elapsedMs: performance.now() - start }
        record(result)
        resolve(result)
      })
    })
    req.on('socket', value => {
      socket = value
      if (!sockets.has(value)) sockets.set(value, ++socketNumber)
      socketId = sockets.get(value)
    })
    req.on('finish', () => { localPort = socket?.localPort; record({ event: 'request.flushed', name, port, socketId, localPort }) })
    req.on('error', reject)
    req.end()
  })
}
async function start(file) {
  activeConfig = file
  assert.equal((await command([...args(file), '-t'])).exit, 0, 'Actual nginx -t')
  processHandle = spawn(executable, [...args(file), '-g', 'daemon off;'], { cwd: root, shell: false, windowsHide: true })
  processClose = once(processHandle, 'close')
  processClose.catch(error => record({ event: 'nginx.start-error', error: error.message }))
  record({ event: 'nginx.start', pid: processHandle.pid, file })
  processHandle.stdout.on('data', b => record({ processStdout: b.toString() }))
  processHandle.stderr.on('data', b => record({ processStderr: b.toString() }))
  for (let i = 0; ; i++) {
    try { await get('/catalog', { rawPath: true }); break }
    catch (error) { if (i >= 99) throw error; await sleep(50) }
  }
}
async function stop() {
  for (const connection of agents) connection.destroy()
  agents.clear()
  if (!processHandle) return
  const shutdown = await command([...args(activeConfig), '-s', 'quit'])
  assert.equal(shutdown.exit, 0, 'Graceful shutdown command')
  const [exit, signal] = await deadline(processClose, 15000, 'Nginx did not close')
  record({ event: 'nginx.close', exit, signal })
  processHandle = undefined
  assert.equal(exit, 0, 'Observed nginx terminal exit')
}
const count = name => counters.get('/_ipx/cards/' + name) || 0
async function checkEncodedPath(port) {
  const connection = agent()
  const first = await get(encodedPath, { port, agent: connection, rawPath: true })
  assert.equal(first.status, 200)
  assert.equal(first.headers['content-type'], 'image/webp')
  assert.equal(first.sha256, hash(bodies[0]))
  assert.equal(first.headers['x-image-cache'], 'MISS')
  assert.equal(counters.get(encodedPath), 1)
  assert.equal(received.at(-1).url, encodedPath, 'No decoding, double encoding or slash normalization before upstream')
  for (const [name, value] of Object.entries(sourceHeaders.get(encodedPath))) {
    assert.equal(first.headers[name.toLowerCase()], value, 'Chain preserves source header ' + name)
  }
  const hit = await get(encodedPath, { port, agent: connection, rawPath: true })
  assert.equal(hit.headers['x-image-cache'], 'HIT')
  assert.equal(hit.sha256, first.sha256)
  assert.equal(hit.socketId, first.socketId)
  assert(hit.reusedSocket)
  assert.equal(hit.headers['x-fixture-connection'], first.headers['x-fixture-connection'], 'Proxy connection reuse')
  const rejected = []
  for (const damaged of [encodedPath.replace('%2F', '/'), encodedPath.replaceAll('%', '%25'), encodedPath.replace('%2F%2F', '%2F')]) {
    const direct = await get(damaged, { port: upstreamPort, rawPath: true })
    assert.equal(direct.status, 404, 'The upstream itself must reject a damaged fixture token')
    const proxied = await get(damaged, { port, rawPath: true })
    assert([400, 404].includes(proxied.status), 'A damaged path must not silently return an image')
    assert.notEqual(proxied.sha256, first.sha256)
    rejected.push({ damaged, direct, proxied })
  }
  await sleep(2200)
  const late = await get(encodedPath, { port, agent: connection, rawPath: true })
  assert.equal(late.headers['x-image-cache'], 'HIT')
  for (const name of ['date', 'cache-control', 'x-accel-expires']) assert.equal(late.headers[name], first.headers[name])
  assert.equal(late.sha256, first.sha256)
  assert.equal(counters.get(encodedPath), 1)
  assert(Date.now() - Date.parse(late.headers.date) >= 3000, 'Browser freshness already elapsed')
  const expiry = Number(first.headers['x-accel-expires'].slice(1)) * 1000
  await sleep(Math.max(0, expiry + 1200 - Date.now()))
  const expired = await get(encodedPath, { port, rawPath: true })
  assert.equal(expired.headers['x-image-cache'], 'EXPIRED')
  assert.equal(expired.status, 200)
  assert.equal(expired.sha256, hash(bodies[1]))
  assert.equal(counters.get(encodedPath), 2, 'Late HIT must not extend absolute freshness')
  const upstreamPaths = received.filter(r => r.url === encodedPath).map(r => r.url)
  assert.deepEqual(upstreamPaths, [encodedPath, encodedPath])
  record({ case: 'encoded-path', chain: port === proxyPort ? 'Nginx' : 'Apache -> Nginx', encodedPath,
    upstreamPaths, first, hit, late, expired, rejected, assertions: 'PASS' })
  connection.destroy()
}
let primaryFailure, cleanupFailure
let apache
try {
  assert.equal((await command(['-V'])).exit, 0)
  await start('nginx.conf')
  const first = await get('320/public')
  assert.equal(first.headers['x-image-cache'], 'MISS')
  const hit = await get('320/public')
  assert.equal(hit.headers['x-image-cache'], 'HIT')
  assert.equal(hit.sha256, first.sha256)
  assert.equal(hit.headers.date, first.headers.date)
  assert.equal(count('320/public'), 1)
  assert.equal((await get('640/public')).headers['x-image-cache'], 'MISS')
  assert.equal((await get('320/other-url')).headers['x-image-cache'], 'MISS')
  assert.equal(count('640/public'), 1)
  for (const [name, value] of Object.entries({ 'x-frame-options': 'SAMEORIGIN', 'x-content-type-options': 'nosniff',
    'referrer-policy': 'strict-origin-when-cross-origin', 'permissions-policy': 'camera=(), microphone=(), geolocation=()' })) assert.equal(hit.headers[name], value)
  const concurrent = await Promise.all(Array.from({ length: 6 }, () => get('320/cold')))
  assert.equal(count('320/cold'), 1)
  assert.equal(concurrent.filter(r => r.headers['x-image-cache'] === 'MISS').length, 1)
  assert.equal(concurrent.filter(r => r.headers['x-image-cache'] === 'HIT').length, 5)

  apache = await startApacheProxy(root, proxyPort, record)
  if (apache) {
    const connection = agent()
    let first
    for (let i = 0; ; i++) {
      try { first = await get('320/public', { port: apache.port, agent: connection }); break }
      catch (error) { if (i >= 99) throw error; await sleep(50) }
    }
    const next = await get('320/other-url', { port: apache.port, agent: connection })
    assert.equal(first.status, 200)
    assert.equal(next.status, 200)
    assert.equal(first.socketId, next.socketId)
    assert.equal(first.headers['x-fixture-connection'], next.headers['x-fixture-connection'], 'Apache reuses its Nginx connection')
    assert.equal(next.headers['x-image-cache'], 'HIT')
    assert.equal(first.sha256, hit.sha256)
    assert.equal(first.headers.date, hit.headers.date)
    await checkEncodedPath(apache.port)
    record({ apache: 'PASS', first, next })
    connection.destroy()
    await apache.stop()
    apache = null
  } else {
    await checkEncodedPath(proxyPort)
  }

  // An already-aged response, then a late HIT through a SECOND real cache.
  const aged = await get('320/aged')
  const expiry = Number(aged.headers['x-accel-expires'].slice(1)) * 1000
  await sleep(2200)
  const sparse = await get('320/aged')
  assert.equal(sparse.headers['x-image-cache'], 'HIT')
  assert.equal(sparse.headers.date, aged.headers.date)
  assert.equal(sparse.sha256, aged.sha256)
  assert.equal(count('320/aged'), 1)
  const apparentAge = Math.max((Date.now() - Date.parse(sparse.headers.date)) / 1000, Number(sparse.headers.age || 0))
  assert(apparentAge >= 3 && apparentAge < 14, 'Browser expired while shared representation remains fresh')
  const outer = await get('320/aged', { port: outerPort })
  assert.equal(outer.headers['x-outer-image-cache'], 'MISS')
  assert.equal(outer.headers['x-image-cache'], 'HIT')
  assert.equal(outer.headers.date, aged.headers.date)
  assert.equal(outer.headers['x-accel-expires'], aged.headers['x-accel-expires'])
  assert.equal(outer.sha256, aged.sha256)
  assert.equal(count('320/aged'), 1)
  await sleep(Math.max(0, expiry + 1200 - Date.now()))
  const expiredOuter = await get('320/aged', { port: outerPort })
  assert.equal(expiredOuter.headers['x-outer-image-cache'], 'EXPIRED')
  assert.equal(expiredOuter.headers['x-image-cache'], 'EXPIRED')
  assert.equal(count('320/aged'), 2, 'Late downstream HIT must not restart shared freshness')
  assert.notEqual(expiredOuter.sha256, aged.sha256)
  record({ case: 'absolute-age', browserExpiredAtHit: true, aged, sparse, outer, expiredOuter })

  const connection = agent()
  const warmA = await get('320/public', { agent: connection })
  const warmB = await get('320/other-url', { agent: connection })
  assert.equal(warmA.socketId, warmB.socketId)
  assert.equal(warmA.localPort, warmB.localPort)
  assert(warmB.reusedSocket)
  assert.equal(warmB.headers['x-image-cache'], 'HIT')
  const slowRequest = get('320/slow')
  pending.push(slowRequest)
  await deadline(slow.entered, 5000, 'Slow MISS did not reach local upstream')
  const independent = await get('320/public')
  assert.equal(independent.headers['x-image-cache'], 'HIT')
  assert.equal(count('320/public'), 1)
  record({ case: 'warm-reuse-and-independent-hit', warmA, warmB, independent, slowReleased: false })
  if (process.env.NGINX_TEST_FAIL_AFTER_WARM_REUSE === '1') throw new Error('Controlled fixture failure while a local MISS is held')
  slow.release()
  await slowRequest

  await get('320/refresh')
  await get('320/becomes-error')
  await get('320/timeout')
  await sleep(2200)
  assert.equal(count('320/refresh'), 1, 'No refresh starts without a request')
  let refreshComplete = false
  const refreshing = get('320/refresh').then(result => { refreshComplete = true; return result })
  pending.push(refreshing)
  await deadline(refresh.entered, 5000, 'Expired request did not synchronously reach upstream')
  const unrelated = await get('320/public')
  assert.equal(unrelated.headers['x-image-cache'], 'HIT')
  assert.equal(refreshComplete, false, 'Expired content is not served stale')
  refresh.release()
  const refreshed = await refreshing
  assert.equal(refreshed.headers['x-image-cache'], 'EXPIRED')
  assert.equal(refreshed.sha256, hash(bodies[1]))
  assert.equal(count('320/refresh'), 2)
  assert.equal((await get('320/becomes-error')).status, 503)
  assert.equal((await get('320/becomes-error')).status, 503)
  assert.equal(count('320/becomes-error'), 3, 'Errors are not cached and old content is not served')
  const timeoutRequest = get('320/timeout')
  pending.push(timeoutRequest)
  await deadline(timedOut.entered, 5000, 'Timeout fixture did not enter')
  const timeoutResult = await timeoutRequest
  assert.equal(timeoutResult.status, 504, 'Real 10s proxy timeout must not serve stale')
  timedOut.release()

  for (const name of ['private', 'no-store', 'no-cache', 'cookie', 'vary', 'vary-star', 'html', 'redirect', 'error']) {
    await get('320/' + name)
    const again = await get('320/' + name)
    assert.notEqual(again.headers['x-image-cache'], 'HIT', name)
    assert.equal(count('320/' + name), 2, name + ': absolute expiry must not override privacy/status/MIME')
  }
  assert.equal((await get('320/recovery')).status, 503)
  recovering.add('recovery')
  assert.equal((await get('320/recovery')).status, 200)
  assert.equal((await get('320/recovery')).headers['x-image-cache'], 'HIT')
  await get('320/anonymous', { headers: { Authorization: 'Bearer synthetic', Cookie: 'synthetic=user-a', 'X-Api-Key': 'synthetic', 'X-Locale': 'en' } })
  const anonymous = received.find(r => r.url === '/_ipx/cards/320/anonymous')
  for (const key of ['authorization', 'cookie', 'x-api-key', 'x-locale']) assert.equal(anonymous.headers[key], undefined)
  assert.equal((await get('320/anonymous', { headers: { Cookie: 'synthetic=user-b' } })).headers['x-image-cache'], 'HIT')
  const bypass = await get('320/public', { headers: { 'Cache-Control': 'no-cache' } })
  assert.equal(bypass.headers['x-image-cache'], 'BYPASS')
  assert.equal(received.at(-1).headers['cache-control'], undefined)
  assert.equal((await get('320/public')).sha256, first.sha256, 'Client BYPASS must not overwrite the existing entry')
  const htmlBefore = counters.get('/catalog') || 0
  await get('/catalog', { rawPath: true })
  await get('/catalog', { rawPath: true })
  assert.equal(counters.get('/catalog'), htmlBefore + 2)

  await stop()
  await start('nginx.conf')
  const restarted = await get('320/public')
  assert.equal(restarted.headers['x-image-cache'], 'HIT', 'Same disposable disk cache is readable after restart')
  assert.equal(restarted.sha256, first.sha256)
  await stop()
  rollback = true
  await start('rollback.conf')
  const isolated = await get('320/public')
  assert.equal(isolated.headers['x-image-cache'], 'MISS', 'Fresh rollback namespace must not reuse a long-TTL object')
  assert.equal(isolated.sha256, hash(bodies[1]))
  assert.equal(isolated.headers['cache-control'], 'public, max-age=3, s-maxage=3, must-revalidate')
  record({ case: 'restart-and-rollback', restarted, isolated })
  record({ assertions: 'PASS', proxyPort, outerPort, upstreamPort, received, counters: Object.fromEntries(counters) })
} catch (error) {
  primaryFailure = error
  record({ result: 'FAIL', primaryFailure: error.stack })
} finally {
  slow.release(); refresh.release(); timedOut.release()
  try {
    await deadline(Promise.allSettled(pending), 15000, 'Pending requests did not settle')
    if (apache) await apache.stop()
    await stop()
    upstream.closeAllConnections()
    await new Promise(resolve => upstream.close(resolve))
    record({ cleanup: 'PASS' })
  } catch (error) {
    cleanupFailure = error
    record({ cleanup: 'FAIL', secondaryFailure: error.stack })
  }
  const result = primaryFailure || cleanupFailure ? 'FAIL' : 'PASS'
  record({ result, primaryFailure: primaryFailure?.message || null, secondaryFailure: cleanupFailure?.message || null })
  await writeFile(root + '/results.json', JSON.stringify(records, null, 2) + '\n', { flag: 'wx' })
  closeSync(journal)
}
if (primaryFailure || cleanupFailure) throw primaryFailure || cleanupFailure
console.log('PASS: actual Nginx proxy, two-cache absolute freshness, sparse HIT, synchronous expiry, no stale, connection reuse, boundaries, restart and namespace isolation')
