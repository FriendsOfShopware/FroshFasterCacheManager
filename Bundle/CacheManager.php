<?php

namespace TinectFasterCacheManager\Bundle;

use Shopware\Components\DependencyInjection\Container;
use Symfony\Component\Process\Process;

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
            $this->removeDir($cacheDir);
        }

        parent::clearHttpCache();
    }

    public function getDirectoryInfo($dir)
    {
        /*
         * start original stuff
         */

        if ($this->container->hasParameter('shopware.app.rootdir')) {
            $docRoot = $this->container->getParameter('shopware.app.rootdir') . '/';
        } else {
            $docRoot = $this->container->getParameter('kernel.root_dir') . '/';
        }

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

        $info['files'] = $this->getInnodeCount($info['dir']);

        if (!$info['size'] = $this->getSize($info['dir'])) {
            return parent::getDirectoryInfo($dir);
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
        $shell = new Process('find ' . $dir . '/ | wc -l');
        $shell->run();

        return (int) $shell->getOutput();
    }

    /**
     * @param $dir
     *
     * @return float
     */
    private function getSize($dir)
    {
        $shell = new Process('du -s "' . $dir . '"');
        $shell->run();
        $output = $shell->getOutput();
        if (preg_match('/[0-9]+/', $output, $match)) {
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
            $rsyncProcess = new Process('rsync -a --delete ' . $blankDir . ' ' . $dir . '/');
            $rsyncProcess->run();
            rmdir($blankDir);
        } else {
            $rsyncProcess = new Process('find ' . $dir . '/ -delete');
            $rsyncProcess->run();
        }
    }

    /**
     * @return bool
     */
    private function rsyncAvailable()
    {
        $rsyncProcess = new Process('command -v rsync');
        $rsyncProcess->run();

        if ($rsyncProcess->getOutput() !== '') {
            return true;
        }

        return false;
    }
}
