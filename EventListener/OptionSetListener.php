<?php

namespace Oro\Bundle\EntityConfigBundle\EventListener;

use Doctrine\Common\Inflector\Inflector;

use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;

use Oro\Bundle\EntityBundle\ORM\OroEntityManager;
use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;
use Oro\Bundle\EntityConfigBundle\Entity\OptionSet;
use Oro\Bundle\EntityConfigBundle\Entity\OptionSetRelation;

/**
 * Class OptionSetListener
 * @package Oro\Bundle\EntityConfigBundle\EventListener
 *
 * - needed by entity extend bundle functionality
 * - listen to doctrine PostPersist event
 * - determinate if NEW optionSet field type model have been created (field create action)
 * - persists and flush option relations for created OptionSet
 */
class OptionSetListener
{
    protected $needFlush = false;

    /**
     * @param LifecycleEventArgs $event
     */
    public function postPersist(LifecycleEventArgs $event)
    {
        /** @var OroEntityManager $em */
        $em             = $event->getEntityManager();
        $entity         = $event->getEntity();
        $configProvider = $em->getExtendConfigProvider();

        $className = get_class($entity);
        if (!$configProvider->hasConfig($className)) {
            return;
        }

        $config = $configProvider->getConfig($className);
        $schema = $config->get('schema');
        if (!isset($schema['relation'])) {
            return;
        }

        foreach ($schema['relation'] as $fieldName) {
            if (!$configProvider->hasConfig($className, $fieldName)) {
                continue;
            }
            /** @var ConfigInterface $fieldConfig */
            $fieldConfig = $configProvider->getConfig($className, $fieldName);
            $options     = $this->getEntityFieldData($fieldConfig, $fieldName, $entity);
            if (!$options) {
                continue;
            }

            $model = $configProvider->getConfigManager()->getConfigFieldModel(
                $fieldConfig->getId()->getClassName(),
                $fieldConfig->getId()->getFieldName()
            );

            /**
             * in case of single select field type, should wrap value in array
             */
            if (!is_array($options)) {
                $options = [$options];
            }

            foreach ($options as $option) {
                $optionSetRelation = new OptionSetRelation();
                $optionSetRelation->setData(
                    null,
                    $entity->getId(),
                    $model,
                    $em->getRepository(OptionSet::ENTITY_NAME)->find($option)
                );

                $em->persist($optionSetRelation);
                $this->needFlush = true;
            }
        }
    }

    /**
     * @param ConfigInterface $fieldConfig
     * @param string          $fieldName
     * @param object          $entity
     * @return null|mixed
     */
    protected function getEntityFieldData(ConfigInterface $fieldConfig, $fieldName, $entity)
    {
        $getterName = Inflector::camelize('get_' . $fieldName);
        if ($fieldConfig->getId()->getFieldType() != 'optionSet'
            || !method_exists($entity, $getterName)
            || !$setData = $entity->$getterName()
        ) {
            return null;
        }

        return $setData;
    }

    /**
     * @param PostFlushEventArgs $eventArgs
     */
    public function postFlush(PostFlushEventArgs $eventArgs)
    {
        if ($this->needFlush) {
            $this->needFlush = false;
            $eventArgs->getEntityManager()->flush();
        }
    }
}
