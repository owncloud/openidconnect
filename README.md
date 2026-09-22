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
§2), `appid` (Entra ID v1.0 tokens and AD FS) or `client_id` (RFC 7662) - whichever
of those three the token carries *first*, in that order of precedence. The second
half matters because an access token's `aud` belongs to the *resource server*
(RFC 9068 §3), so plenty of providers never put the client-id there: Keycloak sends
no `aud` at all unless an audience mapper is configured, Entra ID v1.0 tokens send
the App ID URI, AD FS sends `microsoft:identityserver:<identifier>`. Accepting the
client claim keeps those working.

Precedence is what makes it safe: only the most authoritative claim present is
consulted, so a token whose `azp` truthfully names another client is refused even if
it also carries a `client_id` naming us - a shape a tenant can produce on a shared
realm with a hardcoded-claim mapper, where every claim in the token is honest.

Regardless of audience, a token that labels its own type has to label itself an
access token: `typ` of `Bearer` (Keycloak) or `at+jwt` (RFC 9068 §2.1), `token_use`
of `access` (AWS Cognito). Anything else is refused. That is an allowlist rather than
a list of refresh markers on purpose - Keycloak's refresh, offline, back-channel
logout, registration and ID tokens are all realm-signed and carry an `aud` equal to
the `client-id`, so an enumeration would have to keep up with each of them, and a
refresh token in particular is long-lived and kept at rest, so it must never double
as a bearer credential.

A provider that puts no type claim in the payload is unaffected, which includes Entra
ID and AD FS. There, an ID token still satisfies the default expectation, because an
ID token's `aud` *is* the `client-id` - so it is accepted for as long as the
`client-id` is an accepted audience, and setting `audience` to anything else rejects
it as a side effect. Where that is not an option, treat ID tokens as credentials.

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

Setting it makes `aud` authoritative, which buys one thing and not another.

It buys a binding to the *resource*: a token this client obtained for some other
resource of the same IdP - through an RFC 8707 `resource` parameter, or RFC 8693
token exchange - no longer authenticates here, because the client-naming claims are
no longer consulted. Set it if your IdP issues tokens to ownCloud's client for more
than one resource.

It does not keep another *client's* tokens out. A token whose `aud` names us is
accepted whichever client requested it, with or without this key, and a client of the
same IdP can often be granted exactly that - an audience mapper of its own, or a
`resource` parameter naming us. That is the resource-server model, and the only place
to control it is the IdP. So pick a value that only ownCloud's relying party can be
issued for, and do not reuse it across clients.

Leaving it unset still refuses what the check was added for: a token an attacker
obtained for their own client, addressed at their own client, does not pass. And
Keycloak's stock client is one shape where there is nothing to set at all - with no
`aud` claim, no value can match, so either add a Keycloak audience mapper for the
client-id or rely on `azp`.

Two further notes:

- Do **not** set `audience` if your token introspection response omits `aud`.
  RFC 7662 allows that, and since setting it makes `aud` authoritative, every
  opaque token would then be rejected.
- With `exchange-token-mode-before-introspection`, the first entry that survives the
  usable-string filter is also what the token exchange asks the IdP for - so an
  unquoted number ahead of the real value is dropped and the resource behind it is
  requested instead. List the resource ownCloud should be given first, and keep the
  list free of values that cannot be an audience.

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
