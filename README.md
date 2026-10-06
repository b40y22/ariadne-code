# Ariadne Code

Static analyzer that turns PHP source into a language-agnostic **Code Graph**: the thread through the labyrinth of legacy code.

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

| Edge type  | Meaning                                              |
|------------|------------------------------------------------------|
| `contains` | A class declares a method                            |
| `calls`    | A method calls another; `line` is the call site      |

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
- [ ] Control flow inside methods: `if`/`else`, loops, `try`/`catch`, `throw`
- [ ] Interfaces, traits, enums, inheritance and dependency edges
- [ ] Type-aware call resolution (typed properties, constructor promotion, PHPDoc)
- [ ] Multiple files and project-level graph
- [ ] Interactive web UI: graph with drag, zoom and auto-layout, linked to the source code
- [ ] Step-by-step execution replay of a method
- [ ] AI explanations grounded in the graph

## License

[MIT](LICENSE)

## Authors

Designed and maintained by Yevgen. Developed with [Claude Code](https://claude.com/claude-code).
