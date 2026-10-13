<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psalm\SymfonyPsalmPlugin\Symfony\InstalledSymfonyVersion;

class InstalledSymfonyVersionTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            @unlink($directory.'/composer/installed.php');
            @rmdir($directory.'/composer');
            @rmdir($directory);
        }
    }

    /**
     * @dataProvider provideInstalledPackages
     *
     * @param array<string, array{version: string}> $packages
     */
    public function testItReadsTheMajorVersionTheProjectInstalls(array $packages, ?string $expected): void
    {
        self::assertSame($expected, InstalledSymfonyVersion::major($this->vendorDirectoryWith($packages)));
    }

    /**
     * @return iterable<string, array{array<string, array{version: string}>, ?string}>
     */
    public function provideInstalledPackages(): iterable
    {
        yield 'the HttpKernel component' => [['symfony/http-kernel' => ['version' => '7.4.14.0']], '7'];
        yield 'the full framework' => [['symfony/symfony' => ['version' => '6.4.2.0']], '6'];
        yield 'a development version' => [['symfony/http-kernel' => ['version' => 'dev-7.4']], '7'];
        yield 'no Symfony at all' => [['twig/twig' => ['version' => '3.21.0.0']], null];
    }

    public function testItKnowsNothingWithoutTheComposerMetadata(): void
    {
        self::assertNull(InstalledSymfonyVersion::major(sys_get_temp_dir().'/'.uniqid('no-vendor-', true)));
    }

    /**
     * @param array<string, array{version: string}> $packages
     */
    private function vendorDirectoryWith(array $packages): string
    {
        $directory = sys_get_temp_dir().'/'.uniqid('vendor-', true);
        $this->directories[] = $directory;
        mkdir($directory.'/composer', 0777, true);
        file_put_contents(
            $directory.'/composer/installed.php',
            '<?php return '.var_export(['root' => ['name' => 'app/app'], 'versions' => $packages], true).';',
        );

        return $directory;
    }
}
