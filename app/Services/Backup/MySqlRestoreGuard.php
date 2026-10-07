<?php

namespace App\Services\Backup;

class MySqlRestoreGuard
{
    public function assertGrants(array $grants, string $target, bool $literalUnderscores = false): void
    {
        // DROP is deliberately absent: even a hostile dump cannot drop the target DB.
        $allowed = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'INDEX', 'REFERENCES', 'CREATE TEMPORARY TABLES', 'LOCK TABLES'];
        $effective = [];
        foreach ($grants as $grant) {
            if (! is_string($grant) || stripos($grant, 'WITH GRANT OPTION') !== false || ! preg_match('/^GRANT (.+?) ON (`[^`]+`|\*)\.\* TO /i', $grant, $parts)) {
                throw new BackupException('Le compte restore doit être limité à la seule base cible, sans rôles ni droits globaux.');
            }
            $privileges = array_map(fn ($privilege) => strtoupper(trim($privilege)), explode(',', $parts[1]));
            if ($privileges === ['USAGE'] && $parts[2] === '*') {
                continue;
            }
            $scope = trim($parts[2], '`');
            if (str_contains($scope, '%') || (! $literalUnderscores && preg_match('/(?<!\\\\)_/', $scope)) || str_replace('\\_', '_', $scope) !== $target || array_diff($privileges, $allowed) !== []) {
                throw new BackupException('Droits du compte restore incompatibles avec une restauration isolée.');
            }
            $effective = array_merge($effective, $privileges);
        }
        if (array_diff(['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE'], $effective) !== []) {
            throw new BackupException('Droits du compte restore insuffisants.');
        }
    }

    public function prepareDump(string $source, string $destination): void
    {
        $input = @fopen($source, 'rb');
        $output = @fopen($destination, 'xb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new BackupException('Impossible de préparer le dump pour l’import isolé.');
        }
        @chmod($destination, 0600);
        try {
            $lineStart = true;
            $boundary = true;
            $state = 'normal';
            $escaped = false;
            while (! feof($input)) {
                $chunk = fgets($input, 65537);
                if ($chunk === false) {
                    if (feof($input)) {
                        break;
                    }
                    throw new BackupException('Lecture du dump impossible.');
                }
                // Native mysqldump emits this redundant statement on its own line.
                // The target is empty. Omit only this exact form; never grant DROP.
                $skip = $lineStart && $boundary && $state === 'normal' && preg_match('/^DROP TABLE IF EXISTS `(?:[^`\r\n]|``)+`;\r?\n$/D', $chunk);
                $lineStart = str_ends_with($chunk, "\n");
                if ($skip) {
                    continue;
                }
                // Never alter a matching line inside a string or a multiline statement.
                for ($index = 0; $index < strlen($chunk); $index++) {
                    $char = $chunk[$index];
                    $next = $chunk[$index + 1] ?? '';
                    if ($state === 'line-comment') {
                        if ($char === "\n") {
                            $state = 'normal';
                        }

                        continue;
                    }
                    if ($state === 'block-comment') {
                        if ($char === '*' && $next === '/') {
                            $state = 'normal';
                            $index++;
                        }

                        continue;
                    }
                    if (in_array($state, ["'", '"', '`'], true)) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($char === '\\') {
                            $escaped = true;
                        } elseif ($char === $state) {
                            $state = 'normal';
                        }

                        continue;
                    }
                    if (($char === '-' && $next === '-') || $char === '#') {
                        $state = 'line-comment';

                        continue;
                    }
                    if ($char === '/' && $next === '*') {
                        $state = 'block-comment';
                        $index++;

                        continue;
                    }
                    if (in_array($char, ["'", '"', '`'], true)) {
                        $state = $char;
                        $boundary = false;

                        continue;
                    }
                    if ($char === ';') {
                        $boundary = true;
                    } elseif (! ctype_space($char)) {
                        $boundary = false;
                    }
                }
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new BackupException('Écriture du dump temporaire impossible.');
                    }
                    $offset += $written;
                }
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
