# OpenID Connect

<!-- OSPO-managed README | Generated: 2026-04-16 | v2 -->

[![License](https://img.shields.io/badge/License-GPL--2.0-blue.svg)](LICENSE) [![ownCloud OSPO](https://img.shields.io/badge/OSPO-ownCloud-blue)](https://kiteworks.com/opensource) [![Docker Hub](https://img.shields.io/docker/pulls/owncloud)](https://hub.docker.com/r/owncloud/server)

An ownCloud Server app that enables OpenID Connect (OIDC) authentication, allowing users to log in through an external identity provider. It supports multiple identity providers via database-stored configuration, automatic user provisioning, token-based session management, and flexible login button customization.

## Getting Started

Follow the steps below to install and configure the OpenID Connect app.

### Prerequisites

- ownCloud Server 10.x
- PHP 7.4+
- Distributed memcache (Redis recommended)
- An OpenID Connect provider

### Installation

```bash
occ app:enable openidconnect
```

### Configuration

Configure via `occ` command:

```bash
occ config:app:set openidconnect openid-connect \
  --value='{"provider-url":"https://idp.example.net","client-id":"your-client-id","client-secret":"your-secret","loginButtonName":"Login via OpenId Connect"}'
```

#### Access token audience

An access token is accepted only if it names this relying party. Out of the box
that means either its `aud` claim carries the configured `client-id`, or a claim
naming the client the token was issued to does: `azp` (OpenID Connect Core 1.0
§2), `appid` (Entra ID v1.0 tokens and AD FS) or `client_id` (RFC 7662). The
second half matters because an access token's `aud` belongs to the *resource
server* (RFC 9068 §3), so plenty of providers never put the client-id there:
Keycloak sends no `aud` at all unless an audience mapper is configured, Entra ID
v1.0 tokens send the App ID URI, AD FS sends
`microsoft:identityserver:<identifier>`. Accepting the client claim keeps those
working while still rejecting a token minted for a *different* client.

For anything stronger, declare what your provider actually puts in `aud` with the
optional `audience` key - which then becomes the only thing accepted:

```json
{"provider-url":"https://adfs.example.com/adfs","client-id":"your-client-id","client-secret":"your-secret","audience":"microsoft:identityserver:your-client-id"}
```

`audience` takes a single string or a list of strings, and replaces the
`client-id` as the expected value rather than adding to it. For AD FS, read the
application identifier off `Get-AdfsWebApiApplication` (an OpenID Connect
application group) or `Get-AdfsRelyingPartyTrust` (a legacy WS-Federation or SAML
trust): AD FS prefixes it with `microsoft:identityserver:` unless it is already a
URL, in which case it is sent verbatim. The value must match exactly, including
case.

Setting it makes `aud` authoritative, which cuts both ways. A token issued to this
client for some *other* resource is now rejected, because the client-naming claims
above are no longer consulted - that is the reason to set it. But a token issued to
a *different* client of the same IdP is accepted if its `aud` names us, which is
the standard resource-server model and the only thing that can work when the
client-id never appears in `aud`. So pick a value that only ownCloud's relying
party can be issued for, and do not reuse it across clients.

Leaving it unset is safe for the case it was added for - a token minted for another
client never passes - but it cannot distinguish resources. If your IdP issues
tokens to ownCloud's client for several resources (RFC 8707 resource indicators),
set `audience`. Keycloak's stock client is the one shape where there is nothing to
set: with no `aud` claim at all, no value can match, so either add a Keycloak
audience mapper for the client-id or rely on `azp`.

Two further notes:

- Do **not** set `audience` if your token introspection response omits `aud`.
  RFC 7662 allows that, and since setting it makes `aud` authoritative, every
  opaque token would then be rejected.
- With `exchange-token-mode-before-introspection`, the first entry is also what
  the token exchange asks the IdP for, so list the resource ownCloud should be
  given first.

See the
[admin manual](https://doc.owncloud.com/server/latest/admin_manual/configuration/user/oidc/index.html)
for the full parameter reference.

### Run Tests

```bash
make test-php-unit
make test-php-style
```

## Documentation

- [ownCloud OIDC Documentation](https://doc.owncloud.com/server/latest/admin_manual/)
- [OpenID Connect Specification](https://openid.net/connect/)

## Part of ownCloud Server (Classic)

This app extends [ownCloud Server 10](https://github.com/owncloud/core) with OIDC support for single sign-on. It requires a distributed memcache backend (Redis, Memcached, or APCu for development).

The ownCloud Server is available on [Docker Hub](https://hub.docker.com/r/owncloud/server).

## Community & Support

**[Star](https://github.com/owncloud/openidconnect)** this repo and **Watch** for release notifications!

- [ownCloud Website](https://owncloud.com)
- [Community Discussions](https://github.com/orgs/owncloud/discussions)
- [Matrix Chat](https://app.element.io/#/room/#owncloud:matrix.org)
- [Documentation](https://doc.owncloud.com)
- [Enterprise Support](https://owncloud.com/contact-us/)
- [OSPO Home](https://kiteworks.com/opensource)

## Contributing

We welcome contributions! Please read the [Contributing Guidelines](CONTRIBUTING.md)
and our [Code of Conduct](CODE_OF_CONDUCT.md) before getting started.

### Workflow

- **Rebase Early, Rebase Often!** We use a rebase workflow. Always rebase on the target branch before submitting a PR.
- **Dependabot**: Automated dependency updates are managed via Dependabot. Review and merge dependency PRs promptly.
- **Signed Commits**: All commits **must** be PGP/GPG signed. See [GitHub's signing guide](https://docs.github.com/en/authentication/managing-commit-signature-verification).
- **DCO Sign-off**: Every commit must carry a `Signed-off-by` line:
  ```
  git commit -s -S -m "your commit message"
  ```
- **GitHub Actions Policy**: Workflows may only use actions that are (a) owned by `owncloud`, (b) created by GitHub (`actions/*`), or (c) verified in the GitHub Marketplace.

## Translations

Help translate this project on Transifex:
**<https://explore.transifex.com/owncloud-org/owncloud/>**

Please submit translations via Transifex -- do not open pull requests for translation changes.

## Security

**Do not open a public GitHub issue for security vulnerabilities.**

Report vulnerabilities at **<https://security.owncloud.com>** -- see [SECURITY.md](SECURITY.md).

Bug bounty: [YesWeHack ownCloud Program](https://yeswehack.com/programs/owncloud-bug-bounty-program)

## License

This project is licensed under the [GPL-2.0](LICENSE).

## About the ownCloud OSPO

The [Kiteworks Open Source Program Office](https://kiteworks.com/opensource), operating under
the [ownCloud](https://owncloud.com) brand, launched on May 5, 2026, to steward the open source
ecosystem around ownCloud's products. The OSPO ensures transparent governance, license compliance,
community health, and sustainable collaboration between the open source community and
[Kiteworks](https://www.kiteworks.com), which acquired ownCloud in 2023.

- **OSPO Home**: <https://kiteworks.com/opensource>
- **GitHub**: <https://github.com/owncloud>
- **ownCloud**: <https://owncloud.com>

For questions about the OSPO or licensing, contact ospo@kiteworks.com.

### License Migration to Apache 2.0

The OSPO is driving a strategic relicensing of ownCloud repositories toward the
[Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0), following
the [Apache Software Foundation's third-party license policy](https://www.apache.org/legal/resolved.html).

Individual repositories will migrate as their audit is completed. The LICENSE file
in each repo reflects its **current** license status (not the target).

**Current license: GPL-2.0** (Category X per Apache policy -- cannot be included in Apache-2.0 works).

Migration prerequisites for this repository:

- **CLA/DCO coverage**: All past contributors must have signed agreements permitting relicensing
- **Copyleft dependency audit**: All GPL dependencies must be replaced or isolated
- **KDE heritage review**: Any code with KDE-era copyrights requires legal analysis
- **Complete relicensing**: GPL-2.0 is a strong copyleft license; migration requires full relicensing of all files
