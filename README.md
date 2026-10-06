# Ariadne Code

Static analyzer that turns PHP source into a language-agnostic **Code Graph**: the thread through the labyrinth of legacy code.

![Ariadne Code: class map next to the source code](docs/screenshot.png)

> **Status:** early development. The analyzer extracts classes, methods and method calls. An interactive visualization will be built on top of the graph later.

```
PHP source → AST (nikic/php-parser) → Analyzer → Code Graph (JSON) → visualization
```

Principle: **static analysis first, visualization second, AI third.** The graph only contains what the code actually says. Whatever cannot be resolved statically is marked as such instead of being guessed.

## Quick start

Everything runs in Docker, so no local PHP is required.

```bash
make build
make install
make demo
```

### Web UI

```bash
make up    # UI on http://localhost:5180, API on http://localhost:8090
```

The page shows the class map next to the source code. Click a node to jump to its code; move the cursor in the editor to highlight the matching node. Use **Open .php** to analyze your own file.

Classes are containers that hold their methods and can be resized; drag any block and the layout is remembered per file.

**Method flow:** double-click a method (or select it and press **Show flow**) to see how it runs: its calls in execution order, `if` branches (`true`/`false`), loops, `try`/`catch`, `return` and `throw`. A flow can be shared by link, e.g. `http://localhost:5180/#method=method:App\\OrderService::createOrder`.

![Method flow of createOrder](docs/screenshot-flow.png)

**Execution replay:** below a method flow, the *Execution replay* panel walks through the method step by step. **→** takes the next step (or pick a branch when the code forks), **←** goes back, and a click on a log entry jumps to that step. The path walked so far lights up in the graph, and the current step is highlighted in the source code. It is static: no code runs, you choose the branches.

![Execution replay](docs/screenshot-replay.png)

The API is a single endpoint, `POST /api/analyze` with `{"code": "...", "file": "A.php"}`, answering with the Code Graph JSON. Submitted code is only parsed, never executed or stored, and requests are limited to 1 MB.

`make demo` analyzes [`tests/fixtures/OrderService.php`](tests/fixtures/OrderService.php) and prints its graph. To analyze your own file:

```bash
docker compose run --rm php php bin/ariadne analyze path/to/YourClass.php
```

## Code Graph format

```json
{
  "nodes": [
    { "id": "method:App\\OrderService::createOrder", "type": "method", "name": "createOrder", "file": "OrderService.php", "lineStart": 11, "lineEnd": 18 },
    { "id": "unresolved:$this->orders->save", "type": "unresolved", "name": "$this->orders->save", "file": null, "lineStart": null, "lineEnd": null }
  ],
  "edges": [
    { "from": "method:App\\OrderService::createOrder", "to": "unresolved:$this->orders->save", "type": "calls", "line": 17 }
  ]
}
```

| Node type    | Meaning                                                              |
|--------------|----------------------------------------------------------------------|
| `class`      | A named class                                                        |
| `method`     | A method declared in a class                                         |
| `unresolved` | A call target that static analysis cannot map to a known declaration |
| flow nodes   | `start`, `end`, `call`, `condition`, `loop`, `try`, `catch`, `finally`, `return`, `throw`: the control flow of one method, linked to it through `parent` |

| Edge type  | Meaning                                                                  |
|------------|--------------------------------------------------------------------------|
| `contains` | A class declares a method                                                |
| `calls`    | A method calls another; `line` is the call site                          |
| `flow`     | Execution order inside a method; `label` names the branch taken         |

### Method flow

Every method with a body also gets its control flow: calls in execution order, joined by `flow` edges. Branch edges carry a `label`: `true`/`false` (conditions), `body`/`next`/`exit`/`continue` (loops), `exception`/`throw` (try/catch).

Analyzing [`ShippingService`](tests/fixtures/ShippingService.php) gives, for `ship()`, among others:

```
start -> condition $items === []
condition $items === [] -> return return false            [true]
condition $items === [] -> loop foreach ($items as $item)  [false]
loop foreach ($items as $item) -> call $this->inStock      [body]
call $this->inStock -> condition !$this->inStock($item)
condition !$this->inStock($item) -> loop foreach ...       [true]
condition !$this->inStock($item) -> try                    [false]
...
```

Deliberate simplifications (the graph never claims more than it knows):

- `switch`/`match` are not branched: their calls appear in source order as plain steps.
- Calls in loop headers (`while ($this->next())`) are not steps, because they run on every iteration.
- `do ... while` is drawn like `while`, with the condition node before the body.
- Exceptions raised by called methods are unknown, so a `try` links to each of its `catch` blocks.
- `finally` is reached on normal completion only (not after `return`/`break` inside `try`).
- Closures and arrow functions add no steps to the enclosing flow.
- Code after an unconditional `return`/`throw`/`break`/`continue` is unreachable and left out.

What currently resolves: `$this->method()`, `self::method()` and `static::method()` within the same class, case-insensitively. Everything else (calls on other objects, inherited methods, `parent::`, dynamic names such as `$this->$name()`) becomes an `unresolved` node, so the graph never asserts something the code does not prove.

## Development

```bash
make check   # Pint (PER) + PHPStan level max + PHPUnit
make test
make stan
make fix     # auto-format
```

Requires PHP 8.5. The graph for [`OrderService.php`](tests/fixtures/OrderService.php) is pinned by a snapshot test ([`OrderService.graph.json`](tests/fixtures/OrderService.graph.json)).

## Roadmap

- [x] Classes, methods and method calls to Code Graph JSON
- [x] Control flow inside methods: `if`/`else`, loops, `try`/`catch`, `throw`
- [ ] `switch`/`match` branches, `finally` after early exits
- [ ] Interfaces, traits, enums, inheritance and dependency edges
- [ ] Type-aware call resolution (typed properties, constructor promotion, PHPDoc)
- [ ] Multiple files and project-level graph
- [x] Web UI: class map with drag, zoom, auto-layout, resizable class containers, saved layout, linked to the source code
- [x] Web UI: method flow view
- [x] Execution replay: step through a method, choosing branches (static, no runtime tracing)
- [ ] Web UI: expand/collapse of dependencies
- [ ] AI explanations grounded in the graph

## License

[MIT](LICENSE)

## Authors

Designed and maintained by Yevgen. Developed with [Claude Code](https://claude.com/claude-code).
