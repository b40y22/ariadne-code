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
src/Http/      Entry point: the `POST /api/analyze` endpoint, free of globals so it is tested without a server
web/           The UI: a Vue app that only ever reads the Code Graph JSON
```

Dependencies point one way only: `Cli`/`Http → Analyzer → Graph`. `Graph` knows nothing about PHP or the console.

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

### ADR-6: Method flow is a subgraph, built from dangling exits

The flow of a method lives in the same graph: flow nodes point to their method through `parent`, and `flow` edges carry a branch `label`. `MethodFlowBuilder` keeps the end of the flow built so far as a list of `FlowExit`s and attaches every new node to all of them, so branches merge without special cases and `return`/`break`/`continue` simply produce an empty list. This keeps the graph a single structure for any consumer, and a UI can show a method's flow by filtering on `parent`. Constructs the builder does not model (`switch`, `match`) degrade to plain steps and are listed in the README instead of being guessed.

### ADR-7: Functions and the script of a file are nodes like methods

Legacy PHP is often procedural, so a graph of classes alone would be empty for the code that matters. A function is a `function` node and the top-level code of a file is one `script` node named after the file; both have a flow of their own and the same `calls` edges as a method. A call belongs to the innermost place that runs it, and closures belong to the place that defines them. Declarations (classes, functions, `use`, `declare`) are not part of the script, so a file that is only classes has no script node.

### ADR-8: The showcase is the demo, the snapshot and the checklist

`tests/fixtures/OrderShowcase.php` is the class the UI opens with, a snapshot fixture, and the list of constructs the analyzer understands. One file serves all three because separate copies drift apart within a week. A feature that is not in it is not visible, so it is not finished, and a test fails when the showcase stops covering a kind of node or branch. See "Development" in the README.

### ADR-9: A flow node says what it adds, not what the source says

Every call is a step, and `return`, `throw` and conditions are nodes of their own. When one expression is both (`return $this->save()`), the call is the step and the `return` is a bare keyword, because repeating the text shows the same line twice. An expression that is not a call (`throw new E()`, `return $x`) keeps its text, so the exception type and the value stay visible.
