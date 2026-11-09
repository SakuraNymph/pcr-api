<?php

namespace App\Logging;

use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Stringable;
use Throwable;
use UnitEnum;

final class SqlQueryLogger
{
    private const REDACTED = '[REDACTED]';

    private const DEFAULT_SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'password_hash',
        'passwd',
        'pwd',
        'token',
        'access_token',
        'refresh_token',
        'api_token',
        'remember_token',
        'authorization',
        'secret',
        'client_secret',
        'api_key',
        'apikey',
        'private_key',
        'credential',
        'credentials',
    ];

    /**
     * @var list<string>
     */
    private readonly array $sensitiveKeys;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config)
    {
        $configuredKeys = $config['sensitive_keys'] ?? self::DEFAULT_SENSITIVE_KEYS;

        $this->sensitiveKeys = array_values(array_unique(array_filter(array_map(
            fn (mixed $key): string => $this->normalizeKey((string) $key),
            is_array($configuredKeys) ? $configuredKeys : self::DEFAULT_SENSITIVE_KEYS,
        ))));
    }

    public function enabled(): bool
    {
        return filter_var($this->config['enabled'] ?? true, FILTER_VALIDATE_BOOL);
    }

    public function logQuery(QueryExecuted $query): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $sql = (string) $query->sql;
            $duration = $query->time === null ? null : (float) $query->time;
            $isSlow = $duration !== null && $duration > $this->slowThreshold();
            $message = $this->formatMessage(
                marker: $isSlow ? '[SLOW SQL]' : '[SQL]',
                connectionName: (string) ($query->connectionName ?: $query->connection->getName() ?: 'default'),
                duration: $duration,
                sql: $sql,
                bindings: $this->redactBindings($sql, $query->bindings),
            );

            $this->write($isSlow ? 'warning' : 'info', $message);
        } catch (Throwable) {
            // Logging must never affect query execution or application responses.
        }
    }

    public function logFailure(QueryException $exception): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $sql = (string) $exception->getSql();
            $message = $this->formatMessage(
                marker: '[FAILED SQL]',
                connectionName: (string) ($exception->getConnectionName() ?: 'default'),
                duration: null,
                sql: $sql,
                bindings: $this->redactBindings($sql, $exception->getBindings()),
                error: $this->exceptionMessage($exception),
            );

            $this->write('error', $message);
        } catch (Throwable) {
            // Logging must never replace or suppress the original database exception.
        }
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    private function formatMessage(
        string $marker,
        string $connectionName,
        ?float $duration,
        string $sql,
        array $bindings,
        ?string $error = null,
    ): string {
        $lines = [
            $marker,
            'Time: '.now()->format('Y-m-d H:i:s.v P'),
            'Connection: '.$connectionName,
            'Duration: '.($duration === null ? 'n/a' : number_format($duration, 2, '.', '').'ms'),
            'SQL: '.$sql,
            'Bindings: '.$this->encodeBindings($bindings),
        ];

        if ($error !== null && $error !== '') {
            $lines[] = 'Error: '.$error;
        }

        return implode(PHP_EOL, $lines);
    }

    private function write(string $level, string $message): void
    {
        $channel = trim((string) ($this->config['channel'] ?? ''));

        if ($channel !== '') {
            Log::channel($channel)->log($level, $message);

            return;
        }

        Log::log($level, $message);
    }

    private function slowThreshold(): float
    {
        $threshold = $this->config['slow_threshold_ms'] ?? 500;

        return is_numeric($threshold) ? max(0.0, (float) $threshold) : 500.0;
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @return array<array-key, mixed>
     */
    private function redactBindings(string $sql, array $bindings): array
    {
        $sensitivePositions = array_fill_keys($this->sensitivePlaceholderPositions($sql), true);
        $redacted = [];
        $position = 0;

        foreach ($bindings as $key => $value) {
            $isSensitive = is_string($key)
                ? $this->isSensitiveKey($key)
                : isset($sensitivePositions[$position]);

            $redacted[$key] = $isSensitive
                ? self::REDACTED
                : $this->normalizeBinding($value);

            if (is_int($key)) {
                $position++;
            }
        }

        return $redacted;
    }

    /**
     * @return list<int>
     */
    private function sensitivePlaceholderPositions(string $sql): array
    {
        $placeholders = $this->findPositionalPlaceholders($sql);
        $sensitive = [];

        foreach ($placeholders as $position => $offset) {
            if ($this->isSensitivePlaceholder($sql, $offset)) {
                $sensitive[$position] = true;
            }
        }

        foreach ($this->findInsertSensitiveOffsets($sql, $placeholders) as $offset) {
            $position = array_search($offset, $placeholders, true);

            if ($position !== false) {
                $sensitive[$position] = true;
            }
        }

        return array_keys($sensitive);
    }

    /**
     * @return list<int>
     */
    private function findPositionalPlaceholders(string $sql): array
    {
        $offsets = [];
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                if ($char === '\\' && $i + 1 < $length) {
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $i++;

                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;

                continue;
            }

            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i + 2);

                if ($newline === false) {
                    break;
                }

                $i = $newline;

                continue;
            }

            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);

                if ($end === false) {
                    break;
                }

                $i = $end + 1;

                continue;
            }

            if ($char === '?') {
                $offsets[] = $i;
            }
        }

        return $offsets;
    }

    private function isSensitivePlaceholder(string $sql, int $offset): bool
    {
        $start = max(0, $offset - 240);
        $prefix = substr($sql, $start, $offset - $start);

        if (
            preg_match('/([`"\[\]\w.]+)\s*(?:=|<>|!=|<=>|>=|<=|>|<|like|not\s+like|regexp|rlike)\s*$/i', $prefix, $matches)
            && $this->isSensitiveIdentifier($matches[1])
        ) {
            return true;
        }

        if (
            preg_match('/([`"\[\]\w.]+)\s+(?:not\s+)?in\s*\([^)]*$/is', $prefix, $matches)
            && $this->isSensitiveIdentifier($matches[1])
        ) {
            return true;
        }

        if (preg_match('/\(([^()]*)\)\s*=\s*\(([^)]*)$/is', $prefix, $matches)) {
            $columns = $this->parseColumnList($matches[1]);
            $columnIndex = substr_count($matches[2], ',');
            $column = $columns[$columnIndex] ?? null;

            if ($column !== null && $this->isSensitiveKey($column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $placeholders
     * @return list<int>
     */
    private function findInsertSensitiveOffsets(string $sql, array $placeholders): array
    {
        if ($placeholders === []) {
            return [];
        }

        if (! preg_match(
            '/\binsert\b(?:\s+\w+)*?\s+into\s+[^()]*?\(([^()]*)\)\s+values\b/is',
            $sql,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            return [];
        }

        $columns = $this->parseColumnList($matches[1][0]);
        $sensitiveColumns = [];

        foreach ($columns as $index => $column) {
            if ($this->isSensitiveKey($column)) {
                $sensitiveColumns[$index] = true;
            }
        }

        if ($sensitiveColumns === []) {
            return [];
        }

        $placeholderSet = array_fill_keys($placeholders, true);
        $sensitiveOffsets = [];
        $valuesStart = $matches[0][1] + strlen($matches[0][0]);
        $length = strlen($sql);
        $depth = 0;
        $expressionIndex = 0;
        $inRow = false;
        $quote = null;

        for ($i = $valuesStart; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                if ($char === '\\' && $i + 1 < $length) {
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $i++;

                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;

                continue;
            }

            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i + 2);

                if ($newline === false) {
                    break;
                }

                $i = $newline;

                continue;
            }

            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);

                if ($end === false) {
                    break;
                }

                $i = $end + 1;

                continue;
            }

            if ($char === '(') {
                $depth++;

                if ($depth === 1) {
                    $inRow = true;
                    $expressionIndex = 0;
                }

                continue;
            }

            if ($char === ')') {
                if ($depth === 1) {
                    $inRow = false;
                }

                $depth = max(0, $depth - 1);

                continue;
            }

            if ($char === ',' && $depth === 1 && $inRow) {
                $expressionIndex++;

                continue;
            }

            if (
                $char === '?'
                && $inRow
                && isset($placeholderSet[$i])
                && isset($sensitiveColumns[$expressionIndex])
            ) {
                $sensitiveOffsets[] = $i;
            }
        }

        return $sensitiveOffsets;
    }

    /**
     * @return list<string>
     */
    private function parseColumnList(string $columns): array
    {
        $parts = preg_split('/\s*,\s*/', trim($columns)) ?: [];

        return array_values(array_filter(array_map(
            fn (string $column): string => $this->identifierName($column),
            $parts,
        )));
    }

    private function isSensitiveIdentifier(string $identifier): bool
    {
        return $this->isSensitiveKey($this->identifierName($identifier));
    }

    private function identifierName(string $identifier): string
    {
        $identifier = trim($identifier);

        if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)\s*$/', $identifier, $matches)) {
            return $matches[1];
        }

        return trim($identifier, "`\"[] \t\n\r\0\x0B");
    }

    private function isSensitiveKey(string $key): bool
    {
        $dotPosition = strrpos($key, '.');

        if ($dotPosition !== false) {
            $key = substr($key, $dotPosition + 1);
        }

        $key = $this->normalizeKey($key);

        if ($key === '') {
            return false;
        }

        foreach ($this->sensitiveKeys as $sensitiveKey) {
            if ($key === $sensitiveKey || str_ends_with($key, '_'.$sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key, "`\"[] \t\n\r\0\x0B"));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';

        return trim($key, '_');
    }

    private function normalizeBinding(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                return '[BINARY DATA]';
            }

            if ($this->looksLikeCredential($value)) {
                return self::REDACTED;
            }

            return $this->truncate($value);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.uP');
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof Stringable) {
            return $this->normalizeBinding((string) $value);
        }

        if (is_array($value)) {
            $normalized = [];

            foreach (array_slice($value, 0, 50, true) as $key => $item) {
                $normalized[$key] = $this->normalizeBinding($item);
            }

            if (count($value) > 50) {
                $normalized['...'] = '[TRUNCATED]';
            }

            return $normalized;
        }

        if (is_resource($value)) {
            return '[RESOURCE '.get_resource_type($value).']';
        }

        return '[OBJECT '.get_debug_type($value).']';
    }

    private function looksLikeCredential(string $value): bool
    {
        return preg_match('/^\s*(?:bearer|basic)\s+\S+/i', $value) === 1
            || preg_match('/^\d+\|[A-Za-z0-9]{40,}$/', $value) === 1
            || preg_match('/^[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}$/', $value) === 1;
    }

    private function truncate(string $value, int $limit = 2000): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).'...[TRUNCATED]';
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    private function encodeBindings(array $bindings): string
    {
        $encoded = json_encode(
            $bindings,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return $encoded === false ? '[UNSERIALIZABLE BINDINGS]' : $encoded;
    }

    private function exceptionMessage(QueryException $exception): string
    {
        $message = $exception->getPrevious()?->getMessage() ?: $exception->getMessage();
        $message = preg_replace('/\s*\(Connection:.*$/s', '', $message) ?? $message;
        $message = $this->redactSensitiveValuesFromMessage(
            $message,
            (string) $exception->getSql(),
            $exception->getBindings(),
        );
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? $message;

        return $this->truncate($message);
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    private function redactSensitiveValuesFromMessage(string $message, string $sql, array $bindings): string
    {
        $sensitivePositions = array_fill_keys($this->sensitivePlaceholderPositions($sql), true);
        $position = 0;

        foreach ($bindings as $key => $value) {
            $isSensitive = is_string($key)
                ? $this->isSensitiveKey($key)
                : isset($sensitivePositions[$position]);

            if ($isSensitive) {
                $value = is_string($value) || is_int($value) || is_float($value)
                    ? (string) $value
                    : '';

                if ($value !== '') {
                    $message = str_replace(
                        ["'".$value."'", '"'.$value.'"'],
                        ["'[REDACTED]'", '"[REDACTED]"'],
                        $message,
                    );

                    if (strlen($value) >= 8) {
                        $message = str_replace($value, self::REDACTED, $message);
                    }
                }
            }

            if (is_int($key)) {
                $position++;
            }
        }

        return $message;
    }
}
