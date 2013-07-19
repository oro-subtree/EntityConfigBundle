<?php

namespace Oro\Bundle\EntityConfigBundle\Config;

interface ConfigInterface extends \Serializable
{
    /**
     * @return string
     */
    public function getClassName();

    /**
     * @return string
     */
    public function getScope();

    /**
     * @param       $code
     * @param  bool $strict
     * @return string
     */
    public function get($code, $strict = false);

    /**
     * @param $code
     * @param $value
     * @return string
     */
    public function set($code, $value);

    /**
     * @param $code
     * @return bool
     */
    public function has($code);

    /**
     * @param $code
     * @return bool
     */
    public function is($code);

    /**
     * @param  array $exclude
     * @param  array $include
     * @return array
     */
    public function getValues(array $exclude = array(), array $include = array());

    /**
     * @param $values
     */
    public function setValues($values);
}
