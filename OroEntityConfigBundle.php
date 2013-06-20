<?php

namespace Oro\Bundle\EntityConfigBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

use Oro\Bundle\EntityConfigBundle\DependencyInjection\Compiler\LazyServicePass;
use Oro\Bundle\EntityConfigBundle\DependencyInjection\Compiler\EntityConfigPass;

class OroEntityConfigBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new EntityConfigPass);
        $container->addCompilerPass(new LazyServicePass);
    }
}
