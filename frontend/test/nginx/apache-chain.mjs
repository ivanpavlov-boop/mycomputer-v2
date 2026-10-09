// Optional real Apache -> Nginx chain. No installation or system service control.
import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { createServer } from 'node:http'
import { once } from 'node:events'
import { mkdir, access, writeFile } from 'node:fs/promises'

export async function startApacheProxy(root, targetPort, record) {
  const binary = process.env.APACHE_BINARY
  if (!binary) {
    assert(process.env.REQUIRE_APACHE !== '1', 'REQUIRE_APACHE=1 requires APACHE_BINARY; no implicit CI skip')
    record({ apache: 'NOT RUN', reason: 'APACHE_BINARY not supplied; a Nginx PASS is not an Apache PASS' })
    return null
  }
  const modules = process.env.APACHE_MODULE_DIR
  assert(process.platform !== 'win32' && modules, 'Supply an installed Linux/Alpine Apache and APACHE_MODULE_DIR')
  assert(process.getuid() !== 0, 'Run this fixture as an ordinary user, not root')
  for (const value of [root, binary, modules]) assert(!/["\r\n]/.test(value), 'Unsafe fixture path')
  for (const name of ['mod_mpm_event.so', 'mod_authz_core.so', 'mod_proxy.so', 'mod_proxy_http.so']) await access(modules + '/' + name)
  await mkdir(root + '/apache-runtime')
  const reservation = createServer()
  reservation.listen(0, '127.0.0.1')
  await once(reservation, 'listening')
  const port = reservation.address().port
  await new Promise(resolve => reservation.close(resolve))
  const file = root + '/apache.conf'
  const config = [
    'ServerRoot "' + root + '"', 'DefaultRuntimeDir "' + root + '/apache-runtime"',
    'PidFile "' + root + '/apache.pid"', 'ErrorLog "' + root + '/logs/apache-error.log"',
    'ServerName 127.0.0.1', 'Listen 127.0.0.1:' + port,
    'User #' + process.getuid(), 'Group #' + process.getgid(),
    ...[['mpm_event', 'mpm_event'], ['authz_core', 'authz_core'], ['proxy', 'proxy'], ['proxy_http', 'proxy_http']]
      .map(([name, file]) => 'LoadModule ' + name + '_module "' + modules + '/mod_' + file + '.so"'),
    'KeepAlive On', 'KeepAliveTimeout 5', 'MaxKeepAliveRequests 100',
    'AllowEncodedSlashes NoDecode',
    'ProxyRequests Off', '<Proxy "*">', 'Require all granted', '</Proxy>',
    'ProxyPass "/_ipx/cards/" "http://127.0.0.1:' + targetPort + '/_ipx/cards/" nocanon connectiontimeout=5 timeout=15 disablereuse=Off',
    'ProxyPassReverse "/_ipx/cards/" "http://127.0.0.1:' + targetPort + '/_ipx/cards/"',
  ].join('\n') + '\n'
  await writeFile(file, config, { flag: 'wx' })
  for (const args of [['-v'], ['-t', '-f', file]]) {
    const result = spawnSync(binary, args, { shell: false, cwd: root, encoding: 'utf8', timeout: 15000 })
    record({ apacheCommand: { binary, args, exit: result.status, signal: result.signal,
      error: result.error?.message, stdout: result.stdout, stderr: result.stderr } })
    assert.equal(result.status, 0, 'Real Apache version/config check')
  }
  const args = ['-X', '-f', file]
  const child = spawn(binary, args, { shell: false, cwd: root })
  record({ event: 'apache.start', binary, args, pid: child.pid, port })
  child.stdout.on('data', b => record({ apacheStdout: b.toString() }))
  child.stderr.on('data', b => record({ apacheStderr: b.toString() }))
  const close = once(child, 'close')
  close.catch(error => record({ event: 'apache.start-error', error: error.message }))
  return {
    port,
    async stop() {
      if (child.exitCode === null && child.signalCode === null) child.kill('SIGTERM')
      let timer
      try {
        const [exit, signal] = await Promise.race([close, new Promise((_, reject) => {
          timer = setTimeout(() => reject(new Error('Apache fixture did not close after SIGTERM')), 15000)
        })])
        record({ event: 'apache.close', exit, signal })
        assert(exit === 0 || signal === 'SIGTERM', 'Apache fixture terminal result')
      } finally { clearTimeout(timer) }
    },
  }
}
