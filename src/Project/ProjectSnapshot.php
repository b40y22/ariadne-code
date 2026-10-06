<?php

declare(strict_types=1);

namespace Ariadne\Project;

/**
 * A project analyzed at one moment, ready to be served: the parts of the graph are already JSON, so a request
 * only reads them. Plain strings and arrays, so it can be cached without unserializing objects.
 */
final readonly class ProjectSnapshot
{
    /**
     * @param list<string> $files paths relative to the project root
     * @param list<string> $errors files that did not parse, with the parser's message
     * @param string $map the map of the graph, as JSON
     * @param array<string, string> $flows method id => its flow, as JSON
     */
    public function __construct(
        public string $name,
        public array $files,
        public array $errors,
        public string $map,
        public array $flows,
    ) {}

    /** @return array{name: string, files: list<string>, errors: list<string>, map: string, flows: array<string, string>} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'files' => $this->files, 'errors' => $this->errors, 'map' => $this->map, 'flows' => $this->flows];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $name = $data['name'] ?? null;
        $files = $data['files'] ?? null;
        $errors = $data['errors'] ?? null;
        $map = $data['map'] ?? null;
        $flows = $data['flows'] ?? null;

        if (!is_string($name) || !is_string($map) || !self::isStringList($files) || !self::isStringList($errors) || !is_array($flows)) {
            return null;
        }

        foreach ($flows as $id => $flow) {
            if (!is_string($id) || !is_string($flow)) {
                return null;
            }
        }

        /** @var array<string, string> $flows */
        return new self($name, $files, $errors, $map, $flows);
    }

    /** @phpstan-assert-if-true list<string> $value */
    private static function isStringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_all($value, static fn(mixed $item): bool => is_string($item));
    }
}
