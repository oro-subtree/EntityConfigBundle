<?php

namespace Oro\Bundle\EntityConfigBundle\Provider;

use Doctrine\ORM\PersistentCollection;

use Oro\Bundle\EntityConfigBundle\Config\EntityConfig;
use Oro\Bundle\EntityConfigBundle\Config\FieldConfig;
use Oro\Bundle\EntityConfigBundle\ConfigManager;

use Oro\Bundle\EntityConfigBundle\Exception\RuntimeException;

class ConfigProvider implements ConfigProviderInterface
{
    /**
     * @var ConfigManager
     */
    protected $configManager;

    /**
     * @var array|EntityConfig[]
     */
    protected $configs = array();

    /**
     * @var string
     */
    protected $scope;

    /**
     * @param ConfigManager $configManager
     * @param string        $scope
     */
    public function __construct(ConfigManager $configManager, $scope)
    {
        $this->configManager = $configManager;
        $this->scope         = $scope;
    }

    /**
     * @param $className
     * @return EntityConfig
     */
    public function getConfig($className)
    {
        $className = $this->getClassName($className);

        if (isset($this->configs[$className])) {
            return $this->configs[$className];
        } else {
            return $this->configs[$className] = $this->configManager->getConfig($className, $this->scope);
        }
    }

    /**
     * @param $className
     * @return bool
     */
    public function hasConfig($className)
    {
        $className = $this->getClassName($className);

        return isset($this->configs[$className]) ? true : $this->configManager->hasConfig($className);
    }

    /**
     * @param $className
     * @param $code
     * @return FieldConfig
     */
    public function getFieldConfig($className, $code)
    {
        return $this->getConfig($className)->getField($code);
    }

    /**
     * @param $className
     * @param $code
     * @return FieldConfig
     */
    public function hasFieldConfig($className, $code)
    {
        return $this->hasConfig($className)
            ? $this->getConfig($className)->hasField($code)
            : false;
    }

    /**
     * @param       $className
     * @param array $values
     * @param bool  $flush
     * @return EntityConfig
     */
    public function createEntityConfig($className, array $values, $flush = false)
    {
        $className    = $this->getClassName($className);
        $entityConfig = new EntityConfig($className, $this->scope);

        foreach ($values as $key => $value) {
            $entityConfig->set($key, $value);
        }

        $this->configManager->persist($entityConfig);

        if ($flush) {
            $this->configManager->flush();
        }
    }

    /**
     * @param       $className
     * @param       $code
     * @param       $type
     * @param array $values
     * @param bool  $flush
     * @return FieldConfig
     */
    public function createFieldConfig($className, $code, $type, array $values = array(), $flush = false)
    {
        $className   = $this->getClassName($className);
        $fieldConfig = new FieldConfig($className, $code, $type, $this->scope);

        foreach ($values as $key => $value) {
            $fieldConfig->set($key, $value);
        }

        $this->configManager->persist($fieldConfig);

        if ($flush) {
            $this->configManager->flush();
        }
    }

    /**
     * @param $entity
     * @return string
     * @throws RuntimeException
     */
    public function getClassName($entity)
    {
        $className = $entity;

        if ($entity instanceof PersistentCollection) {
            $className = $entity->getTypeClass()->getName();
        } elseif (is_object($entity)) {
            $className = get_class($entity);
        } elseif (is_array($entity) && count($entity) && is_object(reset($entity))) {
            $className = get_class(reset($entity));
        }

        if (!is_string($className)) {
            throw new RuntimeException('AbstractAdvancedConfigProvider::getClassName expects Object, PersistentCollection array of entities or string');
        }

        return $className;
    }

    public function getScope()
    {
        return $this->scope;
    }
}
