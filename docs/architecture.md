# Architecture

## Pipeline

```
PHP source → AST (nikic/php-parser) → Analyzer → Code Graph (JSON) → visualization
```

Consumers (CLI, a future web UI, an AI layer) depend only on the Code Graph, never on the AST.

## Layers

```
src/Graph/     Language-agnostic model: Node, Edge, Graph
src/Analyzer/  PHP-specific analysis: AST → Graph
src/Cli/       Entry point: arguments, stdout/stderr, exit codes
```

Dependencies point one way only: `Cli → Analyzer → Graph`. `Graph` knows nothing about PHP or the console.

## Decisions

### ADR-1: Plain PHP library, no framework

The analyzer is a pure `string → Graph` operation. It needs no routing, ORM or container, so a framework would only add dependencies. A framework may appear later in a web layer, never in the core.

### ADR-2: Code Graph is the only contract

Analyzers for other languages can produce the same `Graph` without any change to consumers. The JSON format is documented in the README.

### ADR-3: Never guess, mark what is unknown

A call that static analysis cannot map to a declaration becomes an `unresolved` node (calls on other objects, inherited methods, `parent::`, dynamic names). A wrong edge destroys trust in the whole graph; a missing edge is visible and can be improved later.

### ADR-4: Resolve calls after traversal

A method can be declared below the code that calls it. The visitor records `PendingCall`s during traversal and resolves them in `afterTraverse`, when every method of the file is known.

### ADR-5: Library layout in the repository root

`composer.json`, `src/` and `tests/` live in the root so the repository is a valid Composer package. Docker and `make` are development tooling, not part of the product, so they sit next to the other tool configs. A web UI will get its own directory (`web/`) when it appears.
