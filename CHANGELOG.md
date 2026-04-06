# Changelog

All notable changes to this project are documented in this file.

## [1.0.2] - 2026-04-06
- Moved from nginx (I had config issues) to FrankenPHP (classic not worker), not a huge performance increase from this alone
- Optimised the image, previously had opcache at 64mb for a 3mb (rounded up) codebase.

## [1.0.1] - 2026-02-20
- Bumped version and rebuilt Docker image due to GitHub Actions issues.
- No code changes; image published as `ghcr.io/10ij/dockyard:v1.0.1` (also available as `:latest`).

## [1.0.0] - 2026-02-20
- Initial public release.
- Core functionality complete: view container logs, start and stop containers via the web UI.
- Most game-breaking bugs addressed; some convenience features and integrations remain missing or partially integrated.
- Released intentionally in this state because the app fulfills its original goal (lightweight, stupid-simple control and log viewing) before feature creep expanded the scope.