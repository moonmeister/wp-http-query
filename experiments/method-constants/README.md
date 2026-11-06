# Method constants: single-method assumptions (#73)

**Question:** how much plugin code treats `WP_REST_Server::READABLE`, `CREATABLE` or `DELETABLE` as
**exactly one method**, and would break if one of them ever held a list? This is the evidence
behind ADR 0006 (#75) and the method-list functions ticket (#74). Those constants can't change, and
this sweep says how many plugins and installs that is.

Run 2026-11-06 against [veloria.dev](https://veloria.dev/), same approach as the
[method-branching sweep](../plugin-spike/README.md#method-branching-sweep). The result JSON is
**not committed**. The regexes are in [`sweep.py`](sweep.py) and the search IDs are below, so every
number can be re-derived:

```sh
/usr/bin/python3 -I sweep.py /tmp/veloria-73     # runs or reuses the searches
/usr/bin/python3 -I analyze.py /tmp/veloria-73   # the tables below
/usr/bin/python3 -I analyze.py /tmp/veloria-73 --lines eq_after   # every matched line
```

PHP files only, de-vendored (`/vendor/`, `/node_modules/`, `/build/` dropped).

## Result

**60 plugins / 20.8M installs would break if a single-method constant gained a second method.**
That includes WooCommerce, Elementor and Jetpack.

| Set | Lines | Plugins | Installs |
|---|---|---|---|
| **Breaks if the value changes** (union of the next two) | 215 | 60 | 20,759,880 |
| compared with the request's method (`get_method()`) | 40 | 25 | 17,340,430 |
| passed where one method is required (`'method' =>`, `new WP_REST_Request()`) | 175 | 35 | 3,419,450 |
| compared with a variable (mostly the constant passed in: survives) | 518 | 166 | 19,851,480 |
| of which copies of core's schema-args pattern | 282 | 137 | 1,232,140 |
| `in_array` on the request method (all also list `'GET'`: survives) | 4 | 1 | 10,000 |
| `switch`/`case` on a constant (largest: Elementor, on the registered constant: survives) | 32 | 4 | 10,091,020 |
| already treats a constant as a list (control) | 13 | 11 | 10,178,510 |
| `EDITABLE`/`ALLMETHODS` compared as one method (already broken today: control) | 35 | 12 | 115,040 |

Denominator: 4,362 plugins / 110.6M installs use at least one of the three constants.

### What breaks, and how

- **Compared with the request's method.** If `READABLE` became `'GET, QUERY'`, a `GET` request
  would stop matching it.
  - WooCommerce (7M): `src/StoreApi/Routes/V1/Products.php:79`
    `if ( \WP_REST_Server::READABLE === $request->get_method() )`, and `Checkout.php:186` for
    `CREATABLE`.
  - Elementor (10M): `core/common/modules/event-tracker/data/controller.php:40`
    `if ( WP_REST_Server::CREATABLE !== $request->get_method() )`.
- **Passed where one method is required.** The constant becomes the method of an outbound HTTP
  request or an internal `WP_REST_Request`, and `'GET, QUERY'` is not a method.
  - Jetpack (3M): `_inc/blogging-prompts.php:277` passes `'method' => \WP_REST_Server::READABLE`
    to `wpcom_json_api_request_as_user()`.
  - Post SMTP (300K): `PostmanEmailTester.php:59` passes it to a remote request in the same way.
  - Both were read in source, not just matched.

### What survives, and why

A comparison where both sides are the constant keeps working when the value changes, because both
sides change together. Most "compared with a variable" hits are like this:
- **Schema builders called with the constant itself.** Core does this too:
  `rest_get_endpoint_args_for_schema( $schema, $method = WP_REST_Server::CREATABLE )` compares
  `WP_REST_Server::CREATABLE === $method`. 137 plugins carry copies of that function.
- **Elementor's `switch` on the registered `$methods`** (`data/base/endpoint.php:155`), not on the
  request.

## Caveats

- **A lower bound for what breaks.** Matching is per line, so a variable that holds the request
  method (`$method = $request->get_method();` a few lines up) lands in "compared with a variable".
  Known examples: Elementor's `$method_type` and Advanced Access Manager's `$http_method`. Only
  reading the source resolves these.
- **Already broken today.** `EDITABLE` compared with the request's method, e.g. BuddyPress
  `class-bp-rest-members-endpoint.php:808` `$append = WP_REST_Server::EDITABLE ===
  $request->get_method();`, is always false. It's a control, not part of the total, because a constant change can't cause it. It shows authors
  treat the constants as single methods regardless of what they hold.
- **The `READABLE` denominator differs from the earlier sweep** (3,775 plugins here, 2,428 in the
  method-branching sweep's search #7). That search's term had a leading space
  (`' WP_REST_Server::READABLE'`), which drops matches like `=>WP_REST_Server::READABLE` and
  `\WP_REST_Server::READABLE`. The index has also grown since 2026-09-15.

## Searches

| Key | Search ID | Lines | Plugins | Installs |
|---|---|---|---|---|
| `eq_after` | `747b6d75-6c2c-4e5b-9eca-ea8684563142` | 516 | 172 | 19,862,920 |
| `eq_before` | `2fdfd329-3778-42b3-a657-18958295d977` | 43 | 17 | 7,232,990 |
| `in_array` | `b3350dc7-eb3c-406d-999e-28af7c5f6cda` | 23 | 7 | 830,100 |
| `case` | `c28b5b5a-4663-400d-a5df-26c6af1d6acc` | 32 | 4 | 10,091,020 |
| `one_method` | `218ad13d-72c3-493a-a4a1-ffaa06e5cedf` | 175 | 35 | 3,419,450 |
| `key` | `9f919c6c-30f7-487c-b40a-1acc92289293` | 73 | 25 | 907,870 |
| `multi_eq` | `ab19266c-bfd2-4c0a-ace9-cfe5e75fd60c` | 35 | 12 | 115,040 |
| `as_list` | `8f75aeb9-26d6-4dfe-9360-ebb59e6cbb46` | 13 | 11 | 10,178,510 |
| `d_readable` | `bb1ce9f4-cfe8-4202-8ead-d5cef1151eee` | 25,966 | 3,775 | 94,009,960 |
| `d_creatable` | `4a61733f-f01b-410d-a928-5fe0d6e29876` | 21,983 | 3,382 | 94,589,950 |
| `d_deletable` | `864691da-38e7-4e53-a9fb-ef8cb17a3ab6` | 4,462 | 1,480 | 66,506,070 |

The `key` search (array keys and `match` arms) turned out to be mostly array-form registrations
(`'methods' => [ CREATABLE ]`), which are safe. Its few real hits are counted nowhere above.
