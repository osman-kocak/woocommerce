<?php

namespace Automattic\WooCommerce;

class Class4
{
    public static function init() {
        echo "!!! Class4 inited!\n";
    }

    public static function foobar($count, $message) {
        echo "4: $count $message\n";
        return $count+1;
    }
}