<?php

$config = new OC\CodingStandard\Config();

$config
    ->setUsingCache(true)
    ->getFinder()
    ->in(__DIR__)
    ->exclude('build')
    ->exclude('vendor-bin')
    ->exclude('vendor');

return $config;