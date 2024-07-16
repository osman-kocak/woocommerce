<?php

namespace Automattic\WooCommerce;

class Class1
{
    public function __construct() {
        //echo "!!! Class1 constructed!\n";
    }

    public function foobar($count, $message) {
        echo "1: $count $message\n";
        return $count+1;
    }
}