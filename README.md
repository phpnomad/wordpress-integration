# phpnomad/wordpress-integration

[![Latest Version](https://img.shields.io/packagist/v/phpnomad/wordpress-integration.svg)](https://packagist.org/packages/phpnomad/wordpress-integration)
[![Total Downloads](https://img.shields.io/packagist/dt/phpnomad/wordpress-integration.svg)](https://packagist.org/packages/phpnomad/wordpress-integration)
[![PHP Version](https://img.shields.io/packagist/php-v/phpnomad/wordpress-integration.svg)](https://packagist.org/packages/phpnomad/wordpress-integration)
[![License](https://img.shields.io/packagist/l/phpnomad/wordpress-integration.svg)](https://packagist.org/packages/phpnomad/wordpress-integration)

Integrates WordPress with PHPNomad's strategy interfaces. This package maps core PHPNomad abstractions (events, cache, database, REST, tasks, auth, email, HTTP, console, templates, translation) onto WordPress core APIs so application code written against PHPNomad interfaces runs inside a plugin or theme without touching WordPress functions directly.

## Installation

```bash
composer require phpnomad/wordpress-integration
```

Composer will pull the PHPNomad packages this integration depends on as transitive installs, along with Action Scheduler for background task dispatch.

## What This Provides

- `WordPressInitializer` wires every strategy below into the DI container, gates loading on WordPress 6.3.0 or higher, and binds WordPress actions like `wp_login`, `wp_logout`, `user_register`, `deleted_user`, `set_current_user`, `parse_request`, and `wp` to PHPNomad's auth, datastore, and framework events.
- Event bridging through `EventStrategy` (broadcasts as `do_action('phpnomad/' . $eventId)` and attaches through `add_action`) and `ActionBindingStrategy` for mapping arbitrary WordPress actions onto PHPNomad event classes.
- Cache backed by the WordPress object cache: `ObjectCacheStrategy` over `wp_cache_*`, `CachePolicy`, and `WordPressObjectCacheIdempotencyStore` for task deduplication.
- Database through `wpdb`: `QueryStrategy`, `QueryBuilder`, `ClauseBuilder`, and `TableCreateStrategy`/`TableUpdateStrategy`/`TableDeleteStrategy`/`TableExistsStrategy` for schema operations, plus `DatabaseProvider` supplying prefix, charset, collation, and default TTL.
- Auth and users: `User` wraps `WP_User`, `CurrentUserResolverStrategy` returns the current user, `HashStrategy` and `PasswordResetStrategy` handle credentials, `LoginUrlProvider` exposes the login URL, and `ActionToCapabilityAdapter` maps PHPNomad actions onto WordPress capabilities.
- REST through the WordPress REST API: `RestStrategy` dispatches PHPNomad controllers with middleware, validation, and interceptors, backed by `Request` and `Response` wrappers around `WP_REST_Request` and `WP_REST_Response`.
- Tasks through Action Scheduler: `WordPressTaskStrategy` dispatches PHPNomad tasks and `WordPressTaskHandlerRegistry` registers handlers against Action Scheduler's hooks.
- Transport: `FetchStrategy` runs HTTP requests through `wp_remote_request`, and `EmailStrategy` sends through `wp_mail`.
- Console through WP-CLI: `ConsoleStrategy` registers PHPNomad commands and routes I/O through `Input` and `ConsoleOutputStrategy`.
- Templates, assets, translation, mutation, and privacy: `PhpEngine` renders PHP templates, `AssetStrategy` resolves plugin URLs, `TranslationStrategy` delegates to WordPress gettext, `MutationStrategy` wraps `apply_filters`, and `TrackingPermissionStrategy` supplies consent information.

## Requirements

- WordPress 6.3.0 or higher
- The PHPNomad packages this integration adapts (`phpnomad/auth`, `phpnomad/cache`, `phpnomad/db`, `phpnomad/datastore`, `phpnomad/event`, `phpnomad/rest`, `phpnomad/http`, `phpnomad/fetch`, `phpnomad/tasks`, `phpnomad/console`, `phpnomad/asset`, `phpnomad/email`, `phpnomad/template`, `phpnomad/translate`, `phpnomad/privacy`, `phpnomad/framework`, `phpnomad/loader`), all installed automatically by Composer
- `woocommerce/action-scheduler` for task dispatch, also installed automatically

## Usage

Compose `WordPressInitializer` into a `Bootstrapper` alongside `CoreInitializer` and your own application initializer. A minimal plugin bootstrap looks like this:

```php
<?php

use MyApp\Application\AppInitializer;
use PHPNomad\Core\Bootstrap\CoreInitializer;
use PHPNomad\Di\Container\Container;
use PHPNomad\Integrations\WordPress\Strategies\WordPressInitializer;
use PHPNomad\Loader\Bootstrapper;

add_action('plugins_loaded', function () {
    $container = new Container();

    (new Bootstrapper(
        $container,
        new CoreInitializer(),
        new WordPressInitializer(),
        new AppInitializer()
    ))->load();
});
```

`WordPressInitializer` maps every PHPNomad strategy interface onto its WordPress implementation inside the container, so services in your own initializer can depend on `CacheStrategy`, `EventStrategy`, `QueryStrategy`, `RestStrategy`, and the rest without knowing they resolve to WordPress-backed classes.

## Documentation

See [phpnomad.com](https://phpnomad.com) for the PHPNomad docs, including the bootstrapping guide and the interfaces this package implements. For the WordPress APIs it adapts, see the [WordPress Developer Resources](https://developer.wordpress.org/).

## License

MIT. See [LICENSE.txt](LICENSE.txt).
