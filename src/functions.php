<?php

declare(strict_types=1);

namespace Empira;

if (!function_exists('Empira\\hx')) {
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