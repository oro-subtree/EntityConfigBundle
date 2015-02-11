<?php

namespace Oro\Bundle\EntityConfigBundle\Tests\Selenium\Pages;

use Oro\Bundle\TestFrameworkBundle\Pages\AbstractPageFilteredGrid;

/**
 * Class ConfigEntities
 *
 * @package Oro\Bundle\EntityConfigBundle\Tests\Selenium\Pages
 * @method ConfigEntities openConfigEntities() openConfigEntities(string)
 * @method ConfigEntities assertTitle() assertTitle($title, $message = '')
 */
class ConfigEntities extends AbstractPageFilteredGrid
{
    const URL = 'entity/config/';

    public function __construct($testCase, $redirect = true)
    {
        $this->redirectUrl = self::URL;
        parent::__construct($testCase, $redirect);
    }

    /**
     * @return ConfigEntity
     */
    public function add()
    {
        $this->test->byXPath("//a[@title='Create entity']")->click();
        $this->waitPageToLoad();
        $this->waitForAjax();
        $entity = new ConfigEntity($this->test);
        return $entity->init(true);
    }

    public function open($entityData = array())
    {
        $contact = $this->getEntity($entityData);
        $contact->click();
        sleep(1);
        $this->waitPageToLoad();
        $this->waitForAjax();

        return new ConfigEntity($this->test);
    }

    public function delete()
    {
        $this->test->byXpath("//td[contains(@class,'action-cell')]//a[contains(., '...')]")->click();
        $this->waitForAjax();
        $this->test->byXpath("//td[contains(@class,'action-cell')]//a[@title= 'Remove']")->click();
        $this->waitPageToLoad();
        return $this;
    }
}
