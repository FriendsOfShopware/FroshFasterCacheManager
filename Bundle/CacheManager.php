<?php

namespace TinectFasterCacheManager\Bundle;

use Shopware\Components\DependencyInjection\Container;

class CacheManager extends \Shopware\Components\CacheManager
{
    /**
     * @var Container
     */
    private $container;

    public function __construct($container)
    {
        parent::__construct($container);
        $this->container = $container;
    }

    public function clearHttpCache()
    {
        if ($this->container->getParameter('shopware.httpCache.enabled')) {
            $cacheDir = $this->container->getParameter('shopware.httpCache.cache_dir');

            if (!$this->checkCacheDir($cacheDir)) {
                return;
            }

            $this->removeDir($cacheDir);
        }

        parent::clearHttpCache();
    }

    public function getDirectoryInfo($dir)
    {
        $docRoot = $this->getRootDir();

        /*
         * start original stuff
         */
        $info = [];

        $info['dir'] = str_replace($docRoot, '', $dir);
        $info['dir'] = str_replace(DIRECTORY_SEPARATOR, '/', $info['dir']);
        $info['dir'] = rtrim($info['dir'], '/') . '/';

        if (!file_exists($dir) || !is_dir($dir)) {
            $info['message'] = 'Cache dir not exists';

            return $info;
        }

        if (!is_readable($dir)) {
            $info['message'] = 'Cache dir is not readable';

            return $info;
        }

        if (!is_writable($dir)) {
            $info['message'] = 'Cache dir is not writable';
        }
        /*
         * end original stuff
         */

        if (!$info['files'] = $this->getInnodeCount($info['dir'])) {
            $info = parent::getDirectoryInfo($dir);
            if (!isset($info['message'])) {
                $info['message'] = 'Could NOT use FastCacheManager for counting!';
            }

            return $info;
        }

        if (!$info['size'] = $this->getSize($info['dir'])) {
            $info = parent::getDirectoryInfo($dir);
            if (!isset($info['message'])) {
                $info['message'] = 'Could NOT use FastCacheManager for size!';
            }

            return $info;
        }

        $info['size'] = $this->encodeSize($info['size']);
        $info['freeSpace'] = $this->encodeSize(disk_free_space($dir));

        return $info;
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
            mkdir($blankDir, 0777, true);
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
        $rootDir = realpath($this->getRootDir());

        if (!$cacheDir || !$rootDir) {
            return false;
        }

        if (strpos(realpath($_SERVER['DOCUMENT_ROOT']), $cacheDir) !== false) {
            return false;
        }

        if (strpos($rootDir, $cacheDir) !== false) {
            return false;
        }

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

    private function getRootDir()
    {
        if ($this->container->hasParameter('shopware.app.rootdir')) {
            return $this->container->getParameter('shopware.app.rootdir') . '/';
        }

        return $this->container->getParameter('kernel.root_dir') . '/';
    }
}
