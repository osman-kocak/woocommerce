<?php

namespace Automattic\WooCommerce;

class Class3
{
    public function __construct() {
        echo "!!! Class3 constructed!\n";
    }

    public function foobar($count, $message) {
        echo "3: $count $message\n";
        return $count+1;
    }
}
