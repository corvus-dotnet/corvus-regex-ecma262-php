# Contributing

This guide is for someone who wants to build the extension, run its tests and gates, and open a pull request. It covers
the tools, the build step by step, the gates every change must pass, and how a pull request is released. The
[design document](design.md) describes what is underneath.

## 1. Tools

| Tool | Needed for | Version |
|---|---|---|
| PHP | Everything | 8.2 to 8.5, NTS or ZTS, with `php-config` (the development headers) |
| Rust | The extension | 1.98.0 on Linux and macOS; the nightly CI names on Windows (for PHP's `vectorcall`) |
| libclang | ext-php-rs reads PHP's headers with bindgen | Any recent Clang |
| Git | The repository and the test data | Any recent version |
| PowerShell 7 | Packaging (`package.ps1`), the API reference's generator and the release scripts | 7.4 or later |
| Node.js | The test data for other versions of Unicode and for ECMAScript 2020, and the V8 rows of the harness | 24 (the V8 it carries is the oracle) |

- **Windows:** PHP from windows.php.net with the PHP SDK's development package (which `package.ps1` downloads),
  `winget install Rustlang.Rustup LLVM.LLVM Git.Git Microsoft.PowerShell OpenJS.NodeJS.LTS`, and the Visual Studio
  Build Tools' C++ workload.
- **Linux:** PHP with its development package (`php-dev` on Ubuntu, or Ondřej Surý's PPA for other versions); `rustup`
  from [rustup.rs](https://rustup.rs); `clang` and `libclang-dev`; PowerShell from Microsoft's feed; Node.js 24.
- **macOS:** `brew install php rustup llvm git powershell node@24`, then `rustup-init`.

## 2. Getting the sources

```
git clone https://github.com/corvus-dotnet/corvus-regex-ecma262-php.git
git clone https://github.com/corvus-dotnet/ecma262-regex-test-data.git   # beside it: the test data
cd corvus-regex-ecma262-php
```

The test data is the oracle for the engine. It is not vendored; the commands below assume it at
`../ecma262-regex-test-data`.

## 3. Building and testing, step by step

Every command runs from the repository root.

1. **Formatted and linted.** `cargo +1.98.0 fmt --check` and
   `cargo +1.98.0 clippy --locked --all-targets -- -D warnings` must pass.
2. **Build.** `cargo build --release` builds the extension for the PHP on the `PATH`, as
   `target/release/libcorvus_regex_ecma262.so` (`.dylib` on macOS, `.dll` on Windows); `pwsh package.ps1` packages it as
   PIE expects.
3. **The tests.**

   ```
   php -d extension=$PWD/target/release/libcorvus_regex_ecma262.so tests/api.php
   php -d extension=$PWD/target/release/libcorvus_regex_ecma262.so -d zend.assertions=1 -d assert.exception=1 tests/getting-started.php
   ```

4. **The API reference.** After a change to the classes, functions or doc comments of `src/lib.rs`, run
   `pwsh tools/docs/gen-api.ps1`; `pwsh tools/docs/gen-api.ps1 -Check` must pass.
5. **The test data.** `php -d extension=<the extension> tools/dataset.php ../ecma262-regex-test-data/data wrong-cases.tsv`
   must report 0 wrong, 0 refused and 0 at a limit. For the other versions of Unicode and for ECMAScript 2020, write
   the data with its own scripts and run it with the extension set to match:

   ```
   node ../ecma262-regex-test-data/unicode.js apply 13.0 /tmp/u13 ../ecma262-regex-test-data/data
   php -d extension=<the extension> tools/dataset.php /tmp/u13 wrong-u13.tsv --unicode 13
   node ../ecma262-regex-test-data/edition.js apply /tmp/es2020 "" ../ecma262-regex-test-data/data
   php -d extension=<the extension> tools/dataset.php /tmp/es2020 wrong-es2020.tsv --edition es2020
   ```

## 4. The crate and the builds

The engine is the `corvus-regex-ecma262` crate from crates.io, at the version `Cargo.toml` names; a fix to the engine
is made in [the crate's repository](https://github.com/corvus-dotnet/corvus-regex-ecma262-rs), released, and taken here
by moving that version. `Cargo.toml` and `Cargo.lock` hold `0.0.0`, and a release writes its own. `php-build.yml`
builds and tests every build a release attaches: each PHP from 8.2 to 8.5, NTS and ZTS, on Linux (glibc, linked by zig
against glibc 2.17, and musl, in the official Alpine images), macOS and Windows.

## 5. Benchmarks

`tools/bench/run.sh <workloads.json> <texts directory> <out.json>` runs this extension, PHP's PCRE functions and V8
over the Industry workloads; the script lists what it needs and its settings. The published figures are on
[the benchmark page](https://corvus-oss.org/corvus-regex-ecma262-benchmarks/), which runs this repository's harness
image each night beside every other engine; a run on another machine is comparable with itself, never with the page.

## 6. What a pull request needs

- **The gates of section 3** passing on the change as pushed, on every build CI makes.
- **A fix to the engine's answers in the crate**, with its case in the test data first: the data repository's
  families are built around the faults found, with V8 as the oracle.
- **A failing test first** for a bug in the extension: the test that reproduces it, then the fix.
- **The design document kept current**, describing the extension as it is.
- **Doc comments** on every class, method and function `src/lib.rs` gives PHP, since the API reference is generated
  from them.
- **The getting-started page** kept true: its examples run in every build.

### Conventions

- Rust formatted by `rustfmt` and clean under clippy at `-D warnings`.
- A string is matched where it lies, and a string checked once is not checked again.

## 7. Releases

Every pull request merged into main releases what it changed, unless it is labelled `no_release`. `auto-release.yml`
runs `tools/release/release.ps1` on the merge: when the extension changed since its last tag (`.cargo/`,
`Cargo.toml`, `Cargo.lock`, `composer.json`, `package.ps1`, `src/`, or a published document), it makes a GitHub release
tagged with the next patch version alone (as Composer and PIE read it), and `php-publish.yml` attaches every build;
Packagist reads the new tag. A change to the benchmark harness alone is a harness release, which publishes the harness
image. A pull request that needs a minor or major version sets `nextVersion` in `.github/release.json`; a minor version
is a breaking change.

`main` is protected by a ruleset: changes arrive by pull request, and the `CI gate` and `Harness image` checks must be
green on the branch as it will merge.
