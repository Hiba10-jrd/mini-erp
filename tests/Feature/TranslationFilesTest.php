<?php

namespace Tests\Feature;

use Tests\TestCase;

class TranslationFilesTest extends TestCase
{
    private function getJsonKeys(string $path): array
    {
        $content = file_get_contents($path);
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return array_keys($data);
    }

    private function getJsonData(string $path): array
    {
        $content = file_get_contents($path);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_all_json_translation_files_have_identical_keys(): void
    {
        $frKeys = $this->getJsonKeys(base_path('lang/fr.json'));
        $enKeys = $this->getJsonKeys(base_path('lang/en.json'));
        $arKeys = $this->getJsonKeys(base_path('lang/ar.json'));

        $this->assertSame($frKeys, $enKeys, 'Keys in lang/en.json do not match lang/fr.json');
        $this->assertSame($frKeys, $arKeys, 'Keys in lang/ar.json do not match lang/fr.json');
    }

    public function test_no_json_translation_value_is_empty(): void
    {
        foreach (['fr', 'en', 'ar'] as $locale) {
            $data = $this->getJsonData(base_path("lang/{$locale}.json"));
            foreach ($data as $key => $value) {
                $this->assertNotEmpty(
                    trim((string) $value),
                    "Empty translation value for key '{$key}' in lang/{$locale}.json"
                );
            }
        }
    }

    public function test_translation_placeholders_match_across_locales(): void
    {
        $frData = $this->getJsonData(base_path('lang/fr.json'));
        $enData = $this->getJsonData(base_path('lang/en.json'));
        $arData = $this->getJsonData(base_path('lang/ar.json'));

        foreach ($frData as $key => $frVal) {
            preg_match_all('/:([a-zA-Z0-9_]+)/', (string) $key, $keyMatches);
            $expectedPlaceholders = $keyMatches[1];
            sort($expectedPlaceholders);

            if (! empty($expectedPlaceholders)) {
                foreach (['en' => $enData[$key], 'ar' => $arData[$key]] as $loc => $val) {
                    preg_match_all('/:([a-zA-Z0-9_]+)/', (string) $val, $valMatches);
                    $actualPlaceholders = $valMatches[1];
                    sort($actualPlaceholders);

                    $this->assertEquals(
                        $expectedPlaceholders,
                        $actualPlaceholders,
                        "Placeholders mismatch for key '{$key}' in {$loc}.json"
                    );
                }
            }
        }
    public function test_every_literal_key_exists_in_translations(): void
    {
        $frData = $this->getJsonData(base_path('lang/fr.json'));
        $jsonKeys = array_fill_keys(array_keys($frData), true);

        // Scan app and resources/views
        $directories = [
            base_path('app'),
            base_path('resources/views'),
        ];

        $literalKeys = [];
        $patterns = [
            '/__\(\s*[\'"]([^\'"]+)[\'"]\s*[\),]/',
            '/trans_choice\(\s*[\'"]([^\'"]+)[\'"]\s*[\),]/',
            '/@lang\(\s*[\'"]([^\'"]+)[\'"]\s*[\),]/',
        ];

        foreach ($directories as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $ext = $file->getExtension();
                if (! in_array($ext, ['php', 'blade.php', 'blade'], true) && ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                foreach ($patterns as $pattern) {
                    if (preg_match_all($pattern, $content, $matches)) {
                        foreach ($matches[1] as $key) {
                            $literalKeys[$key] = true;
                        }
                    }
                }
            }
        }

        // List of PHP translation groups supported in lang/{locale}/*.php
        $phpGroups = ['auth', 'pagination', 'passwords', 'validation', 'roles', 'permissions', 'notifications'];

        $missingKeys = [];
        foreach (array_keys($literalKeys) as $key) {
            if (isset($jsonKeys[$key])) {
                continue;
            }

            // Check if key is a namespaced/PHP file key (e.g. "auth.failed", "validation.required", etc.)
            $parts = explode('.', $key, 2);
            if (count($parts) === 2 && in_array($parts[0], $phpGroups, true)) {
                continue;
            }

            $missingKeys[] = $key;
        }

        sort($missingKeys);

        $this->assertEmpty(
            $missingKeys,
            'Missing translation keys in lang/ JSON files: ' . implode(', ', array_slice($missingKeys, 0, 20)) .
            (count($missingKeys) > 20 ? ' ... and ' . (count($missingKeys) - 20) . ' more' : '')
        );
    }
}
