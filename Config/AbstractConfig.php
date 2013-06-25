<?php

namespace Oro\Bundle\EntityConfigBundle\Config;

use Oro\Bundle\EntityConfigBundle\Exception\RuntimeException;

abstract class AbstractConfig implements ConfigInterface
{
    /**
     * @var array
     */
    protected $values = array();

    /**
     * @param                   $code
     * @param  bool             $strict
     * @throws RuntimeException
     * @return string
     */
    public function get($code, $strict = false)
    {
        if (isset($this->values[$code])) {
            return $this->values[$code];
        } elseif ($strict) {
            throw new RuntimeException(sprintf(
                "Config '%s' for class '%s' in scope '%s' is not found ",
                $code, $this->getClassName(), $this->getScope()
            ));
        }

        return null;
    }

    /**
     * @param $code
     * @param $value
     * @throws RuntimeException
     * @return string
     */
    public function set($code, $value)
    {
        $this->values[$code] = $value;

        return $this;
    }

    /**
     * @param $code
     * @return bool
     */
    public function has($code)
    {
        return isset($this->values[$code]);
    }

    /**
     * @param $code
     * @return bool
     */
    public function is($code)
    {
        return (bool) $this->get($code, false);
    }

    /**
     * @return array
     */
    public function getValues()
    {
        return $this->values;
    }

    /**
     * @param array $values
     * @return $this
     */
    public function setValues($values)
    {
        $this->values = $values;

        return $this;
    }
}
