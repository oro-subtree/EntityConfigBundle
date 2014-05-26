<?php

namespace Oro\Bundle\EntityConfigBundle\Form\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

use Oro\Bundle\EntityBundle\ORM\OroEntityManager;
use Oro\Bundle\EntityConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityConfigBundle\Entity\FieldConfigModel;
use Oro\Bundle\TranslationBundle\Entity\Translation;
use Oro\Bundle\TranslationBundle\Entity\Repository\TranslationRepository;
use Oro\Bundle\TranslationBundle\Translation\Translator;
use Oro\Bundle\TranslationBundle\Translation\DynamicTranslationMetadataCache;

class ConfigSubscriber implements EventSubscriberInterface
{
    /**
     * @var ConfigManager
     */
    protected $configManager;

    /**
     * @var Translator
     */
    protected $translator;

    /**
     * @var OroEntityManager
     */
    protected $em;

    /**
     * @var DynamicTranslationMetadataCache
     */
    protected $dbTranslationMetadataCache;

    /**
     * @param ConfigManager                   $configManager
     * @param Translator                      $translator
     * @param DynamicTranslationMetadataCache $dbTranslationMetadataCache
     */
    public function __construct(
        ConfigManager $configManager,
        Translator $translator,
        DynamicTranslationMetadataCache $dbTranslationMetadataCache
    ) {
        $this->configManager              = $configManager;
        $this->translator                 = $translator;
        $this->dbTranslationMetadataCache = $dbTranslationMetadataCache;
        $this->em                         = $configManager->getEntityManager();
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            FormEvents::POST_SUBMIT  => 'postSubmit',
            FormEvents::PRE_SET_DATA => 'preSetData'
        );
    }

    /**
     * Check for translatable values and preSet it on form
     * if have NO translation in translation catalogue return:
     *  - field name (in case of creating new FieldConfigModel)
     *  - empty string (in case of editing FieldConfigModel)
     *
     * @param FormEvent $event
     */
    public function preSetData(FormEvent $event)
    {
        $configModel = $event->getForm()->getConfig()->getOption('config_model');
        $data        = $event->getData();

        $dataChanges = false;
        foreach ($this->configManager->getProviders() as $provider) {
            $scope = $provider->getScope();
            if (isset($data[$scope])) {
                $translatable = $provider->getPropertyConfig()->getTranslatableValues(
                    $this->configManager->getConfigIdByModel($configModel, $scope)
                );
                foreach ($data[$scope] as $code => $value) {
                    if (in_array($code, $translatable)) {
                        if ($this->translator->hasTrans($value)) {
                            $data[$scope][$code] = $this->translator->trans($value);
                        } elseif (!$configModel->getId() && $configModel instanceof FieldConfigModel) {
                            $data[$scope][$code] = $configModel->getFieldName();
                        } else {
                            $data[$scope][$code] = '';
                        }
                        $dataChanges = true;
                    }
                }
            }
        }

        if ($dataChanges) {
            $event->setData($data);
        }
    }

    /**
     * @param FormEvent $event
     */
    public function postSubmit(FormEvent $event)
    {
        $configModel = $event->getForm()->getConfig()->getOption('config_model');
        if ($configModel instanceof FieldConfigModel) {
            $className = $configModel->getEntity()->getClassName();
            $fieldName = $configModel->getFieldName();
        } else {
            $fieldName = null;
            $className = $configModel->getClassName();
        }

        $data = $event->getData();
        foreach ($this->configManager->getProviders() as $provider) {
            $scope = $provider->getScope();
            if (isset($data[$scope])) {
                $config = $provider->getConfig($className, $fieldName);

                // config translations
                $translatable = $provider->getPropertyConfig()->getTranslatableValues(
                    $this->configManager->getConfigIdByModel($configModel, $scope)
                );
                foreach ($data[$scope] as $code => $value) {
                    if (in_array($code, $translatable)) {
                        $key = $this->configManager->getProvider('entity')
                            ->getConfig($className, $fieldName)
                            ->get($code);

                        if ($event->getForm()->get($scope)->get($code)->isValid()
                            && $value != $this->translator->trans($config->get($code))
                        ) {
                            $locale = $this->translator->getLocale();
                            // save into translation table
                            /** @var TranslationRepository $translationRepo */
                            $translationRepo = $this->em->getRepository(Translation::ENTITY_NAME);
                            $translationRepo->saveValue(
                                $key,
                                $value,
                                $locale,
                                TranslationRepository::DEFAULT_DOMAIN,
                                Translation::SCOPE_UI
                            );
                            // mark translation cache dirty
                            $this->dbTranslationMetadataCache->updateTimestamp($locale);
                        }

                        if (!$configModel->getId()) {
                            $data[$scope][$code] = $key;
                        } else {
                            unset($data[$scope][$code]);
                        }
                    }
                }

                foreach ($data[$scope] as $code => $value) {
                    $config->set($code, $value);
                }
                $this->configManager->persist($config);
            }
        }

        if ($event->getForm()->isValid()) {
            $this->configManager->flush();
        }
    }
}
