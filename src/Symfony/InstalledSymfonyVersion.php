<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Symfony;

/**
 * The version of Symfony the analysed project installs, read from its Composer metadata.
 *
 * Kernel::MAJOR_VERSION is the version of whichever Kernel class was loaded first, which is Psalm's own
 * when Psalm is installed apart from the project (bamarni/composer-bin-plugin, a PHAR, a global install).
 * The plugin then loads the stubs of the wrong version, or none, and no request input is tainted.
 */
final class InstalledSymfonyVersion
{
    private const PACKAGES = ['symfony/http-kernel', 'symfony/symfony'];

    public static function major(string $vendorDirectory): ?string
    {
        $installedFile = $vendorDirectory.'/composer/installed.php';
        if (!is_file($installedFile)) {
            return null;
        }

        $installed = require $installedFile;
        if (!\is_array($installed) || !\is_array($installed['versions'] ?? null)) {
            return null;
        }

        foreach (self::PACKAGES as $package) {
            $version = $installed['versions'][$package]['version'] ?? null;
            if (\is_string($version) && 1 === preg_match('/^(?:dev-)?v?(\d+)/', $version, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
