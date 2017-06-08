<?php

namespace ease\composer;


use Composer\Util\Filesystem;
use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Package\CompletePackageInterface;
use Composer\Repository\InstalledRepositoryInterface;

class Installer extends LibraryInstaller
{
    const PACKAGES_FILE = 'ejsoft/packages.php';
    const EXTRA_REGISTRAR = 'registrar';

    /**
     * @inheritdoc
     */
    public function supports($packageType)
    {
        return $packageType === 'ease-package';
    }

    /**
     * @inheritdoc
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);

        $this->addPackage($package);
    }

    /**
     * @inheritdoc
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        parent::update($repo, $initial, $target);

        $this->removePackage($initial);
        $this->addPackage($target);
    }

    /**
     * @inheritdoc
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::uninstall($repo, $package);

        $this->removePackage($package);
    }

    /**
     * @param PackageInterface $package
     */
    protected function addPackage(PackageInterface $package)
    {
        $prettyName = $package->getPrettyName();
        $aliases = $this->generateDefaultAliases($package);

        $pack = [];

        $extra = $package->getExtra();
        if (isset($extra[self::EXTRA_REGISTRAR])) {
            $pack['package'] = $extra[self::EXTRA_REGISTRAR];
        }

        if (empty($registration['registration'])) {
            throw new \InvalidArgumentException('Unable to determine the registration file for ' . $prettyName);
        }

        if ($aliases) {
            $pack['aliases'] = $aliases;
        }

        $packages = $this->loadPackages();
        $packages[$package->getName()] = $pack;
        $this->savePackages($packages);
    }

    /**
     * @param PackageInterface $package
     * @return array
     */
    protected function generateDefaultAliases(PackageInterface $package)
    {
        $fs = new Filesystem();
        $vendorDir = $fs->normalizePath($this->vendorDir);
        $autoload = $package->getAutoload();
        $aliases = [];
        if (!empty($autoload['psr-0'])) {
            foreach ($autoload['psr-0'] as $name => $path) {
                $name = str_replace('\\', '/', trim($name, '\\'));
                if (!$fs->isAbsolutePath($path)) {
                    $path = $this->vendorDir . '/' . $package->getPrettyName() . '/' . $path;
                }
                $path = $fs->normalizePath($path);
                if (strpos($path . '/', $vendorDir . '/') === 0) {
                    $aliases["@$name"] = '<vendor-dir>' . substr($path, strlen($vendorDir)) . '/' . $name;
                } else {
                    $aliases["@$name"] = $path . '/' . $name;
                }
            }
        }
        if (!empty($autoload['psr-4'])) {
            foreach ($autoload['psr-4'] as $name => $path) {
                if (is_array($path)) {
                    // ignore psr-4 autoload specifications with multiple search paths
                    // we can not convert them into aliases as they are ambiguous
                    continue;
                }
                $name = str_replace('\\', '/', trim($name, '\\'));
                if (!$fs->isAbsolutePath($path)) {
                    $path = $this->vendorDir . '/' . $package->getPrettyName() . '/' . $path;
                }
                $path = $fs->normalizePath($path);
                if (strpos($path . '/', $vendorDir . '/') === 0) {
                    $aliases["@$name"] = '<vendor-dir>' . substr($path, strlen($vendorDir));
                } else {
                    $aliases["@$name"] = $path;
                }
            }
        }
        return $aliases;
    }

    /**
     * @param PackageInterface $package
     */
    protected function removePackage(PackageInterface $package)
    {
        $packages = $this->loadPackages();
        unset($packages[$package->getName()]);
        $this->savePackages($packages);
    }

    /**
     * @return array|mixed
     */
    protected function loadPackages()
    {
        $file = $this->vendorDir . '/' . static::PACKAGES_FILE;

        if (!is_file($file)) {
            return [];
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        $packages = require($file);

        // Swap absolute paths with <vendor-dir> tags
        $vendorDir = str_replace('\\', '/', $this->vendorDir);
        $n = strlen($vendorDir);

        foreach ($packages as &$package) {
            // aliases
            if (isset($package['aliases'])) {
                foreach ($package['aliases'] as $alias => $path) {
                    $path = str_replace('\\', '/', $path);
                    if (strpos($path . '/', $vendorDir . '/') === 0) {
                        $package['aliases'][$alias] = '<vendor-dir>' . substr($path, $n);
                    }
                }
            }
        }

        return $packages;
    }

    /**
     * @param array $package
     */
    protected function savePackages(array $package)
    {
        $file = $this->vendorDir . '/' . static::PACKAGES_FILE;

        if (!file_exists(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        $array = str_replace("'<vendor-dir>", '$vendorDir . \'', var_export($package, true));
        file_put_contents($file, "<?php\n\n\$vendorDir = dirname(__DIR__);\n\nreturn $array;\n");

        // Invalidate opcache of bootstrap.php if it exists
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}