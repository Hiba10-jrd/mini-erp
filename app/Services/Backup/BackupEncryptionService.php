<?php

namespace App\Services\Backup;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class BackupEncryptionService
{
    public const MAGIC = "ERPBACKUP\n";

    public const FORMAT_VERSION = 1;

    public const CIPHER = 'secretstream-xchacha20-poly1305';

    public const CHUNK_BYTES = 65536;

    public const MAX_PLAINTEXT_BYTES = 107374182400; // 100 GiB, bounded temporary disk usage.

    public function validateKey(): void
    {
        $key = $this->key();
        sodium_memzero($key);
    }

    private function key(?string $keyId = null): string
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new BackupException('Extension Sodium requise.');
        }
        $configuredId = config('backup.key_id');
        if (! is_string($configuredId) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $configuredId)) {
            throw new BackupException('BACKUP_KEY_ID invalide.');
        }
        if ($keyId !== null && $keyId !== $configuredId) {
            throw new BackupException('Clé indisponible pour ce key_id.');
        }
        $encoded = config('backup.encryption_key');
        if (! is_string($encoded) || $encoded === '') {
            throw new BackupException('BACKUP_ENCRYPTION_KEY obligatoire.');
        }
        if (! str_starts_with($encoded, 'base64:')) {
            throw new BackupException('BACKUP_ENCRYPTION_KEY invalide : format base64: de 32 octets requis.');
        }
        $key = base64_decode(substr($encoded, 7), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES || base64_encode($key) !== substr($encoded, 7)) {
            throw new BackupException('BACKUP_ENCRYPTION_KEY invalide : format base64: de 32 octets requis.');
        }
        $appKey = (string) config('app.key');
        $appKey = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if (is_string($appKey) && hash_equals($appKey, $key)) {
            sodium_memzero($key);
            throw new BackupException('BACKUP_ENCRYPTION_KEY doit être distincte de APP_KEY.');
        }

        return $key;
    }

    public function encrypt(string $source, string $destination, array $metadata): void
    {
        $key = $this->key();
        $input = $output = null;
        $created = false;
        $success = false;
        $state = null;
        try {
            (new BackupWorkspace)->assertSafePath($source);
            (new BackupWorkspace)->assertSafePath($destination);
            $header = [
                'format_version' => self::FORMAT_VERSION,
                'cipher' => self::CIPHER,
                'key_id' => config('backup.key_id'),
                'backup_id' => $metadata['backup_id'] ?? null,
                'created_at' => $metadata['created_at'] ?? null,
            ];
            $this->validateHeader($header);
            $json = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $authenticatedHeader = self::MAGIC.pack('N', strlen($json)).$json.$streamHeader;
            $input = @fopen($source, 'rb');
            $output = @fopen($destination, 'xb');
            $created = is_resource($output);
            if (! is_resource($input) || ! $created) {
                throw new BackupException('Impossible de lire ou écrire l’archive temporaire.');
            }
            @chmod($destination, 0600);
            $this->writeBytes($output, $authenticatedHeader);
            $total = 0;
            while (! feof($input)) {
                $plain = fread($input, self::CHUNK_BYTES);
                if ($plain === false) {
                    throw new BackupException('Lecture de l’archive impossible.');
                }
                if ($plain === '') {
                    if (! feof($input)) {
                        throw new BackupException('Lecture de l’archive impossible.');
                    }
                    break;
                }
                $total += strlen($plain);
                if ($total > self::MAX_PLAINTEXT_BYTES) {
                    throw new BackupException('Archive supérieure à la limite de taille autorisée.');
                }
                $length = pack('N', strlen($plain) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, $authenticatedHeader.$length);
                $this->writeBytes($output, $length.$cipher);
            }
            // A separate authenticated empty FINAL frame is mandatory in format v1.
            $length = pack('N', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', $authenticatedHeader.$length, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            $this->writeBytes($output, $length.$final);
            if (! fflush($output) || ! fsync($output)) {
                throw new BackupException('Écriture de l’archive impossible.');
            }
            $success = true;
        } catch (BackupException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BackupException('Échec du chiffrement de la sauvegarde.');
        } finally {
            foreach ([$input, $output] as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
            sodium_memzero($key);
            if (is_string($state)) {
                sodium_memzero($state);
            }
            if ($created && ! $success && is_file($destination) && ! @unlink($destination)) {
                throw new BackupException('Impossible de nettoyer le fichier temporaire.');
            }
        }
    }

    public function decrypt(string $source, string $destination): array
    {
        $input = $output = null;
        $key = $state = null;
        $created = $success = false;
        try {
            (new BackupWorkspace)->assertSafePath($source);
            (new BackupWorkspace)->assertSafePath($destination);
            $input = @fopen($source, 'rb');
            if (! is_resource($input)) {
                throw new BackupException('Archive introuvable ou illisible.');
            }
            [$header, $authenticatedHeader, $streamHeader] = $this->parseHeader($input);
            $key = $this->key($header['key_id']);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $key);
            $output = @fopen($destination, 'xb');
            $created = is_resource($output);
            if (! $created) {
                throw new BackupException('Impossible de lire ou écrire l’archive temporaire.');
            }
            @chmod($destination, 0600);
            $total = 0;
            while (true) {
                $lengthBytes = $this->readBytes($input, 4);
                $length = unpack('Nlength', $lengthBytes)['length'];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new BackupException('Bloc chiffré invalide.');
                }
                $cipher = $this->readBytes($input, $length);
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher, $authenticatedHeader.$lengthBytes);
                if ($result === false) {
                    throw new BackupException('Authentification échouée : mauvaise clé ou archive altérée.');
                }
                [$plain, $tag] = $result;
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if ($plain !== '' || fread($input, 1) !== '') {
                        throw new BackupException('Données inattendues après le tag final.');
                    }
                    break;
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || $plain === '') {
                    throw new BackupException('Tag de chiffrement invalide.');
                }
                $total += strlen($plain);
                if ($total > self::MAX_PLAINTEXT_BYTES) {
                    throw new BackupException('Archive supérieure à la limite de taille autorisée.');
                }
                $this->writeBytes($output, $plain);
            }
            if (! fflush($output)) {
                throw new BackupException('Écriture de l’archive impossible.');
            }
            $success = true;

            return $header;
        } catch (BackupException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BackupException('Échec du déchiffrement de la sauvegarde.');
        } finally {
            foreach ([$input, $output] as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
            if (is_string($key)) {
                sodium_memzero($key);
            }
            if (is_string($state)) {
                sodium_memzero($state);
            }
            if ($created && ! $success && is_file($destination) && ! @unlink($destination)) {
                throw new BackupException('Impossible de nettoyer le fichier temporaire.');
            }
        }
    }

    public function readHeader(string $path): array
    {
        (new BackupWorkspace)->assertSafePath($path);
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            throw new BackupException('Archive introuvable ou illisible.');
        }
        try {
            return $this->parseHeader($handle)[0];
        } finally {
            fclose($handle);
        }
    }

    private function parseHeader($handle): array
    {
        $magic = $this->readBytes($handle, strlen(self::MAGIC));
        if ($magic !== self::MAGIC) {
            throw new BackupException('Magic de sauvegarde invalide.');
        }
        $lengthBytes = $this->readBytes($handle, 4);
        $length = unpack('Nlength', $lengthBytes)['length'];
        if ($length < 2 || $length > 4096) {
            throw new BackupException('Header de sauvegarde invalide.');
        }
        $json = $this->readBytes($handle, $length);
        try {
            $header = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new BackupException('Header de sauvegarde invalide.');
        }
        $this->validateHeader($header);
        $streamHeader = $this->readBytes($handle, 24);

        return [$header, $magic.$lengthBytes.$json.$streamHeader, $streamHeader];
    }

    private function validateHeader(mixed $header): void
    {
        if (! is_array($header) || count($header) !== 5 || ($header['format_version'] ?? null) !== self::FORMAT_VERSION || ($header['cipher'] ?? null) !== self::CIPHER || ! is_string($header['key_id'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $header['key_id']) || ! self::isUuid($header['backup_id'] ?? null) || ! self::isUtcDate($header['created_at'] ?? null)) {
            throw new BackupException('Header ou format_version de sauvegarde invalide.');
        }
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    public static function isUtcDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private function readBytes($handle, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($handle, $length - strlen($bytes));
            if ($part === false || $part === '') {
                throw new BackupException('Archive tronquée ou tag final absent.');
            }
            $bytes .= $part;
        }

        return $bytes;
    }

    private function writeBytes($handle, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new BackupException('Écriture de l’archive impossible.');
            }
            $offset += $written;
        }
    }
}
