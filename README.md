# wp-http-query

[![Decision tests](https://github.com/moonmeister/wp-http-query/actions/workflows/test.yml/badge.svg?branch=main)](https://github.com/moonmeister/wp-http-query/actions/workflows/test.yml)
[![Latest release](https://img.shields.io/github/v/release/moonmeister/wp-http-query?include_prereleases&sort=semver)](https://github.com/moonmeister/wp-http-query/releases)

A WordPress plugin that adds the HTTP `QUERY` method
([RFC 10008](https://www.rfc-editor.org/rfc/rfc10008.html)) to the REST API. It works on stock
WordPress 7.2 and later, with no core patch. `QUERY` is a read request that carries a body, for
searches and filters too large or too structured for a URL. The plugin is for site owners who want
their REST API to accept it, and for plugin developers who want their routes to.

## I want to…

| I want to… | Go to |
|---|---|
| Use the plugin on my site | [plugin/README.md](plugin/README.md) |
| Add `QUERY` to my own plugin's routes | [docs/integrating.md](docs/integrating.md) |
| Try it locally | [Try it locally](#try-it-locally) |
| Contribute or run the tests | [CONTRIBUTING.md](CONTRIBUTING.md) |
| See what's planned | The [project board](https://github.com/users/moonmeister/projects/1) and [milestones](https://github.com/moonmeister/wp-http-query/milestones) |
| Understand the decisions | [docs/decisions/](docs/decisions/) and [docs/background.md](docs/background.md) |

## Try it locally

Needs Docker running and Node 24.18+.

```sh
make demo
```

This starts WordPress at <http://localhost:8873> with the plugin active. Log in as `admin` /
`password`. The plugin's own routes and the **Test your server** button work straight away;
WordPress's own routes (posts, pages, search…) accept `QUERY` once you tick **Core collections**
on **Settings → HTTP QUERY**. [CONTRIBUTING.md](CONTRIBUTING.md#try-it-by-hand) has a walkthrough and the other
commands.

## Status

[v0.1.0](https://github.com/moonmeister/wp-http-query/releases/tag/v0.1.0) prerelease ·
WordPress 7.2+ · PHP 8.0+ · next release: the
[v0.2.0 milestone](https://github.com/moonmeister/wp-http-query/milestone/5) · route to core:
[#60](https://github.com/moonmeister/wp-http-query/issues/60), likely a direct core patch on
[Trac#65616](https://core.trac.wordpress.org/ticket/65616) rather than a feature plugin.

## License

[GPL-2.0-or-later](LICENSE).
