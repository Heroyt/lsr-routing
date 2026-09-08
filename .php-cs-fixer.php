<?php

declare(strict_types=1);
use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->files()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/config',
    ])
    ->exclude([
        'vendor',
        'node_modules',
        'temp',
        'tmp',
        'logs',
        'dist',
        'build',
        'gen',
        'out',
    ])
    ->ignoreDotFiles(false)
    ->ignoreVCS(true);

return new Config()
    ->setUsingCache(true)
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        '@PSR12' => true,
        'braces_position' => [
            'allow_single_line_anonymous_functions' => false,
            'allow_single_line_empty_anonymous_classes' => true,
            'anonymous_classes_opening_brace' => 'same_line',
            'anonymous_functions_opening_brace' => 'same_line',
            'classes_opening_brace' => 'next_line_unless_newline_at_signature_end',
            'control_structures_opening_brace' => 'same_line',
            'functions_opening_brace' => 'same_line',
        ],
        'elseif' => true,
        'method_argument_space' => [
            'after_heredoc' => false,
            'attribute_placement' => 'ignore',
            'keep_multiple_spaces_after_comma' => false,
            'on_multiline' => 'ensure_fully_multiline',
        ],
        'ordered_imports' => [
            'imports_order' => [
                'class',
                'function',
                'const',
            ],
            'sort_algorithm' => 'alpha',
        ],
        'spaces_inside_parentheses' => [
            'space' => 'none',
        ],
        'trailing_comma_in_multiline' => [
            'after_heredoc' => true,
            'elements' => [
                'arguments',
                'array_destructuring',
                'arrays',
                'match',
                'parameters',
            ],
        ],
        'encoding' => true,
        'class_reference_name_casing' => true,
        'constant_case' => ['case' => 'lower'],
        'lowercase_cast' => true,
        'new_with_parentheses' => [
            'anonymous_class' => false,
            'named_class' => true,
        ],
        'align_multiline_comment' => true,
        'array_indentation' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'concat_space' => ['spacing' => 'one'],
        'declare_parentheses' => true,
        'method_chaining_indentation' => true,
        'no_empty_comment' => true,
        'not_operator_with_space' => true,
        'trim_array_spaces' => true,
        'no_empty_phpdoc' => true,
        'no_empty_statement' => true,
        'no_unused_imports' => true,
        'combine_consecutive_issets' => true,
        'combine_consecutive_unsets' => true,
        'explicit_string_variable' => true,
        'fully_qualified_strict_types' => [
            'leading_backslash_in_global_namespace' => true,
            'import_symbols' => true,
        ],
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'ternary_to_null_coalescing' => true,
        'assign_null_coalescing_to_coalesce_equal' => true,
        'no_superfluous_elseif' => true,
        'no_useless_else' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'php_unit_method_casing' => ['case' => 'snake_case'],
        'declare_strict_types' => true,
        'void_return' => true,
        'modernize_types_casting' => true,
        'modernize_strpos' => true,
        'is_null' => true,
    ]);
