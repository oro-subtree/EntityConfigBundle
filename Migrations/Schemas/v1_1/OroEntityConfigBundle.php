<?php

namespace Oro\Bundle\EntityConfigBundle\Migrations\Schemas\v1_1;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\InstallerBundle\Migrations\Migration;

class OroEntityConfigBundle implements Migration
{
    /**
     * @inheritdoc
     */
    public function up(Schema $schema)
    {
        return [
            "RENAME TABLE oro_entity_config_optionset_relation TO oro_entity_config_optset_rel;",
        ];
    }
}
