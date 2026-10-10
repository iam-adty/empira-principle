<?php

declare(strict_types=1);

namespace IamAdty\Empira;

if (!function_exists('IamAdty\Empira\\hx')) {
    /**
     * Create an element outside of a component.
     *
     *     echo hx()->button('Refresh')->hxGet('/fragment');
     */
    function hx(): ElementFactory
    {
        return ElementFactory::default();
    }
}