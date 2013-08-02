<?php

namespace Oro\Bundle\EntityConfigBundle\Tests\Unit;

use Doctrine\Common\Annotations\AnnotationReader;

use Metadata\MetadataFactory;

use Symfony\Component\EventDispatcher\EventDispatcher;

use Oro\Bundle\EntityConfigBundle\Config\Config;
use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;
use Oro\Bundle\EntityConfigBundle\Config\Id\EntityConfigId;
use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;

use Oro\Bundle\EntityConfigBundle\Entity\FieldConfigModel;
use Oro\Bundle\EntityConfigBundle\Entity\EntityConfigModel;

use Oro\Bundle\EntityConfigBundle\ConfigManager;
use Oro\Bundle\EntityConfigBundle\DependencyInjection\EntityConfigContainer;
use Oro\Bundle\EntityConfigBundle\Metadata\Driver\AnnotationDriver;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\EntityConfigBundle\Tests\Unit\Fixture\Repository\NotFoundEntityConfigRepository;

class ConfigManagerTest extends AbstractEntityManagerTest
{
    const DEMO_ENTITY                        = 'Oro\Bundle\EntityConfigBundle\Tests\Unit\Fixture\DemoEntity';
    const NO_CONFIGURABLE_ENTITY             = 'Oro\Bundle\EntityConfigBundle\Tests\Unit\Fixture\NoConfigurableEntity';
    const NOT_FOUND_CONFIG_ENTITY_REPOSITORY = 'Oro\Bundle\EntityConfigBundle\Tests\Unit\Fixture\Repository\NotFoundEntityConfigRepository';
    const FOUND_CONFIG_ENTITY_REPOSITORY     = 'Oro\Bundle\EntityConfigBundle\Tests\Unit\Fixture\Repository\FoundEntityConfigRepository';

    /**
     * @var ConfigManager
     */
    private $configManager;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $configCache;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $emProxy;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $securityProxy;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $provider;

    private $securityContext;

    public function setUp()
    {
        parent::setUp();

        $user = $this->getMockBuilder('Oro\Bundle\UserBundle\Entity\User')
            ->disableOriginalConstructor()
            ->getMock();

        $this->securityContext = $this->getMockForAbstractClass('Symfony\Component\Security\Core\SecurityContextInterface');

        $token = $this->getMockForAbstractClass('Symfony\Component\Security\Core\Authentication\Token\TokenInterface');

        $token->expects($this->any())->method('getUser')->will($this->returnValue($user));

        $this->securityContext->expects($this->any())->method('getToken')->will($this->returnValue($token));

        $this->securityProxy = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\DependencyInjection\Proxy\ServiceProxy')
            ->disableOriginalConstructor()
            ->getMock();
        $this->securityProxy->expects($this->any())->method('getService')->will($this->returnValue($this->securityContext));

        $this->initConfigManager();

        $this->configCache = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Cache\FileCache')
            ->disableOriginalConstructor()
            ->getMock();

        $this->configCache->expects($this->any())->method('putConfigInCache')->will($this->returnValue(null));

        $this->provider = new ConfigProvider($this->configManager, new EntityConfigContainer('test', array()));
    }

    public function testGetConfigFoundConfigEntity()
    {
        $meta = $this->em->getClassMetadata(EntityConfigModel::ENTITY_NAME);
        $meta->setCustomRepositoryClass(self::FOUND_CONFIG_ENTITY_REPOSITORY);

        $configId = new EntityConfigId(self::DEMO_ENTITY, 'test');
        $this->configManager->getConfig($configId);
    }

    /**
     * @expectedException \Oro\Bundle\EntityConfigBundle\Exception\RuntimeException
     * @expectedExceptionMessage ConfigEntity Entity "Oro\Bundle\EntityConfigBundle\Tests\Unit\Fixture\DemoEntity" is not found
     */
    public function testGetConfigNotFoundConfigEntity()
    {
        $meta = $this->em->getClassMetadata(EntityConfigModel::ENTITY_NAME);
        $meta->setCustomRepositoryClass(self::NOT_FOUND_CONFIG_ENTITY_REPOSITORY);

        $configId = new EntityConfigId(self::DEMO_ENTITY, 'test');
        $this->configManager->getConfig($configId);
    }

    /**
     * @expectedException \Oro\Bundle\EntityConfigBundle\Exception\RuntimeException
     * @expectedExceptionMessage Entity "Oro\Bundle\EntityConfigBundle\Tests\Unit\Fixture\NoConfigurableEntity" is not Configurable
     */
    public function testGetConfigRuntimeException()
    {
        $this->configManager->getConfig(new EntityConfigId(self::NO_CONFIGURABLE_ENTITY, 'test'));
    }

    public function testGetConfigNotFoundCache()
    {
        $this->configCache->expects($this->any())->method('loadConfigFromCache')->will($this->returnValue(null));

        $meta = $this->em->getClassMetadata(EntityConfigModel::ENTITY_NAME);
        $meta->setCustomRepositoryClass(self::FOUND_CONFIG_ENTITY_REPOSITORY);

        $this->configManager->setCache($this->configCache);

        $this->configManager->getConfig(new EntityConfigId(self::DEMO_ENTITY, 'test'));
    }

    public function testGetConfigFoundCache()
    {
        $configId     = new EntityConfigId(self::DEMO_ENTITY, 'test');
        $entityConfig = new Config($configId);
        $this->configCache->expects($this->any())->method('loadConfigFromCache')->will($this->returnValue($entityConfig));

        $this->configManager->setCache($this->configCache);

        $this->assertEquals($entityConfig, $this->configManager->getConfig($configId, 'test'));
    }

    public function testHasConfig()
    {
        $this->assertEquals(true, $this->configManager->hasConfig(new EntityConfigId(self::DEMO_ENTITY, 'test')));
    }

    public function testGetEventDispatcher()
    {
        $this->assertInstanceOf('Symfony\Component\EventDispatcher\EventDispatcher', $this->configManager->getEventDispatcher());
    }

    public function testClearCache()
    {
        $meta = $this->em->getClassMetadata(EntityConfigModel::ENTITY_NAME);
        $meta->setCustomRepositoryClass(self::FOUND_CONFIG_ENTITY_REPOSITORY);
        $this->configManager->getConfig(new EntityConfigId(self::DEMO_ENTITY, 'test'));

        $this->configCache->expects($this->any())->method('removeConfigFromCache')->will($this->returnValue(null));
        $this->configManager->setCache($this->configCache);
        $this->configManager->addProvider($this->provider);

        $this->configManager->clearCache(new EntityConfigId(self::DEMO_ENTITY, 'test'));
    }

    public function testAddAndGetProvider()
    {
        $this->configManager->addProvider($this->provider);

        $providers = $this->configManager->getProviders();
        $provider  = $this->configManager->getProvider('test');

        $this->assertEquals(array('test' => $this->provider), $providers);
        $this->assertEquals($this->provider, $provider);
    }


    public function testPersist()
    {
        $config = new Config(new EntityConfigId(self::DEMO_ENTITY, 'test'));

        $this->configManager->persist($config);
    }

    public function testMerge()
    {
        $config = new Config(new EntityConfigId(self::DEMO_ENTITY, 'test'));

        $this->configManager->merge($config);
    }


    public function testFlush()
    {
        $meta = $this->em->getClassMetadata(EntityConfigModel::ENTITY_NAME);

        $this->em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->em->expects($this->any())->method('flush')->will($this->returnValue(null));
        $this->em->expects($this->any())->method('getRepository')
            ->will($this->returnValue(new NotFoundEntityConfigRepository($this->em, $meta)));

        $this->initConfigManager();

        $this->configManager->addProvider($this->provider);

        $this->configCache->expects($this->any())->method('removeConfigFromCache')->will($this->returnValue(null));
        $this->configManager->setCache($this->configCache);

        $configEntity = new Config(new EntityConfigId(self::DEMO_ENTITY, 'test'));
        $configField  = new Config(new FieldConfigId(self::DEMO_ENTITY, 'test', 'test', 'string'));

        $this->configManager->persist($configEntity);
        $this->configManager->persist($configField);

        $this->configManager->flush();
    }

    public function testChangeSet()
    {
        $meta = $this->em->getClassMetadata(EntityConfigModel::ENTITY_NAME);
        $meta->setCustomRepositoryClass(self::FOUND_CONFIG_ENTITY_REPOSITORY);

        $meta = $this->em->getClassMetadata(FieldConfigModel::ENTITY_NAME);
        $meta->setCustomRepositoryClass(self::FOUND_CONFIG_ENTITY_REPOSITORY);

        $config      = $this->configManager->getConfig(new EntityConfigId(self::DEMO_ENTITY, 'test'));
        $configField = $this->configManager->getConfig(new FieldConfigId(self::DEMO_ENTITY, 'test', 'test', 'string'));

        $configField->set('test_field_value1', 'test_field_value1_new');

        $config->set('test_value', 'test_value_new');
        $config->set('test_value1', 'test_value1_new');

        $config->set('test_value_serializable', array('test_value' => 'test_value_new'));

        $this->configManager->calculateConfigChangeSet($config);
        $this->configManager->calculateConfigChangeSet($configField);

        $result = array(
            'test_value'              => array('test_value_origin', 'test_value_new'),
            'test_value_serializable' => array(
                array('test_value' => 'test_value_origin'),
                array('test_value' => 'test_value_new')
            ),
            'test_value1'             => array(null, 'test_value1_new'),
        );

        $this->assertEquals($result, $this->configManager->getConfigChangeSet($config));
    }

    protected function initConfigManager()
    {
        $metadataFactory = new MetadataFactory(new AnnotationDriver(new AnnotationReader));

        $this->emProxy = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\DependencyInjection\Proxy\ServiceProxy')
            ->disableOriginalConstructor()
            ->getMock();
        $this->emProxy->expects($this->any())->method('getService')->will($this->returnValue($this->em));

        $this->configManager = new ConfigManager($metadataFactory, new EventDispatcher, $this->emProxy, $this->securityProxy);
    }
}
