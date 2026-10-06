# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/). Until 1.0 the Code Graph format may still change between minor versions.

## [Unreleased]

### Added

- **Several files, one project.** `bin/ariadne analyze` takes files and directories (recursively, skipping `vendor`, `node_modules` and `.git`) and resolves calls across them. A file that does not parse is reported and left out.
- **Inheritance.** A method is looked up in the class, then its parents: `$this->m()` and `static::m()` reach an inherited method, and `parent::m()` resolves.
- **Typed receivers.** Calls on typed and promoted properties, `@var` docblocks, legacy properties assigned a typed constructor parameter or a `new` object, typed parameters never reassigned, `(new Foo())->m()`, and chains of such properties.
- **`external` nodes** for calls into classes outside the analyzed files (libraries, the framework), shown and hidden on the class map together with unresolved calls.
- **Project mode** (`make up PROJECT=path`, then `/?project`): the API reads a mounted directory, serves its map, flows and sources on demand, and caches the analysis until a file changes. The UI focuses the map on one class with its callers and callees, and the editor follows the selection across files. A small demo project is the default.
- **Return types and local variables.** Chains follow declared return types and `@return` docblocks (`$this->repo->find($id)->markPaid()`, `Order::query()->first()`), and a local variable has a class when every value it is given agrees: a typed parameter, `new Foo()`, a caught exception, another variable or a call with a known return type. Script code has variables too.
- **Step into a call.** Each call step of a flow has a `target` edge to what it calls. In the UI a step that reaches a method of the analyzed code is marked; double-click it, or press **Open call** (also during a replay), to open that method's flow, and go back the same way.

### Fixed

- Selecting a method far outside the view now brings it into view; the position of a method inside its class was taken as a position on the canvas.
- Hiding unresolved calls on a large map no longer leaves the view pointing at empty space.
- The demo project is reached through the mounted repository: a checkout that recreated its directory left the API with an empty mount.

### Changed

- The UI and API listen on `127.0.0.1` only.
- A call into a class that is not among the analyzed files is now `external` instead of `unresolved`, also when one file is analyzed alone.

## [0.1.0] - 2026-10-06

First release: a static analyzer that turns PHP into a Code Graph, a web UI to explore it, and a step-by-step replay of a method.

### Added

- **Analyzer.** PHP source is parsed with `nikic/php-parser` into a language-agnostic Code Graph (JSON): classes, methods, functions, the script of a file, and the calls between them. Calls that cannot be traced to a declaration are kept as `unresolved` nodes instead of being guessed.
- **Method flow.** Every method, function and script has a control-flow subgraph: calls in execution order, `if`/`elseif`/`else`, `switch` with fall-through, `match`, loops with `break`/`continue` at any depth, `try`/`catch`/`finally`, `return`, `throw`, `exit`/`die`, `include`/`require`.
- **Branches inside expressions:** ternaries, `??`, `??=`, `&&`/`||` when the right side calls or throws, and `throw` expressions.
- **Callbacks.** Closures and arrow functions passed to a call show their calls after it, at any depth, on a `callback` edge.
- **Builtin steps.** Calls to common pure PHP functions (`count`, `trim`, `array_merge`...) are `builtin` steps, from a fixed list, so the same code gives the same graph everywhere.
- **Command line:** `bin/ariadne analyze File.php` prints the graph as JSON.
- **HTTP API:** `POST /api/analyze` parses submitted code without executing or storing it, with a 1 MB limit.
- **Web UI** (Vue 3, Vue Flow, ELK, Monaco): the class map, with classes as resizable containers, drag, zoom, a remembered layout, and a grid for classes with many methods; the method flow view; click a block to see its code, move the cursor in the code to see its block; shareable links to a method.
- **Execution replay:** step through a method with the arrow keys, choose a branch where the code forks, jump back through the log. Static: no code runs.
- **Hiding noise.** Unresolved calls on the class map and builtin steps in a flow are hidden by default behind toggles that show how many they hide.
- **Tooling:** PHPUnit, PHPStan at the maximum level, Pint, Vitest and `vue-tsc`; Docker for everything; CI on Forgejo and GitHub; a showcase fixture that is the demo, a snapshot test and a coverage check at once.

### Known limitations

- One file at a time: calls into other files stay `unresolved`.
- `finally` is reached on normal completion only, not after an early `return` or `break` inside `try`.
- Calls in loop headers and in `case` values are not steps.
- Exceptions thrown by called methods are unknown, so a `try` links to each of its `catch` blocks.
- The layout of a method flow can route a loop-back edge under a block.

[0.1.0]: https://github.com/b40y22/ariadne-code/releases/tag/v0.1.0
