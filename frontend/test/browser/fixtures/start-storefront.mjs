import { spawn, spawnSync } from 'node:child_process'

const fixture = process.argv[2]

if (!['approved', 'draft'].includes(fixture)) {
  throw new Error('Legal fixture must be approved or draft.')
}

const buildCommand = process.platform === 'win32'
  ? [process.env.ComSpec ?? 'cmd.exe', ['/d', '/s', '/c', 'npm.cmd run build']]
  : ['npm', ['run', 'build']]
const build = spawnSync(buildCommand[0], buildCommand[1], {
  cwd: process.cwd(),
  env: {
    ...process.env,
    PLAYWRIGHT_TEST_BUILD: 'true',
    LEGAL_CONTENT_TEST_FIXTURE: fixture,
  },
  stdio: 'inherit',
})

if (build.status !== 0) {
  process.exit(build.status ?? 1)
}

const server = spawn(process.execPath, ['.output/server/index.mjs'], {
  cwd: process.cwd(),
  env: process.env,
  stdio: 'inherit',
})

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => server.kill(signal))
}

server.on('exit', code => process.exit(code ?? 1))
