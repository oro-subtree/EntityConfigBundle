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

use Oro\Bundle\EntityConfigBundle\Entity\ConfigEntity;
use Oro\Bundle\EntityConfigBundle\ConfigManager;

class FieldsDatagridManager extends FieldsDatagrid
{
    /**
     * {@inheritDoc}
     */
    protected function configureFields(FieldDescriptionCollection $fieldsCollection)
    {
        $fieldObjectName = new FieldDescription();
        $fieldObjectName->setName('code');
        $fieldObjectName->setOptions(
            array(
                'type'        => FieldDescriptionInterface::TYPE_TEXT,
                'label'       => 'Code',
                'field_name'  => 'code',
                'filter_type' => FilterInterface::TYPE_STRING,
                'required'    => false,
                'sortable'    => true,
                'filterable'  => true,
                'show_filter' => false,
            )
        );
        $fieldsCollection->add($fieldObjectName);

        $this->addDynamicRows($fieldsCollection);

        $fieldObjectCreate = new FieldDescription();
        $fieldObjectCreate->setName('created');
        $fieldObjectCreate->setOptions(
            array(
                'type'        => FieldDescriptionInterface::TYPE_DATETIME,
                'label'       => 'Create At',
                'field_name'  => 'created',
                'filter_type' => FilterInterface::TYPE_DATETIME,
                'required'    => true,
                'sortable'    => true,
                'filterable'  => false,
                'show_filter' => false,
            )
        );
        $fieldsCollection->add($fieldObjectCreate);

        $fieldObjectUpdate = new FieldDescription();
        $fieldObjectUpdate->setName('updated');
        $fieldObjectUpdate->setOptions(
            array(
                'type'        => FieldDescriptionInterface::TYPE_DATETIME,
                'label'       => 'Update At',
                'field_name'  => 'updated',
                'filter_type' => FilterInterface::TYPE_DATETIME,
                'required'    => false,
                'sortable'    => true,
                'filterable'  => true,
                'show_filter' => false,
            )
        );
        $fieldsCollection->add($fieldObjectUpdate);
    }
}
