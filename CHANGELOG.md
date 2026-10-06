# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/). Until 1.0 the Code Graph format may still change between minor versions.

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
