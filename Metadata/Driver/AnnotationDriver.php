<?php

namespace Oro\Bundle\EntityConfigBundle\Metadata\Driver;

use Doctrine\Common\Annotations\Reader;

use Metadata\Driver\DriverInterface;

use Oro\Bundle\EntityConfigBundle\Metadata\ConfigClassMetadata;

class AnnotationDriver implements DriverInterface
{
    /**
     * Annotation reader uses a full class pass for parsing
     */
    const CONFIGURABLE = 'Oro\\Bundle\\EntityConfigBundle\\Metadata\\Annotation\\Configurable';

    /**
     * @var Reader
     */
    protected $reader;

    /**
     * @param Reader $reader
     */
    public function __construct(Reader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * {@inheritdoc}
     */
    public function loadMetadataForClass(\ReflectionClass $class)
    {
        $metadata = new ConfigClassMetadata($class->getName());

        if ($this->reader->getClassAnnotation($class, self::CONFIGURABLE)) {
            $metadata->configurable = true;
        }

        return $metadata;
    }
}
