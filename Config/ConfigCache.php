<?php

namespace Oro\Bundle\EntityConfigBundle\Config;

use Doctrine\Common\Cache\CacheProvider;

use Oro\Bundle\EntityConfigBundle\Config\Id\ConfigIdInterface;

/**
 * Cache for ConfigInterface
 */
class ConfigCache
{
    /**
     * @var CacheProvider
     */
    protected $cache;

    /**
     * @param $cache
     */
    public function __construct(CacheProvider $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @param ConfigIdInterface $configId
     * @return bool|ConfigInterface
     */
    public function loadConfigFromCache(ConfigIdInterface $configId)
    {
        return unserialize($this->cache->fetch($configId->getId()));
    }

    /**
     * @param ConfigInterface $config
     * @return bool
     */
    public function putConfigInCache(ConfigInterface $config)
    {
        return $this->cache->save($config->getConfigId()->getId(), serialize($config));
    }

    /**
     * @param ConfigIdInterface $configId
     * @return bool
     */
    public function removeConfigFromCache(ConfigIdInterface $configId)
    {
        return $this->cache->delete($configId->getId());
    }

    /**
     * @return bool
     */
    public function removeAll()
    {
        return $this->cache->deleteAll();
    }
}
