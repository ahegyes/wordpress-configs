# WordPress Configs

A collection of shared configuration files for WordPress projects. Provides base configs for PHPCS, PHPMD, and PHPStan, plus Composer helpers and Docker utilities for wp-env testing.

## Requirements

- PHP 8.5+
- Composer 2.x

## Installation

```bash
composer require --dev deep-web-solutions/wordpress-configs
```

For projects not published on Packagist, add the repository first:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ahegyes/wordpress-configs.git" }
    ]
}
```

## What's Included

### Quality Assurance Configs

Base configuration files that your project extends. Create thin project-level config files that reference these.

#### PHPCS (WordPress Coding Standards)

`quality-assurance/phpcs.dist.xml` — WordPress-Extra + WordPress-Docs + PHPCompatibilityWP.

| Setting              | Value |
|----------------------|-------|
| PHP compatibility    | 8.5+  |
| WordPress minimum    | 7.0   |
| Parallel workers     | 8     |

Create a `.phpcs.xml` in your project:

```xml
<?xml version="1.0"?>
<ruleset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/squizlabs/php_codesniffer/phpcs.xsd">
    <!-- Extend the shared ruleset. -->
    <rule ref="./vendor/deep-web-solutions/wordpress-configs/quality-assurance/phpcs.dist.xml"/>

    <!-- Check that the proper text domain(s) is used everywhere. -->
    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array">
                <element value="your-text-domain"/>
            </property>
        </properties>
    </rule>

    <!-- Check that the proper prefix is used everywhere. -->
    <rule ref="WordPress.NamingConventions.PrefixAllGlobals">
        <properties>
            <property name="prefixes" type="array">
                <element value="dws_"/>
                <element value="DeepWebSolutions\YourPlugin"/>
            </property>
        </properties>
    </rule>
</ruleset>
```

Run:

```bash
vendor/bin/phpcs --standard=./.phpcs.xml --basepath=. ./ -v       # Check
vendor/bin/phpcbf --standard=./.phpcs.xml --basepath=. ./ -v      # Auto-fix
```

#### PHPMD (PHP Mess Detector)

`quality-assurance/phpmd.dist.xml` — cleancode, codesize, design, naming, unusedcode rulesets.

| Ruleset     | Notable Customizations                              |
|-------------|-----------------------------------------------------|
| cleancode   | `StaticAccess` and `ElseExpression` excluded        |
| naming      | `LongVariable` max raised to 25 characters          |
| unusedcode  | `UnusedFormalParameter` excluded (covered by PHPCS)  |

Create a `.phpmd.xml` in your project:

```xml
<?xml version="1.0"?>
<ruleset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://phpmd.org/xml/ruleset_xml_schema_1.0.0.xsd">
    <!-- Extend the shared ruleset. -->
    <rule ref="./vendor/deep-web-solutions/wordpress-configs/quality-assurance/phpmd.dist.xml"/>

    <!-- What NOT to scan. -->
    <exclude-pattern>*/tests/*</exclude-pattern>
    <exclude-pattern>*/vendor/*</exclude-pattern>
    <exclude-pattern>*/node_modules/*</exclude-pattern>
</ruleset>
```

Run:

```bash
vendor/bin/phpmd ./ ansi ./.phpmd.xml -v
```

#### PHPStan (Static Analysis)

`quality-assurance/phpstan.dist.neon` — level 8, WordPress stubs, auto-discovered paths.

| Setting                          | Value                                          |
|----------------------------------|-------------------------------------------------|
| Level                            | 8                                               |
| `treatPhpDocTypesAsCertain`      | `false`                                         |
| `inferPrivatePropertyTypeFromConstructor` | `true`                                |
| WordPress stubs                  | `php-stubs/wordpress-stubs` (auto-bootstrapped) |

Create a `.phpstan.neon` in your project:

```neon
includes:
    - vendor/deep-web-solutions/wordpress-configs/quality-assurance/phpstan.dist.neon

parameters:
    scanDirectories:
        - vendor/wp-plugin/woocommerce  # If using WooCommerce
```

Run:

```bash
vendor/bin/phpstan analyse -c ./.phpstan.neon -v --memory-limit=1G
```

The bundled `phpstan.dist.neon.php` auto-discovers paths to analyse:

| Directories                               | Root Files                                             |
|-------------------------------------------|--------------------------------------------------------|
| `src/`, `includes/`, `models/`, `blocks/`, `templates/` | `{plugin-name}.php`, `functions-bootstrap.php`, `functions.php` |

**Included PHPStan extensions** (auto-installed):

| Extension                          | Purpose                                    |
|------------------------------------|--------------------------------------------|
| `phpstan-deprecation-rules`        | Detects usage of deprecated code           |
| `phpstan-strict-rules`             | Additional strict type checks              |
| `szepeviktor/phpstan-wordpress`    | WordPress function signatures and types    |
| `johnbillion/wp-compat`            | WordPress version compatibility checks     |
| `swissspidy/phpstan-no-private`    | Flags usage of private WordPress APIs      |

### Composer Helpers

#### ScopePhpDependencies

`composer/ScopePhpDependencies.php` — Composer hooks for scoping third-party PHP dependencies via [php-scoper](https://github.com/humbug/php-scoper).

This is **opt-in**: it only triggers if `humbug/php-scoper` is installed in your project's dev dependencies. Intended for third-party libraries (PDF generators, HTTP clients, etc.) that may conflict with other plugins on the same WordPress site.

Wire it in your project's `composer.json`:

```json
{
    "scripts": {
        "pre-autoload-dump": [
            "DeepWebSolutions\\Config\\Composer\\ScopePhpDependencies::preAutoloadDump"
        ],
        "post-autoload-dump": [
            "DeepWebSolutions\\Config\\Composer\\ScopePhpDependencies::postAutoloadDump"
        ],
        "scope-php-dependencies": [
            "@php ./vendor/bin/php-scoper add-prefix --config=scoper.inc.php --force --quiet"
        ]
    }
}
```

| Hook                 | What It Does                                                        |
|----------------------|---------------------------------------------------------------------|
| `preAutoloadDump`    | Ensures scoped directories/files exist before autoloader runs       |
| `postAutoloadDump`   | Triggers the `scope-php-dependencies` script (only in dev mode)     |

### Docker Utilities

#### wp-env PDO MySQL

`docker/wp-env-install-pdo_mysql.sh` — installs the `pdo_mysql` PHP extension in wp-env Docker containers. Required for Codeception/WPBrowser database operations.

Reference it in your `.wp-env.json`:

```json
{
    "phpVersion": "8.5",
    "mappings": {
        "wp-content/plugins/your-plugin": "."
    },
    "lifecycleScripts": {
        "afterStart": "vendor/deep-web-solutions/wordpress-configs/docker/wp-env-install-pdo_mysql.sh"
    }
}
```

### Editor Config

`.editorconfig` — copy to your project root or reference in your editor's settings.

| File Type       | Indent Style | Indent Size |
|-----------------|--------------|-------------|
| `*` (default)   | Tabs         | —           |
| `*.yml`, `*.yaml`, `*.json` | Spaces | 2   |
| `*.md`          | Tabs         | — (trailing whitespace preserved) |
| `*.txt`         | Tabs         | — (CRLF line endings)             |

## Typical Composer Scripts

Add these to your project's `composer.json` for a consistent dev workflow:

```json
{
    "scripts": {
        "format:php": "phpcbf --standard=./.phpcs.xml --basepath=. ./ -v",
        "lint:php": ["@lint:php:phpcs", "@lint:php:phpmd", "@lint:php:phpstan"],
        "lint:php:phpcs": "phpcs --standard=./.phpcs.xml --basepath=. ./ -v",
        "lint:php:phpmd": "phpmd ./ ansi ./.phpmd.xml -v",
        "lint:php:phpstan": "phpstan analyse -c ./.phpstan.neon -v --memory-limit=1G"
    }
}
```

## License

MIT
