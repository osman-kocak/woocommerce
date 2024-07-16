<?php

namespace Automattic\WooCommerce;

class Class2
{
    public function __construct() {
        echo "!!! Class2 constructed!\n";
    }

    public function foobar($count, $message) {
        echo "2: $count $message\n";
        return $count+1;
    }
}