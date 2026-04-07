## Out of date read-me

todo:
 - Update readme
 - Remove or use `settings.php` for something
    - no idea yet
 - Remove user deletion logic fully (rely on OIDC)
    - actually no, ima keep this
    - because someone may login right
    - and you wanna reset their perms
    - or maybe theyve been deleted oidc side
      - can we add this to cron, check if theyve been deleted oidc side?
 - Finish and migrate to `users/action.php`

notes to add in:
 - codebase is under 3mb
 - FrankenPHP
 - 96mb RAM usage
    - compare to other platforms
 - OIDC Only, need to setup instructions to use it w github, google, discord
    - maybe authelia, authentik, pocket-id (which i use too)
    - time consuming though
 - oh yeah per user perms actually work now too...
    - portainer makes u pay for this
 - download and run portainer, dockge, yacht, and whatever else there is
    - this is time consuming ah
    - this is also gonna kill my homeserver
 - this isnt for orchestration
    - purely stop/start/logs/rbac
    - literally started for minecraft and grew
 - note ai usage
    - minimal, just for ui and double checking
    - also for minor refactors
    - most of copilots agentic code has been written over
      - really messed up a lot of the app never gonna use agentic coding again
    - also used for readme, changelog (vetted), and improving comments
 - config via `config.php`
   - theres an example one
   - you can either have admins defined by OIDC or by local
 - we store users by their oidc sub_id
 - setup instructions need to be altered
   - download the example.yml
   - download the setup.sh
      - make it executable
   - execute setup.sh
   - follow oidc instructions at https://ismailj.eu.org/dockyard/start


# Dockyard

A dead-simple, super-lightweight PHP + SQLite web UI for managing Docker containers. It does one thing well: let you view container logs and start/stop containers with per-user permissions and minimal ops overhead.

Image: `ghcr.io/10ij/dockyard:v1.0.1` (also available as `:latest`)  
Stack: PHP 8, SQLite3, HTMX, Pico CSS

## Table of contents
- [Quick start (Docker)](#quick-start-docker)
- [Docker Compose example](#docker-compose-example)
- [Features](#features)
- [Usage](#usage)
- [Security & deployment notes](#security--deployment-notes)
- [Development](#development)
- [Contributing](#contributing)
- [License](#license)
- [Changelog](#changelog)

---

## Quick start (Docker)

Run on the Docker host (requires access to the Docker socket):

```bash
docker run -d \
  --name dockyard \
  -p 8080:80 \
  -v /var/run/docker.sock:/var/run/docker.sock:ro \
  -v dockyard-data:/app/data \
  ghcr.io/10ij/dockyard:v1.0.1
```

Open: http://localhost:8080

Notes:
- The app inspects and controls containers via the Docker socket.
- Persist data by mounting `/app/data` (above uses a named volume `dockyard-data`).

## Docker Compose example

```yaml
version: "3.8"
services:
  dockyard:
    image: ghcr.io/10ij/dockyard:v1.0.1
    ports:
      - "8080:80"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - dockyard-data:/app/data
volumes:
  dockyard-data:
```

## Features
- Live container status (HTMX polling).
- Tail and refresh container logs.
- Start / stop containers from the UI.
- Per-user permissions and basic user management.
- Tiny single-repo app: PHP + SQLite, no external DB required.

## Usage
- Launch the container (see Quick start).
- Open the web UI.
- Default user combo is admin/pass
- Create users and assign container permissions via the Users pages.
- Click a container to view logs, status, and use Start / Stop controls.

## Development
- Very small codebase; HTMX endpoints handle partial updates (logs, status, actions).
- Data stored in SQLite under `/app/data` inside the container.
- Recommended for self-hosting and quick local setups; not a replacement for full-featured orchestration UIs.

## License
See LICENSE file

## Changelog
See CHANGELOG.md
