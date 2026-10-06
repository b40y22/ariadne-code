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

A call that static analysis cannot map to a declaration becomes an `unresolved` node (an untyped receiver, the result of another call, a dynamic name). A wrong edge destroys trust in the whole graph; a missing edge is visible and can be improved later.

### ADR-4: Resolve calls after every file is read

A method can be declared below the code that calls it, or in another file. Analysis runs in two passes over all the files given: the first (`DeclarationCollector`) fills a `ProjectIndex` with classes, their parents, methods, functions and property types; the second (`CallGraphVisitor`) builds nodes and flows and only records `PendingCall`s. `CallResolver` resolves them last, against the whole project. One file is simply a project of one, so there is a single code path.

Names are resolved by PhpParser's `NameResolver` before the graph is built, with the original names kept, and labels are printed from those (`SourcePrinter`): a reader looks for `new Clock()` in the code, not `new \App\Clock()`. Node ids of static calls use the full name, so two files importing different classes under one alias do not share a node.

### ADR-5: Library layout in the repository root

`composer.json`, `src/` and `tests/` live in the root so the repository is a valid Composer package. Docker and `make` are development tooling, not part of the product, so they sit next to the other tool configs. A web UI will get its own directory (`web/`) when it appears.

### ADR-6: Method flow is a subgraph, built from dangling exits

The flow of a method lives in the same graph: flow nodes point to their method through `parent`, and `flow` edges carry a branch `label`. `MethodFlowBuilder` keeps the end of the flow built so far as a list of `FlowExit`s and attaches every new node to all of them, so branches merge without special cases and `return`/`break`/`continue` simply produce an empty list. This keeps the graph a single structure for any consumer, and a UI can show a method's flow by filtering on `parent`. Constructs the builder does not model (`switch`, `match`) degrade to plain steps and are listed in the README instead of being guessed.

### ADR-7: Functions and the script of a file are nodes like methods

Legacy PHP is often procedural, so a graph of classes alone would be empty for the code that matters. A function is a `function` node and the top-level code of a file is one `script` node named after the file; both have a flow of their own and the same `calls` edges as a method. A call belongs to the innermost place that runs it, and closures belong to the place that defines them. Declarations (classes, functions, `use`, `declare`) are not part of the script, so a file that is only classes has no script node.

### ADR-8: The showcase is the demo, the snapshot and the checklist

`tests/fixtures/OrderShowcase.php` is the class the UI opens with, a snapshot fixture, and the list of constructs the analyzer understands. One file serves all three because separate copies drift apart within a week. A feature that is not in it is not visible, so it is not finished, and a test fails when the showcase stops covering a kind of node or branch. See "Development" in the README.

### ADR-10: Noise is marked by the analyzer and hidden by the UI

A real 2,500-line service showed that a fifth of all calls were `is_array`, `array_merge` and similar helpers. They are facts about the code, so the analyzer keeps them, as `builtin` steps from a fixed list; whether to show them is a question for the reader, so the UI decides, and rejoins the steps around a hidden one so the path stays a path. The same split applies to unresolved calls on the class map. A list read from the running PHP would have been shorter to write and would have changed the graph between machines, which would have broken the snapshots.

### ADR-9: A flow node says what it adds, not what the source says

Every call is a step, and `return`, `throw` and conditions are nodes of their own. When one expression is both (`return $this->save()`), the call is the step and the `return` is a bare keyword, because repeating the text shows the same line twice. An expression that is not a call (`throw new E()`, `return $x`) keeps its text, so the exception type and the value stay visible.

### ADR-11: A receiver gets a type only when the code guarantees it

Resolving `$this->repo->save()` needs the class of `$this->repo`. The analyzer takes it from what the code states: a declared type, a promoted constructor parameter, a `@var` docblock, or a typed parameter that the body never assigns. Legacy code often declares `public $repo;` and assigns it in the constructor from a typed parameter, so a property with no declared type gets one when every `$this->repo = ...` in the class agrees on a class (a typed parameter or `new Repo()`). One assignment of anything else, even in a branch that may not run, and it stays untyped. A declared type always wins, even a scalar or a union that names no single class. Return types and local `$x = new Foo()` are not used yet: they are the next most common receivers, but each needs its own rules for when the type holds.

### ADR-12: Outside code is `external`, not unknown

Most calls on a real application go to the framework and libraries (`Carbon::now()`, `DB::table()`, a model's `User::where()`), which are not analyzed. Leaving them `unresolved` mixed "the analyzer does not know" with "the analyzer knows, the code is elsewhere", and the first is what a reader needs to notice. When the method lookup reaches a class that is not among the analyzed files, the call ends in an `external` node named after that class, the first point where the lookup left the project, since the method may be declared there or above it. A lookup that stays inside the project and finds nothing remains `unresolved`, and so does one that passes a class using a trait or `__call`, or reaches an interface of the project, because the method could come from code the index does not follow. The UI hides `external` and `unresolved` nodes together on the class map (ADR-10).
