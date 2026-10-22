# Security policy

## Supported versions

Pegwork is pre-1.0. Security fixes go into the latest release only.

| Version | Supported |
| --- | --- |
| 0.2.x (latest) | Yes |
| Older | No |

## Reporting a vulnerability

Please report security issues privately, never in a public issue, pull request or discussion.

1. Open the [Security tab](https://github.com/brandonmead/pegwork/security) of this repository.
2. Choose **Report a vulnerability** (GitHub private vulnerability reporting).
3. Describe the issue, the affected version, and the steps to reproduce it. A proof of concept helps.

Only the maintainer sees the report. Pegwork has one maintainer, who aims to acknowledge reports within a week. Once a fix is ready, it is released and a GitHub security advisory is published, crediting you unless you ask not to be named.

## Scope

In scope: the Pegwork plugin code in this repository, installed from a release zip. Production sites should run release builds, which exclude dev dependencies; a git checkout with dev tooling in `vendor/` is a development setup, not a supported install.

Out of scope: WordPress core, other plugins and themes, and sites' own registry packages.
