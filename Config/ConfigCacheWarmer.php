<?php

namespace Oro\Bundle\EntityConfigBundle\Config;

use Doctrine\ORM\Mapping\ClassMetadata;

use Oro\Bundle\EntityBundle\Provider\VirtualFieldProviderInterface;
use Oro\Bundle\EntityBundle\Provider\VirtualRelationProviderInterface;
use Oro\Bundle\EntityConfigBundle\Entity\ConfigModel;

class ConfigCacheWarmer
{
    /** @var ConfigManager */
    protected $configManager;

    /** @var ConfigCache */
    protected $cache;

    /** @var EntityManagerBag */
    protected $entityManagerBag;

    /** @var VirtualFieldProviderInterface */
    protected $virtualFieldProvider;

    /** @var VirtualRelationProviderInterface */
    protected $virtualRelationProvider;

    /**
     * @param ConfigManager                    $configManager
     * @param ConfigCache                      $cache
     * @param EntityManagerBag                 $entityManagerBag
     * @param VirtualFieldProviderInterface    $virtualFieldProvider
     * @param VirtualRelationProviderInterface $virtualRelationProvider
     */
    public function __construct(
        ConfigManager $configManager,
        ConfigCache $cache,
        EntityManagerBag $entityManagerBag,
        VirtualFieldProviderInterface $virtualFieldProvider,
        VirtualRelationProviderInterface $virtualRelationProvider
    ) {
        $this->configManager           = $configManager;
        $this->cache                   = $cache;
        $this->entityManagerBag        = $entityManagerBag;
        $this->virtualFieldProvider    = $virtualFieldProvider;
        $this->virtualRelationProvider = $virtualRelationProvider;
    }

    /**
     * Warms up the configuration data cache.
     */
    public function warmUpCache()
    {
        $this->loadConfigurable();
        $this->loadNonConfigurable();
        $this->loadVirtualFields();
    }

    protected function loadConfigurable()
    {
        $emptyData = [];
        foreach ($this->configManager->getProviders() as $scope => $provider) {
            $emptyData[$scope] = [];
        }

        $entityRows = $this->configManager->getEntityManager()
            ->getRepository('Oro\Bundle\EntityConfigBundle\Entity\EntityConfigModel')
            ->createQueryBuilder('e')
            ->select('e.id, e.className, e.mode, e.data')
            ->getQuery()
            ->getArrayResult();
        $fieldRows  = $this->configManager->getEntityManager()
            ->getRepository('Oro\Bundle\EntityConfigBundle\Entity\FieldConfigModel')
            ->createQueryBuilder('f')
            ->select('IDENTITY(f.entity) AS entityId, f.fieldName, f.type, f.mode, f.data')
            ->getQuery()
            ->getArrayResult();

        $classMap = [];
        $entities = [];
        $fields   = [];
        foreach ($entityRows as $row) {
            $entityId  = $row['id'];
            $className = $row['className'];
            $isHidden  = $row['mode'] === ConfigModel::MODE_HIDDEN;
            $data      = array_merge($emptyData, $row['data']);

            $classMap[$entityId]  = $className;
            $entities[$className] = $isHidden;

            $this->cache->saveConfigurable(true, $className);
            $this->cache->saveEntityConfigValues($data, $className);
        }
        foreach ($fieldRows as $row) {
            $entityId = $row['entityId'];
            if (!isset($classMap[$entityId])) {
                continue;
            }
            $className = $classMap[$entityId];
            $fieldName = $row['fieldName'];
            $fieldType = $row['type'];
            $isHidden  = $row['mode'] === ConfigModel::MODE_HIDDEN;
            $data      = array_merge($emptyData, $row['data']);

            $fields[$className][$fieldName] = ['t' => $fieldType, 'h' => $isHidden];

            $this->cache->saveConfigurable(true, $className, $fieldName);
            $this->cache->saveFieldConfigValues($data, $className, $fieldName, $fieldType);
        }

        $this->cache->saveEntities($entities);
        foreach ($fields as $className => $values) {
            $this->cache->saveFields($className, $values);
        }
    }

    protected function loadNonConfigurable()
    {
        $cached = $this->cache->getEntities();
        foreach ($this->entityManagerBag->getEntityManagers() as $em) {
            /** @var ClassMetadata $metadata */
            foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
                if ($metadata->isMappedSuperclass) {
                    continue;
                }

                $className = $metadata->getName();
                if (!isset($cached[$className]) && null === $this->cache->getConfigurable($className)) {
                    $this->cache->saveConfigurable(false, $className);
                    foreach ($metadata->getFieldNames() as $fieldName) {
                        $this->cache->saveConfigurable(false, $className, $fieldName);
                    }
                    foreach ($metadata->getAssociationNames() as $fieldName) {
                        $this->cache->saveConfigurable(false, $className, $fieldName);
                    }
                }
            }
        }
    }

    protected function loadVirtualFields()
    {
        foreach ($this->cache->getEntities() as $className => $isHidden) {
            $virtualFields = $this->virtualFieldProvider->getVirtualFields($className);
            if (!empty($virtualFields)) {
                foreach ($virtualFields as $fieldName) {
                    if (null === $this->cache->getConfigurable($className, $fieldName)) {
                        $this->cache->saveConfigurable(false, $className, $fieldName);
                    }
                }
            }
            $virtualRelations = $this->virtualRelationProvider->getVirtualRelations($className);
            if (!empty($virtualRelations)) {
                foreach ($virtualRelations as $fieldName => $config) {
                    if (null === $this->cache->getConfigurable($className, $fieldName)) {
                        $this->cache->saveConfigurable(false, $className, $fieldName);
                    }
                }
            }
        }
    }
}
