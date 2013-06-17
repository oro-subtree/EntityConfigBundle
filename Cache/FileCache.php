<?php

namespace Oro\Bundle\EntityConfigBundle\Cache;

use Oro\Bundle\EntityConfigBundle\Config\EntityConfig;

class FileCache implements CacheInterface
{
    private $dir;

    public function __construct($dir)
    {
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException(sprintf('The directory "%s" does not exist.', $dir));
        }
        if (!is_writable($dir)) {
            throw new \InvalidArgumentException(sprintf('The directory "%s" is not writable.', $dir));
        }

        $this->dir = rtrim($dir, '\\/');
    }

    /**
     * @param $className
     * @return EntityConfig
     */
    public function loadConfigFromCache($className)
    {
        $path = $this->dir.'/'.strtr($className, '\\', '-').'.cache.php';
        if (!file_exists($path)) {
            return null;
        }

        return include $path;
    }

    /**
     * @param EntityConfig $config
     */
    public function putConfigInCache(EntityConfig $config)
    {
        $path = $this->dir.'/'.strtr($config->getClassName(), '\\', '-').'.cache.php';
        file_put_contents($path, '<?php return unserialize('.var_export(serialize($config), true).');');
    }

    /**
     * @param $className
     */
    public function removeConfigFromCache($className)
    {
        $path = $this->dir.'/'.strtr($className, '\\', '-').'.cache.php';
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
