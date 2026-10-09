<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Tests\TestCase;

class TranslationFilesTest extends TestCase
{
    private const LOCALES = ['fr', 'en', 'ar'];

    private function getJsonData(string $path): array
    {
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function placeholders(string $value): array
    {
        preg_match_all('/:([a-zA-Z0-9_]+)/', $value, $matches);
        $names = array_unique($matches[1]);
        sort($names);

        return array_values($names);
    }

    private function literalKeys(string $content): array
    {
        $content = implode('', array_map(
            fn ($token) => is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                : $token,
            token_get_all($content)
        ));
        $content = preg_replace('/\{\{--.*?--\}\}/s', '', $content);
        $pattern = <<<'REGEX'
~(?<![\w>])(?:__|trans_choice|@lang)\(\s*(?:'((?:\\.|[^'\\])*)'|"((?:\\.|[^"\\])*)")\s*[,)]~s
REGEX;
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $keys = [];
        foreach ($matches as $match) {
            if ($match[1] !== null) {
                // Single-quoted PHP strings only unescape apostrophes and backslashes.
                $keys[] = preg_replace_callback('/\\\\([\\\\\'])/', fn ($escape) => $escape[1], $match[1]);
            } elseif (! preg_match('/(?<!\\\\)(?:\\\\\\\\)*\$/', $match[2])) {
                // Interpolated strings are dynamic, rather than literal translation keys.
                $keys[] = stripcslashes($match[2]);
            }
        }

        return array_values(array_unique($keys));
    }

    public function test_all_json_translation_files_have_identical_keys(): void
    {
        $expected = array_keys($this->getJsonData(base_path('lang/fr.json')));
        sort($expected);
        foreach (self::LOCALES as $locale) {
            $actual = array_keys($this->getJsonData(base_path("lang/{$locale}.json")));
            sort($actual);
            $this->assertSame($expected, $actual, "JSON keys differ in {$locale}");
        }
    }

    public function test_json_translation_keys_are_not_duplicated(): void
    {
        foreach (self::LOCALES as $locale) {
            $content = file_get_contents(base_path("lang/{$locale}.json"));
            // Catalogues are flat objects; decode keys to catch escaped duplicates.
            preg_match_all('/^\s*("(?:\\\\.|[^"\\\\])*")\s*:/m', $content, $matches);
            $keys = array_map(fn ($key) => json_decode($key, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
            $this->assertCount(count($this->getJsonData(base_path("lang/{$locale}.json"))), $keys);
            $this->assertSame(count($keys), count(array_unique($keys)), "Duplicate JSON keys in {$locale}");
        }
    }

    public function test_no_json_translation_value_is_empty(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach ($this->getJsonData(base_path("lang/{$locale}.json")) as $key => $value) {
                $this->assertIsString($value, "Non-string translation {$locale}: {$key}");
                $this->assertNotSame('', trim($value), "Empty translation {$locale}: {$key}");
            }
        }
    }

    public function test_translation_placeholders_match_across_locales(): void
    {
        $reference = $this->getJsonData(base_path('lang/fr.json'));
        foreach (self::LOCALES as $locale) {
            foreach ($this->getJsonData(base_path("lang/{$locale}.json")) as $key => $value) {
                // Existing symbolic history keys carry their parameters in the French
                // value, while sentence keys declare them in the key itself.
                $expected = $this->placeholders(preg_match('/^[a-z][a-z0-9_.-]*$/D', $key) ? $reference[$key] : $key);
                // Plural branch counts vary by language; every branch retains the names.
                foreach (explode('|', $value) as $branch) {
                    $this->assertSame($expected, $this->placeholders($branch), "Placeholders differ in {$locale}: {$key}");
                }
            }
        }
    }

    public function test_php_catalogues_have_matching_keys_values_and_placeholders(): void
    {
        $groups = array_map('basename', glob(base_path('lang/fr/*.php')));
        foreach (self::LOCALES as $locale) {
            $this->assertSame($groups, array_map('basename', glob(base_path("lang/{$locale}/*.php"))), "PHP groups differ in {$locale}");
            foreach ($groups as $group) {
                $reference = Arr::dot(require base_path("lang/fr/{$group}"));
                $data = Arr::dot(require base_path("lang/{$locale}/{$group}"));
                $expected = array_keys($reference);
                $actual = array_keys($data);
                sort($expected);
                sort($actual);
                $this->assertSame($expected, $actual, "PHP keys differ in {$locale}/{$group}");
                foreach ($reference as $key => $value) {
                    $this->assertIsString($data[$key]);
                    $this->assertNotSame('', trim($data[$key]), "Empty PHP translation {$locale}/{$group}: {$key}");
                    $this->assertSame($this->placeholders($value), $this->placeholders($data[$key]), "PHP placeholders differ in {$locale}/{$group}: {$key}");
                }
            }
        }
    }

    public function test_every_literal_key_exists_in_translations(): void
    {
        $keys = [];
        foreach ([base_path('app'), base_path('resources/views')] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    foreach ($this->literalKeys(file_get_contents($file->getPathname())) as $key) {
                        $keys[$key][] = $file->getPathname();
                    }
                }
            }
        }
        $missing = [];
        foreach (self::LOCALES as $locale) {
            $json = $this->getJsonData(base_path("lang/{$locale}.json"));
            foreach ($keys as $key => $files) {
                if (array_key_exists($key, $json)) {
                    continue;
                }
                // Resolve PHP items in this locale only; a prefix is not a whitelist.
                $translation = app('translator')->get($key, [], $locale, false);
                if (! is_string($translation) || $translation === $key || trim($translation) === '') {
                    $missing[] = "{$locale}: {$key} (".implode(', ', array_unique($files)).')';
                }
            }
        }
        sort($missing);
        $this->assertEmpty($missing, 'Missing translations:'.PHP_EOL.implode(PHP_EOL, $missing));
    }

    public function test_literal_extraction_handles_quotes_escapes_and_comments(): void
    {
        $source = <<<'PHP'
<?php
__('L\'article');
__("Une \"citation\"");
__('Un "mot"');
__('Chemin \\ fichier');
__('Valeur \n conservée');
trans_choice(':count jour|:count jours', 2);
// __('Commentaire ignoré');
__("Texte $variable");
?>
@lang('auth.failed')
{{-- __('Commentaire Blade ignoré') --}}
PHP;
        $this->assertSame([
            "L'article", 'Une "citation"', 'Un "mot"', 'Chemin \\ fichier',
            'Valeur \n conservée', ':count jour|:count jours', 'auth.failed',
        ], $this->literalKeys($source));
    }

    public function test_nonexistent_php_item_is_not_accepted_by_its_group_prefix(): void
    {
        foreach (self::LOCALES as $locale) {
            $this->assertSame('auth.__missing_translation__', app('translator')->get('auth.__missing_translation__', [], $locale, false));
            $this->assertNotSame('auth.failed', app('translator')->get('auth.failed', [], $locale, false));
        }
    }

    public function test_pluralized_messages_render_for_supported_locales(): void
    {
        $key = ':count jour|:count jours';
        $this->assertSame('1 jour', app('translator')->choice($key, 1, ['count' => 1], 'fr'));
        $this->assertSame('2 jours', app('translator')->choice($key, 2, ['count' => 2], 'fr'));
        $this->assertSame('1 day', app('translator')->choice($key, 1, ['count' => 1], 'en'));
        $this->assertSame('2 days', app('translator')->choice($key, 2, ['count' => 2], 'en'));
        foreach ([0 => 'يوم', 1 => 'يوم', 2 => 'يومان', 3 => 'أيام', 11 => 'يوماً', 100 => 'يوم', 103 => 'أيام', 111 => 'يوماً'] as $count => $word) {
            $this->assertSame("{$count} {$word}", app('translator')->choice($key, $count, ['count' => $count], 'ar'));
        }
        foreach ([':count non lue|:count non lues', 'Notifications : :count non lue|Notifications : :count non lues'] as $notificationKey) {
            foreach (self::LOCALES as $locale) {
                foreach ([0, 1, 2, 3, 11, 100] as $count) {
                    $text = app('translator')->choice($notificationKey, $count, ['count' => $count], $locale);
                    $this->assertStringContainsString((string) $count, $text);
                    $this->assertStringNotContainsString(':count', $text);
                    $this->assertStringNotContainsString('|', $text);
                }
            }
        }
    }
}
