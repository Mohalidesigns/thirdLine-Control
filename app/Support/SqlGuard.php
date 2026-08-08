<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The custom_sql gate (12.3).
 *
 * A denylist of scary words is not a security control — `DEL/**' + '*'/ETE`
 * defeats one, and so does `EXECUTE IMMEDIATE`. This class tokenises the
 * statement and then *whitelists*: every keyword must be one of the
 * SELECT-clause keywords, every function must be on the allowed list,
 * every table must be one the caller declared, and every parameter must be
 * one the caller declared. Anything the tokeniser does not recognise fails
 * closed.
 *
 * It is the second of three layers. The first is that custom_sql never
 * touches the application database at all — RuleEngineService runs it
 * against a throwaway in-memory SQLite built from the snapshot rows. The
 * third is a statement timeout and a row cap on that connection.
 */
final class SqlGuard
{
    /** Keywords a read-only SELECT may legitimately contain. */
    public const ALLOWED_KEYWORDS = [
        'select', 'distinct', 'all', 'as', 'from', 'where', 'group', 'by', 'having',
        'order', 'asc', 'desc', 'limit', 'offset', 'join', 'inner', 'left', 'right',
        'full', 'outer', 'cross', 'on', 'using', 'union', 'intersect', 'except',
        'and', 'or', 'not', 'in', 'exists', 'between', 'like', 'is', 'null', 'case',
        'when', 'then', 'else', 'end', 'cast', 'with', 'recursive', 'over',
        'partition', 'rows', 'range', 'preceding', 'following', 'current', 'row',
        'unbounded', 'nulls', 'first', 'last', 'true', 'false', 'escape', 'collate',
        'filter', 'within', 'interval', 'day', 'month', 'year', 'hour', 'minute', 'second',
    ];

    /**
     * Everything below is a keyword the tokeniser must recognise as a
     * keyword (so it is rejected by the whitelist) rather than mistake for
     * a column name. The whitelist above is what actually permits; this
     * list only makes sure nothing dangerous slips through as an
     * identifier.
     */
    public const FORBIDDEN_KEYWORDS = [
        'insert', 'update', 'delete', 'merge', 'upsert', 'replace', 'truncate',
        'drop', 'create', 'alter', 'rename', 'comment', 'grant', 'revoke', 'deny',
        'commit', 'rollback', 'savepoint', 'begin', 'transaction', 'lock', 'unlock',
        'call', 'execute', 'exec', 'do', 'prepare', 'deallocate', 'declare', 'set',
        'into', 'values', 'returning', 'copy', 'load', 'outfile', 'infile', 'dumpfile',
        'attach', 'detach', 'pragma', 'vacuum', 'analyze', 'reindex', 'explain',
        'describe', 'show', 'use', 'handler', 'shutdown', 'kill', 'flush', 'reset',
        'backup', 'restore', 'bulk', 'openrowset', 'opendatasource', 'openquery',
        'xp_cmdshell', 'sp_executesql', 'dbms_sql', 'utl_file', 'utl_http', 'dblink',
        'lateral', 'for', 'share', 'nowait', 'skip', 'trigger', 'procedure', 'function',
        'view', 'index', 'database', 'schema', 'table', 'temporary', 'temp', 'if',
    ];

    /** Functions a rule may call. Everything else fails. */
    public const ALLOWED_FUNCTIONS = [
        'count', 'sum', 'avg', 'min', 'max', 'abs', 'round', 'floor', 'ceil', 'ceiling',
        'coalesce', 'nullif', 'ifnull', 'length', 'lower', 'upper', 'trim', 'ltrim',
        'rtrim', 'substr', 'substring', 'replace', 'instr', 'cast', 'date', 'datetime',
        'time', 'strftime', 'julianday', 'row_number', 'rank', 'dense_rank', 'lag',
        'lead', 'ntile', 'group_concat', 'total', 'printf', 'hex', 'typeof',
    ];

    /** Any identifier starting with one of these is a system object. */
    private const SYSTEM_PREFIXES = [
        'information_schema', 'pg_', 'mysql', 'sqlite_', 'sys', 'performance_schema',
        'sysobjects', 'syscolumns', 'dba_', 'all_', 'user_', 'v$', 'gv$', 'msdb', 'master',
    ];

    private const MAX_LENGTH = 8000;

    /**
     * Validate a statement and return the parameter names it uses.
     *
     * @param  array<int, string>  $allowedTables  table names the sandbox exposes
     * @param  array<int, string>  $allowedParams  named parameters the rule declares
     * @return array<int, string> the parameters actually referenced
     *
     * @throws InvalidArgumentException
     */
    public static function assertReadOnly(string $sql, array $allowedTables = [], array $allowedParams = []): array
    {
        $sql = trim($sql);

        if ($sql === '') {
            throw new InvalidArgumentException('The query is empty.');
        }

        if (strlen($sql) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('The query exceeds the '.self::MAX_LENGTH.'-character limit.');
        }

        $tokens = self::tokenise($sql);

        self::assertSingleStatement($tokens);
        self::assertStartsWithSelect($tokens);

        // A CTE is a name the query defines for itself, so it joins the
        // allowed-table list alongside the declared datasets. The CTE body
        // is still checked, so it cannot become a way in.
        $tables = array_map(fn ($t) => strtolower($t), [...$allowedTables, ...self::cteNames($tokens)]);
        $tablePositions = self::tablePositions($tokens);
        $params = [];

        foreach ($tokens as $index => $token) {
            match ($token['type']) {
                'keyword' => self::assertKeywordAllowed($token['value']),
                'function' => self::assertFunctionAllowed($token['value']),
                'identifier' => self::assertIdentifierAllowed(
                    $token, $tokens, $index, $tables, isset($tablePositions[$index])
                ),
                'param' => $params[] = $token['value'],
                default => null,
            };
        }

        $params = array_values(array_unique($params));

        if ($allowedParams !== []) {
            $unknown = array_diff($params, $allowedParams);

            if ($unknown !== []) {
                throw new InvalidArgumentException(
                    'Undeclared parameter(s): '.implode(', ', array_map(fn ($p) => ':'.$p, $unknown))
                );
            }
        } elseif ($params !== []) {
            throw new InvalidArgumentException(
                'The query uses parameters but the rule declares none: '
                .implode(', ', array_map(fn ($p) => ':'.$p, $params))
            );
        }

        return $params;
    }

    /**
     * Tokenise into a typed stream. Comments are a token type so they can
     * be rejected explicitly rather than stripped — a comment in a rule is
     * either an obfuscation attempt or noise, and neither is wanted.
     *
     * @return array<int, array{type: string, value: string}>
     */
    public static function tokenise(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            // Whitespace.
            if (ctype_space($char)) {
                $i++;

                continue;
            }

            // Comments — rejected, never skipped.
            if (($char === '-' && ($sql[$i + 1] ?? '') === '-')
                || ($char === '/' && ($sql[$i + 1] ?? '') === '*')
                || $char === '#') {
                throw new InvalidArgumentException('Comments are not permitted in a monitoring query.');
            }

            // String literal (single-quoted, '' escape).
            if ($char === "'") {
                $i++;
                $value = '';

                while ($i < $length) {
                    if ($sql[$i] === "'" && ($sql[$i + 1] ?? '') === "'") {
                        $value .= "'";
                        $i += 2;

                        continue;
                    }

                    if ($sql[$i] === "'") {
                        $i++;
                        break;
                    }

                    if ($sql[$i] === '\\') {
                        throw new InvalidArgumentException('Backslash escapes are not permitted in a string literal.');
                    }

                    $value .= $sql[$i];
                    $i++;
                }

                $tokens[] = ['type' => 'string', 'value' => $value];

                continue;
            }

            // Quoted identifier.
            if ($char === '"' || $char === '`' || $char === '[') {
                $close = $char === '[' ? ']' : $char;
                $i++;
                $value = '';

                while ($i < $length && $sql[$i] !== $close) {
                    $value .= $sql[$i];
                    $i++;
                }

                $i++;
                $tokens[] = ['type' => 'identifier', 'value' => $value, 'quoted' => true];

                continue;
            }

            // Named parameter.
            if ($char === ':' && preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $m, 0, $i)) {
                $tokens[] = ['type' => 'param', 'value' => $m[1]];
                $i += strlen($m[0]);

                continue;
            }

            // Positional / driver placeholders are refused: a rule declares
            // named parameters so the audit record says what was bound.
            if ($char === '?' || ($char === '$' && ctype_digit($sql[$i + 1] ?? ''))) {
                throw new InvalidArgumentException('Use named parameters (:name), not positional placeholders.');
            }

            // Number.
            if (ctype_digit($char) || ($char === '.' && ctype_digit($sql[$i + 1] ?? ''))) {
                preg_match('/\G\d+(\.\d+)?([eE][+-]?\d+)?/', $sql, $m, 0, $i);
                $tokens[] = ['type' => 'number', 'value' => $m[0]];
                $i += strlen($m[0]);

                continue;
            }

            // Word: keyword, function name, or identifier.
            if (ctype_alpha($char) || $char === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $sql, $m, 0, $i);
                $word = $m[0];
                $i += strlen($word);

                // Look ahead past whitespace for an opening parenthesis.
                $j = $i;
                while ($j < $length && ctype_space($sql[$j])) {
                    $j++;
                }

                $lower = strtolower($word);
                $isCall = ($sql[$j] ?? '') === '(';

                // `x AS (…)` opens a CTE, `IN (…)` a list, `OVER (…)` a window
                // — none of them is a function call.
                if ($isCall && ! in_array($lower, ['as', 'in', 'values', 'exists', 'case', 'over', 'filter', 'using', 'on'], true)) {
                    $tokens[] = ['type' => 'function', 'value' => $lower];

                    continue;
                }

                $tokens[] = [
                    'type' => self::isKeyword($lower) ? 'keyword' : 'identifier',
                    'value' => self::isKeyword($lower) ? $lower : $word,
                ];

                continue;
            }

            // Operators and punctuation.
            if (str_contains('()[],.;+-*/%<>=!|&^~', $char)) {
                // `||` is concatenation and fine; a lone `|`/`&` is a bitwise
                // operator we have no use for and will not guess about.
                $two = substr($sql, $i, 2);

                if (in_array($two, ['<=', '>=', '<>', '!=', '||'], true)) {
                    $tokens[] = ['type' => 'operator', 'value' => $two];
                    $i += 2;

                    continue;
                }

                if (str_contains('|&^~', $char)) {
                    throw new InvalidArgumentException("The operator '{$char}' is not permitted.");
                }

                $tokens[] = ['type' => 'operator', 'value' => $char];
                $i++;

                continue;
            }

            throw new InvalidArgumentException("Unexpected character '{$char}' in the query.");
        }

        return $tokens;
    }

    private static function isKeyword(string $word): bool
    {
        return in_array($word, self::ALLOWED_KEYWORDS, true)
            || in_array($word, self::FORBIDDEN_KEYWORDS, true);
    }

    /** @param array<int, array<string, mixed>> $tokens */
    private static function assertSingleStatement(array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if ($token['type'] === 'operator' && $token['value'] === ';' && $index !== count($tokens) - 1) {
                throw new InvalidArgumentException('Only a single statement is permitted.');
            }
        }
    }

    /** @param array<int, array<string, mixed>> $tokens */
    private static function assertStartsWithSelect(array $tokens): void
    {
        $first = $tokens[0] ?? null;

        if ($first === null
            || $first['type'] !== 'keyword'
            || ! in_array($first['value'], ['select', 'with'], true)) {
            throw new InvalidArgumentException('A monitoring query must begin with SELECT or WITH.');
        }
    }

    private static function assertKeywordAllowed(string $keyword): void
    {
        if (! in_array($keyword, self::ALLOWED_KEYWORDS, true)) {
            throw new InvalidArgumentException(
                strtoupper($keyword).' is not permitted — a monitoring query is read-only.'
            );
        }
    }

    private static function assertFunctionAllowed(string $function): void
    {
        if (! in_array($function, self::ALLOWED_FUNCTIONS, true)) {
            throw new InvalidArgumentException("The function {$function}() is not on the permitted list.");
        }
    }

    /**
     * Names the query defines for itself: `WITH x AS (…)` and, inside a
     * comma-separated CTE list, `, y AS (…)`.
     *
     * @param  array<int, array<string, mixed>>  $tokens
     * @return array<int, string>
     */
    private static function cteNames(array $tokens): array
    {
        $names = [];

        foreach ($tokens as $index => $token) {
            if ($token['type'] !== 'identifier') {
                continue;
            }

            $next = $tokens[$index + 1] ?? null;
            $after = $tokens[$index + 2] ?? null;

            if (($next['value'] ?? null) === 'as' && ($after['value'] ?? null) === '(') {
                $names[] = strtolower($token['value']);
            }
        }

        return $names;
    }

    /**
     * Indexes of identifiers sitting where a table name goes: after FROM
     * or JOIN, and after each comma in an old-style `FROM a, b` list.
     *
     * @param  array<int, array<string, mixed>>  $tokens
     * @return array<int, true>
     */
    private static function tablePositions(array $tokens): array
    {
        $positions = [];
        $expectTable = false;
        $inFromList = false;

        foreach ($tokens as $index => $token) {
            if ($token['type'] === 'keyword') {
                if (in_array($token['value'], ['from', 'join'], true)) {
                    $expectTable = true;
                    $inFromList = $token['value'] === 'from';

                    continue;
                }

                if ($token['value'] !== 'as') {
                    $expectTable = false;
                    $inFromList = false;
                }

                continue;
            }

            if ($token['type'] === 'operator') {
                if ($token['value'] === ',' && $inFromList) {
                    $expectTable = true;
                } elseif ($token['value'] === '(') {
                    // `FROM (SELECT …)` — a derived table, not a name.
                    $expectTable = false;
                } elseif ($token['value'] === '.') {
                    $expectTable = false;
                }

                continue;
            }

            if ($token['type'] === 'identifier' && $expectTable) {
                $positions[$index] = true;
                $expectTable = false;
            }
        }

        return $positions;
    }

    /**
     * Identifiers must not name a system object, must not be
     * three-part (a cross-database reference), and — where they sit in a
     * table position — must be one of the declared dataset tables.
     *
     * @param  array{type: string, value: string}  $token
     * @param  array<int, array<string, mixed>>  $tokens
     * @param  array<int, string>  $tables
     */
    private static function assertIdentifierAllowed(
        array $token,
        array $tokens,
        int $index,
        array $tables,
        bool $isTable,
    ): void {
        $lower = strtolower($token['value']);

        foreach (self::SYSTEM_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                throw new InvalidArgumentException("'{$token['value']}' refers to a system object.");
            }
        }

        // Three-part name: a.b.c crosses a database boundary.
        if (self::dotDepth($tokens, $index) >= 2) {
            throw new InvalidArgumentException('Cross-database references are not permitted.');
        }

        if (! $isTable) {
            return;
        }

        // A qualified name in table position — schema.table or
        // database.schema.table — reaches outside the sandbox, which holds
        // exactly one unqualified table per dataset.
        if (($tokens[$index + 1]['value'] ?? null) === '.') {
            throw new InvalidArgumentException(
                "'{$token['value']}' is a qualified table reference; only this rule's own datasets are available."
            );
        }

        if ($tables === []) {
            throw new InvalidArgumentException('This rule declares no datasets, so it can reference no tables.');
        }

        if (! in_array($lower, $tables, true)) {
            throw new InvalidArgumentException(
                "'{$token['value']}' is not one of this rule's datasets (".implode(', ', $tables).').'
            );
        }
    }

    /**
     * How many dots precede this identifier in an unbroken qualified name.
     *
     * @param  array<int, array<string, mixed>>  $tokens
     */
    private static function dotDepth(array $tokens, int $index): int
    {
        $depth = 0;
        $i = $index - 1;

        while ($i >= 1
            && ($tokens[$i]['type'] ?? '') === 'operator'
            && $tokens[$i]['value'] === '.'
            && ($tokens[$i - 1]['type'] ?? '') === 'identifier') {
            $depth++;
            $i -= 2;
        }

        return $depth;
    }
}
