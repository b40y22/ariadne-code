<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * Common PHP functions that only compute a value: string, array, math and type helpers.
 *
 * Their calls are shown in a flow as `builtin` steps, which the UI can hide, so a method does not drown in
 * `is_array` and `array_merge` while `header()`, `mysqli_query()` or `file_put_contents()` stay visible.
 * The list is fixed on purpose, not read from the running PHP, so the same code gives the same graph everywhere.
 */
final class QuietFunctions
{
    private const array PREFIXES = ['array_', 'is_', 'str_', 'mb_', 'ctype_', 'preg_', 'json_', 'iterator_'];

    private const array NAMES = [
        // strings
        'strlen', 'strtolower', 'strtoupper', 'strtotime', 'strpos', 'strrpos', 'stripos', 'strstr', 'strrev', 'strcmp', 'strcasecmp', 'strncmp',
        'substr', 'substr_count', 'substr_replace', 'trim', 'ltrim', 'rtrim', 'implode', 'join', 'explode', 'sprintf', 'vsprintf', 'number_format',
        'ucfirst', 'lcfirst', 'ucwords', 'nl2br', 'wordwrap', 'htmlspecialchars', 'htmlentities', 'html_entity_decode', 'strip_tags', 'addslashes',
        'stripslashes', 'similar_text', 'levenshtein', 'md5', 'sha1', 'crc32', 'base64_encode', 'base64_decode', 'urlencode', 'urldecode',
        'rawurlencode', 'http_build_query', 'serialize', 'unserialize', 'uniqid',
        // arrays and counting
        'count', 'sizeof', 'in_array', 'range', 'compact', 'sort', 'rsort', 'usort', 'uasort', 'uksort', 'ksort', 'krsort', 'asort', 'arsort',
        // math and conversion
        'min', 'max', 'abs', 'round', 'floor', 'ceil', 'sqrt', 'pow', 'intval', 'floatval', 'strval', 'boolval', 'random_int', 'mt_rand', 'rand',
        // dates
        'date', 'gmdate', 'mktime', 'time', 'checkdate',
        // introspection
        'gettype', 'get_class', 'get_object_vars', 'method_exists', 'property_exists', 'function_exists', 'class_exists', 'defined', 'constant',
        'func_get_args', 'func_num_args', 'spl_object_id',
    ];

    /** @var array<string, true>|null */
    private static ?array $names = null;

    /**
     * @param string $name Lowercased, without a leading backslash.
     */
    public static function contains(string $name): bool
    {
        self::$names ??= array_fill_keys(self::NAMES, true);

        if (isset(self::$names[$name])) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
