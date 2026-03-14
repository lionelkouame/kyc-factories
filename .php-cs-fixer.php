<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->name('*.php');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony'                   => true,
        '@Symfony:risky'             => true,
        'declare_strict_types'       => true,
        'strict_param'               => true,
        'array_syntax'               => ['syntax' => 'short'],
        'ordered_imports'            => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'          => true,
        'phpdoc_order'               => true,
        'final_class'                => true,
    ])
    ->setFinder($finder);
