# Invoice Ninja frontend builds

This backend is paired with `../invoiceninja-dk-flutter` and `../invoiceninja-dk-ui`.
After frontend changes, completion includes compiling Flutter macOS, Flutter web,
and React web, and copying both web builds into this backend. Analysis/type checks
alone do not count as compilation. Follow a narrower scope if the user explicitly
requests it.

## Build and copy

Inspect all three worktrees first and preserve unrelated changes. Use Flutter
from the version pinned in `../invoiceninja-dk-flutter/.flutter-version`; see that
repo's `BUILD_MACOS.md` for prerequisites and local ad-hoc signing limitations.

From `../invoiceninja-dk-ui`:

```sh
npm run build
rsync -a --exclude index.html dist/ ../invoiceninja/public/
cp dist/index.html ../invoiceninja/resources/views/react/index.blade.php
```

React bundles belong in `public/react/`; its shared static files belong at their
paths under `public/`. Updating the Blade template is required because it names
the hashed bundles. Do not copy React's index to `public/index.html`.

From `../invoiceninja-dk-flutter`, run these sequentially:

```sh
bash ./build_macos.sh
bash ./build_web.sh ../invoiceninja
```

The macOS script regenerates model serializers and produces
`build/macos/Build/Products/Release/Invoice Ninja.app` (universal, ad-hoc signed).
The web script builds regular and FOSS release JavaScript with source maps,
copies regular web output into backend `public/` excluding `index.html`, and
copies the FOSS entrypoint as `public/main.foss.dart.js` with its map. It
temporarily swaps FOSS source/dependency files and restores them on exit; verify
restoration. Do not run another Flutter build/test/codegen concurrently with it.

React and Flutter share root web assets. Copy React first, then run the Flutter
web build/copy. Preserve Laravel's `public/index.php`, `.htaccess`, storage, and
existing server assets. Never use `rsync --delete` against backend `public/`.
Back up overwritten generated files when practical; do not remove old hashed
bundles as unrelated cleanup.

## Verification and handoff

- Run relevant regression tests as well as compilation. Report tests, builds,
  and interactive runtime checks separately; never imply unperformed UI tests.
  For yearly exchange-rate changes, run `npm test` in the React repo,
  `flutter test test_yearly_exchange_rates.dart` in the Flutter repo (before
  `build_web.sh`), and PHP 8.3 `vendor/bin/phpunit
  tests/Unit/YearlyExchangeRatesTest.php` in the backend. The backend test uses an
  isolated in-memory schema; it is not evidence of a live MySQL migration.
- Require successful exit status from all three builds. Check the macOS binary
  with `lipo -info` and `codesign --verify --deep --strict`.
- Verify copied web files by checksum and ensure every local script/stylesheet
  named by the React Blade entrypoint exists in `public/`.
- Confirm the FOSS source swaps were restored and inspect all worktree diffs.
- Report the macOS app path, web destinations, remaining warnings/failures, and
  any required backend migration. Do not apply a database migration merely to
  compile or copy frontends.
