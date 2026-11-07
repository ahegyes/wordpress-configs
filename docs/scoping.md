# Dependency scoping

This document is the contract of the scoping pipeline that `php/php-scoper/scoper-base.inc.php` and `php/composer/ScopePhpDependencies.php` run: the packages a project names, what a scope run prefixes, leaves global and rejects, and how a project declares host stubs, text domains, its own php-scoper settings and per-package recipes. [The README](../README.md#dependency-scoping) shows how a project installs it.

## Packages to scope

A name is a package name or an `fnmatch()` pattern, such as `'your-vendor/your-library-*'`. The runtime dependencies of each named package follow from Composer's `installed.json`, through `require` and never `require-dev`, so naming a library scopes what it needs; a requirement that another package or the project itself replaces or provides, as Composer records in `installed.php`, counts as met. A pattern matches every installed package, development tools included, so keep it as narrow as the packages it means. A name that starts with `!` keeps the packages it matches unscoped, such as a polyfill whose global functions must stay reachable (`'!symfony/polyfill-php80'`), and the symbols they declare stay global too. Keep the scoped packages in `require-dev`, so a production install never loads their unprefixed copies, and require an unscoped package in `require`, so a production install still ships it.

## A scope run

A scope run:

- scopes every file of each scoped package into `vendor-prefixed.tmp/` and, once php-scoper succeeds, moves it to `vendor-prefixed/<vendor>/<package>/` in place of the previous build, refusing a `vendor-prefixed/` that is a symbolic link, so a run php-scoper fails keeps the previous build;
- leaves global the WordPress symbols of `sniccowp/php-scoper-wordpress-excludes`, Action Scheduler (`as_*` and `ActionScheduler*`), every symbol of the stubs files the project or a scoped package declares under `extra.scoping-stubs`, and their namespaces;
- prefixes the strings that start with a scoped package's own namespace, which libraries use to build class names, unless they name a namespace the run leaves global or a symbol in one;
- applies the recipe of each scoped package that has one in `php/php-scoper/recipes/<vendor>/<package>.inc.php`;
- rewrites each string literal of the text domain a scoped package declares under `extra.scoping-text-domain` to the project's `extra.text-domain`, unless the two are the same;
- fails, naming each one, when the scoped code references a prefixed name it does not declare as the kind of symbol the reference needs: a host symbol no stubs file declares, a dependency outside the scoped packages, a string php-scoper took for a class name, or the class of a `Class::MEMBER` string;
- fails, naming its namespace, when the scoped code declares a class, interface, trait or enum outside the prefix, which a copy the host or another plugin loads can replace, other than the polyfill of a PHP class;
- passes a `function_exists()` check of the prefixed name of a function it leaves global, PHP's own included, which php-scoper adds to every guard of that function and which is false while nothing declares the name;
- writes `vendor-prefixed/scoper-autoload.php`, a class map over each scoped package's `psr-4`, `psr-0` and `classmap` paths that honors `exclude-from-classmap`, followed by its `files` entries.

The run also fails when `extra.scoping-prefix` is missing or empty, when a name matches no installed package, when a scoped package requires one that is not installed, when a declared stubs file is missing, when a scoped file, a declared stubs file or a dropped package's file does not parse, when a scoped package declares a text domain and the project declares none, when a declared text domain survives outside a string literal of its own, and when a recipe's target is gone.

A package that reaches a dependency only through a metapackage includes its `files` entries before that dependency's, because the autoloader orders them by each package's direct `require`.

The run supports none of php-scoper's `expose-*` options, since each plugin keeps its own prefixed copy of what it scopes: the namespace-string patcher prefixes the global name in the alias php-scoper writes for an exposed class, so the class stays unexposed.

## Host stubs

The project or a scoped package declares the stubs files of the host symbols the scoped code calls, each as `./path/to/file.php` relative to its `composer.json`, as `vendor/package` for that package's `<package>.php`, or as `vendor/package:path/to/file.php`:

```json
{
	"extra": {
		"scoping-stubs": [
			"php-stubs/woocommerce-stubs",
			"php-stubs/woocommerce-stubs:woocommerce-packages-stubs.php",
			"./stubs/host-plugin.php"
		]
	}
}
```

## Text domains

A package whose strings take the host plugin's text domain declares the domain it uses, and the project declares its own:

```json
{
	"extra": {
		"scoping-text-domain": "your-library"
	}
}
```

## Project settings and recipes

`scoper.inc.php` returns an ordinary php-scoper config, so a project can add exclusions or patchers before returning it. Write namespace exclusions as anchored regular expressions, such as `'/^YourVendor\\\\Name(?:\\\\|$)/i'`, because php-scoper matches a plain namespace string anywhere in a name.

A recipe returns php-scoper settings for one package, with paths relative to it: `exclude-files`, anchored `exclude-namespaces`, and `replacements`, a map of search to replace strings per file of the scoped code, in which `{prefix}` stands for the scoping prefix. A replacement whose search string is missing fails the run, so a library update that invalidates a recipe surfaces when scoping.
