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
src/Project/   A directory on disk as one project: its PHP files, a cached analysis split into map and flows
src/Cli/       Entry point: arguments, stdout/stderr, exit codes
src/Http/      Entry point: `POST /api/analyze` and the project endpoints, free of globals so they are tested without a server
web/           The UI: a Vue app that only ever reads the Code Graph JSON
```

Dependencies point one way only: `Cli`/`Http → Project → Analyzer → Graph`. `Graph` knows nothing about PHP or the console.

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

Resolving `$this->repo->save()` needs the class of `$this->repo`. The analyzer takes it from what the code states: a declared type, a promoted constructor parameter, a `@var` docblock, or a typed parameter that the body never assigns. Legacy code often declares `public $repo;` and assigns it in the constructor from a typed parameter, so a property with no declared type gets one when every `$this->repo = ...` in the class agrees on a class (a typed parameter or `new Repo()`). One assignment of anything else, even in a branch that may not run, and it stays untyped. A declared type always wins, even a scalar or a union that names no single class. The same rule holds for local variables: every value a variable is given, whether as a parameter, by an assignment, a `foreach`, a `catch`, a reference or `global`, must agree on one class. A value can be a chain whose class is only known once every file is indexed (`$order = $this->repo->find($id)`, where `find()` declares `: Order`), so a variable keeps its values as receivers in a `VariableScope`, and `CallResolver` works the class out at the end, caching it; a variable that reaches itself (`$n = $n->next()`) has none. Return types are read like property types: a declared type or a `@return` docblock naming one class, looked up through parents, with `static` and `$this` standing for the class the method is called on. An override without a type hides the parent's, since the code no longer promises it.

### ADR-12: Outside code is `external`, not unknown

Most calls on a real application go to the framework and libraries (`Carbon::now()`, `DB::table()`, a model's `User::where()`), which are not analyzed. Leaving them `unresolved` mixed "the analyzer does not know" with "the analyzer knows, the code is elsewhere", and the first is what a reader needs to notice. When the method lookup reaches a class that is not among the analyzed files, the call ends in an `external` node named after that class, the first point where the lookup left the project, since the method may be declared there or above it. A lookup that stays inside the project and finds nothing remains `unresolved`, and so does one that passes a class using a trait or `__call`, or reaches an interface of the project, because the method could come from code the index does not follow. The UI hides `external` and `unresolved` nodes together on the class map (ADR-10).

### ADR-13: Project mode reads a mounted directory, and the UI focuses on one class

A project has to reach the analyzer somehow. Uploading it from the browser would send someone's whole codebase over HTTP and keep it in memory or on disk; the privacy note in the README already says nobody wants that. So the API reads a directory mounted read-only into its container, and the browser only asks for what it shows. The source endpoint answers only for paths in the project's own list, so no request can reach another file, and both ports are bound to `127.0.0.1` because the API now serves code.

On a 590-file application the whole graph is 21 MB of JSON, two thirds of it method flows, and a reader opens a handful of those. The API splits it: the map (declarations and calls, 6 MB, gzip makes it a fraction) in one request, each flow on demand. The analysis is cached in a file keyed by the path, modification time and size of every project file and of the analyzer's own code, so a request after an edit re-analyzes and the rest take milliseconds; the cache holds only strings and arrays and is read with `allowed_classes: false`.

Hundreds of classes in one picture help nobody, so the UI shows one unit (a class, function or script) with everything that calls into it and everything it calls, and the classes of those methods with only the methods involved. Calls between two neighbours are left out, so every edge on screen touches the unit. Moving the focus is the "expand" step; it opens on the unit with the most calls, which is usually where a newcomer should start.

### ADR-14: A call step knows its target from the analyzer

Stepping from a call in a flow into the method it calls needs to know which `calls` edge belongs to which step. The UI could match them by line and name, but one line often holds several calls (`$this->save($this->build())`), and names are printed differently on the two sides, so it would have to guess, which ADR-3 rules out. The analyzer knows for certain: when `MethodFlowBuilder` turns a call into a step, it marks the call's AST node with the step id; when the call graph records the same AST node, it carries the mark along, and `CallResolver` draws a `target` edge from the step to the very node the `calls` edge reaches. The edge belongs to the flow (it starts at a flow node), so the API serves it with the flow and the class map never sees it. A step opens a flow only when its target has one; `external` and `unresolved` targets stay where they are.

