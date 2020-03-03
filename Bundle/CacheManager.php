<?php declare(strict_types=1);

namespace FroshFasterCacheManager\Bundle;

use Doctrine\DBAL\Connection;
use Enlight_Controller_Request_Request;
use RecursiveIteratorIterator;
use RuntimeException;
use Shopware\Components\ContainerAwareEventManager;
use Shopware\Components\Model\Configuration;
use Shopware\Components\Theme\PathResolver;
use Shopware_Components_Config;
use Symfony\Component\Finder\Iterator\RecursiveDirectoryIterator;
use Symfony\Component\Finder\SplFileInfo;
use Zend_Cache;
use Zend_Cache_Backend_File;
use Zend_Cache_Core;

class CacheManager extends \Shopware\Components\CacheManager
{
    /**
     * @var string
     */
    private $docRoot;
    /**
     * @var array
     */
    private $httpCache;
    /**
     * @var Zend_Cache_Core
     */
    private $cache;
    /**
     * @var array
     */
    private $cacheConfig;
    /**
     * @var array
     */
    private $templateConfig;
    /**
     * @var string
     */
    private $hookProxyDir;
    /**
     * @var string
     */
    private $modelProxyDir;
    /**
     * @var PathResolver
     */
    private $themePathResolver;

    public function __construct(
        Zend_Cache_Core $cache,
        Configuration $emConfig,
        Connection $db,
        Shopware_Components_Config $config,
        ContainerAwareEventManager $events,
        PathResolver $themePathResolver,
        array $httpCache,
        array $cacheConfig,
        array $templateConfig,
        string $docRoot,
        string $hookProxyDir,
        string $modelProxyDir
    ) {
        parent::__construct(
            $cache,
            $emConfig,
            $db,
            $config,
            $events,
            $themePathResolver,
            $httpCache,
            $cacheConfig,
            $templateConfig,
            $docRoot,
            $hookProxyDir,
            $modelProxyDir
        );
        $this->docRoot = $docRoot;
        $this->httpCache = $httpCache;
        $this->cache = $cache;
        $this->cacheConfig = $cacheConfig;
        $this->templateConfig = $templateConfig;
        $this->hookProxyDir = $hookProxyDir;
        $this->modelProxyDir = $modelProxyDir;
        $this->themePathResolver = $themePathResolver;
    }

    /**
     * Returns cache information
     *
     * @param Enlight_Controller_Request_Request|null $request
     *
     * @return array
     */
    public function getHttpCacheInfo($request = null)
    {
        $info = $this->httpCache['enabled'] ? $this->getDirectoryInfoFast($this->httpCache['cache_dir']) : [];

        $info['name'] = 'Http-Reverse-Proxy';
        $info['backend'] = 'Unknown';

        if ($request && $request->getHeader('Surrogate-Capability')) {
            $info['backend'] = $request->getHeader('Surrogate-Capability');
        }

        return $info;
    }

    /**
     * Returns cache information
     *
     * @return array
     */
    public function getConfigCacheInfo()
    {
        $backendCache = $this->cache->getBackend();

        if (!$backendCache instanceof Zend_Cache_Backend_File) {
            return parent::getConfigCacheInfo();
        }

        $dir = null;

        if (!empty($this->cacheConfig['backendOptions']['cache_dir'])) {
            $dir = $this->cacheConfig['backendOptions']['cache_dir'];
        } elseif (!empty($this->cacheConfig['backendOptions']['slow_backend_options']['cache_dir'])) {
            $dir = $this->cacheConfig['backendOptions']['slow_backend_options']['cache_dir'];
        }
        $info = $this->getDirectoryInfoFast($dir);
        $info['name'] = 'Shopware configuration';
        $info['backend'] = 'File';

        return $info;
    }

    /**
     * Returns template cache information
     *
     * @return array
     */
    public function getTemplateCacheInfo()
    {
        $info = $this->getDirectoryInfoFast($this->templateConfig['compileDir']);
        $info['name'] = 'Shopware templates';

        return $info;
    }

    /**
     * Returns template cache information
     *
     * @return array
     */
    public function getThemeCacheInfo()
    {
        $dir = $this->themePathResolver->getCacheDirectory();
        $info = $this->getDirectoryInfoFast($dir);
        $info['name'] = 'Shopware theme';

        return $info;
    }

    /**
     * Returns cache information
     *
     * @return array
     */
    public function getDoctrineProxyCacheInfo()
    {
        $info = $this->getDirectoryInfoFast($this->modelProxyDir);
        $info['name'] = 'Doctrine Proxies';

        return $info;
    }

    /**
     * Returns cache information
     *
     * @return array
     */
    public function getShopwareProxyCacheInfo()
    {
        $info = $this->getDirectoryInfoFast($this->hookProxyDir);
        $info['name'] = 'Shopware Proxies';

        return $info;
    }

    public function clearHttpCache()
    {
        if ($this->httpCache['enabled'] && $this->checkCacheDir($this->httpCache['cache_dir'])) {
            $this->removeDir($this->httpCache['cache_dir']);
        }

        parent::clearHttpCache();
    }

    /**
     * @param $dir
     *
     * @return int
     */
    private function getInnodeCount($dir)
    {
        // I want to know all innodes, not just files
        $output = null;
        exec('find ' . $dir . '/ | wc -l', $output);

        return (int) $output[0];
    }

    /**
     * @param $dir
     *
     * @return float
     */
    private function getSize($dir)
    {
        $output = null;
        exec('du -s "' . $dir . '"', $output);
        if (preg_match('/[0-9]+/', $output[0], $match)) {
            return $match[0] * 1024;
        }

        return 0;
    }

    /**
     * @param $dir
     */
    private function removeDir($dir)
    {
        if ($this->rsyncAvailable()) {
            $blankDir = sys_get_temp_dir() . '/' . md5($dir . time()) . '/';

            if (!mkdir($blankDir, 0777, true) && !is_dir($blankDir)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $blankDir));
            }

            exec('rsync -a --delete ' . $blankDir . ' ' . $dir . '/');
            rmdir($blankDir);
        } else {
            exec('find ' . $dir . '/ -delete');
        }
    }

    /**
     * @return bool
     */
    private function rsyncAvailable()
    {
        $output = null;
        exec('command -v rsync', $output);

        if ($output[0] !== '') {
            return true;
        }

        return false;
    }

    /*
     * special thanks to @aragon999 for inspecting
     */
    private function checkCacheDir($cacheDir)
    {
        $cacheDir = realpath($cacheDir);
        $rootDir = realpath($this->docRoot);

        // both should be set
        if (!$cacheDir || !$rootDir) {
            return false;
        }

        // $cacheDir shouldn't be part of $rootDir
        if (strpos($rootDir, $cacheDir) !== false) {
            return false;
        }

        // verify $cacheDir is part of open_basedir
        if (!$this->checkPathInOpenDirs($cacheDir)) {
            return false;
        }

        return true;
    }

    private function checkPathInOpenDirs($dir)
    {
        $dir = realpath($dir);

        $openBaseDirs = explode(':', ini_get('open_basedir'));
        foreach ($openBaseDirs as $openBaseDir) {
            if (strpos($dir, realpath($openBaseDir)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format size method
     *
     * @param float $bytes
     *
     * @return string
     */
    private function convertSize($bytes)
    {
        $types = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes >= 1024 && $i < (count($types) - 1); ++$i) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $types[$i];
    }

    private function getShortDirectoryInfo($dir)
    {
        $info = [];
        $info['dir'] = str_replace($this->docRoot . '/', '', $dir);
        $info['dir'] = str_replace(DIRECTORY_SEPARATOR, '/', $info['dir']);
        $info['dir'] = rtrim($info['dir'], '/') . '/';

        if (is_string($this->checkDir($dir))) {
            $info['message'] = $this->checkDir($dir);

            return $info;
        }

        $info['size'] = (float) 0;
        $info['files'] = 0;

        return $info;
    }

    /**
     * Returns cache information by try using fast ways
     *
     * @param string $dir
     *
     * @return array
     */
    private function getDirectoryInfoFast($dir)
    {
        $info = $this->getShortDirectoryInfo($dir);

        if (isset($info['message'])) {
            return $info;
        }

        if (!$info['files'] = $this->getInnodeCount($info['dir'])) {
            $info = $this->getDirectoryInfoSlow($dir);
            if (!isset($info['message'])) {
                $info['message'] = 'Could NOT use FastCacheManager for counting!';
            }

            return $info;
        }

        if (!$info['size'] = $this->getSize($info['dir'])) {
            $info = $this->getDirectoryInfoSlow($dir);
            if (!isset($info['message'])) {
                $info['message'] = 'Could NOT use FastCacheManager for size!';
            }

            return $info;
        }

        $info['size'] = $this->convertSize($info['size']);
        $info['freeSpace'] = $this->convertSize(disk_free_space($dir));

        return $info;
    }

    /**
     * Returns cache information by using slow ways
     *
     * @param string $dir
     *
     * @return array
     */
    private function getDirectoryInfoSlow($dir)
    {
        $info = $this->getShortDirectoryInfo($dir);

        if (isset($info['message'])) {
            return $info;
        }

        $dirIterator = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator(
            $dirIterator,
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->getFilename() === '.gitkeep') {
                continue;
            }

            if (!$entry->isFile()) {
                continue;
            }

            $info['size'] += $entry->getSize();
            ++$info['files'];
        }
        $info['size'] = $this->convertSize($info['size']);
        $info['freeSpace'] = disk_free_space($dir);
        $info['freeSpace'] = $this->convertSize($info['freeSpace']);

        return $info;
    }

    private function checkDir($dir)
    {
        if (!file_exists($dir) || !is_dir($dir)) {
            return 'Cache dir not exists';
        }

        if (!is_readable($dir)) {
            return 'Cache dir is not readable';
        }

        if (!is_writable($dir)) {
            return 'Cache dir is not writable';
        }

        return true;
    }
}
