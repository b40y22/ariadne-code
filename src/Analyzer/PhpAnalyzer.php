<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use Ariadne\Graph\Graph;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final readonly class PhpAnalyzer
{
    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForHostVersion();
    }

    /**
     * @param string $file Path stored on graph nodes; it is not read from disk.
     *
     * @throws AnalysisException when the source cannot be parsed
     */
    public function analyze(string $code, string $file): Graph
    {
        try {
            $statements = $this->parser->parse($code);
        } catch (Error $error) {
            throw new AnalysisException(sprintf('%s: %s', $file, $error->getMessage()), previous: $error);
        }

        if ($statements === null) {
            throw new AnalysisException(sprintf('%s: could not be parsed.', $file));
        }

        $graph = new Graph();

        $traverser = new NodeTraverser(new NameResolver(), new CallGraphVisitor($graph, $file));
        $traverser->traverse($statements);

        return $graph;
    }
}
