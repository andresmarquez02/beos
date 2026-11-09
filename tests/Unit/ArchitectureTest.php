<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ArchitectureTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function layerProvider(): array
    {
        return [
            'controllers' => ['app/Http/Controllers'],
            'resources' => ['app/Http/Resources'],
            'form requests' => ['app/Http/Requests'],
            'dtos' => ['app/DTOs'],
            'services' => ['app/Services'],
        ];
    }

    #[DataProvider('layerProvider')]
    public function test_only_repositories_query_the_database(string $directory): void
    {
        foreach ($this->phpFiles($directory) as $file) {
            $source = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression('/\b(?:DB|Product|ProductPrice|Currency|User)::(?:query|where|find|create|all|first|select|table|raw)\b/', $source, $file);
            $this->assertStringNotContainsString('->whereRaw(', $source, $file);
        }
    }

    public function test_services_do_not_know_the_http_layer(): void
    {
        foreach ($this->phpFiles('app/Services') as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringNotContainsString('Illuminate\\Http\\Request', $source, $file);
            $this->assertStringNotContainsString('Illuminate\\Support\\Facades\\Auth', $source, $file);
            $this->assertStringNotContainsString('request()', $source, $file);
        }
    }

    public function test_dtos_are_final_readonly_classes(): void
    {
        foreach ($this->phpFiles('app/DTOs') as $file) {
            $class = 'App\\DTOs\\'.basename($file, '.php');
            $reflection = new ReflectionClass($class);

            $this->assertTrue($reflection->isFinal(), $class);
            $this->assertTrue($reflection->isReadOnly(), $class);
        }
    }

    public function test_mass_assignment_is_never_disabled_and_request_all_is_never_used(): void
    {
        foreach ($this->phpFiles('app') as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringNotContainsString('$guarded', $source, $file);
            $this->assertStringNotContainsString('#[Guarded', $source, $file);
            $this->assertStringNotContainsString('#[Unguarded', $source, $file);
            $this->assertStringNotContainsString('Model::unguard', $source, $file);
            $this->assertStringNotContainsString('$request->all()', $source, $file);
            $this->assertStringNotContainsString('$this->all()', $source, $file);
        }
    }

    public function test_no_raw_sql_with_user_input_in_the_application(): void
    {
        foreach ($this->phpFiles('app') as $file) {
            $source = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression('/(?:whereRaw|orderByRaw|selectRaw|havingRaw|DB::raw|DB::unprepared)\(/', $source, $file);
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $files = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
