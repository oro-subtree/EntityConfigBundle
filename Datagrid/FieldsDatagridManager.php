<?php

namespace Oro\Bundle\EntityConfigBundle\Datagrid;

use Doctrine\ORM\Query;

use Oro\Bundle\GridBundle\Datagrid\ProxyQueryInterface;
use Oro\Bundle\GridBundle\Action\ActionInterface;
use Oro\Bundle\GridBundle\Datagrid\DatagridManager;
use Oro\Bundle\GridBundle\Field\FieldDescription;
use Oro\Bundle\GridBundle\Field\FieldDescriptionCollection;
use Oro\Bundle\GridBundle\Field\FieldDescriptionInterface;
use Oro\Bundle\GridBundle\Filter\FilterInterface;
use Oro\Bundle\GridBundle\Property\UrlProperty;

use Oro\Bundle\EntityConfigBundle\ConfigManager;

class FieldsDatagridManager extends DatagridManager
{
    /**
     * @var FieldDescriptionCollection
     */
    protected $fieldsCollection;

    /**
     * @var ConfigEntity id
     */
    protected $entityId;

    /**
     * @var ConfigManager
     */
    protected $configManager;

    public function __construct(ConfigManager $configManager)
    {
        $this->configManager = $configManager;
    }

    /**
     * @param $entityId
     * @return array
     */
    public function getLayoutActions($entityId)
    {
        $actions = array();
        foreach ($this->configManager->getProviders() as $provider) {
            foreach ($provider->getConfigContainer()->getFieldLayoutActions() as $config) {
                if (isset($config['entity_id']) && $config['entity_id'] == true) {
                    $config['args'] = array('id' => $entityId);
                }
                $actions[] = $config;
            }
        }

        return $actions;
    }

    /**
     * {@inheritDoc}
     */
    protected function getProperties()
    {
        $properties = array();
        foreach ($this->configManager->getProviders() as $provider) {
            foreach ($provider->getConfigContainer()->getFieldGridActions() as $config) {
                $properties[] = new UrlProperty(
                    strtolower($config['name']).'_link',
                    $this->router,
                    $config['route'],
                    (isset($config['args']) ? $config['args'] : array())
                );
            }
        }

        return $properties;
    }

    /**
     * {@inheritDoc}
     */
    protected function configureFields(FieldDescriptionCollection $fieldsCollection)
    {
        $fieldObjectId = new FieldDescription();
        $fieldObjectId->setName('Id');
        $fieldObjectId->setOptions(
            array(
                'type'        => FieldDescriptionInterface::TYPE_INTEGER,
                'label'       => 'Id',
                'field_name'  => 'id',
                'filter_type' => FilterInterface::TYPE_NUMBER,
                'required'    => false,
                'sortable'    => true,
                'filterable'  => false,
                'show_filter' => true,
            )
        );
        $fieldsCollection->add($fieldObjectId);

        $fieldEntityId = new FieldDescription();
        $fieldEntityId->setName('entity');
        $fieldEntityId->setOptions(
            array(
                'type'        => FieldDescriptionInterface::TYPE_INTEGER,
                'label'       => 'entity Id',
                'field_name'  => 'entity_id',
                'filter_type' => FilterInterface::TYPE_NUMBER,
                'required'    => false,
                'sortable'    => true,
                'filterable'  => false,
                'show_filter' => false,
            )
        );
        $fieldsCollection->add($fieldEntityId);

        $fieldObjectName = new FieldDescription();
        $fieldObjectName->setName('code');
        $fieldObjectName->setOptions(
            array(
                'type'        => FieldDescriptionInterface::TYPE_TEXT,
                'label'       => 'Field Name',
                'field_name'  => 'code',
                'filter_type' => FilterInterface::TYPE_STRING,
                'required'    => false,
                'sortable'    => true,
                'filterable'  => false,
                'show_filter' => false,
            )
        );
        $fieldsCollection->add($fieldObjectName);

        foreach ($this->configManager->getProviders() as $provider) {
            foreach ($provider->getConfigContainer()->getFieldItems() as $code => $item) {
                if (isset($item['grid'])) {
                    $fieldObjectName = new FieldDescription();
                    $fieldObjectName->setName($code);
                    $fieldObjectName->setOptions(array_merge($item['grid'], array(
                        'expression' => 'cfv_' . $code . '.value',
                        'field_name' => $code,
                    )));
                    $fieldsCollection->add($fieldObjectName);
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function getRowActions()
    {
        $actions = array();
        foreach ($this->configManager->getProviders() as $provider) {
            foreach ($provider->getConfigContainer()->getFieldGridActions() as $config) {
                $configItem = array(
                    'name'         => strtolower($config['name']),
                    'acl_resource' => isset($config['acl_resource']) ? $config['acl_resource'] : 'root',
                    'options'      => array(
                        'label' => ucfirst($config['name']),
                        'icon'  => isset($config['icon']) ? $config['icon'] : 'question-sign',
                        'link'  => strtolower($config['name']).'_link'
                    )
                );

                if (isset($config['type'])) {
                    switch ($config['type']) {
                        case 'delete':
                            $configItem['type'] = ActionInterface::TYPE_DELETE;
                        case 'redirect':
                            $configItem['type'] = ActionInterface::TYPE_REDIRECT;
                            break;
                    }
                } else {
                    $configItem['type'] = ActionInterface::TYPE_REDIRECT;
                }

                $actions[] = $configItem;
            }
        }

        return $actions;
    }

    /**
     * @param $id
     */
    public function setEntityId($id)
    {
        $this->entityId = $id;
    }

    /**
     * @return ProxyQueryInterface
     */
    protected function createQuery()
    {
        /** @var ProxyQueryInterface|Query $query */
        $query = parent::createQuery();
        $query->innerJoin('cf.entity', 'ce', 'WITH', 'ce.id=' . $this->entityId);
        $query->addSelect('ce.id as entity_id', true);

        foreach ($this->configManager->getProviders() as $provider) {
            foreach ($provider->getConfigContainer()->getFieldItems() as $code => $item) {
                $alias = 'cfv_'. $code;
                $query->leftJoin('cf.values', $alias, 'WITH', $alias . ".code='".$code . "' AND " . $alias . ".scope='" . $provider->getScope() . "'");
                $query->addSelect($alias . '.value as '. $code, true);
            }
        }

        return $query;
    }
}
