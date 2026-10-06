<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use Ariadne\Graph\Graph;
use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final readonly class PhpAnalyzer
{
    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? new ParserFactory()->createForHostVersion();
    }

    /**
     * @param string $file Path stored on graph nodes; it is not read from disk.
     *
     * @throws AnalysisException when the source cannot be parsed
     */
    public function analyze(string $code, string $file): Graph
    {
        return $this->analyzeFiles([$file => $code]);
    }

    /**
     * One graph for several files, so a call in one of them reaches a method declared in another.
     *
     * Two passes: the first indexes the declarations of every file, the second builds the nodes and flows and
     * records the calls, which are resolved last, against the whole project.
     *
     * @param array<string, string> $files path => source; the paths are stored on graph nodes, not read from disk
     * @param (callable(AnalysisException): void)|null $onError Called for a file that cannot be parsed, which is
     *                                                          then left out. Without it, the first such file throws.
     *
     * @throws AnalysisException when a file cannot be parsed and there is no $onError
     */
    public function analyzeFiles(array $files, ?callable $onError = null): Graph
    {
        $index = new ProjectIndex();
        $parsed = [];

        foreach ($files as $file => $code) {
            $file = (string) $file;

            try {
                $statements = $this->parse($code, $file);
            } catch (AnalysisException $exception) {
                if ($onError === null) {
                    throw $exception;
                }

                $onError($exception);

                continue;
            }

            $names = new NameResolver(options: ['preserveOriginalNames' => true]);
            new NodeTraverser($names, new DeclarationCollector($index, $names))->traverse($statements);
            $parsed[$file] = $statements;
        }

        $graph = new Graph();
        $calls = [];

        foreach ($parsed as $file => $statements) {
            $visitor = new CallGraphVisitor($graph, $file);
            new NodeTraverser($visitor)->traverse($statements);
            $calls[] = $visitor->pendingCalls();
        }

        $resolver = new CallResolver($graph, $index);

        foreach (array_merge(...$calls) as $call) {
            $resolver->resolve($call);
        }

        return $graph;
    }

    /**
     * @return array<Stmt>
     *
     * @throws AnalysisException
     */
    private function parse(string $code, string $file): array
    {
        try {
            $statements = $this->parser->parse($code);
        } catch (Error $error) {
            throw new AnalysisException(sprintf('%s: %s', $file, $error->getMessage()), previous: $error);
        }

        if ($statements === null) {
            throw new AnalysisException(sprintf('%s: could not be parsed.', $file));
        }

        return $statements;
    }
}
