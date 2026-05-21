<?php
/**
 * API pubblica runtime diagnostics FP Image Optimizer.
 *
 * @package FP\ImgOpt\Services\Diagnostics
 */

declare(strict_types=1);

use FP\ImgOpt\Services\Diagnostics\RuntimeDiagnostics;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RuntimeDiagnostics.php';

if (!function_exists('fp_imgopt_get_runtime_diagnostics')) {
    /**
     * @param array<int, string>   $sections
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    function fp_imgopt_get_runtime_diagnostics(array $sections = [], array $options = []): array
    {
        return RuntimeDiagnostics::build($sections, $options);
    }
}

if (!function_exists('fp_imgopt_get_runtime_diagnostics_sections')) {
    /**
     * @return array<int, string>
     */
    function fp_imgopt_get_runtime_diagnostics_sections(): array
    {
        return RuntimeDiagnostics::ALL_SECTIONS;
    }
}
