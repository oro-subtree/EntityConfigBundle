<?php

namespace Oro\Bundle\EntityConfigBundle;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

use Metadata\MetadataFactory;

use Oro\Bundle\EntityConfigBundle\Event\PersistConfigEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

use Oro\Bundle\EntityConfigBundle\Audit\AuditManager;
use Oro\Bundle\EntityConfigBundle\DependencyInjection\Proxy\ServiceProxy;
use Oro\Bundle\EntityConfigBundle\Cache\CacheInterface;
use Oro\Bundle\EntityConfigBundle\Exception\RuntimeException;
use Oro\Bundle\EntityConfigBundle\Metadata\ConfigClassMetadata;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;

use Oro\Bundle\EntityConfigBundle\Entity\ConfigEntity;
use Oro\Bundle\EntityConfigBundle\Entity\ConfigField;

use Oro\Bundle\EntityConfigBundle\Config\EntityConfigInterface;
use Oro\Bundle\EntityConfigBundle\Config\FieldConfigInterface;
use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;
use Oro\Bundle\EntityConfigBundle\Config\EntityConfig;
use Oro\Bundle\EntityConfigBundle\Config\FieldConfig;

use Oro\Bundle\EntityConfigBundle\Event\FlushConfigEvent;
use Oro\Bundle\EntityConfigBundle\Event\NewFieldEvent;
use Oro\Bundle\EntityConfigBundle\Event\NewEntityEvent;
use Oro\Bundle\EntityConfigBundle\Event\Events;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
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
     * @var ConfigInterface[]|\SplQueue
     */
    protected $persistConfigs;

    /**
     * @var ConfigInterface[]
     */
    protected $removeConfigs = array();

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
     * @var ConfigProvider[]
     */
    protected $providers = array();

    /**
     * @param MetadataFactory $metadataFactory
     * @param EventDispatcher $eventDispatcher
     * @param ServiceProxy    $proxyEm
     * @param ServiceProxy    $security
     */
    public function __construct(MetadataFactory $metadataFactory, EventDispatcher $eventDispatcher, ServiceProxy $proxyEm, ServiceProxy $security)
    {
        $this->persistConfigs  = new \SplQueue();
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
     * @param $className
     * @param $scope
     * @throws Exception\RuntimeException
     * @return EntityConfig
     */
    public function getConfig($className, $scope)
    {
        /** @var ConfigClassMetadata $metadata */
        $metadata = $this->metadataFactory->getMetadataForClass($className);
        if (!$metadata || $metadata->name != $className || !$metadata->configurable) {
            throw new RuntimeException(sprintf("Entity '%s' is not Configurable", $className));
        }

        $resultConfig = null;
        if (null !== $this->configCache
            && $config = $this->configCache->loadConfigFromCache($className, $scope)
        ) {
            $resultConfig = $config;
        } else {
            $entityConfigRepo = $this->em()->getRepository(ConfigEntity::ENTITY_NAME);

            /** @var ConfigEntity $entity */
            $entity = $this->isSchemaSynced() ? $entityConfigRepo->findOneBy(array('className' => $className)) : null;
            if ($entity) {
                $config = $this->entityToConfig($entity, $scope);

                if (null !== $this->configCache) {
                    $this->configCache->putConfigInCache($config);
                }

                $resultConfig = $config;
            } else {
                $resultConfig = new EntityConfig($className, $scope);
            }
        }

        $this->originalConfigs[spl_object_hash($resultConfig)] = clone $resultConfig;

        foreach ($resultConfig->getFields() as $field) {
            $this->originalConfigs[spl_object_hash($field)] = clone $field;
        }

        return $resultConfig;
    }

    /**
     * @param $className
     * @return bool
     */
    public function hasConfig($className)
    {
        /** @var ConfigClassMetadata $metadata */
        $metadata = $this->metadataFactory->getMetadataForClass($className);

        return $metadata ? ($metadata->configurable && $metadata->name == $className) : false;
    }

    /**
     * @param ClassMetadataInfo $doctrineMetadata
     */
    public function initConfigByDoctrineMetadata(ClassMetadataInfo $doctrineMetadata)
    {
        /** @var ConfigClassMetadata $metadata */
        $metadata = $this->metadataFactory->getMetadataForClass($doctrineMetadata->getName());
        if ($metadata
            && $metadata->name == $doctrineMetadata->getName()
            && $metadata->configurable
            && !$this->em()->getRepository(ConfigEntity::ENTITY_NAME)->findOneBy(array(
                'className' => $doctrineMetadata->getName()))
        ) {
            foreach ($this->getProviders() as $provider) {
                $defaultValues = $provider->getConfigContainer()->getEntityDefaultValues();
                if (isset($metadata->defaultValues[$provider->getScope()])) {
                    $defaultValues = array_merge($defaultValues, $metadata->defaultValues[$provider->getScope()]);
                }

                $provider->createEntityConfig($doctrineMetadata->getName(), $defaultValues);
            }

            $this->eventDispatcher->dispatch(
                Events::NEW_ENTITY,
                new NewEntityEvent($doctrineMetadata->getName(), $this)
            );

            foreach ($doctrineMetadata->getFieldNames() as $fieldName) {
                $type = $doctrineMetadata->getTypeOfField($fieldName);

                foreach ($this->getProviders() as $provider) {
                    $provider->createFieldConfig(
                        $doctrineMetadata->getName(),
                        $fieldName,
                        $type,
                        $provider->getConfigContainer()->getFieldDefaultValues()
                    );
                }

                $this->eventDispatcher->dispatch(
                    Events::NEW_FIELD,
                    new NewFieldEvent($doctrineMetadata->getName(), $fieldName, $type, $this)
                );
            }

            foreach ($doctrineMetadata->getAssociationNames() as $fieldName) {
                $type = $doctrineMetadata->isSingleValuedAssociation($fieldName) ? 'ref-one' : 'ref-many';

                foreach ($this->getProviders() as $provider) {
                    $provider->createFieldConfig(
                        $doctrineMetadata->getName(),
                        $fieldName,
                        $type,
                        $provider->getConfigContainer()->getFieldDefaultValues()
                    );
                }

                $this->eventDispatcher->dispatch(
                    Events::NEW_FIELD,
                    new NewFieldEvent($doctrineMetadata->getName(), $fieldName, $type, $this)
                );
            }
        }
    }

    /**
     * @param $className
     */
    public function clearCache($className)
    {
        if ($this->configCache) {
            foreach ($this->getProviders() as $provider) {
                $this->configCache->removeConfigFromCache($className, $provider->getScope());
            }
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
                    $configField = new ConfigField($config->getCode(), $config->getType());
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
        return isset($this->configChangeSets[spl_object_hash($config)]) ? $this->configChangeSets[spl_object_hash($config)] : array();
    }

    /**
     * @param ConfigEntity $entity
     * @param              $scope
     * @return EntityConfig
     */
    protected function entityToConfig(ConfigEntity $entity, $scope)
    {
        $config = new EntityConfig($entity->getClassName(), $scope);
        $config->setValues($entity->toArray($scope));

        foreach ($entity->getFields() as $field) {
            $fieldConfig = new FieldConfig($entity->getClassName(), $field->getCode(), $field->getType(), $scope);
            $fieldConfig->setValues($field->toArray($scope));
            $config->addField($fieldConfig);
        }

        return $config;
    }

    /**
     * @param $className
     * @return ConfigEntity
     */
    protected function findOrCreateConfigEntity($className)
    {
        $entityConfigRepo = $this->em()->getRepository(ConfigEntity::ENTITY_NAME);
        /** @var ConfigEntity $entity */
        $entity = $entityConfigRepo->findOneBy(array('className' => $className));
        if (!$entity) {
            $metadata = $this->metadataFactory->getMetadataForClass($className);
            $entity   = new ConfigEntity($className);
            $entity->setMode($metadata->viewMode);

            foreach ($this->getProviders() as $provider) {
                $provider->createEntityConfig(
                    $className,
                    $provider->getConfigContainer()->getEntityDefaultValues()
                );
            }

            $this->eventDispatcher->dispatch(
                Events::NEW_ENTITY,
                new NewEntityEvent($className, $this)
            );
        }

        return $entity;
    }

    /**
     * @return bool
     */
    protected function isSchemaSynced()
    {
        $tables = $this->em()->getConnection()->getSchemaManager()->listTableNames();
        $table  = $this->em()->getClassMetadata(ConfigEntity::ENTITY_NAME)->getTableName();

        return in_array($table, $tables);
    }
}
