# Contributing

Thanks for looking. This is a small project with strong opinions about how it is built, written down here so a change fits on the first try.

## Setup

Everything runs in Docker, so no local PHP or Node is needed.

```bash
make build        # build the PHP image
make install      # composer install
make web-install  # npm ci
make up           # UI on http://localhost:5180, API on http://localhost:8090
make up PROJECT=path/to/code   # project mode on that directory: http://localhost:5180/?project
```

Before sending a change:

```bash
make check        # Pint, PHPStan (level max), PHPUnit
make web-check    # vue-tsc and Vitest
```

CI runs the same checks.

## The showcase rule

[`tests/fixtures/OrderShowcase.php`](tests/fixtures/OrderShowcase.php) is the demo class the UI opens with, a snapshot fixture, and the list of constructs the analyzer understands, all in one file.

**When the analyzer learns something new, add an example of it to the showcase in the same change.** Then run `make snapshots`, read the diff (it is the visible effect of your change), and open the method in the UI. A feature that cannot be seen in the demo is not finished. A test fails if the showcase stops covering a kind of node or branch.

Resolution across files has a showcase of its own: [`tests/fixtures/project`](tests/fixtures/project), a small shop of seven files that project mode opens by default. A change to how calls are resolved between files (parents, property types, `external` targets) gets an example there and a test in `tests/Analyzer/ProjectTest.php` or `tests/Http/ProjectEndpointTest.php`.

## Principles the code follows

These are decisions with reasons, in [`docs/architecture.md`](docs/architecture.md). The short version:

- **Never guess; mark what is unknown.** A call the analyzer cannot trace becomes an `unresolved` node. A wrong edge destroys trust in the whole graph; a missing one is visible and can be improved.
- **The Code Graph is the only contract.** The UI reads the graph and knows nothing about PHP syntax. Keep it that way.
- **The analyzer marks, the UI hides.** Noise (builtins, unresolved and external calls) stays in the graph as facts; the UI decides what to show.
- **A type only when the code guarantees it.** A receiver's class comes from what the code states (declared types, `@var`, a property every assignment of which agrees). One assignment of something else and it stays unknown.
- **Deterministic output.** The same code gives the same graph on every machine, so snapshots are stable. Do not read it from the running PHP.

## Tests

Test what could go wrong, not only the happy path. For the analyzer, assert the flow as readable `from -> to [label]` lines (see `MethodFlowTest`), so a failure shows the difference at a glance. For output that comes from a library (printed code), take the expected text from the real output, not from memory.

## Commits

One line, in the form `[action] message`, with the action in lower case: `add`, `fix`, `update`, `remove`, `refactor`, `docs`, `test`.

```
[add] branch ternaries, null coalescing and short-circuit operators in method flow
[fix] keep dragged node positions on selection change
```

Keep commits small and about one thing. A refactoring and a behaviour change are two commits.

## Reporting a problem

The most useful report is the smallest PHP snippet that gives a wrong graph, with what you expected. For a call resolved wrongly between files, two or three short files are usually enough; `bin/ariadne analyze` takes a directory.

## License

By contributing you agree that your work is released under the [MIT License](LICENSE).
