<?php

namespace Oro\Bundle\EntityConfigBundle\ImportExport\Serializer;

use Doctrine\Common\Persistence\ManagerRegistry;

use Oro\Bundle\EntityConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityConfigBundle\Config\ConfigModelManager;
use Oro\Bundle\EntityConfigBundle\Entity\EntityConfigModel;
use Oro\Bundle\EntityConfigBundle\Entity\FieldConfigModel;
use Oro\Bundle\EntityExtendBundle\Provider\FieldTypeProvider;
use Oro\Bundle\ImportExportBundle\Serializer\Normalizer\DenormalizerInterface;
use Oro\Bundle\ImportExportBundle\Serializer\Normalizer\NormalizerInterface;

class EntityFieldNormalizer implements NormalizerInterface, DenormalizerInterface
{
    const TYPE_BOOLEAN = 'boolean';
    const TYPE_INTEGER = 'integer';
    const TYPE_STRING = 'string';
    const TYPE_ENUM = 'enum';

    const CONFIG_TYPE = 'value_type';
    const CONFIG_DEFAULT= 'default_value';

    /** @var ManagerRegistry */
    protected $registry;

    /** @var ConfigManager */
    protected $configManager;

    /** @var ConfigModelManager */
    protected $configModelManager;

    /** @var FieldTypeProvider */
    protected $fieldTypeProvider;

    /**
     * @param ManagerRegistry $registry
     */
    public function setRegistry(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param ConfigManager $configManager
     */
    public function setConfigManager(ConfigManager $configManager)
    {
        $this->configManager = $configManager;
    }

    /**
     * @param ConfigModelManager $configModelManager
     */
    public function setConfigModelManager(ConfigModelManager $configModelManager)
    {
        $this->configModelManager = $configModelManager;
    }

    /**
     * @param FieldTypeProvider $fieldTypeProvider
     */
    public function setFieldTypeProvider(FieldTypeProvider $fieldTypeProvider)
    {
        $this->fieldTypeProvider = $fieldTypeProvider;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null, array $context = [])
    {
        return $data instanceof FieldConfigModel;
    }

    /**
     * {@inheritdoc}
     */
    public function normalize($object, $format = null, array $context = [])
    {
        $result = [
            'id' => $object->getId(),
            'fieldName' => $object->getFieldName(),
            'type' => $object->getType(),
        ];

        foreach ($this->configManager->getProviders() as $provider) {
            $scope = $provider->getScope();

            foreach ($object->toArray($scope) as $code => $value) {
                $result[sprintf('%s.%s', $scope, $code)] = $value;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization($data, $type, $format = null, array $context = [])
    {
        $supportedTypes = $this->fieldTypeProvider->getSupportedFieldTypes();

        return is_array($data) &&
            array_key_exists('type', $data) &&
            in_array($data['type'], $supportedTypes, true) &&
            array_key_exists('fieldName', $data) &&
            is_a($type, 'Oro\Bundle\EntityConfigBundle\Entity\FieldConfigModel', true);
    }

    /**
     * {@inheritdoc}
     */
    public function denormalize($data, $class, $format = null, array $context = [])
    {
        $fieldType = $data['type'];
        $fieldName = $data['fieldName'];
        $entity = $this->getEntityConfigModel($data['entity']['id']);

        $model = $this->configModelManager->createFieldModel($entity->getClassName(), $fieldName, $fieldType);

        $options = [];
        foreach ($data as $key => $value) {
            $this->extractAndAppendKeyValue($options, $key, $value);
        }

        $this->updateModelConfig($model, $options);

        return $model;
    }

    /**
     * @param int $entityId
     * @return EntityConfigModel
     */
    protected function getEntityConfigModel($entityId)
    {
        $entityClassName = 'Oro\Bundle\EntityConfigBundle\Entity\EntityConfigModel';

        return $this->registry->getManagerForClass($entityClassName)->find($entityClassName, $entityId);
    }

    /**
     * @param FieldConfigModel $model
     * @param array $options
     */
    protected function updateModelConfig(FieldConfigModel $model, array $options)
    {
        $fieldProperties = $this->fieldTypeProvider->getFieldProperties($model->getType());
        foreach ($fieldProperties as $scope => $properties) {
            $values = [];

            foreach ($properties as $code => $config) {
                if (!isset($options[$scope][$code])) {
                    continue;
                }

                $values[$code] = $this->denormalizeFieldValue(
                    isset($config['options']) ? $config['options'] : [],
                    $options[$scope][$code]
                );
            }

            $model->fromArray($scope, $values, []);
        }
    }

    /**
     * @param array $config
     * @param mixed $value
     * @return mixed
     */
    public function denormalizeFieldValue($config, $value)
    {
        if ($value === null && array_key_exists(self::CONFIG_DEFAULT, $config)) {
            return $config[self::CONFIG_DEFAULT];
        }

        $type = array_key_exists(self::CONFIG_TYPE, $config) ? $config[self::CONFIG_TYPE] : null;
        $result = null;

        switch ($type) {
            case self::TYPE_BOOLEAN:
                $result = $this->normalizeBoolValue($value);
                break;
            case self::TYPE_ENUM:
                $result = $this->normalizeEnumValue($value);
                break;
            case self::TYPE_INTEGER:
                $result = (int)$value;
                break;
            default:
                $result = (string)$value;
                break;
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    protected function normalizeBoolValue($value)
    {
        $lvalue = strtolower($value);
        if (in_array($lvalue, ['yes', 'no', 'true', 'false'], true)) {
            $value = str_replace(['yes', 'no', 'true', 'false'], [true, false, true, false], $lvalue);
        }

        return (bool)$value;
    }

    /**
     * @param mixed $value
     * @return array
     */
    protected function normalizeEnumValue($value)
    {
        $updatedValue = [];
        foreach ($value as $key => $subvalue) {
            $updatedValue[$key] = [];
            foreach ($this->getEnumConfig() as $subfield => $subconfig) {
                $updatedValue[$key][$subfield]= $this->denormalizeFieldValue($subconfig, $subvalue[$subfield]);
            }
        }

        return $updatedValue;
    }

    /**
     * @param array $array
     * @param string $key
     * @param mixed $value
     * @return boolean
     */
    protected function extractAndAppendKeyValue(&$array, $key, $value)
    {
        if (false === strpos($key, '.')) {
            return false;
        }

        $parts = explode('.', $key);

        $current = &$array;
        foreach ($parts as $part) {
            if (!isset($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }
        $current = $value;

        return true;
    }

    /**
     * @return array
     */
    protected function getEnumConfig()
    {
        return [
            'label' => [
                self::CONFIG_TYPE => self::TYPE_STRING
            ],
            'is_default' => [
                self::CONFIG_TYPE => self::TYPE_BOOLEAN,
                self::CONFIG_DEFAULT => false,
            ],
        ];
    }
}
