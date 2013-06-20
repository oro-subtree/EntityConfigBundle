<?php

namespace Oro\Bundle\EntityConfigBundle\DependencyInjection;

class EntityConfigContainer
{

    /**
     * @var string
     */
    protected $scope;

    /**
     * @var array
     */
    protected $config;

    /**
     * @param $config
     */
    public function __construct($config)
    {
        $this->config = $config;
        $this->scope  = $config['scope'];
    }

    /**
     * @return string
     */
    public function getScope()
    {
        return $this->scope;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return array
     */
    public function getEntityItems()
    {
        if (isset($this->config['entity']) && isset($this->config['entity']['items'])) {
            return $this->config['entity']['items'];
        }

        return array();
    }

    /**
     * @return array
     */
    public function getEntityGridActions()
    {
        if (isset($this->config['entity']) && isset($this->config['entity']['grid_action'])) {
            return $this->config['entity']['grid_action'];
        }

        return array();
    }

    /**
     * @return array
     */
    public function getEntityLayoutActions()
    {
        if (isset($this->config['entity']) && isset($this->config['entity']['layout_action'])) {
            return $this->config['entity']['layout_action'];
        }

        return array();
    }

    /**
     * @return array
     */
    public function getFieldItems()
    {
        if (isset($this->config['field']) && isset($this->config['field']['items'])) {
            return $this->config['field']['items'];
        }

        return array();
    }

    /**
     * @return array
     */
    public function getFieldGridActions()
    {
        if (isset($this->config['field']) && isset($this->config['field']['grid_action'])) {
            return $this->config['field']['grid_action'];
        }

        return array();
    }

    /**
     * @return array
     */
    public function getFieldLayoutActions()
    {
        if (isset($this->config['field']) && isset($this->config['field']['layout_action'])) {
            return $this->config['entity']['layout_action'];
        }

        return array();
    }
}
