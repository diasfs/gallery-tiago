# Apps monorepo

## Purpose

Application stubs and Docker build contexts for the Gallery v4 local stack (`docker-compose.yml` at repo root).

## Ownership

- `api/` — Symfony API (PHP 8.3); shared image with `worker-convert`
- `web/` — Vue/Vite frontend (Node 22)
- `worker-faces/` — Python face embedding worker

## Local Contracts

- Each app has a stub `Dockerfile`; Task 2+ flesh out runtime, dependencies, and source trees
- Bind-mount `./apps/<name>:/app` in compose; do not commit `.env` (use `.env.example`)

## Work Guidance

- Match base images already chosen in stub Dockerfiles when expanding (PHP Apache, Node Alpine, Python slim)

## Verification

- From repo root: `docker compose config` (requires `.env` copied from `.env.example`)

## Child DOX Index

No child `AGENTS.md` files under individual apps yet.
