<?php

namespace Oro\Bundle\EntityConfigBundle;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManager;

use Metadata\ClassHierarchyMetadata;
use Metadata\MetadataFactory;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

use Oro\Bundle\EntityConfigBundle\DependencyInjection\Proxy\ServiceProxy;

use Oro\Bundle\EntityConfigBundle\Cache\CacheInterface;
use Oro\Bundle\EntityConfigBundle\Exception\RuntimeException;

use Oro\Bundle\EntityConfigBundle\Entity\ConfigEntity;
use Oro\Bundle\EntityConfigBundle\Entity\ConfigField;

use Oro\Bundle\EntityConfigBundle\Config\FieldConfigInterface;
use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;
use Oro\Bundle\EntityConfigBundle\Config\EntityConfig;
use Oro\Bundle\EntityConfigBundle\Config\FieldConfig;

use Oro\Bundle\EntityConfigBundle\Event\FieldConfigEvent;
use Oro\Bundle\EntityConfigBundle\Event\EntityConfigEvent;
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
     * @var CacheInterface
     */
    protected $configCache;

    /**
     * @var EntityConfig[]
     */
    protected $persistEntityConfig = array();

    /**
     * @var FieldConfig[]
     */
    protected $persistFieldConfig = array();

    /**
     * @param MetadataFactory $metadataFactory
     * @param ServiceProxy    $proxyEm
     */
    public function __construct(MetadataFactory $metadataFactory, ServiceProxy $proxyEm)
    {
        $this->metadataFactory = $metadataFactory;
        $this->proxyEm         = $proxyEm;
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
     * @return EventDispatcher
     */
    public function dispatcher()
    {
        return $this->proxyEm->get('event_dispatcher');
    }

    /**
     * @param $className
     * @param $scope
     * @throws Exception\RuntimeException
     * @return EntityConfig
     */
    public function getConfig($className, $scope)
    {
        /** @var ClassHierarchyMetadata $metadata */
        $metadata = $this->metadataFactory->getMetadataForClass($className);
        if (!$metadata->getOutsideClassMetadata()->configurable) {
            throw new RuntimeException(sprintf("Entity '%s' is not Configurable", $className));
        }

        if (null !== $this->configCache
            && $config = $this->configCache->loadConfigFromCache($className, $scope)
        ) {
            return $config;
        } else {
            $entityConfigRepo = $this->em()->getRepository(ConfigEntity::ENTITY_NAME);
            /** @var ConfigEntity $entity */
            $entity = $entityConfigRepo->findOneBy(array('className' => $className));
            if ($entity) {
                $config = new EntityConfig($className, $scope);
                $config->setValues($entity->toArray($scope));

                foreach ($entity->getFields() as $field) {
                    $fieldConfig = new FieldConfig($className, $field->getCode(), $field->getType(), $scope);
                    $fieldConfig->setValues($field->toArray($scope));
                    $config->addField($fieldConfig);
                }

                if (null !== $this->configCache) {
                    $this->configCache->putConfigInCache($config);
                }

                return $config;
            } else {
                return new EntityConfig($className, $scope);
            }
        }
    }

    public function hasConfig($className)
    {
        /** @var ClassHierarchyMetadata $metadata */
        $metadata = $this->metadataFactory->getMetadataForClass($className);

        return $metadata->getOutsideClassMetadata()->configurable;
    }

    public function updateAll()
    {
        /** @var $doctrineMetadata ClassMetadata */
        $entities = array();
        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $doctrineMetadata) {
            /** @var ClassHierarchyMetadata $metadata */
            $metadata = $this->metadataFactory->getMetadataForClass($doctrineMetadata->getName());
            if ($metadata->getOutsideClassMetadata()->configurable
                && !$this->em()->getRepository(ConfigEntity::ENTITY_NAME)->findOneBy(array(
                    'className' => $doctrineMetadata->getName()))
            ) {
                // listeners can add their configs for new ConfigEntity
                $entity = new ConfigEntity($doctrineMetadata->getName());

                $this->dispatcher()->dispatch(
                    Events::newEntityConfig,
                    new EntityConfigEvent($doctrineMetadata->getName(), $this)
                );

                foreach ($doctrineMetadata->getFieldNames() as $fieldName) {
                    $type = $doctrineMetadata->getTypeOfField($fieldName);
                    $entity->addFiled(new ConfigField($fieldName, $type));
                    $this->dispatcher()->dispatch(
                        Events::newFieldConfig,
                        new FieldConfigEvent($doctrineMetadata->getName(), $fieldName, $type, $this)
                    );
                }

                foreach ($doctrineMetadata->getAssociationNames() as $fieldName) {
                    $type = $doctrineMetadata->isSingleValuedAssociation($fieldName) ? 'ref-one' : 'ref-many';
                    $entity->addFiled(new ConfigField($fieldName, $type));
                    $this->dispatcher()->dispatch(
                        Events::newFieldConfig,
                        new FieldConfigEvent($doctrineMetadata->getName(), $fieldName, $type, $this)
                    );
                }

                $entities[$entity->getClassName()] = $entity;
            }
        }

        $this->flush($entities);
    }

    /**
     * @param ConfigInterface $config
     */
    public function persist(ConfigInterface $config)
    {
        if ($config instanceof FieldConfigInterface) {
            $this->persistFieldConfig[] = $config;
        } else {
            $this->persistEntityConfig[] = $config;
        }
    }

    public function flush(array $entities = array())
    {
        foreach ($this->persistEntityConfig as $entityConfig) {
            $className = $entityConfig->getClassName();
            if (isset($entities[$className])) {
                $configEntity = $entities[$className];
            } else {
                $configEntity = $entities[$className] = $this->findOrCreateConfigEntity($className);
            }

            $configEntity->fromArray($entityConfig->getScope(), $entityConfig->getValues());
        }

        foreach ($this->persistFieldConfig as $fieldConfig) {
            $className = $fieldConfig->getClassName();
            if (isset($entities[$className])) {
                $configEntity = $entities[$className];
            } else {
                $configEntity = $entities[$className] = $this->findOrCreateConfigEntity($className);
            }

            if (!$field = $configEntity->getField($fieldConfig->getCode())) {
                $field = new ConfigField($fieldConfig->getCode(), $field->getType());
                $configEntity->addFiled($field);
            }

            $field->fromArray($fieldConfig->getScope(), $fieldConfig->getValues());
        }

        foreach ($entities as $entity) {
            $this->em()->persist($entity);
        }

        $this->em()->flush();
    }

    protected function findOrCreateConfigEntity($className)
    {
        $entityConfigRepo = $this->em()->getRepository(ConfigEntity::ENTITY_NAME);
        /** @var ConfigEntity $entity */
        $entity = $entityConfigRepo->findOneBy(array('className' => $className));
        if (!$entity) {
            $entity = new ConfigEntity($className);
        }

        return $entity;
    }
}
