<?php

namespace Oro\Bundle\EntityConfigBundle\Datagrid;

use Oro\Bundle\GridBundle\Datagrid\ProxyQueryInterface;

class AuditDatagridManager extends AuditDatagrid
{
    /**
     * @var int
     */
    public $entityClassId;

    /**
     * @param ProxyQueryInterface $query
     * @return ProxyQueryInterface|void
     */
    protected function prepareQuery(ProxyQueryInterface $query)
    {
        parent::prepareQuery($query);

        $query->where('diff.className = :className AND diff.fieldName IS NULL');
        $query->setParameter('className', $this->entityClass);

        return $query;
    }
}