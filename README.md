# chromedriver (Composer distribution)

A Composer distribution of
[Chromedriver](https://github.com/googlechromelabs/chrome-for-testing) by
Google. Adds a `chromedriver` executable to your Composer `bin` directory —
`vendor/bin/chromedriver`.

**No binaries are bundled.** Instead, the package ships a tiny bootstrap shim
and an update script: the chromedriver for **your host platform** is
automatically downloaded at run time (hash-verified against Google's published
SHA-256 checksums) and installed for:

| Platform    | Binary installed          |
| ----------- | ------------------------- |
| linux64     | `bin/chromedriver`        |
| linux-arm64 | `bin/chromedriver`        |
| mac-x64     | `bin/chromedriver`        |
| mac-arm64   | `bin/chromedriver`        |
| win32/win64 | `bin/chromedriver.exe`    |

## Installation

```sh
composer require enm1989/chromedriver
```

That is all. By default the exact binary matching your OS and architecture is
installed on first use.

> **Note:** Composer only runs scripts defined in the *root* package of an
> install, so the package's `post-install-cmd` hook does not run for you. The
> first call to `vendor/bin/chromedriver` self-installs the binary instead.

## Usage

Call the driver like any other `vendor/bin` tool:

```sh
vendor/bin/chromedriver --version
```

> **Windows:** point Selenium/Webdriver directly at the `.exe`
> (`vendor/enm1989/chromedriver/bin/chromedriver.exe`) and run
> `vendor/bin/update-chromedriver` once after installing. The
> `vendor/bin/chromedriver` proxy additionally requires `sh`/bash (e.g. Git
> Bash) to bootstrap there.


```sh
vendor/bin/chromedriver --version
```

In Selenium/Webdriver scripts, point your driver service at that path, e.g.
with [php-webdriver](https://github.com/php-webdriver/php-webdriver):

```php
$service = ChromeDriverService::createDefaultService(__DIR__ . '/vendor/bin/chromedriver');
$driver  = ChromeDriver::start($service, $capabilities);
```

On the very first run the bootstrap shim downloads and installs the current
stable chromedriver for your platform (a few seconds on a decent connection;
later runs are plain pass-throughs).

## Updating

Chromedriver follows Chrome releases roughly every four weeks. This package
deliberately steps **exactly one stable version per update run**, so you can
follow releases gradually instead of jumping straight to the newest build:

```sh
vendor/bin/update-chromedriver
# Installed chromedriver: 148.0.7611.1
# Next stable version    : 149.0.7612.1 (one-version-only step)
```

Each download is recorded in a manifest (`bin/chromedriver-manifest.json`
with version, platform, URL and SHA-256 hashes). A later mismatch of the
recorded hash aborts the update, so corrupted or tampered downloads are never
installed. The install is verified for your host's platform on every run —
moving `vendor/` between machines (e.g. a different OS) automatically installs
the right build the next time.

## Pinned versions

Pin a specific chromedriver version (milestone or full version) — for
example to match a particular Chrome build:

```sh
vendor/bin/update-chromedriver --version=115
vendor/bin/update-chromedriver --version=115.0.5790.170
```

Install a build for a *different* machine than the one running the script
(e.g. shipping a linux64 build from an arm64 machine):

```sh
vendor/bin/update-chromedriver --version=115 --platform=linux64
```

Recognised platforms are the Chrome-for-Testing identifiers:
`linux64`, `linux-arm64`, `mac-x64`, `mac-arm64`, `win32`, `win64`.

## Supported platforms

`linux64` (x86-64), `linux-arm64` (aarch64), `mac-x64`, `mac-arm64`,
`win32`, `win64` — whichever matches the OS and architecture of the machine
that invokes the update command (PHP `PHP_OS` + `php_uname('m')`).

## How it works

* `bin/chromedriver` is a committed **bootstrap shim** (this is the composer
  bin). It finds the real binary and, if it is missing (e.g. directly after
  `composer install`), runs the automatic update script to install it first.
  On Windows the real binary is `chromedriver.exe` next to it, so the shim
  keeps proxying; on Linux/macOS the binary replaces the shim itself and later
  invocations run it directly.
* The package ships no binary of its own — everything is downloaded at run time
  for the host platform and hash-verified before installation.
* `scripts/update-chromedriver` (exposed as `vendor/bin/update-chromedriver`)
  resolves the right download from Google's Chrome-for-Testing metadata, so
  versions are picked up automatically as Chrome releases them. It is always
  re-runnable and safe to use concurrently with your tests (existing installs
  are only replaced after a successful download).

## License

This Composer package is released under the [Apache-2.0 license](LICENSE.chromedriver),
as is the Google chromedriver binary (see `LICENSE.chromedriver`).
