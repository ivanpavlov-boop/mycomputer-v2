const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');

const root = path.resolve(__dirname, '../..');
const guard = path.join(root, 'scripts/verify-backend-images.sh');
const ids = ['a', 'b', 'c'].map(letter => `sha256:${letter.repeat(64)}`);

// Command-boundary tests only. The Docker regression separately exercises real
// Linux permissions and Composer; this fake must not stand in for that result.
function run(t, scenario = 'pass', args = [], bootstrap = false) {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'backend guard-'));
    t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
    const journal = path.join(dir, 'calls.jsonl');
    fs.writeFileSync(path.join(dir, 'docker'), `#!/usr/bin/env node
const fs = require('node:fs');
const args = process.argv.slice(2);
const scenario = process.env.GUARD_SCENARIO;
const ids = ${JSON.stringify(ids)};
const journal = process.env.GUARD_JOURNAL;
const prior = fs.existsSync(journal) ? fs.readFileSync(journal, 'utf8') : '';
fs.appendFileSync(journal, JSON.stringify(args) + '\\n');
if (args[0] === 'compose' && ['version', 'build'].includes(args[1])) {
    process.exit(0);
} else if (args[0] === 'compose' && args[1] === 'config') {
    const config = { name: 'custom-project', services: {
        app: { build: '.', depends_on: { mysql: {} } },
        queue: { image: 'custom-queue:v2' },
        scheduler: { build: '.' },
        mysql: { image: 'mysql:8.4' }
    }};
    if (scenario === 'missing-service') delete config.services.queue;
    console.log(JSON.stringify(config));
} else if (args[0] === 'image' && args[1] === 'inspect') {
    const ref = args.at(-1);
    const index = ['custom-project-app', 'custom-queue:v2', 'custom-project-scheduler'].indexOf(ref);
    if (index === -1) process.exit(17);
    console.log(scenario === 'invalid-id' ? 'not-an-image-id' :
        scenario === 'retag' && prior.includes('"run"') ? ids[2] : ids[index]);
} else if (args[0] === 'run') {
    fs.readFileSync(0);
    if (scenario === 'probe-failure') { console.error('permission fixture failure'); process.exit(23); }
} else { console.error('Unexpected Docker command'); process.exit(99); }
`, { mode: 0o755 });
    let entry = guard;
    if (bootstrap) {
        for (const file of ['deploy/staging/bootstrap.sh', 'scripts/verify-backend-images.sh',
            'scripts/backend-image-probe.php']) {
            const destination = path.join(dir, file);
            fs.mkdirSync(path.dirname(destination), { recursive: true });
            fs.copyFileSync(path.join(root, file), destination);
        }
        fs.writeFileSync(path.join(dir, '.env'), 'APP_KEY=base64:isolated-test-fixture\n');
        entry = path.join(dir, 'deploy/staging/bootstrap.sh');
    }
    const result = spawnSync('bash', [entry, ...args], {
        cwd: dir,
        env: { ...process.env, PATH: `${dir}${path.delimiter}${process.env.PATH}`,
            GUARD_SCENARIO: scenario, GUARD_JOURNAL: journal },
        encoding: 'utf8',
        timeout: 10000,
    });
    assert.ifError(result.error);
    const calls = fs.existsSync(journal)
        ? fs.readFileSync(journal, 'utf8').trim().split('\n').map(JSON.parse) : [];
    return { ...result, calls };
}

test('uses configured build tags, then exact IDs as a restricted non-root process', t => {
    const result = run(t);
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /BACKEND_IMAGES_READABLE_OK/);
    const runs = result.calls.filter(args => args[0] === 'run');
    assert.equal(runs.length, 3);
    runs.forEach((args, index) => {
        assert.ok(args.includes(ids[index]));
        for (const [flag, value] of [['--user', 'www-data'], ['--network', 'none'],
            ['--pull', 'never'], ['--cap-drop', 'ALL'],
            ['--security-opt', 'no-new-privileges'], ['--entrypoint', 'sh']]) {
            assert.equal(args[args.indexOf(flag) + 1], value);
        }
        assert.ok(args.includes('--read-only'));
        assert.ok(!args.some(arg => ['--env', '--env-file', '-e', '-v', '--volume', '--mount'].includes(arg)));
    });
    assert.ok(!result.calls.some(args => ['up', 'exec', 'images', 'build'].includes(args[1])));
});

test('explicit image mode does not consult Compose or running services', t => {
    const result = run(t, 'pass', ['--image', 'custom-queue:v2']);
    assert.equal(result.status, 0, result.stderr);
    assert.ok(!result.calls.some(args => args[0] === 'compose'));
    assert.equal(result.calls.filter(args => args[0] === 'run').length, 1);
});

test('probe failure preserves its exit and stops before the next backend', t => {
    const result = run(t, 'probe-failure');
    assert.equal(result.status, 23);
    assert.equal(result.calls.filter(args => args[0] === 'run').length, 1);
    assert.doesNotMatch(result.stdout, /BACKEND_IMAGES_READABLE_OK/);
});

test('staging bootstrap stops after the gate failure without starting services', t => {
    const result = run(t, 'probe-failure', [], true);
    assert.equal(result.status, 23, result.stderr);
    assert.ok(result.calls.some(args => args[0] === 'compose' && args[1] === 'build'));
    assert.ok(!result.calls.some(args => args[0] === 'compose' && ['up', 'exec'].includes(args[1])));
    assert.doesNotMatch(result.stdout, /Staging bootstrap complete/);
});

for (const scenario of ['missing-service', 'invalid-id']) {
    test(`${scenario} stops before any probe`, t => {
        const result = run(t, scenario);
        assert.notEqual(result.status, 0);
        assert.equal(result.calls.filter(args => args[0] === 'run').length, 0);
    });
}

test('retagging during validation cannot yield a successful receipt', t => {
    const result = run(t, 'retag');
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Image changed during validation/);
    assert.doesNotMatch(result.stdout, /BACKEND_IMAGES_READABLE_OK/);
});

test('invalid arguments stop without Docker operations', t => {
    const result = run(t, 'pass', ['--image', '-invalid']);
    assert.notEqual(result.status, 0);
    assert.equal(result.calls.length, 0);
});
