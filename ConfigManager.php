<?php

namespace Oro\Bundle\EntityConfigBundle;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

use Metadata\MetadataFactory;

use Symfony\Component\EventDispatcher\EventDispatcher;

use Oro\Bundle\EntityConfigBundle\Exception\LogicException;
use Oro\Bundle\EntityConfigBundle\Exception\RuntimeException;

use Oro\Bundle\EntityConfigBundle\Audit\AuditManager;
use Oro\Bundle\EntityConfigBundle\Cache\CacheInterface;
use Oro\Bundle\EntityConfigBundle\DependencyInjection\Proxy\ServiceProxy;
use Oro\Bundle\EntityConfigBundle\Metadata\ConfigClassMetadata;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;

use Oro\Bundle\EntityConfigBundle\Entity\EntityConfigModel;
use Oro\Bundle\EntityConfigBundle\Entity\FieldConfigModel;
use Oro\Bundle\EntityConfigBundle\Entity\AbstractConfigModel;

use Oro\Bundle\EntityConfigBundle\Config\Id\EntityConfigId;
use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;
use Oro\Bundle\EntityConfigBundle\Config\Id\ConfigIdInterface;

use Oro\Bundle\EntityConfigBundle\Config\Config;
use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;

use Oro\Bundle\EntityConfigBundle\Event\PersistConfigEvent;
use Oro\Bundle\EntityConfigBundle\Event\FlushConfigEvent;
use Oro\Bundle\EntityConfigBundle\Event\NewConfigModelEvent;
use Oro\Bundle\EntityConfigBundle\Event\Events;

class ConfigManager
{
    /**
     * @var MetadataFactory
     */
    protected $metadataFactory;

    /**
     * @var ServiceProxy
     */
    protected $proxyEm;

    /**
     * @var EventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var CacheInterface
     */
    protected $configCache;

    /**
     * @var AuditManager
     */
    protected $auditManager;

    /**
     * @var ConfigProvider[]
     */
    protected $providers = array();

    /**
     * @var ConfigInterface[]
     */
    protected $persistConfigs = array();

    /**
     * @var ConfigInterface[]
     */
    protected $insertConfigs = array();

    /**
     * @var ConfigInterface[]
     */
    protected $updateConfigs = array();

    /**
     * @var ConfigInterface[]
     */
    protected $removeConfigs = array();

    /**
     * @var ConfigInterface[]
     */
    protected $configs = array();

    /**
     * @var AbstractConfigModel[]
     */
    protected $entities = array();

    /**
     * @var ConfigInterface[]
     */
    protected $originalConfigs = array();

    /**
     * @var array
     */
    protected $configChangeSets = array();

    /**
     * @var array
     */
    protected $updatedConfigs = array();

    /**
     * @param MetadataFactory $metadataFactory
     * @param EventDispatcher $eventDispatcher
     * @param ServiceProxy    $proxyEm
     * @param ServiceProxy    $security
     */
    public function __construct(MetadataFactory $metadataFactory, EventDispatcher $eventDispatcher, ServiceProxy $proxyEm, ServiceProxy $security)
    {
        $this->metadataFactory = $metadataFactory;
        $this->proxyEm         = $proxyEm;
        $this->eventDispatcher = $eventDispatcher;

        $this->auditManager = new AuditManager($this, $security);
    }

    /**
     * @param CacheInterface $cache
     */
    public function setCache(CacheInterface $cache)
    {
        $this->configCache = $cache;
    }

    /**
     * @return EntityManager
     */
    public function em()
    {
        return $this->proxyEm->getService();
    }

    /**
     * @return ConfigProvider[]
     */
    public function getProviders()
    {
        return $this->providers;
    }

    /**
     * @param ConfigProvider $provider
     */
    public function addProvider(ConfigProvider $provider)
    {
        $this->providers[$provider->getScope()] = $provider;
    }

    /**
     * @param $scope
     * @return ConfigProvider
     */
    public function getProvider($scope)
    {
        return $this->providers[$scope];
    }

    /**
     * @return EventDispatcher
     */
    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }

    /**
     * @param $className
     * @return \Metadata\ClassMetaData
     */
    public function getClassMetadata($className)
    {
        return $this->metadataFactory->getMetadataForClass($className);
    }

    /**
     * @return bool
     */
    public function checkDatabase()
    {
        $tables = $this->em()->getConnection()->getSchemaManager()->listTableNames();
        $table  = $this->em()->getClassMetadata(EntityConfigModel::ENTITY_NAME)->getTableName();

        return in_array($table, $tables);
    }

    /**
     * @param $className
     * @return bool
     */
    public function isConfigurable($className)
    {
        /** @var ConfigClassMetadata $metadata */
        $metadata = $this->metadataFactory->getMetadataForClass($className);

        return $metadata && $metadata->name == $className && $metadata->configurable;
    }

    /**
     * @param ConfigIdInterface $configId
     * @return bool
     */
    public function hasConfig(ConfigIdInterface $configId)
    {
        if (isset($this->configs[$configId->getId()])) {
            return true;
        }

        if (null !== $this->configCache
            && $config = $this->configCache->loadConfigFromCache($configId)
        ) {
            return true;
        }

        return (bool) $this->getConfigModelByConfigId($configId);
    }

    /**
     * @param ConfigIdInterface $configId
     * @throws Exception\RuntimeException
     * @throws Exception\LogicException
     * @return null|Config|ConfigInterface
     */
    public function getConfig(ConfigIdInterface $configId)
    {
        if (isset($this->configs[$configId->getId()])) {
            return $this->configs[$configId->getId()];
        }

        if (!$this->checkDatabase()) {
            throw new LogicException(
                "Database is not synced, if you use ConfigManager, when a db schema may be hasn't synced. check it by ConfigManager::checkDatabase"
            );
        }

        if (!$this->isConfigurable($configId->getClassName())) {
            throw new RuntimeException(sprintf("Entity '%s' is not Configurable", $configId->getClassName()));
        }

        if (null !== $this->configCache && $config = $this->configCache->loadConfigFromCache($configId)) {
            $resultConfig = $config;
        } else {
            if (!$model = $this->getConfigModelByConfigId($configId)) {
                throw new RuntimeException(sprintf('%s is not found', $configId->getEntityId()));
            }

            $config = new Config($configId);
            $config->setValues($model->toArray($configId->getScope()));

            if (null !== $this->configCache) {
                $this->configCache->putConfigInCache($config);
            }

            $resultConfig = $config;
        }

        //internal cache
        $this->configs[$resultConfig->getConfigId()->getId()] = $resultConfig;

        //for calculate change set
        $this->originalConfigs[$resultConfig->getConfigId()->getId()] = clone $resultConfig;

        return $resultConfig;
    }

    /**
     * @param $className
     */
    public function createConfigEntityModel($className)
    {
        if (!$this->getConfigModel($className)) {
            /** @var ConfigClassMetadata $metadata */
            $metadata = $this->metadataFactory->getMetadataForClass($className);

            foreach ($this->getProviders() as $provider) {
                $defaultValues = array();
                if (isset($metadata->defaultValues[$provider->getScope()])) {
                    $defaultValues = $metadata->defaultValues[$provider->getScope()];
                }

                $entityId = new EntityConfigId($className, $provider->getScope());
                $provider->createConfig($entityId, $defaultValues);

                $this->eventDispatcher->dispatch(Events::NEW_CONFIG_MODEL, new NewConfigModelEvent($entityId, $this));
            }
        }
    }

    /**
     * @param $className
     * @param $fieldName
     * @param $fieldType
     */
    public function createConfigFieldModel($className, $fieldName, $fieldType)
    {
        if (!$this->getConfigModel($className, $fieldName)) {
            /** @var ConfigClassMetadata $metadata */
            //$metadata = $this->metadataFactory->getMetadataForClass($className); //TODO::implement default value for config

            foreach ($this->getProviders() as $provider) {
                $entityId = new FieldConfigId($className, $provider->getScope(), $fieldName, $fieldType);
                $provider->createConfig($entityId, array());

                $provider->createConfig(new FieldConfigId($className, $provider->getScope(), $fieldName, $fieldType), array());

                $this->eventDispatcher->dispatch(Events::NEW_CONFIG_MODEL, new NewConfigModelEvent($entityId, $this));
            }
        }
    }


    /**
     * @param ConfigIdInterface $configId
     */
    public function clearCache(ConfigIdInterface $configId)
    {

        if ($this->configCache) {
            $this->configCache->removeConfigFromCache($configId);
        }
    }

    /**
     * @param ConfigInterface $config
     */
    public function persist(ConfigInterface $config)
    {
        $this->persistConfigs->push($config);

        if ($config instanceof EntityConfigInterface) {
            foreach ($config->getFields() as $fieldConfig) {
                $this->persistConfigs->push($fieldConfig);
            }
        }
    }

    /**
     * @param ConfigInterface $config
     */
    public function remove(ConfigInterface $config)
    {
        $this->removeConfigs[spl_object_hash($config)] = $config;

        if ($config instanceof EntityConfigInterface) {
            foreach ($config->getFields() as $fieldConfig) {
                $this->removeConfigs[spl_object_hash($fieldConfig)] = $fieldConfig;
            }
        }
    }

    /**
     * TODO:: remove configs
     */
    public function flush()
    {
        $entities = array();

        foreach ($this->persistConfigs as $config) {
            $className = $config->getClassName();

            if (isset($entities[$className])) {
                $configEntity = $entities[$className];
            } else {
                $configEntity = $entities[$className] = $this->findOrCreateConfigEntity($className);
            }

            $this->eventDispatcher->dispatch(Events::PERSIST_CONFIG, new PersistConfigEvent($config, $this));

            $this->calculateConfigChangeSet($config);

            $changes = $this->getConfigChangeSet($config);

            if (!count($changes)) {
                continue;
            }

            $values = array_intersect_key($config->getValues(), $changes);

            if ($config instanceof FieldConfigInterface) {
                if (!$configField = $configEntity->getField($config->getCode())) {
                    $configField = new FieldConfigModel($config->getCode(), $config->getType());
                    $configEntity->addField($configField);
                }

                $serializableValues = $this->getProvider($config->getScope())->getConfigContainer()->getFieldSerializableValues();
                $configField->fromArray($config->getScope(), $values, $serializableValues);
            } else {
                $serializableValues = $this->getProvider($config->getScope())->getConfigContainer()->getEntitySerializableValues();
                $configEntity->fromArray($config->getScope(), $values, $serializableValues);
            }


            if ($this->configCache) {
                $this->configCache->removeConfigFromCache($className, $config->getScope());
            }
        }

        $this->eventDispatcher->dispatch(Events::PRE_FLUSH, new FlushConfigEvent($this));

        $this->auditManager->log();

        foreach ($entities as $entity) {
            $this->em()->persist($entity);
        }

        $this->em()->flush();

        $this->eventDispatcher->dispatch(Events::ON_FLUSH, new FlushConfigEvent($this));

        $this->removeConfigs    = array();
        $this->originalConfigs  = array();
        $this->configChangeSets = array();
        $this->updatedConfigs   = array();


        $this->eventDispatcher->dispatch(Events::POST_FLUSH, new FlushConfigEvent($this));
    }


    /**
     * @param ConfigInterface $config
     */
    public function calculateConfigChangeSet(ConfigInterface $config)
    {
        $originConfigValue = array();
        if (isset($this->originalConfigs[spl_object_hash($config)])) {
            $originConfig      = $this->originalConfigs[spl_object_hash($config)];
            $originConfigValue = $originConfig->getValues();
        }

        foreach ($config->getValues() as $key => $value) {
            if (!isset($originConfigValue[$key])) {
                $originConfigValue[$key] = null;
            }
        }

        $diffNew = array_udiff_assoc($config->getValues(), $originConfigValue, function ($a, $b) {
            return ($a == $b) ? 0 : 1;
        });

        $diffOld = array_udiff_assoc($originConfigValue, $config->getValues(), function ($a, $b) {
            return ($a == $b) ? 0 : 1;
        });

        $diff = array();
        foreach ($diffNew as $key => $value) {
            $oldValue   = isset($diffOld[$key]) ? $diffOld[$key] : null;
            $diff[$key] = array($oldValue, $value);
        }


        if (!isset($this->configChangeSets[spl_object_hash($config)])) {
            $this->configChangeSets[spl_object_hash($config)] = array();
        }

        if (count($diff)) {
            $this->configChangeSets[spl_object_hash($config)] = array_merge($this->configChangeSets[spl_object_hash($config)], $diff);

            if (!isset($this->updatedConfigs[spl_object_hash($config)])) {
                $this->updatedConfigs[spl_object_hash($config)] = $config;
            }
        }
    }

    /**
     * @param null $scope
     * @return ConfigInterface[]|EntityConfigInterface[]
     */
    public function getUpdatedEntityConfig($scope = null)
    {
        return array_filter($this->updatedConfigs, function (ConfigInterface $config) use ($scope) {
            if (!$config instanceof EntityConfigInterface) {
                return false;
            }

            if ($scope && $config->getScope() != $scope) {
                return false;
            }

            return true;
        });
    }

    /**
     * @param null $className
     * @param null $scope
     * @return ConfigInterface[]|FieldConfigInterface[]
     */
    public function getUpdatedFieldConfig($scope = null, $className = null)
    {
        return array_filter($this->updatedConfigs, function (ConfigInterface $config) use ($className, $scope) {
            if (!$config instanceof FieldConfigInterface) {
                return false;
            }

            if ($className && $config->getClassName() != $className) {
                return false;
            }

            if ($scope && $config->getScope() != $scope) {
                return false;
            }

            return true;
        });
    }

    /**
     * @param ConfigInterface $config
     * @return array
     */
    public function getConfigChangeSet(ConfigInterface $config)
    {
        return isset($this->configChangeSets[$config->getConfigId()->getId()])
            ? $this->configChangeSets[$config->getConfigId()->getId()]
            : array();
    }

    /**
     * @param ConfigIdInterface $configId
     * @return AbstractConfigModel|null
     */
    protected function getConfigModelByConfigId(ConfigIdInterface $configId)
    {
        $fieldName = $configId instanceof FieldConfigId ? $configId->getFieldName() : 'null';

        return $this->getConfigModel($configId->getClassName(), $fieldName);
    }

    /**
     * @param      $className
     * @param null $fieldName
     * @return object|AbstractConfigModel
     */
    protected function getConfigModel($className, $fieldName = null)
    {
        $id = $className . $fieldName;

        if (isset($this->entities[$id])) {
            return $this->entities[$id];
        }

        $entityConfigRepo = $this->em()->getRepository(EntityConfigModel::ENTITY_NAME);
        $fieldConfigRepo  = $this->em()->getRepository(FieldConfigModel::ENTITY_NAME);

        $result = $entity = $entityConfigRepo->findOneBy(array('className' => $className));

        if ($fieldName) {
            $result = $fieldConfigRepo->findOneBy(
                array(
                    'entity'    => $result,
                    'fieldName' => $fieldName
                )
            );
        }

        return $result;
    }
}
