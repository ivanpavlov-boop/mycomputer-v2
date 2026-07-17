# Docker Service Restart Recovery

## Purpose

Define the Docker Compose restart policy required for the permanent MyComputer
services to recover after a Docker daemon restart, VPS reboot, or unexpected
container exit. This is infrastructure recovery guidance only; it does not
change application or Catalog Sync behavior.

## Incident Summary

On 2026-07-17, the Docker daemon was stopped and started while the VPS itself
remained online. The permanent containers had an effective `restart=no` policy,
so MySQL, Redis, Meilisearch, the queue worker, and the scheduler remained
stopped. Redis-backed admin sessions and cache then caused the Laravel admin to
return HTTP 500 responses. The Nuxt shell was still reachable, but backend API
requests failed until the infrastructure services were started manually.

This was an infrastructure recovery failure. It was not caused by application
code or the Phase 9C supplier review work.

## Permanent Service List

The production Compose definition declares `restart: unless-stopped` for:

- `app`
- `nginx`
- `frontend`
- `queue`
- `scheduler`
- `mysql`
- `redis`
- `meilisearch`

## Why `unless-stopped`

`unless-stopped` restarts a previously running container after a Docker daemon
restart, VPS reboot, or unexpected process exit. It intentionally does not
restart a container that an administrator explicitly stopped.

`always` would also restart intentionally stopped containers, which is not
appropriate for controlled maintenance. `on-failure` does not cover the daemon
and reboot recovery requirement. `restart=no` leaves stopped containers down
until an administrator starts them manually.

## Existing Healthchecks And Dependencies

The existing startup architecture is preserved:

- `app` and `queue` wait for healthy MySQL, Redis, and Meilisearch.
- `scheduler` waits for healthy MySQL and Redis.
- `nginx` waits for healthy `app` and started `frontend`.
- The MySQL, Redis, Meilisearch, and app healthchecks are unchanged.

Restart policy does not replace healthchecks or dependency conditions. It only
defines the recovery behavior for permanent services once Docker is available.

## Applying The Policy After Merge

Changing a Compose restart policy requires recreation of the affected
containers. The following sequence is for a future VPS operation only, after
the PR is merged, CI passes, and a human approves the maintenance work. It must
not be run from a feature branch or by Codex in this phase.

```bash
cd /var/www/mycomputer-v2

git fetch origin
git reset --hard origin/main

docker compose config --quiet

docker compose up -d --force-recreate mysql redis meilisearch
sleep 30

docker compose up -d --force-recreate app queue scheduler frontend
sleep 20

docker compose up -d --force-recreate nginx
sleep 15

docker compose ps
```

## Verify Effective Restart Policies

After the future approved application, verify the recreated containers:

```bash
docker inspect \
  mycomputer-v2-app-1 \
  mycomputer-v2-nginx-1 \
  mycomputer-v2-frontend-1 \
  mycomputer-v2-queue-1 \
  mycomputer-v2-scheduler-1 \
  mycomputer-v2-mysql-1 \
  mycomputer-v2-redis-1 \
  mycomputer-v2-meilisearch-1 \
  --format '{{.Name}} restart={{.HostConfig.RestartPolicy.Name}} status={{.State.Status}}'
```

Each permanent container should report `restart=unless-stopped`.

## Controlled Docker Daemon Restart Test

A daemon restart test is future operational work and is not part of this
change. It requires all of the following before it may be approved:

- the PR is merged into `main` and CI is successful;
- the VPS is synced to `origin/main`;
- current backup status is checked;
- all containers are healthy;
- a human gives explicit approval during a low-traffic maintenance window;
- an active SSH session is retained; and
- public-site and admin health checks are ready.

Only after those conditions are met may an approved operator run:

```bash
systemctl restart docker
sleep 45

cd /var/www/mycomputer-v2
docker compose ps -a

curl -I http://localhost:8080
curl -I https://computer2u.eu
curl -I https://computer2u.eu/admin
```

Expected result: every permanent service returns automatically; MySQL, Redis,
Meilisearch, and app become healthy; queue, scheduler, frontend, and nginx
remain running; the public site returns HTTP 200; and admin returns HTTP 200 or
302 rather than HTTP 500. Manual `docker compose up` should not be required.

Codex must not run `systemctl restart docker`.

## Rollback

If a future approved recreation causes a problem, restore the previously
reviewed `docker-compose.yml` from the last known-good `origin/main` commit,
validate it with `docker compose config --quiet`, and recreate only the
affected services under an approved maintenance procedure. Do not run `docker
compose down`, delete volumes, or perform data recovery as part of this policy
rollback.

## Safety Boundaries

- This local change does not start, stop, restart, or recreate containers.
- It does not access the VPS, Docker daemon, production data, or incident logs.
- It does not alter services, images, commands, environments, ports, volumes,
  healthchecks, dependencies, networks, queues, or scheduler configuration.
- It adds no migrations, application behavior changes, or secret values.

## No Catalog Sync Impact

Catalog Sync is untouched. The effective flags remain CREATE enabled, UPDATE
disabled, Sync All disabled, and automatic sync disabled. This change does not
run supplier imports, alter supplier schedules, execute lifecycle preview, or
modify products, supplier products, offers, categories, attributes, or images.

## Status

- Implementation: local/in review
- Production application: not performed
- Docker daemon recovery test: not performed
- Catalog Sync impact: none
