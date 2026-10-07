<?php

namespace Tests\Feature;

use App\Services\Backup\BackupEncryptionService;
use App\Services\Backup\BackupException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BackupFixtures;
use Tests\TestCase;

class BackupEncryptionTest extends TestCase
{
    use BackupFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBackupFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupBackupFixtures();
        parent::tearDown();
    }

    private function encryptFixture(int $size = 131073): string
    {
        $source = $this->backupRoot.'/plain';
        $handle = fopen($source, 'wb');
        while ($size > 0) {
            $count = min($size, BackupEncryptionService::CHUNK_BYTES);
            fwrite($handle, random_bytes($count));
            $size -= $count;
        }
        fclose($handle);
        $path = $this->backupRoot.'/encrypted.part';
        app(BackupEncryptionService::class)->encrypt($source, $path, ['backup_id' => (string) Str::uuid(), 'created_at' => '2026-10-07T12:30:00Z']);

        return $path;
    }

    public static function sizes(): array
    {
        return [[0], [1], [65536], [65537], [20 * 1024 * 1024]];
    }

    #[DataProvider('sizes')]
    public function test_streaming_roundtrip(int $size): void
    {
        $path = $this->encryptFixture($size);
        $header = app(BackupEncryptionService::class)->decrypt($path, $this->backupRoot.'/decrypted');
        $this->assertSame(hash_file('sha256', $this->backupRoot.'/plain'), hash_file('sha256', $this->backupRoot.'/decrypted'));
        $this->assertSame('test-v1', $header['key_id']);
        $this->assertSame(1, $header['format_version']);
    }

    public static function invalidKeys(): array
    {
        return [
            'absent' => [null], 'empty' => [''], 'raw' => [str_repeat('a', 32)],
            'too short' => ['base64:'.base64_encode(str_repeat('a', 31))],
            'too long' => ['base64:'.base64_encode(str_repeat('a', 33))],
            'invalid base64' => ['base64:SECRET_NOT_BASE64'],
            'non canonical' => ['base64:'.rtrim(base64_encode(str_repeat('a', 32)), '=')],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function test_missing_or_invalid_key_is_rejected_without_exposing_it(?string $key): void
    {
        config(['backup.encryption_key' => $key]);
        try {
            app(BackupEncryptionService::class)->validateKey();
            $this->fail('Invalid key accepted');
        } catch (BackupException $exception) {
            $this->assertStringContainsString('BACKUP_ENCRYPTION_KEY', $exception->getMessage());
            if ($key !== null && $key !== '') {
                $this->assertStringNotContainsString($key, $exception->getMessage());
            }
        }
    }

    public function test_backup_key_must_differ_from_app_key(): void
    {
        config(['app.key' => config('backup.encryption_key')]);
        $this->expectExceptionMessage('distincte de APP_KEY');
        app(BackupEncryptionService::class)->validateKey();
    }

    public function test_raw_app_key_is_also_compared_by_bytes(): void
    {
        config(['app.key' => base64_decode(substr(config('backup.encryption_key'), 7))]);
        $this->expectExceptionMessage('distincte de APP_KEY');
        app(BackupEncryptionService::class)->validateKey();
    }

    public function test_wrong_key_removes_partially_decrypted_output(): void
    {
        $path = $this->encryptFixture();
        config(['backup.encryption_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertDecryptFailure($path, 'Authentification échouée');
    }

    public function test_unknown_key_id_is_rejected(): void
    {
        $path = $this->encryptFixture();
        config(['backup.key_id' => 'test-v2']);
        $this->assertDecryptFailure($path, 'Clé indisponible');
    }

    public function test_encrypt_failure_removes_new_part_file(): void
    {
        $part = $this->backupRoot.'/failed.part';
        try {
            app(BackupEncryptionService::class)->encrypt($this->backupRoot.'/missing-source', $part, ['backup_id' => (string) Str::uuid(), 'created_at' => '2026-10-07T12:30:00Z']);
            $this->fail('Missing source accepted');
        } catch (BackupException) {
            $this->assertFileDoesNotExist($part);
        }
    }

    public function test_decryption_never_overwrites_an_existing_file(): void
    {
        $path = $this->encryptFixture();
        $output = $this->backupRoot.'/decrypted';
        file_put_contents($output, 'existing content');
        try {
            app(BackupEncryptionService::class)->decrypt($path, $output);
            $this->fail('Existing output overwritten');
        } catch (BackupException) {
            $this->assertSame('existing content', file_get_contents($output));
        }
    }

    public static function corruptions(): array
    {
        return [['altered'], ['truncated'], ['missing-final'], ['trailing'], ['header-altered'], ['oversized-block']];
    }

    #[DataProvider('corruptions')]
    public function test_corrupted_or_incomplete_stream_is_rejected(string $corruption): void
    {
        $path = $this->encryptFixture();
        $handle = fopen($path, 'r+b');
        $size = filesize($path);
        if ($corruption === 'truncated') {
            ftruncate($handle, $size - 1);
        } elseif ($corruption === 'missing-final') {
            ftruncate($handle, $size - 21);
        } elseif ($corruption === 'trailing') {
            fseek($handle, 0, SEEK_END);
            fwrite($handle, 'extra');
        } elseif ($corruption === 'header-altered') {
            $bytes = file_get_contents($path);
            $offset = strpos($bytes, '2026-10-07');
            fseek($handle, $offset);
            fwrite($handle, '2025');
        } elseif ($corruption === 'oversized-block') {
            fseek($handle, strlen(BackupEncryptionService::MAGIC));
            $length = unpack('Nlength', fread($handle, 4))['length'];
            fseek($handle, $length + 24, SEEK_CUR);
            fwrite($handle, pack('N', 0xFFFFFFFF));
        } else {
            fseek($handle, $size - 1);
            $byte = fread($handle, 1);
            fseek($handle, $size - 1);
            fwrite($handle, chr(ord($byte) ^ 1));
        }
        fclose($handle);
        $this->assertDecryptFailure($path);
    }

    private function assertDecryptFailure(string $path, ?string $reason = null): void
    {
        $output = $this->backupRoot.'/decrypted';
        try {
            app(BackupEncryptionService::class)->decrypt($path, $output);
            $this->fail('Invalid stream accepted');
        } catch (BackupException $exception) {
            if ($reason !== null) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
            $this->assertFileDoesNotExist($output);
        }
    }
}
