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

Calls the analyzer cannot trace to a declaration (builtins, calls on other objects) are hidden on the map by default, because there are often more of them than real nodes; the **Unresolved (N)** button shows them. A class with more than 14 methods is laid out as a grid, four across, instead of a column that would have to be shrunk until nothing can be read. Classes are containers that hold their methods and can be resized; drag any block and the layout is remembered per file.

**Method flow:** double-click a method (or select it and press **Show flow**) to see how it runs: its calls in execution order, `if` branches (`true`/`false`), loops, `try`/`catch`, `return` and `throw`. A flow can be shared by link, e.g. `http://localhost:5180/#method=method:App\\OrderService::createOrder`.

![Method flow of createOrder](docs/screenshot-flow.png)

`switch` and `match` branch per case; a `case` without `break` falls through to the next one:

![Method flow with switch and match](docs/screenshot-flow-switch.png)

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
| `function`   | A function declared outside any class                                |
| `script`     | The code of a file that sits outside every class and function: what runs when the file is executed |
| `unresolved` | A call target that static analysis cannot map to a known declaration |
| flow nodes   | `start`, `end`, `call`, `builtin`, `condition`, `loop`, `try`, `catch`, `finally`, `return`, `throw`: the control flow of one method, linked to it through `parent` |

| Edge type  | Meaning                                                                  |
|------------|--------------------------------------------------------------------------|
| `contains` | A class declares a method                                                |
| `calls`    | A method calls another; `line` is the call site                          |
| `flow`     | Execution order inside a method; `label` names the branch taken         |

### Method flow

Every method with a body also gets its control flow: calls in execution order, joined by `flow` edges. Branch edges carry a `label`: `true`/`false` (conditions), `body`/`next`/`exit`/`continue` (loops), `exception`/`throw` (try/catch), `set`/`null` (`??`, `??=`), `callback`, and the case value for `switch` and `match`.

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

- Expressions branch where PHP does: `?:` and ternaries (`true`/`false`), `??` and `??=` (`set`/`null`), and `&&`/`||` when their right side calls or throws. A `throw` inside an expression (`$x ?? throw new E()`) leaves the method, so a call after it runs only on the surviving path.
- `switch` branches per `case` (labelled with the case value, or `default`; `no match` when there is no default) and falls through like PHP until a `break`; `match` branches per arm. Calls inside a `case` value itself are not steps.
- Calls in loop headers (`while ($this->next())`) are not steps, because they run on every iteration.
- `do ... while` is drawn like `while`, with the condition node before the body.
- Exceptions raised by called methods are unknown, so a `try` links to each of its `catch` blocks.
- `finally` is reached on normal completion only (not after `return`/`break` inside `try`).
- A closure or arrow function passed straight to a method or static call (`DB::transaction(fn () => ...)`) is a callback: its calls follow that call as plain steps, entered by a dotted `callback` edge. The analyzer cannot know whether the callee runs it, so the edge says "callback", not "runs". `return` and `throw` inside it never leave the method. Closures anywhere else add no steps. Callbacks nest to any depth; inside one, branching constructs are plain steps and a `throw` stays inside it.
- A `return` or `throw` whose expression is itself a call is a bare keyword: the call is already the step just before it, and repeating its text would show the same line twice. `throw new E()` and `return $x` keep their expression, so the exception type stays visible.
- `exit`/`die` end the flow like `return`. `include`/`require` are a step, but the included file is not followed, and neither are calls into other files.
- Code after an unconditional `return`/`throw`/`break`/`continue` is unreachable and left out.

Plain PHP scripts work too. A function is a node with its own flow, and the top-level code of a file becomes a `script` node named after the file, so a legacy page that is nothing but `require`, `if` and function calls still has a graph:

![Flow of a legacy-style script](docs/screenshot-script.png)

What currently resolves: `$this->method()`, `self::method()` and `static::method()` within the same class, and calls to functions declared in the same file (also namespaced, imported with `use function`, or falling back to the global one), case-insensitively. Everything else (calls on other objects, inherited methods, `parent::`, dynamic names such as `$this->$name()`) becomes an `unresolved` node, so the graph never asserts something the code does not prove.

**Builtins.** Calls to common pure PHP functions (`count`, `trim`, `array_merge`, `is_array`, `preg_match`...) are `builtin` steps rather than `call` steps. The UI hides them by default and rejoins the steps around them; the **Builtins (N)** button in a method flow brings them back. `header()`, `mysqli_query()` or `file_put_contents()` are not on the list, so they stay visible. The list is fixed in the code ([`QuietFunctions`](src/Analyzer/QuietFunctions.php)), not read from the running PHP, so the same code gives the same graph on every machine.

## Development

**CI.** Forgejo runs [`.forgejo/workflows/ci.yml`](.forgejo/workflows/ci.yml) and GitHub runs [`.github/workflows/ci.yml`](.github/workflows/ci.yml). They are separate files because the Forgejo runner is a plain `node:20-bookworm` image and `code.forgejo.org` mirrors `actions/checkout` but not `setup-php` or `setup-node`, so there PHP 8.5 comes from the sury.org apt repository ([`ci/install-php.sh`](ci/install-php.sh)). Both run Pint, PHPStan and PHPUnit, then the web app's types, tests and build. To try the Forgejo steps locally, run them in that image: `docker run --rm -v "$PWD":/work -w /work node:20-bookworm ci/install-php.sh`.

**The showcase.** [`tests/fixtures/OrderShowcase.php`](tests/fixtures/OrderShowcase.php) is the demo class the UI opens with, and its graph is pinned by a snapshot test. Every construct the analyzer understands appears in it. When you teach the analyzer something new, add an example of it there, run `make snapshots`, review the diff, and open the method in the UI: a feature that is not visible in the demo is not finished. A test fails if the showcase stops covering a kind of branch.

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
- [x] `switch`/`match` branches
- [ ] `finally` after early exits
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
